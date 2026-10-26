/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Top-bar unread indicator (ActionsConvomail::printTopRightMenu, ajax/unread.php): the count arrives after the
 * page has loaded, then again every data-interval seconds while the tab is visible. With the preview on, a click
 * shows the newest unread emails counted, each opening that email in the mailbox. Every value from the server is
 * written as text.
 */
(function () {
	'use strict';

	function init() {
		var link = document.querySelector('.cm-topunread');
		if (!link || link.getAttribute('data-ready')) {
			return;
		}
		link.setAttribute('data-ready', '1');
		var wrap = link.closest('.cm-topunread-wrap');
		var menu = wrap.querySelector('.cm-topunread-menu');
		var bubble = link.querySelector('.cm-topunread-count');
		var texts = {};
		try {
			texts = JSON.parse(link.getAttribute('data-texts') || '{}');
		} catch (e) { /* the keys as text */ }
		var t = function (key, arg) { return String(texts[key] || key).replace('%s', arg === undefined ? '' : arg); };
		var interval = Math.max(60, parseInt(link.getAttribute('data-interval'), 10) || 120) * 1000;
		var preview = link.getAttribute('data-preview') === '1';
		var data = null;
		var failed = false;
		var timer = null;
		var busy = false;
		var lastLoad = 0;
		var lastRefresh = 0;

		function schedule() {
			clearTimeout(timer);
			if (!document.hidden) {
				timer = setTimeout(function () { load(false); }, interval);
			}
		}

		function load(refresh) {
			if (busy) {
				return;
			}
			busy = true;
			lastLoad = Date.now();
			var url = link.getAttribute('data-url') + '?token=' + encodeURIComponent(link.getAttribute('data-token')) + (refresh ? '&refresh=1' : '');
			fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
				.then(function (r) {
					return (r.headers.get('Content-Type') || '').indexOf('json') !== -1 ? r.json() : null;
				})
				.then(function (d) {
					busy = false;
					failed = !d || !d.ok;
					if (!failed) {
						data = d;
					}
					render();
					schedule();
				})
				.catch(function () {
					busy = false;
					failed = true;
					render();
					schedule();
				});
		}

		function render() {
			if (data && data.mode === 'off') {
				wrap.hidden = true;
				return;
			}
			var count = data ? (data.total || 0) : 0;
			bubble.textContent = (count > 99 ? '99' : String(count)) + ((count > 99 || (data && data.plus)) ? '+' : '');
			bubble.hidden = !data || count === 0;
			link.classList.toggle('cm-topunread-has', count > 0);
			link.classList.toggle('cm-topunread-failed', failed && !data);
			// Tooltip: the count, then each mailbox (and those that could not be read)
			var lines = [count > 0 ? t('count', bubble.textContent) : t('none')];
			(data ? data.accounts || [] : []).forEach(function (a) {
				lines.push(a.label + ': ' + (a.error ? t('error') : a.count + (a.plus ? '+' : '')));
			});
			if (failed && !data) {
				lines = [t('error')];
			}
			link.title = lines.join('\n');
			if (!menu.hidden) {
				fillMenu();
			}
		}

		function when(ts) {
			if (!ts) {
				return '';
			}
			var d = new Date(ts * 1000);
			var today = new Date();
			return d.toDateString() === today.toDateString()
				? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
				: d.toLocaleDateString([], { day: 'numeric', month: 'short' });
		}

		function el(tag, cls, text) {
			var e = document.createElement(tag);
			if (cls) {
				e.className = cls;
			}
			if (text !== undefined) {
				e.textContent = text;
			}
			return e;
		}

		function fillMenu() {
			menu.textContent = '';
			menu.appendChild(el('div', 'cm-topunread-head', t('title')));
			var items = data ? data.items || [] : [];
			var accounts = data ? data.accounts || [] : [];
			if (failed && !data) {
				menu.appendChild(el('div', 'cm-topunread-empty', t('error')));
			} else if (!items.length) {
				menu.appendChild(el('div', 'cm-topunread-empty', t('none')));
			}
			items.forEach(function (it) {
				var a = el('a', 'cm-topunread-item');
				a.href = it.url;
				var top = el('span', 'cm-topunread-top');
				top.appendChild(el('span', 'cm-topunread-from', it.from || it.email));
				top.appendChild(el('span', 'cm-topunread-when', when(it.date)));
				a.appendChild(top);
				if (it.contact) {
					var c = el('span', 'cm-topunread-contact');
					c.appendChild(el('span', 'fas fa-user pictofixedwidth'));
					c.appendChild(document.createTextNode(it.contact));
					a.appendChild(c);
				}
				a.appendChild(el('span', 'cm-topunread-subject', it.subject || '…'));
				if (accounts.length > 1) {
					a.appendChild(el('span', 'cm-topunread-account', it.account));
				}
				a.title = it.email;
				menu.appendChild(a);
			});
			var count = data ? data.total || 0 : 0;
			var foot = el('div', 'cm-topunread-foot');
			var inbox = el('a', '', count > items.length ? t('more', count - items.length) : t('inbox'));
			inbox.href = link.href;
			foot.appendChild(inbox);
			if (count > 0) {
				var markall = el('button', 'cm-topunread-markall', t('markall'));
				markall.type = 'button';
				markall.addEventListener('click', function (ev) {
					ev.preventDefault();
					markAllRead(markall, count);
				});
				foot.appendChild(markall);
			}
			var settings = el('a', 'cm-topunread-settings', t('settings'));
			settings.href = link.getAttribute('data-settings');
			foot.appendChild(settings);
			menu.appendChild(foot);
		}

		// Mark what is counted as read on the mail server, up to the newest message counted in each mailbox
		function markAllRead(button, count) {
			if (!data || !window.confirm(t('markconfirm', count + (data.plus ? '+' : '')))) {
				return;
			}
			var fd = new FormData();
			fd.append('token', link.getAttribute('data-token'));
			fd.append('action', 'markread');
			(data.accounts || []).forEach(function (a) {
				if (a.maxuid > 0) {
					fd.append('maxuid[' + a.id + ']', a.maxuid);
				}
			});
			button.disabled = true;
			button.textContent = t('marking');
			busy = true;
			fetch(link.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json' } })
				.then(function (r) {
					return (r.headers.get('Content-Type') || '').indexOf('json') !== -1 ? r.json() : null;
				})
				.then(function (d) {
					busy = false;
					failed = !d || !d.ok;
					if (!failed) {
						data = d;
					}
					render();
					fillMenu();
					if (d && d.errors && d.errors.length) {
						menu.insertBefore(el('div', 'cm-topunread-empty', t('error')), menu.children[1] || null);
					}
					// The mailbox page, when open, shows the rows read
					window.dispatchEvent(new CustomEvent('convomail:unread-marked'));
				})
				.catch(function () {
					busy = false;
					button.disabled = false;
					button.textContent = t('markall');
				});
		}

		function openMenu() {
			fillMenu();
			menu.hidden = false;
			link.setAttribute('aria-expanded', 'true');
			// Opening the list is a good moment for a fresh count (the cache keeps it cheap)
			if (Date.now() - lastLoad > 30000) {
				load(false);
			}
		}

		function closeMenu() {
			menu.hidden = true;
			link.setAttribute('aria-expanded', 'false');
		}

		link.addEventListener('click', function (ev) {
			if (!preview || ev.metaKey || ev.ctrlKey || ev.shiftKey) {
				return;	// straight to the Inbox
			}
			ev.preventDefault();
			if (menu.hidden) {
				openMenu();
			} else {
				closeMenu();
			}
		});
		document.addEventListener('click', function (ev) {
			if (!menu.hidden && !wrap.contains(ev.target)) {
				closeMenu();
			}
		});
		document.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape' && !menu.hidden) {
				closeMenu();
				link.focus();
			}
		});
		document.addEventListener('visibilitychange', function () {
			if (document.hidden) {
				clearTimeout(timer);
			} else if (Date.now() - lastLoad >= interval) {
				load(false);
			} else {
				schedule();
			}
		});
		// The mailbox page saw new mail or a message read: count again (not more than every 20 s)
		window.addEventListener('convomail:unread-refresh', function () {
			if (Date.now() - lastRefresh > 20000) {
				lastRefresh = Date.now();
				load(true);
			}
		});

		load(false);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
