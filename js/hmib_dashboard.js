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
 * Host Dashboard adapter for the shared card chrome (js/cards.js). The
 * CactiCards controller owns drag/keyboard reorder and the per-card tools; this
 * adapter maps those to hmib's server endpoints (hmib.php) and wires hmib's
 * dashboard extras - sortable card tables, the device picker, the auto-refresh
 * interval and manual refresh-all. The card markup uses the shared .cc*
 * contract and the card bodies the shared .cc* chart primitives.
 */

var hmibDashAdapter = null;

/* Named navigation callback for the device picker. The select2 drop_callback
 * invokes this by name on selection (it does not emit a change event on older
 * Cacti), so it must stay global for executeFunctionByName(). */
function hmibDashboardNavigate() {
	var host = document.getElementById('hmib_dashboard_host');

	if (host && host.value) {
		window.location = 'hmib.php?action=dashboard&device=' + encodeURIComponent(host.value);
	}
}

/* Make a container's sortable headers focusable and screen-reader friendly. */
function hmibDashPrepSortHeaders(container) {
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
function hmibDashSortTable(th) {
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
function hmibDashSyncAddOptions(grid) {
	var add = document.getElementById('hmib_dashboard_add');

	if (!add || typeof hmibDashCatalog === 'undefined') {
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

	Object.keys(hmibDashCatalog).forEach(function(key) {
		if (!present[key]) {
			var option = document.createElement('option');
			option.value = key;
			option.textContent = hmibDashCatalog[key];
			add.appendChild(option);
		}
	});
}

/* Open a copy of a card's body in a large modal dialog, re-wiring the clone's
 * sortable table headers (they live outside the grid). */
function hmibDashMaximize(card) {
	var dialog = document.getElementById('hmib_dashboard_dialog');

	if (!dialog) {
		return;
	}

	var title = card.querySelector('.ccTitle');
	var body  = card.querySelector('.ccCardBody');

	dialog.innerHTML = '<div class="ccDialogBody">' + (body ? body.innerHTML : '') + '</div>';

	hmibDashPrepSortHeaders(dialog);
	$(dialog).off('click.hmibdashsort').on('click.hmibdashsort', 'th.hmibDashSortable', function() {
		hmibDashSortTable(this);
	});
	$(dialog).off('keydown.hmibdashsort').on('keydown.hmibdashsort', 'th.hmibDashSortable', function(event) {
		if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
			event.preventDefault();
			hmibDashSortTable(this);
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
function hmibDashFetch(grid, id, expanded) {
	return $.post('hmib.php', {
		action: 'dashboard_card',
		card: id,
		device: grid.dataset.host,
		expanded: expanded ? '1' : '0',
		__csrf_magic: csrfMagicToken
	}, null, 'json').then(function(data) {
		return data && data.html ? data.html : null;
	});
}

/* Persist order + expanded state and resync the "Add" catalogue. */
function hmibDashPostLayout(grid, state) {
	var expanded = {};

	state.order.forEach(function(id) {
		if (state.cards[id] && state.cards[id].expanded) {
			expanded[id] = true;
		}
	});

	$.post('hmib.php', {
		action: 'dashboard_layout',
		layout: JSON.stringify({order: state.order, expanded: expanded}),
		__csrf_magic: csrfMagicToken
	}, null, 'json');

	hmibDashSyncAddOptions(grid);
}

/* Refresh every card on the page, spinning the toolbar glyph until all land. */
function hmibDashRefreshAll(grid, button) {
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

		hmibDashFetch(grid, card.dataset.card, expanded).then(function(html) {
			if (html && card.parentNode && window.CactiCards) {
				CactiCards.replaceCard(grid, hmibDashAdapter, card, html);
			}
		}).always(function() {
			pending--;
			if (pending <= 0 && icon) {
				icon.classList.remove('fa-spin');
			}
		});
	});
}

/* Wire the Host Dashboard grid to the shared card chrome plus hmib's extras. */
function initHmibDashboard() {
	$(function() {
		var grid = document.getElementById('hmib_dashboard');

		if (!grid || !window.CactiCards) {
			return;
		}

		hmibDashAdapter = {
			addControl: 'hmib_dashboard_add',

			fetchCard: function(id, state) {
				return hmibDashFetch(grid, id, state && state.expanded);
			},

			onAddCard: function(id) {
				return hmibDashFetch(grid, id, false);
			},

			onLayoutChange: function(state) {
				hmibDashPostLayout(grid, state);
			},

			maximize: function(id, mode, card) {
				hmibDashMaximize(card);
			}
		};

		CactiCards.init(grid, hmibDashAdapter);
		hmibDashSyncAddOptions(grid);

		// Client-side column sorting for card tables (mouse and keyboard).
		$(grid).off('click.hmibdashsort').on('click.hmibdashsort', 'th.hmibDashSortable', function() {
			hmibDashSortTable(this);
		});
		$(grid).off('keydown.hmibdashsort').on('keydown.hmibdashsort', 'th.hmibDashSortable', function(event) {
			if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
				event.preventDefault();
				hmibDashSortTable(this);
			}
		});

		// Device picker: the plain-<select> fallback emits change; the select2
		// AJAX control instead invokes hmibDashboardNavigate() by name.
		var host = document.getElementById('hmib_dashboard_host');
		if (host) {
			$(host).off('change.hmibdash').on('change.hmibdash', function() {
				hmibDashboardNavigate();
			});
		}

		// Auto-refresh interval: persist through a CSRF-protected POST, then
		// reload so Cacti's page refresh applies.
		var interval = document.getElementById('hmib_dashboard_refresh');
		if (interval) {
			$(interval).off('change.hmibdash').on('change.hmibdash', function() {
				$.post('hmib.php', {action: 'dashboard_refresh', refresh: this.value, __csrf_magic: csrfMagicToken}, null, 'json').always(function() {
					window.location = 'hmib.php?action=dashboard';
				});
			});
		}

		var refreshNow = document.getElementById('hmib_dashboard_refresh_now');
		if (refreshNow) {
			$(refreshNow).off('click.hmibdash').on('click.hmibdash', function() {
				hmibDashRefreshAll(grid, this);
			});
		}
	});
}
