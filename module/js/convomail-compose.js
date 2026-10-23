/* Copyright (C) 2026 DPG Supply - convomail compose
 * Email templates (fill subject and body above the signature/quote), project contact chips (add to To / Cc),
 * and address suggestions while typing in To / Cc / Bcc (jQuery UI autocomplete, as elsewhere in Dolibarr).
 */
(function () {
	'use strict';

	function field(name) { return document.querySelector('[name="' + name + '"]'); }

	function addAddress(name, addr) {
		var input = field(name);
		if (!input) {
			return;
		}
		var current = input.value.trim();
		var bare = (addr.match(/<([^>]+)>/) || [null, addr])[1].toLowerCase();
		if (current.toLowerCase().indexOf(bare) !== -1) {
			return;
		}
		input.value = current ? current.replace(/,\s*$/, '') + ', ' + addr : addr;
	}

	function bindChips(box) {
		box.addEventListener('click', function (ev) {
			var chip = ev.target.closest('.cm-chip');
			if (!chip) {
				return;
			}
			ev.preventDefault();
			addAddress(ev.target.closest('[data-cc]') ? 'sendtocc' : 'sendto', chip.getAttribute('data-addr'));
		});
	}

	function renderChips(box, list) {
		box.textContent = '';
		list.forEach(function (c) {
			var addr = c.name ? c.name + ' <' + c.email + '>' : c.email;
			var chip = document.createElement('span');
			chip.className = 'cm-chip';
			chip.setAttribute('data-addr', addr);
			chip.title = c.role || c.email;
			chip.appendChild(document.createTextNode(c.name || c.email));
			var cc = document.createElement('a');
			cc.href = '#';
			cc.setAttribute('data-cc', '1');
			cc.textContent = box.getAttribute('data-cc-label');
			chip.appendChild(cc);
			box.appendChild(chip);
		});
	}

	function editorData() {
		if (window.CKEDITOR && CKEDITOR.instances.message) {
			return CKEDITOR.instances.message.getData();
		}
		var ta = field('message');
		return ta ? ta.value : '';
	}

	function setEditorData(html) {
		if (window.CKEDITOR && CKEDITOR.instances.message) {
			CKEDITOR.instances.message.setData(html);
		} else if (field('message')) {
			field('message').value = html;
		}
	}

	// The part of an address list being typed: after the last comma that is not inside quotes
	function lastTerm(value) {
		var inquote = false, start = 0;
		for (var i = 0; i < value.length; i++) {
			if (value[i] === '"') { inquote = !inquote; }
			if (value[i] === ',' && !inquote) { start = i + 1; }
		}
		return { head: value.slice(0, start), term: value.slice(start).replace(/^\s+/, '') };
	}

	function bindAddressAutocomplete(input) {
		var $ = window.jQuery;
		if (!$ || !$.fn.autocomplete) {
			return;
		}
		var url = input.getAttribute('data-cm-addresses');
		var kinds = {};
		try { kinds = JSON.parse(input.getAttribute('data-cm-kinds') || '{}'); } catch (e) { kinds = {}; }
		$(input).on('keydown', function (ev) {
			// Tab picks the highlighted suggestion instead of leaving the field
			var inst = $(this).autocomplete('instance');
			if (ev.keyCode === $.ui.keyCode.TAB && inst && inst.menu.active) {
				ev.preventDefault();
			}
		}).autocomplete({
			minLength: 2,
			delay: 200,
			source: function (request, response) {
				var term = lastTerm(request.term).term;
				if (term.length < 2) {
					response([]);
					return;
				}
				$.getJSON(url, { term: term }).done(function (data) { response(Array.isArray(data) ? data : []); }).fail(function () { response([]); });
			},
			focus: function () { return false; },
			select: function (ev, ui) {
				// Replace what was typed with the chosen address, ready for the next one
				var head = lastTerm(this.value).head.replace(/\s*$/, '');
				this.value = head + (head ? ' ' : '') + ui.item.value + ', ';
				return false;
			}
		}).autocomplete('instance')._renderItem = function (ul, item) {
			// Built from text nodes: names and addresses come from the database
			var row = $('<div class="cm-ac-item">');
			row.append($('<span class="cm-ac-name">').text(item.name || item.email));
			if (item.name) {
				row.append($('<span class="cm-ac-email">').text(item.email));
			}
			var extra = [kinds[item.kind] || '', item.detail || ''].filter(Boolean).join(' · ');
			if (extra) {
				row.append($('<span class="cm-ac-kind">').text(extra));
			}
			return $('<li>').append(row).appendTo(ul);
		};
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('input[data-cm-addresses]').forEach(bindAddressAutocomplete);

		var chips = document.getElementById('cm-projectcontacts');
		if (chips) {
			bindChips(chips);
			var reload = function (projectid) {
				if (!projectid || projectid === '-1' || projectid === '0') {
					chips.textContent = '';
					return;
				}
				fetch(chips.getAttribute('data-url') + '?id=' + encodeURIComponent(projectid), { credentials: 'same-origin' })
					.then(function (r) { return r.ok ? r.json() : []; })
					.then(function (list) { renderChips(chips, list || []); });
			};
			// The project selector may be a select2: listen through jQuery when present
			if (window.jQuery) {
				jQuery(document).on('change', '#projectid', function () { reload(this.value); });
			} else if (document.getElementById('projectid')) {
				document.getElementById('projectid').addEventListener('change', function () { reload(this.value); });
			}
		}

		// Files picked from Dolibarr with the Document Browser module (optional): chips holding dbfile[] keys
		var dbbtn = document.getElementById('cm-docbrowser');
		var dbbox = document.getElementById('cm-dbfiles');
		if (dbbtn && dbbox && window.DocBrowser) {
			var picked = [];
			var renderPicked = function () {
				dbbox.textContent = '';
				var total = 0;
				picked.forEach(function (f, i) {
					total += f.size || 0;
					var chip = document.createElement('span');
					chip.className = 'cm-chip cm-dbchip';
					chip.title = f.record || f.name;
					var icon = document.createElement('span');
					icon.className = 'fa fa-' + (f.icon || 'file') + ' pictofixedwidth';
					chip.appendChild(icon);
					chip.appendChild(document.createTextNode(f.name + ' (' + f.sizetext + ')'));
					var input = document.createElement('input');
					input.type = 'hidden';
					input.name = 'dbfile[]';
					input.value = f.key;
					chip.appendChild(input);
					var x = document.createElement('a');
					x.href = '#';
					x.textContent = '×';
					x.title = dbbox.getAttribute('data-remove');
					x.addEventListener('click', function (ev) {
						ev.preventDefault();
						picked.splice(i, 1);
						renderPicked();
					});
					chip.appendChild(x);
					dbbox.appendChild(chip);
				});
				if (window.DocBrowser.maxBytes > 0 && total > window.DocBrowser.maxBytes) {
					var note = document.createElement('div');
					note.className = 'opacitymedium';
					note.textContent = dbbox.getAttribute('data-overlimit');
					dbbox.appendChild(note);
				}
			};
			try { picked = JSON.parse(dbbox.getAttribute('data-files') || '[]') || []; } catch (e) { picked = []; }
			renderPicked();
			dbbtn.addEventListener('click', function () {
				var project = document.getElementById('projectid');
				window.DocBrowser.open({
					// Read when the dialog opens, so a third party or project chosen on the form is used
					context: function () {
						return {
							socid: (field('socid') || {}).value || '',
							contactid: (field('contactid') || {}).value || '',
							projectid: project ? project.value : '',
							origin: (field('origin') || {}).value || '',
							originid: (field('originid') || {}).value || ''
						};
					},
					onPick: function (files) {
						files.forEach(function (f) {
							if (!picked.some(function (p) { return p.key === f.key; })) {
								picked.push(f);
							}
						});
						renderPicked();
					}
				});
			});
		}

		var tpl = document.getElementById('cm-template');
		if (tpl) {
			tpl.addEventListener('change', function () {
				if (!tpl.value) {
					return;
				}
				var project = document.getElementById('projectid');
				var params = '?id=' + encodeURIComponent(tpl.value)
					+ '&socid=' + encodeURIComponent((field('socid') || {}).value || '')
					+ '&contactid=' + encodeURIComponent((field('contactid') || {}).value || '')
					+ '&projectid=' + encodeURIComponent(project ? project.value : '');
				fetch(tpl.getAttribute('data-url') + params, { credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (data) {
						if (!data || !data.ok) {
							return;
						}
						if (data.subject && field('subject')) {
							field('subject').value = data.subject;
						}
						// Put the template above what is there (signature, quoted message), dropping a leading empty line.
						// Searching for the signature markup is unreliable: the editor rewrites it.
						var current = editorData().replace(/^\s*<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>\s*/i, '');
						setEditorData(data.body + current);
						tpl.value = '';
					});
			});
		}
	});
})();
