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

/* Host Dashboard: the per-device drilldown card grid. Mirrors the Syslog Status
 * tab's card interaction model (drag/keyboard reorder, per-card tools, an "Add
 * card" catalogue, auto-refresh interval + manual refresh) with the card order,
 * expanded state and current host persisted server-side in settings_user. */

var hmibDashDragging = null;

function initHmibDashboard() {
	$(function() {
		var grid = document.getElementById('hmib_dashboard');
		if (!grid) {
			return;
		}

		grid.querySelectorAll('.hmibDashCard').forEach(function(card) {
			hmibDashBindCard(grid, card);
		});

		grid.addEventListener('dragover', function(event) {
			if (!hmibDashDragging) {
				return;
			}
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			var before = hmibDashDragReference(grid, event.clientX, event.clientY);
			if (before == null) {
				grid.appendChild(hmibDashDragging);
			} else if (before !== hmibDashDragging) {
				grid.insertBefore(hmibDashDragging, before);
			}
		});

		// Per-card tool buttons bubble to the grid.
		$(grid).off('click.hmibdash').on('click.hmibdash', '.hmibDashCardTool', function() {
			var card = this.closest('.hmibDashCard');
			if (!card) {
				return;
			}
			switch (this.dataset.tool) {
				case 'expand':   card.classList.add('hmibDashCardExpanded'); hmibDashSaveLayout(grid); break;
				case 'collapse': card.classList.remove('hmibDashCardExpanded'); hmibDashSaveLayout(grid); break;
				case 'maximize': hmibDashMaximize(card); break;
				case 'refresh':  hmibDashRefreshCard(grid, card); break;
				case 'remove':   hmibDashRemoveCard(grid, card); break;
			}
		});

		// Client-side column sorting for card tables.
		$(grid).off('click.hmibdashsort').on('click.hmibdashsort', 'th.hmibDashSortable', function() {
			hmibDashSortTable(this);
		});

		var add = document.getElementById('hmib_dashboard_add');
		if (add) {
			$(add).off('change.hmibdash').on('change.hmibdash', function() {
				var key = this.value;
				this.value = '';
				if (key) {
					hmibDashAddCard(grid, key);
				}
			});
		}

		var host = document.getElementById('hmib_dashboard_host');
		if (host) {
			$(host).off('change.hmibdash').on('change.hmibdash', function() {
				if (this.value) {
					window.location = 'hmib.php?action=dashboard&device=' + encodeURIComponent(this.value);
				}
			});
		}

		// Changing the interval reloads the page so Cacti's page refresh picks up
		// the new value; it persists in the session for subsequent auto-reloads.
		var interval = document.getElementById('hmib_dashboard_refresh');
		if (interval) {
			$(interval).off('change.hmibdash').on('change.hmibdash', function() {
				window.location = 'hmib.php?action=dashboard&refresh=' + encodeURIComponent(this.value);
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

/** Wire a single card's drag handle (mouse + keyboard) and drag events. */
function hmibDashBindCard(grid, card) {
	var handle = card.querySelector('.hmibDashCardDrag');
	if (!handle) {
		return;
	}

	// Arm HTML5 drag only from the handle, and disarm on release when no drag
	// began so selecting content elsewhere can't drag the card.
	var disarm = function() { card.draggable = false; };
	handle.addEventListener('mousedown', function() {
		card.draggable = true;
		document.addEventListener('mouseup', disarm, {once: true});
	});
	handle.addEventListener('touchstart', function() {
		card.draggable = true;
		document.addEventListener('touchend', disarm, {once: true});
		document.addEventListener('touchcancel', disarm, {once: true});
	}, {passive: true});

	// Keyboard-accessible reordering: move the card with the arrow keys.
	handle.addEventListener('keydown', function(event) {
		var back = event.key === 'ArrowLeft' || event.key === 'ArrowUp';
		var fwd  = event.key === 'ArrowRight' || event.key === 'ArrowDown';
		if (!back && !fwd) {
			return;
		}
		event.preventDefault();
		if (back && card.previousElementSibling) {
			grid.insertBefore(card, card.previousElementSibling);
		} else if (fwd && card.nextElementSibling) {
			grid.insertBefore(card.nextElementSibling, card);
		} else {
			return;
		}
		handle.focus();
		hmibDashSaveLayout(grid);
	});

	card.addEventListener('dragstart', function(event) {
		hmibDashDragging = card;
		card.classList.add('hmibDashCardDragging');
		event.dataTransfer.effectAllowed = 'move';
		try { event.dataTransfer.setData('text/plain', card.dataset.card || ''); } catch (error) { /* IE guard */ }
	});

	card.addEventListener('dragend', function() {
		card.draggable = false;
		card.classList.remove('hmibDashCardDragging');
		if (hmibDashDragging) {
			hmibDashDragging = null;
			hmibDashSaveLayout(grid);
		}
	});
}

/** The card the dragged card should be inserted before for the current pointer
 *  position, or null to append at the end. */
function hmibDashDragReference(grid, x, y) {
	var cards = Array.prototype.slice.call(grid.querySelectorAll('.hmibDashCard:not(.hmibDashCardDragging)'));
	for (var i = 0; i < cards.length; i++) {
		var rect = cards[i].getBoundingClientRect();
		if (y < rect.top - 1) {
			return cards[i];
		}
		if (y <= rect.bottom && x < rect.left + rect.width / 2) {
			return cards[i];
		}
	}
	return null;
}

/** Add a card from the catalogue: fetch its HTML, append it, persist and refresh
 *  the "Add" options. */
function hmibDashAddCard(grid, key) {
	if (hmibDashHasCard(grid, key)) {
		return;
	}
	$.post('hmib.php', {action: 'dashboard_card', card: key, device: grid.dataset.host, __csrf_magic: csrfMagicToken}, null, 'json').done(function(data) {
		if (!data || !data.html || hmibDashHasCard(grid, key)) {
			return;
		}
		var card = hmibDashParseCard(data.html);
		if (!card) {
			return;
		}
		grid.appendChild(card);
		hmibDashBindCard(grid, card);
		hmibDashSaveLayout(grid);
		hmibDashSyncAddOptions(grid);
	});
}

/** Whether a card with the given key is currently on the grid. */
function hmibDashHasCard(grid, key) {
	var present = false;
	grid.querySelectorAll('.hmibDashCard').forEach(function(card) {
		if (card.dataset.card === key) {
			present = true;
		}
	});
	return present;
}

/** Remove a card from the page and return it to the "Add" catalogue. */
function hmibDashRemoveCard(grid, card) {
	card.parentNode.removeChild(card);
	hmibDashSaveLayout(grid);
	hmibDashSyncAddOptions(grid);
}

/** Re-fetch a single card's HTML and swap it in place, keeping expanded state. */
function hmibDashRefreshCard(grid, card, done) {
	var icon = card.querySelector('.hmibDashCardTool[data-tool="refresh"] .fa, .hmibDashCardTool[data-tool="refresh"] .fas');
	if (icon) {
		icon.classList.add('fa-spin');
	}
	var settle = function() {
		if (icon) {
			icon.classList.remove('fa-spin');
		}
		if (done) {
			done();
		}
	};
	var expanded = card.classList.contains('hmibDashCardExpanded') ? '1' : '0';
	$.post('hmib.php', {action: 'dashboard_card', card: card.dataset.card, device: grid.dataset.host, expanded: expanded, __csrf_magic: csrfMagicToken}, null, 'json').done(function(data) {
		if (!data || !data.html || !card.parentNode) {
			settle();
			return;
		}
		var fresh = hmibDashParseCard(data.html);
		if (!fresh) {
			settle();
			return;
		}
		card.parentNode.replaceChild(fresh, card);
		hmibDashBindCard(grid, fresh);
		if (done) {
			done();
		}
	}).fail(settle);
}

/** Refresh every card on the page, spinning the toolbar glyph until all land. */
function hmibDashRefreshAll(grid, button) {
	var icon = button ? button.querySelector('.fa, .fas') : null;
	var cards = Array.prototype.slice.call(grid.querySelectorAll('.hmibDashCard'));
	var pending = cards.length;
	if (!pending) {
		return;
	}
	if (icon) {
		icon.classList.add('fa-spin');
	}
	cards.forEach(function(card) {
		hmibDashRefreshCard(grid, card, function() {
			pending--;
			if (pending <= 0 && icon) {
				icon.classList.remove('fa-spin');
			}
		});
	});
}

/** Open a copy of a card's body in a large modal dialog. */
function hmibDashMaximize(card) {
	var dialog = document.getElementById('hmib_dashboard_dialog');
	if (!dialog) {
		return;
	}
	var title = card.querySelector('.hmibDashCardTitle');
	var body  = card.querySelector('.hmibDashCardBody');
	dialog.innerHTML = '<div class="hmibDashDialogBody">' + (body ? body.innerHTML : '') + '</div>';
	$(dialog).dialog({
		modal: true,
		appendTo: 'body',
		width: Math.min(900, $(window).width() - 40),
		title: title ? title.textContent : ''
	});
}

/** Parse a card HTML string into its <section> element. */
function hmibDashParseCard(html) {
	var tmp = document.createElement('div');
	tmp.innerHTML = html;
	return tmp.querySelector('.hmibDashCard');
}

/** Rebuild the "Add" dropdown so it lists only cards not currently on the page. */
function hmibDashSyncAddOptions(grid) {
	var add = document.getElementById('hmib_dashboard_add');
	if (!add || typeof hmibDashCatalog === 'undefined') {
		return;
	}
	var present = {};
	grid.querySelectorAll('.hmibDashCard').forEach(function(card) {
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

/** Sort a card table by the clicked column header, toggling asc/desc. Numeric
 *  columns (data-sort="num") sort by the cell's data-sort-value when present. */
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
	});
	th.classList.add(asc ? 'hmibDashSortAsc' : 'hmibDashSortDesc');

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

/** Serialize layout POSTs so rapid actions can't persist out of order: one
 *  request is in flight at a time, and a save requested meanwhile is coalesced
 *  into a single follow-up that reads the latest DOM, so the final state wins. */
var hmibDashSaveInFlight = false;
var hmibDashSaveQueued = false;

function hmibDashSaveLayout(grid) {
	if (hmibDashSaveInFlight) {
		hmibDashSaveQueued = true;
		return;
	}
	hmibDashSaveInFlight = true;

	var order = [];
	var expanded = {};
	grid.querySelectorAll('.hmibDashCard').forEach(function(card) {
		if (!card.dataset.card) {
			return;
		}
		order.push(card.dataset.card);
		if (card.classList.contains('hmibDashCardExpanded')) {
			expanded[card.dataset.card] = true;
		}
	});

	$.post('hmib.php', {
		action: 'dashboard_layout',
		layout: JSON.stringify({order: order, expanded: expanded}),
		__csrf_magic: csrfMagicToken
	}, null, 'json').always(function() {
		hmibDashSaveInFlight = false;
		if (hmibDashSaveQueued) {
			hmibDashSaveQueued = false;
			hmibDashSaveLayout(grid);
		}
	});
}
