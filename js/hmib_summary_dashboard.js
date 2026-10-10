/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Fleet Dashboard: the Summary-tab aggregate card grid. Mirrors the per-device
 * dashboard's card interaction model (drag/keyboard reorder, add/remove,
 * refresh, maximize, sortable tables, layout persistence) but is scoped by OS
 * type (ostype, 0 = all types) rather than a single device, and reuses the
 * shared .hmibDash* card chrome. */

var hmibSumDragging = null;

function initHmibSummaryDashboard() {
	$(function() {
		var grid = document.getElementById('hmib_summary_dashboard');
		if (!grid) {
			return;
		}

		grid.querySelectorAll('.hmibDashCard').forEach(function(card) {
			hmibSumBindCard(grid, card);
		});

		grid.addEventListener('dragover', function(event) {
			if (!hmibSumDragging) {
				return;
			}
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			var before = hmibSumDragReference(grid, event.clientX, event.clientY);
			if (before == null) {
				grid.appendChild(hmibSumDragging);
			} else if (before !== hmibSumDragging) {
				grid.insertBefore(hmibSumDragging, before);
			}
		});

		$(grid).off('click.hmibsum').on('click.hmibsum', '.hmibDashCardTool', function() {
			var card = this.closest('.hmibDashCard');
			if (!card) {
				return;
			}
			switch (this.dataset.tool) {
				case 'expand':   card.classList.add('hmibDashCardExpanded'); hmibSumSaveLayout(grid); break;
				case 'collapse': card.classList.remove('hmibDashCardExpanded'); hmibSumSaveLayout(grid); break;
				case 'maximize': hmibSumMaximize(card); break;
				case 'refresh':  hmibSumRefreshCard(grid, card); break;
				case 'remove':   hmibSumRemoveCard(grid, card); break;
			}
		});

		$(grid).off('click.hmibsumsort').on('click.hmibsumsort', 'th.hmibDashSortable', function() {
			hmibSumSortTable(this);
		});
		$(grid).off('keydown.hmibsumsort').on('keydown.hmibsumsort', 'th.hmibDashSortable', function(event) {
			if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
				event.preventDefault();
				hmibSumSortTable(this);
			}
		});

		var add = document.getElementById('hmib_summary_add');
		if (add) {
			$(add).off('change.hmibsum').on('change.hmibsum', function() {
				var key = this.value;
				this.value = '';
				if (key) {
					hmibSumAddCard(grid, key);
				}
			});
		}

		var type = document.getElementById('hmib_summary_type');
		if (type) {
			$(type).off('change.hmibsum').on('change.hmibsum', function() {
				window.location = 'hmib.php?action=summary_dashboard&ostype=' + encodeURIComponent(this.value);
			});
		}

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

/** Wire a single card's drag handle (mouse + keyboard) and drag events. */
function hmibSumBindCard(grid, card) {
	hmibSumPrepSortHeaders(card);

	var handle = card.querySelector('.hmibDashCardDrag');
	if (!handle) {
		return;
	}

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
		hmibSumSaveLayout(grid);
	});

	card.addEventListener('dragstart', function(event) {
		hmibSumDragging = card;
		card.classList.add('hmibDashCardDragging');
		event.dataTransfer.effectAllowed = 'move';
		try { event.dataTransfer.setData('text/plain', card.dataset.card || ''); } catch (error) { /* IE guard */ }
	});

	card.addEventListener('dragend', function() {
		card.draggable = false;
		card.classList.remove('hmibDashCardDragging');
		if (hmibSumDragging) {
			hmibSumDragging = null;
			hmibSumSaveLayout(grid);
		}
	});
}

/** The card the dragged card should be inserted before for the current pointer
 *  position, or null to append at the end. */
function hmibSumDragReference(grid, x, y) {
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
function hmibSumAddCard(grid, key) {
	if (hmibSumHasCard(grid, key)) {
		return;
	}
	$.post('hmib.php', {action: 'summary_card', card: key, ostype: grid.dataset.ostype, __csrf_magic: csrfMagicToken}, null, 'json').done(function(data) {
		if (!data || !data.html || hmibSumHasCard(grid, key)) {
			return;
		}
		var card = hmibSumParseCard(data.html);
		if (!card) {
			return;
		}
		grid.appendChild(card);
		hmibSumBindCard(grid, card);
		hmibSumSaveLayout(grid);
		hmibSumSyncAddOptions(grid);
	});
}

/** Whether a card with the given key is currently on the grid. */
function hmibSumHasCard(grid, key) {
	var present = false;
	grid.querySelectorAll('.hmibDashCard').forEach(function(card) {
		if (card.dataset.card === key) {
			present = true;
		}
	});
	return present;
}

/** Remove a card from the page and return it to the "Add" catalogue. */
function hmibSumRemoveCard(grid, card) {
	card.parentNode.removeChild(card);
	hmibSumSaveLayout(grid);
	hmibSumSyncAddOptions(grid);
}

/** Re-fetch a single card's HTML and swap it in place, keeping expanded state. */
function hmibSumRefreshCard(grid, card, done) {
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
	$.post('hmib.php', {action: 'summary_card', card: card.dataset.card, ostype: grid.dataset.ostype, expanded: expanded, __csrf_magic: csrfMagicToken}, null, 'json').done(function(data) {
		if (!data || !data.html || !card.parentNode) {
			settle();
			return;
		}
		var fresh = hmibSumParseCard(data.html);
		if (!fresh) {
			settle();
			return;
		}
		// Use the card's CURRENT expanded state (the user may have toggled it while
		// the request was in flight) rather than the snapshot sent with the request.
		if (card.classList.contains('hmibDashCardExpanded')) {
			fresh.classList.add('hmibDashCardExpanded');
		} else {
			fresh.classList.remove('hmibDashCardExpanded');
		}
		card.parentNode.replaceChild(fresh, card);
		hmibSumBindCard(grid, fresh);
		if (done) {
			done();
		}
	}).fail(settle);
}

/** Refresh every card on the page, spinning the toolbar glyph until all land. */
function hmibSumRefreshAll(grid, button) {
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
		hmibSumRefreshCard(grid, card, function() {
			pending--;
			if (pending <= 0 && icon) {
				icon.classList.remove('fa-spin');
			}
		});
	});
}

/** Open a copy of a card's body in a large modal dialog. */
function hmibSumMaximize(card) {
	var dialog = document.getElementById('hmib_summary_dialog');
	if (!dialog) {
		return;
	}
	var title = card.querySelector('.hmibDashCardTitle');
	var body  = card.querySelector('.hmibDashCardBody');
	dialog.innerHTML = '<div class="hmibDashDialogBody">' + (body ? body.innerHTML : '') + '</div>';

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

/** Make a container's sortable headers focusable and screen-reader friendly. */
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

/** Parse a card HTML string into its <section> element. */
function hmibSumParseCard(html) {
	var tmp = document.createElement('div');
	tmp.innerHTML = html;
	return tmp.querySelector('.hmibDashCard');
}

/** Rebuild the "Add" dropdown so it lists only cards not currently on the page. */
function hmibSumSyncAddOptions(grid) {
	var add = document.getElementById('hmib_summary_add');
	if (!add || typeof hmibSummaryCatalog === 'undefined') {
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
	Object.keys(hmibSummaryCatalog).forEach(function(key) {
		if (!present[key]) {
			var option = document.createElement('option');
			option.value = key;
			option.textContent = hmibSummaryCatalog[key];
			add.appendChild(option);
		}
	});
}

/** Sort a card table by the clicked column header, toggling asc/desc. Numeric
 *  columns (data-sort="num") sort by the cell's data-sort-value when present. */
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

/** Serialize layout POSTs so rapid actions can't persist out of order. */
var hmibSumSaveInFlight = false;
var hmibSumSaveQueued = false;

function hmibSumSaveLayout(grid) {
	if (hmibSumSaveInFlight) {
		hmibSumSaveQueued = true;
		return;
	}
	hmibSumSaveInFlight = true;

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
		action: 'summary_layout',
		layout: JSON.stringify({order: order, expanded: expanded}),
		__csrf_magic: csrfMagicToken
	}, null, 'json').always(function() {
		hmibSumSaveInFlight = false;
		if (hmibSumSaveQueued) {
			hmibSumSaveQueued = false;
			hmibSumSaveLayout(grid);
		}
	});
}
