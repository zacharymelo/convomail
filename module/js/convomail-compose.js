/* Copyright (C) 2026 DPG Supply - convomail compose
 * Email templates (fill subject and body above the signature/quote), project contact chips (add to To / Cc),
 * address suggestions while typing in To / Cc / Bcc (jQuery UI autocomplete, as elsewhere in Dolibarr),
 * and the AI writing tools of core's AI module.
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

	function normalized(text) {
		return (text || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
	}

	/**
	 * The editor content split into what the user typed and what follows: the signature ("-- " line) or the quoted
	 * message (its intro line). The editor drops the classes and the blockquote put there by the server, so these
	 * two texts are what is left to find them by.
	 */
	function splitBody(html, intro) {
		var box = document.createElement('div');
		box.innerHTML = html;
		var nodes = Array.prototype.slice.call(box.childNodes);
		var stop = nodes.length;
		for (var i = 0; i < nodes.length; i++) {
			var n = nodes[i];
			var text = normalized(n.textContent);
			if (n.nodeType === 1 && (/^--( |$)/.test(text) || n.tagName === 'BLOCKQUOTE' || (intro && text.indexOf(intro) === 0))) {
				stop = i;
				break;
			}
		}
		var typed = document.createElement('div');
		var rest = document.createElement('div');
		nodes.forEach(function (n, i) { (i < stop ? typed : rest).appendChild(n); });
		return { typed: typed, rest: rest };
	}

	// AI output is HTML from a remote service: keep the content only, nothing that runs or would trip core's injection filter
	function cleanAiHtml(html) {
		html = String(html || '').trim().replace(/^```(?:html)?\s*/i, '').replace(/\s*```$/, '');
		var doc = new DOMParser().parseFromString(html, 'text/html');
		Array.prototype.forEach.call(doc.querySelectorAll('script, style, iframe, object, embed, form, link, meta, title'), function (el) { el.remove(); });
		Array.prototype.forEach.call(doc.body.querySelectorAll('*'), function (el) {
			Array.prototype.slice.call(el.attributes).forEach(function (a) {
				if (/^on/i.test(a.name) || a.name === 'contenteditable' || a.name === 'style' || /^\s*javascript:/i.test(a.value)) {
					el.removeAttribute(a.name);
				}
			});
		});
		var section = doc.body.querySelector('section');
		return (section && normalized(section.textContent) === normalized(doc.body.textContent) ? section.innerHTML : doc.body.innerHTML).trim();
	}

	/**
	 * AI writing tools (core's AI module, ai/ajax/generate_content.php): write from a prompt, or rephrase,
	 * shorten or translate the typed text. Only the typed part changes; signature and quote stay as they are.
	 */
	function bindAiTools(panel) {
		var $ = window.jQuery;
		var prompt = document.getElementById('cm-ai-prompt');
		var write = document.getElementById('cm-ai-write');
		var status = document.getElementById('cm-ai-status');
		var intro = normalized(panel.getAttribute('data-intro'));
		var busy = false;

		document.getElementById('cm-ai-toggle').addEventListener('click', function (ev) {
			ev.preventDefault();
			panel.classList.toggle('hideobject');
			if (!panel.classList.contains('hideobject')) {
				prompt.focus();
			}
		});
		prompt.addEventListener('input', function () { write.disabled = busy || !prompt.value.trim(); });
		prompt.addEventListener('keydown', function (ev) {
			if (ev.key === 'Enter' && !ev.shiftKey && prompt.value.trim()) {
				ev.preventDefault();
				write.click();
			}
		});

		function say(text, error) {
			status.textContent = text;
			status.classList.toggle('error', !!error);
			status.classList.toggle('hideobject', !text);
		}

		function run(fn, instructions, parts) {
			busy = true;
			write.disabled = true;
			panel.classList.add('cm-ai-busy');
			say(panel.getAttribute('data-wait'), false);
			var editor = window.CKEDITOR && CKEDITOR.instances.message;
			fetch(panel.getAttribute('data-url'), {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ format: 'html', 'function': fn, instructions: instructions })
			}).then(function (r) {
				return r.text().then(function (text) { return { ok: r.ok, text: text }; });
			}).then(function (res) {
				// Core answers 200 with its error text when the provider can't be reached (UPSTREAM item 15)
				var failed = !res.ok || /^\s*(Error\b|Quota or allowed period exceeded)/.test(res.text);
				var html = failed ? '' : cleanAiHtml(res.text);
				if (!html) {
					var box = document.createElement('div');
					box.innerHTML = res.text;
					throw new Error(normalized(box.textContent) || 'Error');
				}
				var rest = parts.rest.innerHTML.trim();
				var previous = editorData();
				setEditorData(html + (rest ? '<p>&nbsp;</p>' + rest : ''));
				prompt.value = '';
				// The editor's own undo can't step back over setData(): offer an explicit one
				say(panel.getAttribute('data-done') + ' ', false);
				var undo = document.createElement('a');
				undo.href = '#';
				undo.textContent = panel.getAttribute('data-undo');
				undo.addEventListener('click', function (ev) {
					ev.preventDefault();
					setEditorData(previous);
					say('', false);
				});
				status.appendChild(undo);
			}).catch(function (e) {
				say(e.message, true);
			}).then(function () {
				busy = false;
				write.disabled = !prompt.value.trim();
				panel.classList.remove('cm-ai-busy');
			});
		}

		write.addEventListener('click', function () {
			var ask = prompt.value.trim();
			if (!ask || busy) {
				return;
			}
			var parts = splitBody(editorData(), intro);
			var draft = normalized(parts.typed.textContent);
			var instructions = ask;
			if (draft) {
				instructions += '\n\nUse and improve this draft:\n' + parts.typed.innerHTML;
			}
			if (panel.getAttribute('data-mode') !== 'new' && panel.getAttribute('data-quote')) {
				instructions += '\n\nThis email ' + (panel.getAttribute('data-mode') === 'forward' ? 'forwards' : 'answers') + ' the message below. Do not repeat it.\n'
					+ panel.getAttribute('data-intro') + '\n' + panel.getAttribute('data-quote');
			}
			instructions += '\n\nWrite only the body text: no subject line and no signature, they are added separately.';
			run('textgenerationemail', instructions, parts);
		});

		function onChoice(id, fn, build) {
			var sel = document.getElementById(id);
			if (!sel || !$) {
				return;
			}
			$(sel).on('change', function () {
				var value = sel.value;
				if (!value || value === '-1' || value === '0' || busy) {
					return;
				}
				var parts = splitBody(editorData(), intro);
				if (!normalized(parts.typed.textContent)) {
					say(panel.getAttribute('data-empty'), true);
				} else {
					run(fn, build(value, parts.typed.innerHTML), parts);
				}
				$(sel).val('-1').trigger('change.select2');
			});
		}
		// Same instructions as core's mail form (core/class/html.formai.class.php)
		onChoice('cm_ai_rephrase', 'textrephraser', function (style, text) {
			return 'Rephrase the following text in a ' + style + ' style: ' + text;
		});
		onChoice('cm_ai_summarize', 'textsummarize', function (value, text) {
			var p = value.split('_');
			var unit = { w: 'words', p: 'paragraphs', pc: 'percent' }[p[1]] || 'words';
			return 'Summarize the following text ' + (unit === 'percent' ? 'by ' : 'in ') + p[0] + ' ' + unit + ': ' + text;
		});
		onChoice('cm_ai_translate', 'texttranslation', function (lang, text) {
			return 'Translate only the following text to ' + lang + ': ' + text;
		});
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

		/**
		 * Files dragged in from the computer, anywhere on the page or into the editor's text: an overlay says what a
		 * drop will do. Images dropped on the text go in it at the drop point (as pasted images do, up to 2 MB each);
		 * every other file, and anything dropped elsewhere on the page, goes to add() as an attachment. A drop that
		 * misses never makes the browser open the file (and leave the draft). Drags that start in the page itself
		 * (an image moved inside the text) are left alone.
		 */
		function watchDrops(add, labels) {
			var INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
			var INLINE_MAX = 2097152;
			var overlay = null;
			var box = null;
			var hideTimer = null;
			var internal = false;
			if (add) {
				overlay = document.createElement('div');
				overlay.className = 'cm-drop-overlay';
				overlay.setAttribute('aria-hidden', 'true');
				box = document.createElement('div');
				box.className = 'cm-drop-overlay-box';
				overlay.appendChild(box);
				document.body.appendChild(overlay);
			}
			var hasFiles = function (ev) {
				return !internal && ev.dataTransfer && Array.prototype.indexOf.call(ev.dataTransfer.types || [], 'Files') !== -1;
			};
			// While dragging, only the types are known (not the sizes): "into the text" when every item is an image
			var allImages = function (ev) {
				var items = ev.dataTransfer.items;
				if (!items || !items.length) {
					return false;
				}
				return Array.prototype.every.call(items, function (it) { return it.kind === 'file' && INLINE_TYPES.indexOf(it.type) !== -1; });
			};
			var hide = function () {
				if (overlay) {
					overlay.classList.remove('cm-drop-on');
				}
			};
			// Put the editor's caret where the files were dropped, so the images land there
			var caretAtDrop = function (ed, ev) {
				var doc = ed.document.$;
				var native = null;
				if (doc.caretRangeFromPoint) {
					native = doc.caretRangeFromPoint(ev.clientX, ev.clientY);
				} else if (doc.caretPositionFromPoint) {
					var pos = doc.caretPositionFromPoint(ev.clientX, ev.clientY);
					if (pos) {
						native = { startContainer: pos.offsetNode, startOffset: pos.offset };
					}
				}
				if (!native || !native.startContainer) {
					return;
				}
				try {
					var range = ed.createRange();
					range.setStart(new CKEDITOR.dom.node(native.startContainer), native.startOffset);
					range.collapse(true);
					ed.getSelection().selectRanges([range]);
				} catch (e) { /* at the caret it had */ }
			};
			var insertImages = function (ed, files) {
				files.forEach(function (f) {
					var reader = new FileReader();
					reader.onload = function () {
						var img = new CKEDITOR.dom.element('img', ed.document);
						img.setAttribute('src', String(reader.result));
						img.setAttribute('alt', f.name);
						ed.insertElement(img);
					};
					reader.readAsDataURL(f);
				});
			};
			var watch = function (doc, ed) {
				if (!doc || doc.cmDropWatched) {
					return;
				}
				doc.cmDropWatched = true;
				doc.addEventListener('dragstart', function () { internal = true; }, true);
				doc.addEventListener('dragend', function () { internal = false; }, true);
				doc.addEventListener('dragover', function (ev) {
					if (!hasFiles(ev)) {
						return;
					}
					ev.preventDefault();
					ev.stopPropagation();
					ev.dataTransfer.dropEffect = add ? 'copy' : 'none';
					if (overlay) {
						// Shown while files are over the page or the editor, gone a moment after they leave both
						box.textContent = (ed && allImages(ev)) ? labels.inline : labels.attach;
						overlay.classList.add('cm-drop-on');
						clearTimeout(hideTimer);
						hideTimer = setTimeout(hide, 250);
					}
				}, true);
				doc.addEventListener('drop', function (ev) {
					if (hasFiles(ev)) {
						ev.preventDefault();
						ev.stopPropagation();	// before the editor's own drop handling
						hide();
						var files = Array.prototype.slice.call(ev.dataTransfer.files || []);
						if (add && ed) {
							var inline = files.filter(function (f) { return INLINE_TYPES.indexOf(f.type) !== -1 && f.size <= INLINE_MAX; });
							if (inline.length) {
								caretAtDrop(ed, ev);
								insertImages(ed, inline);
							}
							files = files.filter(function (f) { return inline.indexOf(f) === -1; });
						}
						if (add && files.length) {
							add(files);
						}
					}
					internal = false;
				}, true);
			};
			watch(document, null);
			// The editor's text is a document of its own (an iframe), made again when switching to source and back
			if (window.CKEDITOR) {
				var hook = function (ed) {
					ed.on('contentDom', function () { watch(ed.document && ed.document.$, ed); });
					if (ed.status === 'ready' && ed.document) {
						watch(ed.document.$, ed);
					}
				};
				Object.keys(CKEDITOR.instances).forEach(function (k) { hook(CKEDITOR.instances[k]); });
				CKEDITOR.on('instanceCreated', function (e) { hook(e.editor); });
			}
		}

		// Files from the computer: a chip each (name, size, x), added across picks instead of replacing them, and
		// files dropped anywhere on the page (watchDrops()); the chosen ones are put back in the field, which the form sends
		var upInput = document.getElementById('cm-upload');
		var upBtn = document.getElementById('cm-upload-btn');
		var upBox = document.getElementById('cm-uploads');
		var canSet = false;
		try {
			canSet = typeof DataTransfer === 'function' && !!new DataTransfer().files;
		} catch (e) { canSet = false; }
		if (upInput && upBtn && upBox && canSet) {
			var chosen = [];
			var limit = parseInt(upBox.getAttribute('data-max'), 10) || 0;
			var sizeText = function (b) {
				return b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
			};
			var sync = function () {
				var dt = new DataTransfer();
				chosen.forEach(function (f) { dt.items.add(f); });
				upInput.files = dt.files;
			};
			var renderUploads = function () {
				upBox.textContent = '';
				var total = 0;
				chosen.forEach(function (f, i) {
					total += f.size;
					var chip = document.createElement('span');
					chip.className = 'cm-chip cm-dbchip cm-upchip';
					chip.title = f.name;
					var icon = document.createElement('span');
					icon.className = 'fa fa-paperclip pictofixedwidth';
					chip.appendChild(icon);
					chip.appendChild(document.createTextNode(f.name + ' (' + sizeText(f.size) + ')'));
					var x = document.createElement('a');
					x.href = '#';
					x.textContent = '\u00d7';
					x.title = upBox.getAttribute('data-remove');
					x.addEventListener('click', function (ev) {
						ev.preventDefault();
						chosen.splice(i, 1);
						sync();
						renderUploads();
					});
					chip.appendChild(x);
					upBox.appendChild(chip);
				});
				if (limit > 0 && total > limit) {
					var note = document.createElement('div');
					note.className = 'warning';
					note.textContent = upBox.getAttribute('data-overlimit');
					upBox.appendChild(note);
				}
			};
			var add = function (list) {
				Array.prototype.forEach.call(list || [], function (f) {
					if (!chosen.some(function (c) { return c.name === f.name && c.size === f.size && c.lastModified === f.lastModified; })) {
						chosen.push(f);
					}
				});
				sync();
				renderUploads();
			};
			upInput.classList.add('cm-upload-field');
			upBtn.hidden = false;
			upBtn.addEventListener('click', function () { upInput.click(); });
			// The browser replaces the field's files on each pick: keep the earlier ones
			upInput.addEventListener('change', function () { add(upInput.files); });
			watchDrops(add, { attach: upBox.getAttribute('data-drop'), inline: upBox.getAttribute('data-drop-inline') });
		} else if (upInput) {
			watchDrops(null, {});	// no chips here: a dropped file just never makes the browser leave the draft
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

		var aipanel = document.getElementById('cm-ai-panel');
		if (aipanel) {
			bindAiTools(aipanel);
		}
	});
})();
