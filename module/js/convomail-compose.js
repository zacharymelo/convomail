/* Copyright (C) 2026 DPG Supply - convomail compose
 * Email templates (fill subject and body above the signature/quote) and project contact chips (add to To / Cc).
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

	document.addEventListener('DOMContentLoaded', function () {
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
