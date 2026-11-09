/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Fleet (Summary) Dashboard adapter for the shared card chrome (js/cards.js).
 * Same model as the per-device Host Dashboard but scoped by OS type (ostype)
 * rather than a single device. The CactiCards controller owns drag/reorder and
 * the per-card tools; this adapter maps them to hmib's summary endpoints and
 * wires the fleet extras (sortable tables, the OS-type picker, the auto-refresh
 * interval and manual refresh-all). Card markup uses the shared .cc* contract.
 */

var hmibSumAdapter = null;

/* Make a container's sortable headers focusable and screen-reader friendly. */
function hmibSumPrepSortHeaders(container) {
	if (!container) {
		return;
	}

	container.querySelectorAll('th.hmibDashSortable').forEach(function(th) {
		if (!th.hasAttribute('tabindex')) {
			th.setAttribute('tabindex', '0');
		}
		th.setAttribute('role', 'button');
		if (!th.hasAttribute('aria-sort')) {
			th.setAttribute('aria-sort', 'none');
		}
	});
}

/* Sort a card table by the clicked column header, toggling asc/desc. Numeric
 * columns (data-sort="num") sort by the cell's data-sort-value when present. */
function hmibSumSortTable(th) {
	var table = th.closest('table');
	if (!table) {
		return;
	}

	var tbody = table.tBodies[0];
	if (!tbody) {
		return;
	}

	var headers = Array.prototype.slice.call(th.parentNode.children);
	var index = headers.indexOf(th);
	var numeric = th.dataset.sort === 'num';
	var asc = !th.classList.contains('hmibDashSortAsc');

	headers.forEach(function(header) {
		header.classList.remove('hmibDashSortAsc', 'hmibDashSortDesc');
		if (header.classList.contains('hmibDashSortable')) {
			header.setAttribute('aria-sort', 'none');
		}
	});
	th.classList.add(asc ? 'hmibDashSortAsc' : 'hmibDashSortDesc');
	th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');

	var rows = Array.prototype.slice.call(tbody.rows).filter(function(row) {
		return !row.classList.contains('hmibDashEmptyRow');
	});

	var value = function(row) {
		var cell = row.cells[index];
		if (!cell) {
			return numeric ? 0 : '';
		}
		if (numeric) {
			var raw = cell.dataset.sortValue !== undefined ? cell.dataset.sortValue : cell.textContent;
			var num = parseFloat(String(raw).replace(/[^0-9.\-]/g, ''));
			return isNaN(num) ? 0 : num;
		}
		return cell.textContent.trim().toLowerCase();
	};

	rows.sort(function(a, b) {
		var va = value(a);
		var vb = value(b);
		if (va < vb) { return asc ? -1 : 1; }
		if (va > vb) { return asc ? 1 : -1; }
		return 0;
	});

	rows.forEach(function(row) {
		tbody.appendChild(row);
	});
}

/* Rebuild the "Add" dropdown so it lists only cards not currently on the page. */
function hmibSumSyncAddOptions(grid) {
	var add = document.getElementById('hmib_summary_add');

	if (!add || typeof hmibSummaryCatalog === 'undefined') {
		return;
	}

	var present = {};

	grid.querySelectorAll('.ccCard').forEach(function(card) {
		if (card.dataset.card) {
			present[card.dataset.card] = true;
		}
	});

	while (add.options.length > 1) {
		add.remove(1);
	}

	Object.keys(hmibSummaryCatalog).forEach(function(key) {
		if (!present[key]) {
			var option = document.createElement('option');
			option.value = key;
			option.textContent = hmibSummaryCatalog[key];
			add.appendChild(option);
		}
	});
}

/* Open a copy of a card's body in a large modal dialog, re-wiring the clone's
 * sortable table headers (they live outside the grid). */
function hmibSumMaximize(card) {
	var dialog = document.getElementById('hmib_summary_dialog');

	if (!dialog) {
		return;
	}

	var title = card.querySelector('.ccTitle');
	var body  = card.querySelector('.ccCardBody');

	dialog.innerHTML = '<div class="ccDialogBody">' + (body ? body.innerHTML : '') + '</div>';

	hmibSumPrepSortHeaders(dialog);
	$(dialog).off('click.hmibsumsort').on('click.hmibsumsort', 'th.hmibDashSortable', function() {
		hmibSumSortTable(this);
	});
	$(dialog).off('keydown.hmibsumsort').on('keydown.hmibsumsort', 'th.hmibDashSortable', function(event) {
		if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
			event.preventDefault();
			hmibSumSortTable(this);
		}
	});

	$(dialog).dialog({
		modal: true,
		appendTo: 'body',
		width: Math.min(900, $(window).width() - 40),
		title: title ? title.textContent : ''
	});
}

/* Fetch a single card's HTML from the server (used by refresh/add). */
function hmibSumFetch(grid, id, expanded) {
	return $.post('hmib.php', {
		action: 'summary_card',
		card: id,
		ostype: grid.dataset.ostype,
		expanded: expanded ? '1' : '0',
		__csrf_magic: csrfMagicToken
	}, null, 'json').then(function(data) {
		return data && data.html ? data.html : null;
	});
}

/* Persist order + expanded state and resync the "Add" catalogue. */
function hmibSumPostLayout(grid, state) {
	var expanded = {};

	state.order.forEach(function(id) {
		if (state.cards[id] && state.cards[id].expanded) {
			expanded[id] = true;
		}
	});

	$.post('hmib.php', {
		action: 'summary_layout',
		layout: JSON.stringify({order: state.order, expanded: expanded}),
		__csrf_magic: csrfMagicToken
	}, null, 'json');

	hmibSumSyncAddOptions(grid);
}

/* Refresh every card on the page, spinning the toolbar glyph until all land. */
function hmibSumRefreshAll(grid, button) {
	var icon = button ? button.querySelector('.fa, .fas') : null;
	var cards = Array.prototype.slice.call(grid.querySelectorAll('.ccCard'));
	var pending = cards.length;

	if (!pending) {
		return;
	}

	if (icon) {
		icon.classList.add('fa-spin');
	}

	cards.forEach(function(card) {
		var expanded = card.classList.contains('ccCardExpanded');

		hmibSumFetch(grid, card.dataset.card, expanded).then(function(html) {
			if (html && card.parentNode && window.CactiCards) {
				CactiCards.replaceCard(grid, hmibSumAdapter, card, html);
			}
		}).always(function() {
			pending--;
			if (pending <= 0 && icon) {
				icon.classList.remove('fa-spin');
			}
		});
	});
}

/* Wire the Fleet (Summary) Dashboard grid to the shared card chrome plus the
 * fleet extras. */
function initHmibSummaryDashboard() {
	$(function() {
		var grid = document.getElementById('hmib_summary_dashboard');

		if (!grid || !window.CactiCards) {
			return;
		}

		hmibSumAdapter = {
			addControl: 'hmib_summary_add',

			fetchCard: function(id, state) {
				return hmibSumFetch(grid, id, state && state.expanded);
			},

			onAddCard: function(id) {
				return hmibSumFetch(grid, id, false);
			},

			onLayoutChange: function(state) {
				hmibSumPostLayout(grid, state);
			},

			maximize: function(id, mode, card) {
				hmibSumMaximize(card);
			}
		};

		CactiCards.init(grid, hmibSumAdapter);
		hmibSumSyncAddOptions(grid);

		// Client-side column sorting for card tables (mouse and keyboard).
		$(grid).off('click.hmibsumsort').on('click.hmibsumsort', 'th.hmibDashSortable', function() {
			hmibSumSortTable(this);
		});
		$(grid).off('keydown.hmibsumsort').on('keydown.hmibsumsort', 'th.hmibDashSortable', function(event) {
			if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
				event.preventDefault();
				hmibSumSortTable(this);
			}
		});

		// OS-type picker: reload the fleet dashboard scoped to the chosen type.
		var type = document.getElementById('hmib_summary_type');
		if (type) {
			$(type).off('change.hmibsum').on('change.hmibsum', function() {
				window.location = 'hmib.php?action=summary_dashboard&ostype=' + encodeURIComponent(this.value);
			});
		}

		// Auto-refresh interval: persist through a CSRF-protected POST, then reload.
		var interval = document.getElementById('hmib_summary_refresh');
		if (interval) {
			$(interval).off('change.hmibsum').on('change.hmibsum', function() {
				$.post('hmib.php', {action: 'summary_refresh', refresh: this.value, __csrf_magic: csrfMagicToken}, null, 'json').always(function() {
					window.location = 'hmib.php?action=summary_dashboard';
				});
			});
		}

		var refreshNow = document.getElementById('hmib_summary_refresh_now');
		if (refreshNow) {
			$(refreshNow).off('click.hmibsum').on('click.hmibsum', function() {
				hmibSumRefreshAll(grid, this);
			});
		}
	});
}
