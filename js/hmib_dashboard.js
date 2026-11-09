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
 * Host MIB dashboard adapter for the shared card chrome (js/cards.js). One
 * factory (hmibDashInit) wires either dashboard - the per-device Host Dashboard
 * or the OS-type-scoped Fleet (Summary) Dashboard - to the CactiCards
 * controller, which owns drag/keyboard reorder and the per-card tools. The
 * factory maps the adapter hooks to hmib's endpoints and adds hmib's extras:
 * sortable card tables, a scope picker, the auto-refresh interval and a manual
 * refresh-all. Card markup uses the shared .cc* contract and the card bodies
 * the shared .cc* chart primitives.
 */

/* Named navigation callback for the device picker. The select2 drop_callback
 * invokes this by name on selection (no change event on older Cacti), so it
 * must stay global for executeFunctionByName(). */
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
			var num = Number.parseFloat(String(raw).replace(/[^0-9.\-]/g, ''));
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

/* Wire sortable-table handlers (mouse + keyboard) on a container. */
function hmibDashBindSort(container, namespace) {
	$(container).off('click.' + namespace).on('click.' + namespace, 'th.hmibDashSortable', function() {
		hmibDashSortTable(this);
	});
	$(container).off('keydown.' + namespace).on('keydown.' + namespace, 'th.hmibDashSortable', function(event) {
		if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
			event.preventDefault();
			hmibDashSortTable(this);
		}
	});
}

/*
 * Wire one dashboard grid to the shared card chrome plus hmib's extras. opts:
 *   gridId, addId, dialogId, intervalId, refreshNowId, pickerId  - element ids
 *   cardAction, layoutAction, refreshAction                      - hmib.php actions
 *   refreshReload                                                - URL to reload on interval change
 *   scopeName, scopeAttr                                         - POST param name + grid.dataset key
 *   catalogVar                                                   - global catalogue object name
 *   onPickerChange(el)                                           - scope picker handler
 */
function hmibDashInit(opts) {
	$(function() {
		var grid = document.getElementById(opts.gridId);

		if (!grid || !window.CactiCards) {
			return;
		}

		function catalogue() {
			return typeof window[opts.catalogVar] !== 'undefined' ? window[opts.catalogVar] : null;
		}

		function syncAddOptions() {
			var add = document.getElementById(opts.addId);
			var cat = catalogue();

			if (!add || !cat) {
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

			Object.keys(cat).forEach(function(key) {
				if (!present[key]) {
					var option = document.createElement('option');
					option.value = key;
					option.textContent = cat[key];
					add.appendChild(option);
				}
			});
		}

		function fetchCard(id, expanded) {
			var data = {action: opts.cardAction, card: id, expanded: expanded ? '1' : '0', __csrf_magic: csrfMagicToken};
			data[opts.scopeName] = grid.dataset[opts.scopeAttr];

			return $.post('hmib.php', data, null, 'json').then(function(d) {
				return d && d.html ? d.html : null;
			});
		}

		function maximize(card) {
			var dialog = document.getElementById(opts.dialogId);

			if (!dialog) {
				return;
			}

			var title = card.querySelector('.ccTitle');
			var body  = card.querySelector('.ccCardBody');

			dialog.innerHTML = '<div class="ccDialogBody">' + (body ? body.innerHTML : '') + '</div>';

			hmibDashPrepSortHeaders(dialog);
			hmibDashBindSort(dialog, 'hmibdlgsort');

			$(dialog).dialog({
				modal: true,
				appendTo: 'body',
				width: Math.min(900, $(window).width() - 40),
				title: title ? title.textContent : ''
			});
		}

		var adapter = {
			addControl: opts.addId,

			fetchCard: function(id, state) {
				return fetchCard(id, state && state.expanded);
			},

			onAddCard: function(id) {
				return fetchCard(id, false);
			},

			onLayoutChange: function(state) {
				var expanded = {};

				state.order.forEach(function(id) {
					if (state.cards[id] && state.cards[id].expanded) {
						expanded[id] = true;
					}
				});

				$.post('hmib.php', {
					action: opts.layoutAction,
					layout: JSON.stringify({order: state.order, expanded: expanded}),
					__csrf_magic: csrfMagicToken
				}, null, 'json');

				syncAddOptions();
			},

			maximize: function(id, mode, card) {
				maximize(card);
			}
		};

		CactiCards.init(grid, adapter);
		syncAddOptions();
		hmibDashBindSort(grid, 'hmibsort');

		var picker = document.getElementById(opts.pickerId);
		if (picker) {
			$(picker).off('change.hmib').on('change.hmib', function() {
				opts.onPickerChange(this);
			});
		}

		var interval = document.getElementById(opts.intervalId);
		if (interval) {
			$(interval).off('change.hmib').on('change.hmib', function() {
				$.post('hmib.php', {action: opts.refreshAction, refresh: this.value, __csrf_magic: csrfMagicToken}, null, 'json').always(function() {
					window.location = opts.refreshReload;
				});
			});
		}

		var refreshNow = document.getElementById(opts.refreshNowId);
		if (refreshNow) {
			$(refreshNow).off('click.hmib').on('click.hmib', function() {
				var icon = this.querySelector('.fa, .fas');
				var cards = Array.prototype.slice.call(grid.querySelectorAll('.ccCard'));
				var pending = cards.length;

				if (!pending) {
					return;
				}

				if (icon) {
					icon.classList.add('fa-spin');
				}

				cards.forEach(function(card) {
					fetchCard(card.dataset.card, card.classList.contains('ccCardExpanded')).then(function(html) {
						if (html && card.parentNode && window.CactiCards) {
							CactiCards.replaceCard(grid, adapter, card, html);
						}
					}).always(function() {
						pending--;
						if (pending <= 0 && icon) {
							icon.classList.remove('fa-spin');
						}
					});
				});
			});
		}
	});
}

/* Per-device Host Dashboard. */
function initHmibDashboard() {
	hmibDashInit({
		gridId:        'hmib_dashboard',
		addId:         'hmib_dashboard_add',
		dialogId:      'hmib_dashboard_dialog',
		intervalId:    'hmib_dashboard_refresh',
		refreshNowId:  'hmib_dashboard_refresh_now',
		pickerId:      'hmib_dashboard_host',
		cardAction:    'dashboard_card',
		layoutAction:  'dashboard_layout',
		refreshAction: 'dashboard_refresh',
		refreshReload: 'hmib.php?action=dashboard',
		scopeName:     'device',
		scopeAttr:     'host',
		catalogVar:    'hmibDashCatalog',
		onPickerChange: function() {
			hmibDashboardNavigate();
		}
	});
}

/* OS-type-scoped Fleet (Summary) Dashboard. */
function initHmibSummaryDashboard() {
	hmibDashInit({
		gridId:        'hmib_summary_dashboard',
		addId:         'hmib_summary_add',
		dialogId:      'hmib_summary_dialog',
		intervalId:    'hmib_summary_refresh',
		refreshNowId:  'hmib_summary_refresh_now',
		pickerId:      'hmib_summary_type',
		cardAction:    'summary_card',
		layoutAction:  'summary_layout',
		refreshAction: 'summary_refresh',
		refreshReload: 'hmib.php?action=summary_dashboard',
		scopeName:     'ostype',
		scopeAttr:     'ostype',
		catalogVar:    'hmibSummaryCatalog',
		onPickerChange: function(el) {
			window.location = 'hmib.php?action=summary_dashboard&ostype=' + encodeURIComponent(el.value);
		}
	});
}
