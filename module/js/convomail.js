/* Copyright (C) 2026 DPG Supply - convomail
 *
 * Mailbox behaviour: open a message in the reading pane, actions (read, flag, move, archive, delete,
 * log to project), selection and keyboard shortcuts. Also sizes the sandboxed message iframe; the iframe
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

	function bindFrames(root) {
		qsa('iframe.convomail-body', root).forEach(function (frame) {
			var onload = function () {
				fit(frame);
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
		if (window.jQuery && jQuery.jnotify) {
			jQuery.jnotify(text, ok ? 'ok' : 'error', ok ? 3000 : true);
		} else if (!ok) {
			window.alert(text);
		}
	}

	document.addEventListener('DOMContentLoaded', function () {
		bindFrames(document);

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
				})
				.catch(function () { /* the list stays as it was */ });
		}

		function run(action, uids, extra) {
			if (!uids.length) {
				return;
			}
			if (action === 'delete' && uids.length > 1 && !window.confirm(app.getAttribute('data-confirm-delete'))) {
				return;
			}
			post(action, uids, extra).then(function (data) {
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
			});
		}

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
						run('move', selectedUids(), { dest: el.value });
						el.value = '';
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
		pane.addEventListener('change', function (ev) {
			var sel = ev.target.closest('select[data-cm-action="move"]');
			if (sel && sel.value) {
				run('move', [currentUid()], { dest: sel.value });
			}
		});

		// Keyboard shortcuts
		document.addEventListener('keydown', function (ev) {
			var t = ev.target;
			if ((t && t.closest && t.closest('input, textarea, select, [contenteditable]')) || ev.metaKey || ev.ctrlKey || ev.altKey) {
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
	});

	/**
	 * Full-page message view (phones, direct links): same toolbar, then back to the list
	 */
	function bindPageView(view) {
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
			if (sel && sel.value) {
				send('move', { dest: sel.value });
			}
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
