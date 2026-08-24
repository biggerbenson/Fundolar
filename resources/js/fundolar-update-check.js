(function () {
	'use strict';

	function showNotice(html, type) {
		var wrap = document.querySelector('.wrap');
		if (!wrap) {
			return;
		}
		document.querySelectorAll('.fundolar-update-check-notice').forEach(function (el) {
			el.remove();
		});
		var notice = document.createElement('div');
		notice.className =
			'notice fundolar-update-check-notice is-dismissible ' +
			(type === 'error' ? 'notice-error' : type === 'update' ? 'notice-warning' : 'notice-success');
		notice.innerHTML = '<p>' + html + '</p>';
		wrap.insertBefore(notice, wrap.firstChild);
		if (typeof window.jQuery !== 'undefined' && window.jQuery && window.jQuery.fn && window.jQuery.fn.wp) {
			try {
				window.jQuery(document).trigger('wp-updates-notice-added');
			} catch (e) {}
		}
	}

	function onClick(e) {
		var link = e.target && e.target.closest ? e.target.closest('a.fundolar-check-updates') : null;
		if (!link || typeof fundolarUpdateCheck === 'undefined') {
			return;
		}
		e.preventDefault();
		if (link.getAttribute('aria-busy') === 'true') {
			return;
		}

		var nonce = link.getAttribute('data-nonce') || '';
		var label = link.textContent;
		link.setAttribute('aria-busy', 'true');
		link.textContent = fundolarUpdateCheck.i18n.checking || 'Checking…';

		var body = new URLSearchParams();
		body.append('action', fundolarUpdateCheck.action || 'fundolar_check_updates');
		body.append('nonce', nonce);

		fetch(fundolarUpdateCheck.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		})
			.then(function (r) {
				return r.json();
			})
			.then(function (res) {
				var data = res && res.data ? res.data : null;
				if (res && res.success && data) {
					if (data.status === 'update_available') {
						showNotice(data.html || data.message, 'update');
					} else {
						showNotice(data.html || data.message, 'success');
					}
					return;
				}
				var msg =
					data && (data.html || data.message)
						? data.html || data.message
						: fundolarUpdateCheck.i18n.error;
				showNotice(msg, 'error');
			})
			.catch(function () {
				showNotice(fundolarUpdateCheck.i18n.error, 'error');
			})
			.finally(function () {
				link.removeAttribute('aria-busy');
				link.textContent = label;
			});
	}

	document.addEventListener('click', onClick);
})();
