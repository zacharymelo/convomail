/* Copyright (C) 2026 DPG Supply - convomail
 * Sizes the sandboxed message iframe to its content. The iframe has no scripts; this runs in the parent page.
 */
(function () {
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
	document.addEventListener('DOMContentLoaded', function () {
		var frames = document.querySelectorAll('iframe.convomail-body');
		for (var i = 0; i < frames.length; i++) {
			(function (frame) {
				frame.addEventListener('load', function () {
					fit(frame);
					// Images load after the document; fit again when they do
					var imgs = frame.contentDocument ? frame.contentDocument.images : [];
					for (var j = 0; j < imgs.length; j++) {
						imgs[j].addEventListener('load', function () { fit(frame); });
					}
				});
			})(frames[i]);
		}
		var switcher = document.querySelector('form.convomail-switcher select');
		if (switcher) {
			switcher.addEventListener('change', function () { switcher.form.submit(); });
		}
	});
})();
