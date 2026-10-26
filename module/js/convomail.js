/* Copyright (C) 2026 DPG Supply - convomail
 *
 * Mailbox behaviour: open a message in the reading pane, actions (read, flag, move, archive, delete,
 * log to project), the "From this email" panel, selection and keyboard shortcuts. Also sizes the sandboxed message iframe; the iframe
 * itself never runs scripts.
 */
(function () {
	'use strict';

	function qs(sel, root) { return (root || document).querySelector(sel); }
	function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

	function fit(frame) {
		try {
			var doc = frame.contentDocument;
			if (doc && doc.body) {
				frame.style.height = (Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight) + 24) + 'px';
			}
		} catch (e) {
			frame.style.height = '600px';
		}
	}

	/**
	 * Compose URL for a mailto: link (to, cc, bcc, subject, body), or '' if it names nobody
	 */
	function composeFromMailto(href, base) {
		var m = /^mailto:([^?]*)(?:\?(.*))?$/i.exec(href.trim());
		if (!m) {
			return '';
		}
		var dec = function (s) {
			try { return decodeURIComponent(s.replace(/\+/g, '%2B')); } catch (e) { return s; }
		};
		var fields = { to: [dec(m[1])], cc: [], bcc: [], subject: '', body: '' };
		(m[2] || '').split('&').forEach(function (pair) {
			var eq = pair.indexOf('=');
			var key = (eq < 0 ? pair : pair.slice(0, eq)).toLowerCase();
			var value = eq < 0 ? '' : dec(pair.slice(eq + 1));
			if (key === 'to' || key === 'cc' || key === 'bcc') {
				fields[key].push(value);
			} else if (key === 'subject' || key === 'body') {
				fields[key] = value;
			}
		});
		var list = function (a) { return a.join(',').split(',').map(function (s) { return s.trim(); }).filter(Boolean).join(', '); };
		if (!list(fields.to) && !list(fields.cc) && !list(fields.bcc)) {
			return '';
		}
		var params = { sendto: list(fields.to), sendtocc: list(fields.cc), sendtobcc: list(fields.bcc), subject: fields.subject };
		if (fields.body) {
			var esc = document.createElement('div');
			esc.textContent = fields.body;
			params.message = '<p>' + esc.innerHTML.replace(/\r?\n/g, '<br>') + '</p>';
		}
		var url = base;
		Object.keys(params).forEach(function (k) {
			if (params[k]) {
				url += '&' + k + '=' + encodeURIComponent(params[k]);
			}
		});
		return url;
	}

	function bindFrames(root) {
		qsa('iframe.convomail-body', root).forEach(function (frame) {
			var onload = function () {
				fit(frame);
				// mailto: links open Convomail's compose page; with Shift (or another modifier) the computer's mail app
				var doc = frame.contentDocument;
				if (doc && !doc.cmMailto && frame.getAttribute('data-compose')) {
					doc.cmMailto = true;
					doc.addEventListener('click', function (ev) {
						var a = ev.target.closest ? ev.target.closest('a[href]') : null;
						if (!a || ev.shiftKey || ev.ctrlKey || ev.metaKey || ev.altKey || ev.button !== 0) {
							return;
						}
						var url = /^\s*mailto:/i.test(a.getAttribute('href')) ? composeFromMailto(a.getAttribute('href'), frame.getAttribute('data-compose')) : '';
						if (url) {
							ev.preventDefault();
							window.location.href = url;
						}
					});
				}
				var imgs = frame.contentDocument ? frame.contentDocument.images : [];
				for (var j = 0; j < imgs.length; j++) {
					imgs[j].addEventListener('load', function () { fit(frame); });
				}
			};
			frame.addEventListener('load', onload);
			if (frame.contentDocument && frame.contentDocument.readyState === 'complete') {
				onload();
			}
		});
	}

	function notify(text, ok) {
		if (!text) {
			return;
		}
		// Plain text: older Dolibarr (20) shows the notice's HTML as tags. Parsed apart from the page (nothing loads or runs)
		text = new DOMParser().parseFromString(String(text).replace(/<br\s*\/?>/gi, ' '), 'text/html').body.textContent.trim();
		if (window.jQuery && jQuery.jnotify) {
			jQuery.jnotify(text, ok ? 'ok' : 'error', ok ? 3000 : true);
		} else if (!ok) {
			window.alert(text);
		}
	}

	/**
	 * Conversation under a message (ajax/thread.php): loaded after the message, later messages opened
	 */
	function loadThread(root) {
		var slot = qs('.cm-conv-slot', root);
		if (!slot) {
			return;
		}
		fetch(slot.getAttribute('data-url'), { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.text() : ''; })
			.then(function (html) {
				if (!slot.isConnected) {
					return;	// another message was opened meanwhile
				}
				slot.innerHTML = html;
				slot.parentNode.classList.toggle('cm-has-conv', html.trim() !== '');
				qsa('.cm-conv-item.cm-autoopen', slot).forEach(function (item) { openThreadItem(item, false); });
			})
			.catch(function () { /* the conversation is optional */ });
	}

	function openThreadItem(item, showimages) {
		var body = qs('.cm-conv-body', item);
		if (!body) {
			return;
		}
		if (item.classList.contains('open') && !showimages) {
			item.classList.remove('open');
			return;
		}
		item.classList.add('open');
		if (body.getAttribute('data-loaded') && !showimages) {
			return;
		}
		item.classList.add('loading');
		fetch(item.getAttribute('data-body') + (showimages ? '&showimages=1' : ''), { credentials: 'same-origin' })
			.then(function (r) { return r.text(); })
			.then(function (html) {
				item.classList.remove('loading');
				body.innerHTML = html;
				body.setAttribute('data-loaded', '1');
				bindFrames(body);
			});
	}

	document.addEventListener('click', function (ev) {
		var images = ev.target.closest('[data-cm-conv-images]');
		if (images) {
			ev.preventDefault();
			openThreadItem(images.closest('.cm-conv-item'), true);
			return;
		}
		var head = ev.target.closest('.cm-conv-item[data-body] .cm-conv-head');
		if (head && !ev.target.closest('a')) {
			openThreadItem(head.parentNode, false);
		}
	});

	/**
	 * "From this email" panel (ajax/record.php): opens under the buttons of the message, in the reading pane and
	 * in the full-page view. Native inputs only; the third party and record pickers search ajax/records.php.
	 */
	function recordLine(el) {
		var view = el.closest('.cm-view');
		return view ? qs('.cm-recordline', view) : null;
	}

	function closeRecordPanel(view) {
		var slot = qs('.cm-recordpanel', view);
		if (slot) {
			slot.innerHTML = '';
			slot.removeAttribute('data-type');
		}
		qsa('.cm-recordline .cm-active', view).forEach(function (b) { b.classList.remove('cm-active'); });
	}

	function closeCreateMenus(except) {
		qsa('.cm-createmenu .cm-menu:not([hidden])').forEach(function (menu) {
			if (menu !== except) {
				menu.hidden = true;
				qs('[data-cm-createmenu]', menu.parentNode).setAttribute('aria-expanded', 'false');
			}
		});
	}

	/**
	 * Open the panel of a type ('file' or a record kind) under the buttons; extra: query string to add (kind switch).
	 * busy: the button that asked for it (Fill from the invoice, Read with AI): the panel stays on screen, locked, with
	 * a status line under that button until the new one arrives, and comes back as it was if that fails.
	 */
	function openRecordPanel(view, type, extra, busy) {
		var line = qs('.cm-recordline', view);
		var slot = qs('.cm-recordpanel', view);
		var status = busy ? startBusy(busy) : null;
		if (!status) {
			closeRecordPanel(view);
		}
		// A button of the line (File, Link) or the Create menu, for a kind picked in it
		var opener = qs(':scope > [data-cm-record="' + type + '"]', line) || qs('[data-cm-createmenu]', line);
		if (opener) {
			opener.classList.add('cm-active');
		}
		slot.setAttribute('data-type', type);
		if (!status) {
			slot.classList.add('loading');
		}
		var url = line.getAttribute('data-url') + '&action=panel&type=' + encodeURIComponent(type)
			+ '&token=' + encodeURIComponent(line.getAttribute('data-token')) + (extra || '');
		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.ok ? r.text() : Promise.reject(new Error(r.status)); })
			.then(function (html) {
				slot.classList.remove('loading');
				if (status) {
					status.stop();
				}
				if (slot.getAttribute('data-type') !== type || !slot.isConnected) {
					return;	// closed, another panel or another message opened meanwhile
				}
				slot.innerHTML = html;
				var form = qs('form.cm-rp', slot);
				var submit = qs('.cm-rp-submit', form);
				if (submit && submit.getAttribute('data-warned')) {
					form.elements.acceptwarnings.value = '1';	// warnings shown from the start: the button says "anyway"
				}
				var first = qsa('.cm-rp-label.fieldrequired', form).map(function (l) {
					return qs('.cm-rp-value input:not([type="hidden"])', l.closest('.cm-rp-row'));
				}).filter(function (i) { return i && !i.value; })[0];
				(first || qs('input:not([type="hidden"]), button', form)).focus({ preventScroll: true });
				slot.scrollIntoView({ block: 'nearest' });
			})
			.catch(function () {
				slot.classList.remove('loading');
				if (status) {
					status.fail();	// the panel as it was, with the error under the button
					return;
				}
				closeRecordPanel(view);
				notify('Error', false);
			});
	}

	/**
	 * Lock a panel while the server works for one of its buttons: the form dimmed and not clickable, the button
	 * disabled with a spinner, a status line under it (data-cm-busy) with the seconds elapsed after a few.
	 * Returns {stop, fail}: fail() unlocks the form and says it did not work (data-cm-busy-failed).
	 */
	function startBusy(button) {
		var form = button.closest('form.cm-rp');
		if (!form) {
			return null;
		}
		qsa('.cm-rp-busy', form).forEach(function (old) { old.remove(); });	// a failure line of an earlier try
		// Its own block of the form (not dimmed), just above the part holding the button
		var block = button;
		while (block.parentNode && block.parentNode !== form) {
			block = block.parentNode;
		}
		var line = document.createElement('div');
		line.className = 'cm-rp-busy';
		line.setAttribute('role', 'status');
		line.setAttribute('aria-live', 'polite');
		var spin = document.createElement('span');
		spin.className = 'cm-spinner';
		spin.setAttribute('aria-hidden', 'true');
		var text = document.createElement('span');
		var label = button.getAttribute('data-cm-busy') || '…';
		text.textContent = label;
		line.appendChild(spin);
		line.appendChild(text);
		form.insertBefore(line, block.parentNode === form ? block : null);
		line.scrollIntoView({ block: 'nearest' });
		form.classList.add('loading', 'cm-rp-working');
		form.setAttribute('aria-busy', 'true');
		button.disabled = true;
		button.classList.add('cm-btn-working');
		var started = Date.now();
		var timer = setInterval(function () {
			var secs = Math.round((Date.now() - started) / 1000);
			if (secs >= 3) {
				text.textContent = label + ' ' + secs + ' s';
			}
		}, 1000);
		function unlock() {
			clearInterval(timer);
			form.classList.remove('loading', 'cm-rp-working');
			form.removeAttribute('aria-busy');
			button.disabled = false;
			button.classList.remove('cm-btn-working');
		}
		return {
			stop: function () {
				unlock();
				line.remove();
			},
			fail: function () {
				unlock();
				spin.remove();
				line.classList.add('cm-rp-busy-failed');
				text.textContent = button.getAttribute('data-cm-busy-failed') || 'Error';
			}
		};
	}

	function recordMessages(form, res) {
		var box = qs('.cm-rp-msgs', form);
		var html = '';
		(res.errors || (res.error ? [res.error] : [])).forEach(function (e) { html += '<div class="error">' + e + '</div>'; });
		(res.warnings || []).forEach(function (w) { html += '<div class="warning">' + w + '</div>'; });
		box.innerHTML = html;
		var submit = qs('.cm-rp-submit', form);
		var warned = !!(res.warnings && res.warnings.length && !(res.errors && res.errors.length));
		form.elements.acceptwarnings.value = warned ? '1' : '0';
		if (submit) {
			submit.textContent = submit.getAttribute(warned ? 'data-label-anyway' : 'data-label');
		}
		box.scrollIntoView({ block: 'nearest' });
	}

	function submitRecordPanel(form) {
		var view = form.closest('.cm-view');
		var line = recordLine(form);
		var submit = qs('.cm-rp-submit', form);
		if (!submit || submit.disabled) {
			return;
		}
		var fd = new FormData(form);
		fd.append('token', line.getAttribute('data-token'));
		submit.disabled = true;
		form.classList.add('loading');
		fetch(line.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				form.classList.remove('loading');
				if (res.ok && res.url) {
					window.location.href = res.url;	// the new record's card, to finish it there
					return;
				}
				submit.disabled = false;
				if (!res.ok) {
					recordMessages(form, res);
					return;
				}
				notify(res.message + ((res.notes || []).length ? '<br>' + res.notes.join('<br>') : ''), true);
				closeRecordPanel(view);
				view.dispatchEvent(new CustomEvent('convomail:reload', { bubbles: true }));
			})
			.catch(function () {
				form.classList.remove('loading');
				submit.disabled = false;
				notify('Error', false);
			});
	}

	function pickerResults(picker) { return qs('.cm-picker-results', picker); }

	function pickerSearch(input) {
		var picker = input.closest('.cm-picker');
		var line = recordLine(input);
		var box = pickerResults(picker);
		var q = input.value.trim();
		if (q.length < 2) {
			box.innerHTML = '';
			return;
		}
		var url = line.getAttribute('data-search') + '?token=' + encodeURIComponent(line.getAttribute('data-token'))
			+ '&q=' + encodeURIComponent(q) + '&types=' + encodeURIComponent(picker.getAttribute('data-types'))
			+ '&role=' + encodeURIComponent(picker.getAttribute('data-role')) + '&write=' + picker.getAttribute('data-write');
		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (input.value.trim() !== q) {
					return;	// typed on meanwhile
				}
				box.innerHTML = '';
				(res.results || []).forEach(function (r, i) {
					var item = document.createElement('button');
					item.type = 'button';
					item.className = 'cm-picker-item' + (i === 0 ? ' active' : '');
					item.setAttribute('role', 'option');
					item.setAttribute('data-id', r.id);
					item.setAttribute('data-type', r.type);
					item.setAttribute('data-name', r.name);
					item.setAttribute('data-url', r.url || '');
					item.setAttribute('data-html', r.html || '');	// Dolibarr's own link to it (core HTML)
					item.textContent = r.label;
					box.appendChild(item);
				});
				if (!box.children.length) {
					var none = document.createElement('span');
					none.className = 'cm-picker-none opacitymedium';
					none.textContent = '…';
					box.appendChild(none);
				}
			});
	}

	function pickerChoose(item) {
		var picker = item.closest('.cm-picker');
		qs('[data-cm-pick="id"]', picker).value = item.getAttribute('data-id');
		var type = qs('[data-cm-pick="type"]', picker);
		if (type) {
			type.value = item.getAttribute('data-type');
		}
		qs('.cm-picker-input', picker).value = item.getAttribute('data-name');
		var open = qs('.cm-picker-open', picker);
		if (open) {
			open.href = item.getAttribute('data-url');
			open.hidden = !item.getAttribute('data-url');
		}
		pickerResults(picker).innerHTML = '';
		showChosen(picker, item.getAttribute('data-html'));
		checkTarget(picker);
	}

	/**
	 * A picker with a record chosen shows Dolibarr's own link to it (as on a record's card) and a button to pick
	 * another, instead of the search field; without a link (older answer) the field keeps the name
	 */
	function showChosen(picker, html) {
		var chosen = qs('.cm-picker-chosen', picker);
		if (!chosen || !html) {
			return;
		}
		chosen.innerHTML = '';
		var link = document.createElement('span');
		link.className = 'cm-picker-link';
		link.innerHTML = html;
		chosen.appendChild(link);
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'cm-picker-change';
		btn.setAttribute('data-cm-picker-change', '1');
		btn.title = chosen.getAttribute('data-change') || '';
		btn.innerHTML = '<span class="fas fa-pen"></span>';
		chosen.appendChild(btn);
		chosen.hidden = false;
		qs('.cm-picker-input', picker).hidden = true;
		var open = qs('.cm-picker-open', picker);
		if (open) {
			open.hidden = true;
		}
	}

	// "Pick another": the search field again, empty and focused; nothing is chosen until a result is
	function pickAnother(picker) {
		var chosen = qs('.cm-picker-chosen', picker);
		if (chosen) {
			chosen.hidden = true;
		}
		qsa('[data-cm-pick]', picker).forEach(function (h) { h.value = ''; });
		var input = qs('.cm-picker-input', picker);
		input.hidden = false;
		input.value = '';
		input.focus();
		var form = picker.closest('form.cm-rp');
		if (form && form.elements.acceptwarnings) {
			form.elements.acceptwarnings.value = '0';
		}
	}

	// File panel: using a picker or the Documents folder choice selects its radio
	function checkTarget(el) {
		var label = el.previousElementSibling;
		var radio = label && label.classList.contains('cm-rp-target') ? qs('input[type="radio"]', label) : null;
		if (radio) {
			radio.checked = true;
		}
	}

	document.addEventListener('click', function (ev) {
		var toggle = ev.target.closest('[data-cm-createmenu]');
		var menu = toggle ? qs('.cm-menu', toggle.parentNode) : null;
		closeCreateMenus(menu);
		if (toggle) {
			ev.preventDefault();
			menu.hidden = !menu.hidden;
			toggle.setAttribute('aria-expanded', menu.hidden ? 'false' : 'true');
			if (!menu.hidden) {
				qs('.cm-menu-item', menu).focus();
			}
			return;
		}
		var btn = ev.target.closest('[data-cm-record]');
		if (btn) {
			ev.preventDefault();
			var view = btn.closest('.cm-view');
			var slot = qs('.cm-recordpanel', view);
			if (btn.classList.contains('cm-active') || slot.getAttribute('data-type') === btn.getAttribute('data-cm-record')) {
				closeRecordPanel(view);	// the File button again, or the kind already open
			} else {
				openRecordPanel(view, btn.getAttribute('data-cm-record'));
			}
			return;
		}
		// Another kind of the same group: keep the third party and the customer's reference
		var kind = ev.target.closest('[data-cm-kind]');
		if (kind) {
			ev.preventDefault();
			var form = kind.closest('form.cm-rp');
			if (kind.classList.contains('active')) {
				return;
			}
			var carry = '&carry_socid=' + encodeURIComponent(form.elements.socid ? form.elements.socid.value : '')
				+ '&carry_ref=' + encodeURIComponent(form.elements.ref_client ? form.elements.ref_client.value : '');
			openRecordPanel(form.closest('.cm-view'), kind.getAttribute('data-cm-kind'), carry);
			return;
		}
		// Fill from the invoice (1) or Read with AI (2): the same panel again, read on the server; keeps the supplier and number typed so far
		var read = ev.target.closest('[data-cm-invoice-read]');
		if (read) {
			ev.preventDefault();
			var rform = read.closest('form.cm-rp');
			var rcarry = '&readinvoice=' + (read.getAttribute('data-cm-invoice-read') === '2' ? '2' : '1') + '&carry_socid=' + encodeURIComponent(rform.elements.socid ? rform.elements.socid.value : '')
				+ '&carry_ref=' + encodeURIComponent(rform.elements.ref_supplier ? rform.elements.ref_supplier.value : '');
			openRecordPanel(rform.closest('.cm-view'), rform.getAttribute('data-type'), rcarry, read);
			return;
		}
		if (ev.target.closest('[data-cm-rp-close]')) {
			ev.preventDefault();
			closeRecordPanel(ev.target.closest('.cm-view'));
			return;
		}
		var x = ev.target.closest('[data-cm-chip-remove]');
		if (x) {
			ev.preventDefault();
			x.closest('.cm-rp-row').remove();	// the project: none (set on the card)
			return;
		}
		var change = ev.target.closest('[data-cm-picker-change]');
		if (change) {
			ev.preventDefault();
			pickAnother(change.closest('.cm-picker'));
			return;
		}
		var item = ev.target.closest('.cm-picker-item');
		if (item) {
			ev.preventDefault();
			pickerChoose(item);
		}
	});
	document.addEventListener('submit', function (ev) {
		if (ev.target.matches && ev.target.matches('form.cm-rp')) {
			ev.preventDefault();
			submitRecordPanel(ev.target);
		}
	});
	document.addEventListener('input', function (ev) {
		var form = ev.target.closest && ev.target.closest('form.cm-rp');
		if (!form) {
			return;
		}
		// Values changed after a warning: check them again before "anyway"
		var submit = qs('.cm-rp-submit', form);
		if (form.elements.acceptwarnings.value === '1' && submit) {
			form.elements.acceptwarnings.value = '0';
			submit.textContent = submit.getAttribute('data-label');
		}
		if (ev.target.classList.contains('cm-picker-input')) {
			var picker = ev.target.closest('.cm-picker');
			qsa('[data-cm-pick]', picker).forEach(function (h) { h.value = ''; });	// typed: nothing chosen yet
			var open = qs('.cm-picker-open', picker);
			if (open) {
				open.hidden = true;
			}
			clearTimeout(ev.target.cmTimer);
			ev.target.cmTimer = setTimeout(function () { pickerSearch(ev.target); }, 250);
		}
	});
	document.addEventListener('focusin', function (ev) {
		var t = ev.target;
		if (t.closest && t.closest('.cm-rp-targets')) {
			if (t.classList.contains('cm-picker-input')) {
				checkTarget(t.closest('.cm-picker'));
			} else if (t.tagName === 'SELECT') {
				checkTarget(t);
			}
		}
	});
	document.addEventListener('focusout', function (ev) {
		if (ev.target.classList && ev.target.classList.contains('cm-picker-input')) {
			var box = pickerResults(ev.target.closest('.cm-picker'));
			setTimeout(function () {
				if (!box.contains(document.activeElement)) {
					box.innerHTML = '';
				}
			}, 200);
		}
	});
	document.addEventListener('keydown', function (ev) {
		var menu = ev.target.closest && ev.target.closest('.cm-createmenu');
		if (menu && !qs('.cm-menu', menu).hidden) {
			var items = qsa('.cm-menu-item', menu);
			var i = items.indexOf(document.activeElement);
			if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
				ev.preventDefault();
				items[(i + (ev.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus();
			} else if (ev.key === 'Escape') {
				ev.preventDefault();
				closeCreateMenus(null);
				qs('[data-cm-createmenu]', menu).focus();
			}
			return;
		}
		var form = ev.target.closest && ev.target.closest('form.cm-rp');
		if (!form) {
			return;
		}
		var picker = ev.target.classList.contains('cm-picker-input') ? ev.target.closest('.cm-picker') : null;
		var items = picker ? qsa('.cm-picker-item', picker) : [];
		if (items.length && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp')) {
			ev.preventDefault();
			var i = items.findIndex(function (it) { return it.classList.contains('active'); });
			i = Math.max(0, Math.min(items.length - 1, i + (ev.key === 'ArrowDown' ? 1 : -1)));
			items.forEach(function (it, n) { it.classList.toggle('active', n === i); });
		} else if (items.length && ev.key === 'Enter') {
			ev.preventDefault();
			pickerChoose(qs('.cm-picker-item.active', picker) || items[0]);
		} else if (ev.key === 'Escape') {
			ev.preventDefault();
			if (items.length) {
				pickerResults(picker).innerHTML = '';
			} else {
				closeRecordPanel(form.closest('.cm-view'));
			}
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		bindFrames(document);
		loadThread(document);

		var switcher = qs('form.convomail-switcher select');
		if (switcher) {
			switcher.addEventListener('change', function () { switcher.form.submit(); });
		}
		// Searching can take a while on large folders: say so, and search again when the scope changes
		var searchform = qs('form.cm-search');
		if (searchform) {
			searchform.addEventListener('submit', function () {
				var rows = qs('.cm-rows');
				if (rows && searchform.elements.search.value.trim() !== '') {
					rows.classList.add('cm-searching');
					rows.setAttribute('data-searching', searchform.getAttribute('data-searching'));
				}
			});
			var bodybox = searchform.querySelector('input[name=searchbody]');
			if (bodybox) {
				bodybox.addEventListener('change', function () {
					if (searchform.elements.search.value.trim() !== '') {
						searchform.requestSubmit ? searchform.requestSubmit() : searchform.submit();
					}
				});
			}
		}
		var pagesize = qs('form.cm-pagesize select');
		if (pagesize) {
			pagesize.addEventListener('change', function () { pagesize.form.submit(); });
		}
		var conversation = qs('form.cm-conversation-switch input[type="checkbox"]');
		if (conversation) {
			conversation.addEventListener('change', function () { conversation.form.submit(); });
		}

		var app = qs('.cm-app');
		if (!app) {
			// Full-page message view: actions go back to the list when done
			var view = qs('.cm-view[data-page="1"]');
			if (view) {
				bindPageView(view);
			}
			return;
		}

		var pane = qs('#cm-pane', app);
		bindLayout(app);
		var rowsBox = qs('.cm-rows', app);

		function rows() { return qsa('.cm-row', rowsBox); }
		function rowByUid(uid) { return qs('.cm-row[data-uid="' + uid + '"]', rowsBox); }
		function currentUid() {
			var v = qs('.cm-view', pane);
			return v ? parseInt(v.getAttribute('data-uid'), 10) : 0;
		}
		function selectedUids() {
			var list = qsa('.cm-check:checked', rowsBox).map(function (c) { return parseInt(c.value, 10); });
			if (!list.length && currentUid()) {
				list = [currentUid()];
			}
			return list;
		}

		function post(action, uids, extra) {
			var fd = new FormData();
			fd.append('token', app.getAttribute('data-token'));
			fd.append('accountid', app.getAttribute('data-accountid'));
			fd.append('folder', app.getAttribute('data-folder'));
			fd.append('action', action);
			uids.forEach(function (u) { fd.append('uids[]', u); });
			Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
			return fetch(app.getAttribute('data-ajax-action'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					if (!data.ok) {
						notify(data.error || 'Error', false);
					}
					return data;
				});
		}

		function decrementUnread() {
			var badge = qs('.cm-count[data-folder="' + CSS.escape(app.getAttribute('data-folder')) + '"]', app);
			if (badge) {
				var n = parseInt(badge.textContent, 10) - 1;
				if (n > 0) {
					badge.textContent = n;
				} else {
					badge.remove();
				}
			}
		}

		function setSelected(uid) {
			rows().forEach(function (r) { r.classList.toggle('selected', parseInt(r.getAttribute('data-uid'), 10) === uid); });
			var row = rowByUid(uid);
			if (row) {
				if (row.classList.contains('unread')) {
					row.classList.remove('unread');
					decrementUnread();
				}
				row.scrollIntoView({ block: 'nearest' });
			}
		}

		function emptyPane() {
			pane.innerHTML = '<div class="cm-empty cm-pane-empty"></div>';
		}

		function openMessage(uid, showimages) {
			var params = '?accountid=' + encodeURIComponent(app.getAttribute('data-accountid'))
				+ '&folder=' + encodeURIComponent(app.getAttribute('data-folder')) + '&uid=' + uid;
			if (window.matchMedia('(max-width: 767px)').matches) {
				window.location.href = app.getAttribute('data-page-message') + params;
				return;
			}
			pane.classList.add('loading');
			fetch(app.getAttribute('data-ajax-message') + params + (showimages ? '&showimages=1' : ''), { credentials: 'same-origin' })
				.then(function (r) { return r.text(); })
				.then(function (html) {
					pane.innerHTML = html;
					pane.classList.remove('loading');
					pane.scrollTop = 0;
					bindFrames(pane);
					loadThread(pane);
					setSelected(uid);
					history.replaceState(null, '', app.getAttribute('data-self') + '&uid=' + uid);
				});
		}

		function removeRows(uids) {
			var cur = currentUid();
			var next = null;
			uids.forEach(function (u) {
				var row = rowByUid(u);
				if (row) {
					if (u === cur) {
						var sib = row.nextElementSibling || row.previousElementSibling;
						next = sib && sib.classList.contains('cm-row') ? parseInt(sib.getAttribute('data-uid'), 10) : null;
					}
					row.remove();
				}
			});
			if (uids.indexOf(cur) !== -1) {
				if (next) {
					openMessage(next);
				} else {
					emptyPane();
				}
			}
		}

		// Reload the rows of the current page (after filing: project chips, logged state, thread replies filed
		// with it), keeping the open message selected, ticked rows and the scroll position
		function refreshRows() {
			return fetch(app.getAttribute('data-self'), { credentials: 'same-origin' })
				.then(function (r) { return r.ok ? r.text() : ''; })
				.then(function (html) {
					var fresh = html ? new DOMParser().parseFromString(html, 'text/html').querySelector('.cm-rows') : null;
					if (!fresh) {
						return;
					}
					var cur = currentUid();
					var ticked = qsa('.cm-check:checked', rowsBox).map(function (c) { return c.value; });
					var scroll = rowsBox.scrollTop;
					rowsBox.innerHTML = fresh.innerHTML;
					rows().forEach(function (r) {
						var uid = r.getAttribute('data-uid');
						r.classList.toggle('selected', parseInt(uid, 10) === cur);
						var box = qs('.cm-check', r);
						if (box && ticked.indexOf(uid) !== -1) {
							box.checked = true;
						}
					});
					rowsBox.scrollTop = scroll;
					// The "1–25 / 53" count and the page arrows (the page size form keeps its own element)
					var paging = qs('.cm-paging', app);
					var freshPaging = new DOMParser().parseFromString(html, 'text/html').querySelector('.cm-paging');
					if (paging && freshPaging) {
						Array.prototype.slice.call(paging.childNodes).forEach(function (n) {
							if (n.nodeType === 3 || (n.matches && n.matches('a'))) {
								paging.removeChild(n);
							}
						});
						Array.prototype.slice.call(freshPaging.childNodes).forEach(function (n) {
							if (n.nodeType === 3 || (n.matches && n.matches('a'))) {
								paging.appendChild(document.importNode(n, true));
							}
						});
					}
				})
				.catch(function () { /* the list stays as it was */ });
		}

		/*
		 * New-mail checks (ajax/poll.php): every data-poll-interval seconds while the tab is visible. New mail
		 * slides into the list when that disturbs nothing (first page, no search, nothing ticked or dragged);
		 * otherwise the notice bar offers to show it. Two failed checks are retried quietly with a longer
		 * wait; the third, or an error that won't fix itself, stops the checks and the bar offers Retry.
		 */
		(function () {
			var interval = (parseInt(app.getAttribute('data-poll-interval'), 10) || 0) * 1000;
			var url = app.getAttribute('data-poll-url');
			var notice = qs('.cm-notice', app);
			if (!interval || !url || !notice) {
				return;
			}
			var texts = {};
			try {
				texts = JSON.parse(app.getAttribute('data-poll-texts') || '{}');
			} catch (e) { /* the defaults below */ }
			var t = function (key, arg) { return String(texts[key] || key).replace('%s', arg === undefined ? '' : arg); };
			var folder = app.getAttribute('data-folder');
			var since = parseInt(app.getAttribute('data-maxuid'), 10);
			if (isNaN(since)) {
				since = -1;
			}
			var uidvalidity = '';
			var waiting = -1;	// newest UID behind the notice bar
			var failures = 0;
			var timer = null;
			var busy = false;
			var stopped = false;
			var lastCheck = Date.now();
			var baseTitle = document.title;

			function schedule(delay) {
				clearTimeout(timer);
				timer = null;
				if (!stopped && !document.hidden) {
					timer = setTimeout(check, delay);
				}
			}
			function quiet() {
				return app.getAttribute('data-page') === '0' && app.getAttribute('data-searching') === '0'
					&& !qs('.cm-check:checked', rowsBox) && !app.classList.contains('cm-dragging') && !app.classList.contains('cm-dragging-folder');
			}
			// Text and buttons only (no HTML from the server): [{label, run}]
			function showNotice(text, buttons, isError) {
				notice.innerHTML = '';
				notice.classList.toggle('cm-notice-error', !!isError);
				var span = document.createElement('span');
				span.className = 'cm-notice-text';
				span.textContent = text;
				notice.appendChild(span);
				(buttons || []).forEach(function (b) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'cm-notice-btn';
					btn.textContent = b.label;
					btn.addEventListener('click', b.run);
					notice.appendChild(btn);
				});
				var close = document.createElement('button');
				close.type = 'button';
				close.className = 'cm-notice-close';
				close.title = t('dismiss');
				close.textContent = '\u00d7';
				close.addEventListener('click', hideNotice);
				notice.appendChild(close);
				notice.hidden = false;
			}
			function hideNotice() {
				notice.hidden = true;
				notice.innerHTML = '';
			}
			function updateCounts(unseen) {
				Object.keys(unseen || {}).forEach(function (name) {
					var link = qs('.cm-folder[data-name="' + CSS.escape(name) + '"]', app);
					if (!link) {
						return;
					}
					var n = parseInt(unseen[name], 10) || 0;
					var badge = qs('.cm-count', link);
					if (n > 0 && !badge) {
						badge = document.createElement('span');
						badge.className = 'cm-count';
						badge.setAttribute('data-folder', name);
						link.appendChild(badge);
					}
					if (badge) {
						if (n > 0) {
							badge.textContent = n;
						} else {
							badge.remove();
						}
					}
					if (name === folder) {
						document.title = (n > 0 ? '(' + n + ') ' : '') + baseTitle;
					}
				});
			}
			function showNew() {
				if (app.getAttribute('data-page') === '0' && app.getAttribute('data-searching') === '0') {
					var before = since;
					since = Math.max(since, waiting);
					hideNotice();
					insertNew(before);
				} else {
					window.location.href = app.getAttribute('data-folder-url');
				}
			}
			function insertNew(before) {
				return refreshRows().then(function () {
					rows().forEach(function (r) {
						if (parseInt(r.getAttribute('data-uid'), 10) > before) {
							r.classList.add('cm-row-new');
						}
					});
				});
			}
			function retry() {
				stopped = false;
				failures = 0;
				hideNotice();
				check();
			}
			function fail(message, halt, session) {
				failures++;
				if (session) {
					stopped = true;
					showNotice(t('session'), [{ label: t('reload'), run: function () { window.location.reload(); } }], true);
				} else if (halt || failures >= 3) {
					stopped = true;
					showNotice(t('stopped', message), [{ label: t('retry'), run: retry }], true);
				} else {
					schedule(interval * Math.pow(2, failures));
				}
			}
			function check() {
				if (busy || stopped) {
					return;
				}
				busy = true;
				lastCheck = Date.now();
				var q = url + '?accountid=' + encodeURIComponent(app.getAttribute('data-accountid'))
					+ '&folder=' + encodeURIComponent(folder) + '&since=' + since;
				fetch(q, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
					.then(function (r) {
						var json = (r.headers.get('Content-Type') || '').indexOf('json') !== -1;
						// Dolibarr answers its login page when the session has ended
						return json ? r.json() : { ok: false, session: r.ok || r.status === 401 };
					})
					.then(function (data) {
						busy = false;
						if (!data.ok) {
							fail(data.error || 'Error', !!data.halt, !!data.session);
							return;
						}
						failures = 0;
						if (notice.classList.contains('cm-notice-error')) {
							hideNotice();
						}
						updateCounts(data.unseen);
						// A new UIDVALIDITY, or UIDs going down (only possible then): the folder was recreated on the server
						if ((uidvalidity !== '' && data.uidvalidity !== uidvalidity) || (since >= 0 && data.newest < since)) {
							stopped = true;
							showNotice(t('changed'), [{ label: t('reload'), run: function () { window.location.reload(); } }]);
							return;
						}
						uidvalidity = data.uidvalidity;
						if (since < 0) {
							since = data.newest;	// first check of a search page: the baseline
						} else if (data.new > 0) {
							if (quiet()) {
								var before = since;
								since = data.newest;
								hideNotice();
								insertNew(before);
							} else {
								waiting = data.newest;
								showNotice(data.new === 1 ? t('one') : t('many', data.new), [{ label: t('show'), run: showNew }]);
							}
						}
						schedule(interval);
					})
					.catch(function () {
						busy = false;
						fail(t('error', ''), false, false);
					});
			}

			document.addEventListener('visibilitychange', function () {
				if (document.hidden) {
					clearTimeout(timer);
					timer = null;
				} else if (!stopped) {
					// Back on the tab: check now if the last check is old, else when it is due
					schedule(Math.max(0, interval - (Date.now() - lastCheck)));
				}
			});
			// A search page has no baseline yet: take it soon
			schedule(since < 0 ? 2000 : interval);
		})();

		function run(action, uids, extra) {
			if (!uids.length) {
				return Promise.resolve(null);
			}
			if (action === 'delete' && uids.length > 1 && !window.confirm(app.getAttribute('data-confirm-delete'))) {
				return Promise.resolve(null);
			}
			return post(action, uids, extra).then(function (data) {
				if (!data.ok) {
					return;
				}
				if (data.removed && data.removed.length) {
					removeRows(data.removed);
				}
				uids.forEach(function (u) {
					var row = rowByUid(u);
					if (!row) {
						return;
					}
					if (action === 'read') { row.classList.remove('unread'); }
					if (action === 'unread') { row.classList.add('unread'); }
					if (action === 'flag') { row.classList.add('flagged'); }
					if (action === 'unflag') { row.classList.remove('flagged'); }
				});
				if (action === 'unread' && uids.indexOf(currentUid()) !== -1) {
					emptyPane();
				}
				if ((action === 'flag' || action === 'unflag' || action === 'log') && uids.indexOf(currentUid()) !== -1) {
					openMessage(currentUid());
				}
				if (action === 'log') {
					refreshRows();
				}
				qsa('.cm-check:checked', rowsBox).forEach(function (c) { c.checked = false; });
				notify(data.message, true);
				return data;
			});
		}

		/*
		 * Folders (ajax/folder.php): New folder, rename and delete an empty folder, "New folder..." in the Move
		 * menus, and dragging messages from the list onto a folder
		 */
		function folderCall(action, fields) {
			var fd = new FormData();
			fd.append('token', app.getAttribute('data-token'));
			fd.append('accountid', app.getAttribute('data-accountid'));
			fd.append('action', action);
			Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
			return fetch(app.getAttribute('data-ajax-folder'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) {
						throw new Error(res.error || 'Error');
					}
					return res;
				});
		}
		function folderPage(name) {
			return window.location.pathname + '?accountid=' + encodeURIComponent(app.getAttribute('data-accountid')) + '&folder=' + encodeURIComponent(name);
		}
		// A text field in place of an element (or under it, keep = true): Enter saves, Escape or leaving it cancels
		function askName(el, value, save, keep) {
			var input = document.createElement('input');
			input.type = 'text';
			input.className = 'cm-folder-input';
			input.maxLength = 100;
			input.value = value;
			input.placeholder = app.getAttribute('data-new-folder-prompt');
			if (keep) {
				input.style.marginLeft = 'calc(' + ((parseInt(el.getAttribute('data-depth') || '0', 10) + 1) * 14) + 'px)';
			}
			el.style.display = keep ? '' : 'none';
			el.parentNode.insertBefore(input, el.nextSibling);
			input.focus();
			input.select();
			var done = false;
			var close = function () {
				if (!done) {
					done = true;
					input.remove();
					el.style.display = '';
				}
			};
			input.addEventListener('keydown', function (ev) {
				if (ev.key === 'Escape') {
					close();
				} else if (ev.key === 'Enter') {
					ev.preventDefault();
					var v = input.value.trim();
					if (!v || v === value) {
						close();
						return;
					}
					input.disabled = true;
					save(v).catch(function (e) {
						notify(e.message, false);
						input.disabled = false;
						input.focus();
					});
				}
			});
			input.addEventListener('blur', function () { setTimeout(function () { if (!input.disabled) { close(); } }, 150); });
		}
		// Destination of a Move menu: the chosen folder, or a new one ("New folder..."); null when cancelled
		function pickFolder(sel) {
			if (sel.value !== '__new__') {
				return Promise.resolve(sel.value);
			}
			var name = window.prompt(app.getAttribute('data-new-folder-prompt'));
			sel.value = '';
			if (!name || !name.trim()) {
				return Promise.resolve(null);
			}
			return folderCall('create', { name: name.trim() }).then(function (res) {
				app.setAttribute('data-folders-changed', '1');
				return res.name;
			}, function (e) {
				notify(e.message, false);
				return null;
			});
		}
		function moveTo(sel, uids) {
			pickFolder(sel).then(function (dest) {
				sel.value = '';
				if (!dest) {
					return;
				}
				run('move', uids, { dest: dest }).then(function () {
					if (app.getAttribute('data-folders-changed')) {
						window.location.reload();	// show the new folder in the list
					}
				});
			});
		}

		var folderBox = qs('.cm-folders', app);
		if (app.getAttribute('data-ajax-folder')) {
			folderBox.addEventListener('click', function (ev) {
				var tool = ev.target.closest('[data-cm-folder]');
				if (!tool) {
					return;
				}
				ev.preventDefault();
				ev.stopPropagation();
				var what = tool.getAttribute('data-cm-folder');
				var link = tool.closest('.cm-folder');
				if (what === 'create') {
					askName(tool, '', function (name) {
						return folderCall('create', { name: name }).then(function () { window.location.reload(); });
					});
				} else if (what === 'subfolder') {
					askName(link, '', function (name) {
						return folderCall('create', { name: name, parent: link.getAttribute('data-name') }).then(function () {
							setCollapsed(link.getAttribute('data-name'), false);	// show the new subfolder
							window.location.reload();
						});
					}, true);
				} else if (what === 'rename') {
					askName(link, link.getAttribute('data-label'), function (name) {
						return folderCall('rename', { name: link.getAttribute('data-name'), newname: name }).then(function (res) {
							if (link.classList.contains('active')) {
								window.location.href = folderPage(res.name);	// the open folder has a new name
							} else {
								window.location.reload();
							}
						});
					});
				} else if (what === 'delete') {
					if (!window.confirm(app.getAttribute('data-confirm-delete-folder').replace('%s', link.getAttribute('data-label')))) {
						return;
					}
					folderCall('delete', { name: link.getAttribute('data-name') }).then(function () {
						if (link.classList.contains('active')) {
							window.location.href = folderPage('INBOX');
						} else {
							window.location.reload();
						}
					}, function (e) { notify(e.message, false); });
				}
			});
			folderBox.addEventListener('keydown', function (ev) {
				var tool = ev.target.closest('[data-cm-folder]');
				if (tool && (ev.key === 'Enter' || ev.key === ' ')) {
					ev.preventDefault();
					tool.click();
				}
			});
		}

		// Folder tree: collapse a folder's subfolders (kept per account in this browser). The open folder's
		// branch is always shown; a collapsed folder opens while messages are dragged over it.
		var collapseKey = 'convomail.collapsed.' + app.getAttribute('data-accountid');
		var collapsed = {};
		try {
			(JSON.parse(window.localStorage.getItem(collapseKey)) || []).forEach(function (n) { collapsed[n] = true; });
		} catch (e) {
			collapsed = {};
		}
		var folderLinks = qsa('.cm-folder', folderBox);
		var parentOf = {};
		folderLinks.forEach(function (a) { parentOf[a.getAttribute('data-name')] = a.getAttribute('data-parent') || ''; });
		function setCollapsed(name, on) {
			if (on) {
				collapsed[name] = true;
			} else {
				delete collapsed[name];
			}
			try {
				window.localStorage.setItem(collapseKey, JSON.stringify(Object.keys(collapsed)));
			} catch (e) { /* sizes last for this page only */ }
		}
		function applyTree() {
			folderLinks.forEach(function (a) {
				var hidden = false;
				for (var p = parentOf[a.getAttribute('data-name')]; p; p = parentOf[p]) {
					if (collapsed[p]) {
						hidden = true;
						break;
					}
				}
				a.classList.toggle('cm-folder-hidden', hidden);
				var toggle = qs('[data-cm-toggle]', a);
				if (toggle) {
					var closed = !!collapsed[a.getAttribute('data-name')];
					a.classList.toggle('cm-folder-collapsed', closed);
					toggle.setAttribute('aria-expanded', closed ? 'false' : 'true');
				}
			});
		}
		for (var open = parentOf[app.getAttribute('data-folder')]; open; open = parentOf[open]) {
			if (collapsed[open]) {
				setCollapsed(open, false);
			}
		}
		applyTree();
		folderBox.addEventListener('click', function (ev) {
			var toggle = ev.target.closest('[data-cm-toggle]');
			if (toggle) {
				ev.preventDefault();
				ev.stopPropagation();
				var name = toggle.closest('.cm-folder').getAttribute('data-name');
				setCollapsed(name, !collapsed[name]);
				applyTree();
			}
		}, true);
		folderBox.addEventListener('keydown', function (ev) {
			var toggle = ev.target.closest('[data-cm-toggle]');
			if (toggle && (ev.key === 'Enter' || ev.key === ' ')) {
				ev.preventDefault();
				toggle.click();
			}
		});
		var openTimer = null;

		// Moving a folder: drag one of the user's folders (data-movable) onto another folder, or onto the
		// top-level zone above the list; ajax/folder.php action=move renames it, subfolders included
		var movingFolder = null;
		var rootZone = qs('[data-cm-root]', folderBox);
		function within(name, ancestor) {	// name is ancestor or one of its subfolders
			for (var n = name; n; n = parentOf[n]) {
				if (n === ancestor) {
					return true;
				}
			}
			return false;
		}
		function folderTarget(ev) {	// where a dragged folder may go: a folder name, '' (top level) or null
			if (!movingFolder) {
				return null;
			}
			if (rootZone && ev.target.closest('[data-cm-root]')) {
				return parentOf[movingFolder] ? '' : null;
			}
			var f = ev.target.closest('.cm-folder');
			if (!f) {
				return null;
			}
			var name = f.getAttribute('data-name');
			return (within(name, movingFolder) || parentOf[movingFolder] === name) ? null : name;
		}
		folderBox.addEventListener('dragstart', function (ev) {
			var f = ev.target.closest ? ev.target.closest('.cm-folder[data-movable]') : null;
			if (!f) {
				return;
			}
			movingFolder = f.getAttribute('data-name');
			ev.dataTransfer.effectAllowed = 'move';
			ev.dataTransfer.setData('text/plain', f.getAttribute('data-label'));
			// After the drag has started: Chrome cancels a drag when the page changes during dragstart
			setTimeout(function () {
				if (movingFolder) {
					app.classList.add('cm-dragging-folder');
					f.classList.add('cm-dragged');
				}
			}, 0);
		});
		folderBox.addEventListener('dragend', function () {
			movingFolder = null;
			app.classList.remove('cm-dragging-folder');
			qsa('.cm-dragged, .cm-drop', folderBox).forEach(function (el) { el.classList.remove('cm-dragged', 'cm-drop'); });
		});
		folderBox.addEventListener('dragover', function (ev) {
			var dest = folderTarget(ev);
			if (dest === null) {
				return;
			}
			ev.preventDefault();
			ev.dataTransfer.dropEffect = 'move';
			var el = dest === '' ? rootZone : ev.target.closest('.cm-folder');
			qsa('.cm-drop', folderBox).forEach(function (o) { if (o !== el) { o.classList.remove('cm-drop'); } });
			if (dest !== '' && !el.classList.contains('cm-drop') && el.classList.contains('cm-folder-collapsed')) {
				clearTimeout(openTimer);
				openTimer = setTimeout(function () {
					if (el.classList.contains('cm-drop')) {
						setCollapsed(dest, false);
						applyTree();
					}
				}, 700);
			}
			el.classList.add('cm-drop');
		});
		folderBox.addEventListener('drop', function (ev) {
			var dest = folderTarget(ev);
			if (dest === null) {
				return;
			}
			ev.preventDefault();
			var name = movingFolder;
			var active = app.getAttribute('data-folder');
			folderCall('move', { name: name, parent: dest }).then(function (res) {
				if (dest) {
					setCollapsed(dest, false);	// show where it went
				}
				if (within(active, name)) {
					window.location.href = folderPage(res.name + active.slice(name.length));	// the open folder moved too
				} else {
					window.location.reload();
				}
			}, function (e) { notify(e.message, false); });
		});

		// Drag messages onto a folder: the ticked ones if the dragged row is ticked, else that row
		var dragging = null;
		function endDrag() {
			dragging = null;
			app.classList.remove('cm-dragging');
			qsa('.cm-dragged', rowsBox).forEach(function (r) { r.classList.remove('cm-dragged'); });
			qsa('.cm-drop', folderBox).forEach(function (f) { f.classList.remove('cm-drop'); });
		}
		rowsBox.addEventListener('dragstart', function (ev) {
			var row = ev.target.closest ? ev.target.closest('.cm-row') : null;
			if (!row) {
				return;
			}
			var check = qs('.cm-check', row);
			dragging = (check && check.checked) ? selectedUids() : [parseInt(row.getAttribute('data-uid'), 10)];
			ev.dataTransfer.effectAllowed = 'move';
			ev.dataTransfer.setData('text/plain', dragging.length + ' message' + (dragging.length > 1 ? 's' : ''));
			app.classList.add('cm-dragging');
			dragging.forEach(function (u) {
				var r = rowByUid(u);
				if (r) {
					r.classList.add('cm-dragged');
				}
			});
		});
		rowsBox.addEventListener('dragend', endDrag);
		folderBox.addEventListener('dragover', function (ev) {
			var f = ev.target.closest('.cm-folder');
			if (!dragging || !f || f.getAttribute('data-name') === app.getAttribute('data-folder')) {
				return;
			}
			ev.preventDefault();
			ev.dataTransfer.dropEffect = 'move';
			qsa('.cm-drop', folderBox).forEach(function (o) { if (o !== f) { o.classList.remove('cm-drop'); } });
			if (!f.classList.contains('cm-drop') && f.classList.contains('cm-folder-collapsed')) {
				clearTimeout(openTimer);
				openTimer = setTimeout(function () {
					if (f.classList.contains('cm-drop')) {
						setCollapsed(f.getAttribute('data-name'), false);
						applyTree();
					}
				}, 700);
			}
			f.classList.add('cm-drop');
		});
		folderBox.addEventListener('dragleave', function (ev) {
			var f = ev.target.closest('.cm-folder');
			if (f && !f.contains(ev.relatedTarget)) {
				f.classList.remove('cm-drop');
			}
		});
		folderBox.addEventListener('drop', function (ev) {
			var f = ev.target.closest('.cm-folder');
			if (!dragging || !f) {
				return;
			}
			ev.preventDefault();
			var uids = dragging;
			endDrag();
			run('move', uids, { dest: f.getAttribute('data-name') });
		});

		// Rows: open, star, select
		rowsBox.addEventListener('click', function (ev) {
			var row = ev.target.closest('.cm-row');
			if (!row) {
				return;
			}
			var uid = parseInt(row.getAttribute('data-uid'), 10);
			if (ev.target.closest('.cm-star')) {
				ev.preventDefault();
				run(row.classList.contains('flagged') ? 'unflag' : 'flag', [uid]);
				return;
			}
			if (ev.target.closest('.cm-check') || ev.target.closest('.cm-row-tags a')) {
				return;
			}
			if (ev.metaKey || ev.ctrlKey || ev.shiftKey) {
				return;	// let the browser open the link in a new tab
			}
			ev.preventDefault();
			openMessage(uid);
		});

		var checkall = qs('.cm-checkall', app);
		if (checkall) {
			checkall.addEventListener('change', function () {
				qsa('.cm-check', rowsBox).forEach(function (c) { c.checked = checkall.checked; });
			});
		}

		// List toolbar
		qsa('[data-cm-bulk]', app).forEach(function (el) {
			var action = el.getAttribute('data-cm-bulk');
			if (el.tagName === 'SELECT') {
				el.addEventListener('change', function () {
					if (el.value) {
						moveTo(el, selectedUids());
					}
				});
			} else {
				el.addEventListener('click', function () { run(action, selectedUids()); });
			}
		});

		// Reading pane toolbar (delegated: the pane is replaced on each open)
		pane.addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-cm-action]');
			if (btn && btn.tagName !== 'SELECT') {
				ev.preventDefault();
				var action = btn.getAttribute('data-cm-action');
				var extra = {};
				if (action === 'log') {
					extra = { projectid: btn.getAttribute('data-projectid'), socid: btn.getAttribute('data-socid'), contactids: btn.getAttribute('data-contactids') || '' };
				}
				run(action, [currentUid()], extra);
				return;
			}
			if (ev.target.closest('[data-cm-showimages]')) {
				ev.preventDefault();
				openMessage(currentUid(), true);
			}
		});
		// Attachments filed from the "From this email" panel: show the message again (its log state may have changed)
		pane.addEventListener('convomail:reload', function () { openMessage(currentUid()); });
		pane.addEventListener('change', function (ev) {
			var sel = ev.target.closest('select[data-cm-action="move"]');
			if (sel && sel.value) {
				moveTo(sel, [currentUid()]);
			}
		});

		// Keyboard shortcuts
		document.addEventListener('keydown', function (ev) {
			var t = ev.target;
			if ((t && t.closest && t.closest('input, textarea, select, [contenteditable], .cm-resize, .cm-rp, .cm-createmenu')) || ev.metaKey || ev.ctrlKey || ev.altKey) {
				return;
			}
			var list = rows();
			var cur = currentUid();
			var idx = list.findIndex(function (r) { return parseInt(r.getAttribute('data-uid'), 10) === cur; });
			var key = ev.key;
			var press = function (selector) {
				var el = qs(selector, pane);
				if (el) {
					ev.preventDefault();
					el.click();
				}
			};
			if (key === 'ArrowDown' || key === 'j') {
				var n = list[Math.min(list.length - 1, idx + 1)];
				if (n) { ev.preventDefault(); openMessage(parseInt(n.getAttribute('data-uid'), 10)); }
			} else if (key === 'ArrowUp' || key === 'k') {
				var p = list[Math.max(0, idx - 1)];
				if (p) { ev.preventDefault(); openMessage(parseInt(p.getAttribute('data-uid'), 10)); }
			} else if (key === 'Enter' && idx === -1 && list.length) {
				ev.preventDefault();
				openMessage(parseInt(list[0].getAttribute('data-uid'), 10));
			} else if (key === 'r') {
				press('[data-cm-key="r"]');
			} else if (key === 'a') {
				press('[data-cm-key="a"]');
			} else if (key === 'f') {
				press('[data-cm-key="f"]');
			} else if ((key === '#' || key === 'Delete') && cur) {
				ev.preventDefault();
				run('delete', [cur]);
			} else if (key === 'e' && cur) {
				ev.preventDefault();
				run('archive', [cur]);
			} else if (key === 's' && cur) {
				ev.preventDefault();
				var row = rowByUid(cur);
				run(row && row.classList.contains('flagged') ? 'unflag' : 'flag', [cur]);
			} else if (key === 'u' && cur) {
				ev.preventDefault();
				run('unread', [cur]);
			}
		});

		var selected = qs('.cm-row.selected', rowsBox);
		if (selected) {
			selected.scrollIntoView({ block: 'nearest' });
		}
		// Phones: the folders are one row that scrolls sideways; show the open one
		var activeFolder = qs('.cm-folderlist a.cm-folder.active', app);
		if (activeFolder && activeFolder.parentNode.scrollWidth > activeFolder.parentNode.clientWidth) {
			activeFolder.parentNode.scrollLeft = activeFolder.offsetLeft - activeFolder.parentNode.offsetLeft - 16;
		}
	});

	/**
	 * Resizable mailbox: the folder and list columns (handles between the columns) and the height of the
	 * conversation under the open message (handle above it). Sizes are kept in this browser (localStorage).
	 */
	function bindLayout(app) {
		var KEY = 'convomail.layout';
		var layout = {};
		try {
			layout = JSON.parse(window.localStorage.getItem(KEY)) || {};
		} catch (e) {
			layout = {};
		}
		function save() {
			try {
				window.localStorage.setItem(KEY, JSON.stringify(layout));
			} catch (e) { /* private window: sizes last for this page only */ }
		}
		function apply() {
			[['col1', '--cm-col1', 'px'], ['col2', '--cm-col2', 'px'], ['conv', '--cm-conv-h', '%']].forEach(function (v) {
				if (layout[v[0]]) {
					app.style.setProperty(v[1], (v[2] === '%' ? layout[v[0]] * 100 : layout[v[0]]) + v[2]);
				} else {
					app.style.removeProperty(v[1]);
				}
			});
		}
		function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }

		// Current size of what a handle controls, and how to set it
		function target(handle) {
			if (handle.classList.contains('cm-resize-row')) {
				var view = handle.parentNode;
				var slot = handle.nextElementSibling;
				return {
					kind: 'row',
					size: slot ? slot.getBoundingClientRect().height : 0,
					set: function (h) {
						var total = view.clientHeight;
						if (total > 0) {
							layout.conv = clamp(h, 60, Math.max(60, total - 120)) / total;
						}
					},
					reset: function () { delete layout.conv; },
					sign: -1	// dragging up makes the conversation taller
				};
			}
			var col = handle.getAttribute('data-col');
			var el = qs(col === '1' ? '.cm-folders' : '.cm-list', app);
			var other = qs(col === '1' ? '.cm-list' : '.cm-folders', app);
			return {
				kind: 'col',
				size: el.getBoundingClientRect().width,
				set: function (w) {
					var max = app.clientWidth - other.getBoundingClientRect().width - 10 - 320;	// keep 320px for the message
					layout['col' + col] = Math.round(clamp(w, col === '1' ? 140 : 220, Math.max(col === '1' ? 140 : 220, Math.min(col === '1' ? 420 : 900, max))));
				},
				reset: function () { delete layout['col' + col]; },
				sign: 1
			};
		}

		apply();

		app.addEventListener('pointerdown', function (ev) {
			var handle = ev.target.closest('.cm-resize');
			if (!handle || ev.button !== 0) {
				return;
			}
			ev.preventDefault();
			var t = target(handle);
			var start = t.kind === 'row' ? ev.clientY : ev.clientX;
			handle.setPointerCapture(ev.pointerId);
			handle.classList.add('active');
			app.classList.add('cm-resizing', 'cm-resizing-' + t.kind);
			var move = function (e) {
				t.set(t.size + t.sign * ((t.kind === 'row' ? e.clientY : e.clientX) - start));
				apply();
			};
			var end = function () {
				handle.removeEventListener('pointermove', move);
				handle.removeEventListener('pointerup', end);
				handle.removeEventListener('pointercancel', end);
				handle.classList.remove('active');
				app.classList.remove('cm-resizing', 'cm-resizing-' + t.kind);
				qsa('iframe.convomail-body', app).forEach(fit);
				save();
			};
			handle.addEventListener('pointermove', move);
			handle.addEventListener('pointerup', end);
			handle.addEventListener('pointercancel', end);
		});
		app.addEventListener('dblclick', function (ev) {
			var handle = ev.target.closest('.cm-resize');
			if (handle) {
				target(handle).reset();
				apply();
				save();
			}
		});
		app.addEventListener('keydown', function (ev) {
			var handle = ev.target.closest && ev.target.closest('.cm-resize');
			if (!handle) {
				return;
			}
			var t = target(handle);
			var keys = t.kind === 'row' ? { ArrowUp: 1, ArrowDown: -1 } : { ArrowRight: 1, ArrowLeft: -1 };
			if (keys[ev.key]) {
				ev.preventDefault();
				t.set(t.size + keys[ev.key] * (ev.shiftKey ? 64 : 16));
				apply();
				save();
			}
		});
	}

	/**
	 * Full-page message view (phones, direct links): same toolbar, then back to the list
	 */
	function bindPageView(view) {
		view.addEventListener('convomail:reload', function () { window.location.reload(); });
		view.addEventListener('click', function (ev) {
			var btn = ev.target.closest('[data-cm-action]');
			if (btn && btn.tagName !== 'SELECT') {
				ev.preventDefault();
				send(btn.getAttribute('data-cm-action'), btn.dataset);
			}
			if (ev.target.closest('[data-cm-showimages]')) {
				ev.preventDefault();
				window.location.href = view.getAttribute('data-self') + '&showimages=1';
			}
		});
		view.addEventListener('change', function (ev) {
			var sel = ev.target.closest('select[data-cm-action="move"]');
			if (!sel || !sel.value) {
				return;
			}
			if (sel.value !== '__new__') {
				send('move', { dest: sel.value });
				return;
			}
			// "New folder...": create it (ajax/folder.php), then move there
			var name = window.prompt(view.getAttribute('data-new-folder-prompt'));
			sel.value = '';
			if (!name || !name.trim()) {
				return;
			}
			var fd = new FormData();
			fd.append('token', view.getAttribute('data-token'));
			fd.append('accountid', view.getAttribute('data-accountid'));
			fd.append('action', 'create');
			fd.append('name', name.trim());
			fetch(view.getAttribute('data-ajax-action').replace(/action\.php$/, 'folder.php'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (res.ok) {
						send('move', { dest: res.name });
					} else {
						notify(res.error || 'Error', false);
					}
				});
		});
		function send(action, data) {
			var fd = new FormData();
			fd.append('token', view.getAttribute('data-token'));
			fd.append('accountid', view.getAttribute('data-accountid'));
			fd.append('folder', view.getAttribute('data-folder'));
			fd.append('action', action);
			fd.append('uids[]', view.getAttribute('data-uid'));
			['dest', 'projectid', 'socid', 'contactids'].forEach(function (k) {
				if (data && data[k]) { fd.append(k, data[k]); }
			});
			fetch(view.getAttribute('data-ajax-action'), { method: 'POST', body: fd, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					if (!res.ok) {
						notify(res.error || 'Error', false);
					} else if (res.removed && res.removed.length || action === 'unread') {
						window.location.href = view.getAttribute('data-list');
					} else {
						window.location.reload();
					}
				});
		}
	}
})();
