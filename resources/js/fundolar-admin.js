(function () {
	'use strict';

	function initPrimaryTabs(root) {
		if (!root) {
			return;
		}
		var bar = root.querySelector('.fundolar-tabs--primary');
		if (!bar) {
			return;
		}
		var buttons = bar.querySelectorAll('.fundolar-tabs__btn');
		var panels = root.querySelectorAll('.fundolar-tab-panel[data-panel]');
		if (!buttons.length || !panels.length) {
			return;
		}

		function activate(id) {
			if (!id) {
				return;
			}
			buttons.forEach(function (btn) {
				var on = btn.getAttribute('data-tab') === id;
				btn.classList.toggle('is-active', on);
				btn.setAttribute('aria-selected', on ? 'true' : 'false');
				btn.setAttribute('tabindex', on ? '0' : '-1');
			});
			panels.forEach(function (panel) {
				var match = panel.getAttribute('data-panel') === id;
				panel.hidden = !match;
				panel.setAttribute('aria-hidden', match ? 'false' : 'true');
			});
			try {
				history.replaceState(null, '', '#' + id);
			} catch (e) {}
		}

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				activate(btn.getAttribute('data-tab'));
			});
			btn.addEventListener('keydown', function (e) {
				var keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
				if (keys.indexOf(e.key) === -1) {
					return;
				}
				e.preventDefault();
				var i = Array.prototype.indexOf.call(buttons, btn);
				if (e.key === 'ArrowRight') {
					i = (i + 1) % buttons.length;
				} else if (e.key === 'ArrowLeft') {
					i = (i - 1 + buttons.length) % buttons.length;
				} else if (e.key === 'Home') {
					i = 0;
				} else if (e.key === 'End') {
					i = buttons.length - 1;
				}
				buttons[i].focus();
				activate(buttons[i].getAttribute('data-tab'));
			});
		});

		var hash = (window.location.hash || '').replace(/^#/, '');
		var valid = Array.prototype.some.call(buttons, function (b) {
			return b.getAttribute('data-tab') === hash;
		});
		activate(valid ? hash : buttons[0].getAttribute('data-tab'));
	}

	function initPaymentSubtabs(root) {
		if (!root) {
			return;
		}
		var payPanel = root.querySelector('.fundolar-tab-panel[data-panel="payments"]');
		if (!payPanel) {
			return;
		}
		var bar = payPanel.querySelector('.fundolar-tabs--sub');
		if (!bar) {
			return;
		}
		var buttons = bar.querySelectorAll('.fundolar-subtab__btn');
		var panels = payPanel.querySelectorAll('.fundolar-subtab-panel');
		if (!buttons.length || !panels.length) {
			return;
		}

		function activateSub(id) {
			if (!id) {
				return;
			}
			buttons.forEach(function (btn) {
				var on = btn.getAttribute('data-subtab') === id;
				btn.classList.toggle('is-active', on);
				btn.setAttribute('aria-selected', on ? 'true' : 'false');
				btn.setAttribute('tabindex', on ? '0' : '-1');
			});
			panels.forEach(function (panel) {
				var match = panel.getAttribute('data-subpanel') === id;
				panel.hidden = !match;
				panel.setAttribute('aria-hidden', match ? 'false' : 'true');
			});
		}

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				activateSub(btn.getAttribute('data-subtab'));
			});
		});

		var first = buttons[0].getAttribute('data-subtab');
		activateSub(first);
	}

	function initCopyButtons() {
		document.querySelectorAll('.fundolar-copy-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var sel = btn.getAttribute('data-copy-target');
				var el = sel ? document.querySelector(sel) : null;
				var text = el ? (el.textContent || '').trim() : (btn.getAttribute('data-copy') || '');
				if (!text) {
					return;
				}
				function done(ok) {
					var msg =
						typeof fundolarAdminL10n !== 'undefined'
							? ok
								? fundolarAdminL10n.copied
								: fundolarAdminL10n.copyFailed
							: ok
								? 'Copied'
								: 'Failed';
					var prev = btn.textContent;
					btn.textContent = msg;
					btn.disabled = true;
					setTimeout(function () {
						btn.textContent = prev;
						btn.disabled = false;
					}, 1600);
				}
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(
						function () {
							done(true);
						},
						function () {
							done(false);
						}
					);
				} else {
					var ta = document.createElement('textarea');
					ta.value = text;
					ta.style.position = 'fixed';
					ta.style.left = '-9999px';
					document.body.appendChild(ta);
					ta.select();
					try {
						done(document.execCommand('copy'));
					} catch (err) {
						done(false);
					}
					document.body.removeChild(ta);
				}
			});
		});
	}

	function initSupportCta() {
		var btn = document.getElementById('fundolar-support-focus-composer');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function () {
			var form = document.getElementById('fundolar-support-form');
			var topic = document.getElementById('fundolar_support_type');
			if (form && form.scrollIntoView) {
				form.scrollIntoView({ behavior: 'smooth', block: 'start' });
			}
			if (topic) {
				topic.focus();
			}
		});
	}

	function initSupportForm() {
		var form = document.getElementById('fundolar-support-form');
		if (!form || typeof fundolarAdminL10n === 'undefined') {
			return;
		}
		var thanks = document.getElementById('fundolar-support-thanks');
		var submitBtn = document.getElementById('fundolar-support-submit');
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			if (!form.reportValidity()) {
				return;
			}
			var fd = new FormData(form);
			fd.append('action', 'fundolar_support');
			fd.append('nonce', fundolarAdminL10n.supportNonce);
			if (thanks) {
				thanks.hidden = true;
			}
			if (submitBtn) {
				submitBtn.disabled = true;
			}
			fetch(fundolarAdminL10n.ajaxUrl, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (res && res.success) {
						form.reset();
						if (thanks) {
							thanks.hidden = false;
						}
					} else {
						var m =
							res && res.data && res.data.message
								? res.data.message
								: fundolarAdminL10n.supportError;
						window.alert(m);
					}
				})
				.catch(function () {
					window.alert(fundolarAdminL10n.supportError);
				})
				.finally(function () {
					if (submitBtn) {
						submitBtn.disabled = false;
					}
				});
		});
	}

	function initPaymentModePanels(root) {
		if (!root) {
			return;
		}
		var radios = root.querySelectorAll('.fundolar-mode-card__input');
		var panels = root.querySelectorAll('[data-fundolar-payment-panel]');
		var cards = root.querySelectorAll('.fundolar-mode-card');
		if (!radios.length) {
			return;
		}
		function syncPanels() {
			var mode = 'own_keys';
			radios.forEach(function (r) {
				if (r.checked) {
					mode = r.value;
				}
			});
			cards.forEach(function (c) {
				var input = c.querySelector('.fundolar-mode-card__input');
				c.classList.toggle('is-selected', input && input.checked);
			});
			panels.forEach(function (p) {
				var show = p.getAttribute('data-fundolar-payment-panel') === mode;
				p.hidden = !show;
			});
		}
		radios.forEach(function (r) {
			r.addEventListener('change', syncPanels);
		});
		var centralLink = root.querySelector('.fundolar-switch-central-link');
		if (centralLink) {
			centralLink.addEventListener('click', function (e) {
				e.preventDefault();
				radios.forEach(function (r) {
					if (r.value === 'central') {
						r.checked = true;
					}
				});
				syncPanels();
				var payTab = root.querySelector('[data-tab="payments"]');
				if (payTab) {
					payTab.click();
				}
			});
		}
		syncPanels();
	}

	function initAdminNoticeDismiss() {
		document.addEventListener('click', function (e) {
			var dismissBtn = e.target && e.target.closest ? e.target.closest('.notice-dismiss') : null;
			if (!dismissBtn) {
				return;
			}
			var wrap = dismissBtn.closest('.fundolar-admin-notice[data-fundolar-dismiss]');
			if (!wrap) {
				return;
			}
			var noticeType = wrap.getAttribute('data-fundolar-dismiss');
			if (!noticeType) {
				return;
			}
			var body = new URLSearchParams();
			body.append('action', 'fundolar_dismiss_admin_notice');
			body.append('notice_type', noticeType);
			body.append('nonce', fundolarAdminL10n.supportNonce);
			fetch(fundolarAdminL10n.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			}).catch(function () {});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var root = document.querySelector('.fundolar-settings-wrap');
		if (root) {
			initPrimaryTabs(root);
			initPaymentSubtabs(root);
			initSupportCta();
			initSupportForm();
		}
		initCopyButtons();
		if (typeof fundolarAdminL10n !== 'undefined') {
			initAdminNoticeDismiss();
		}
	});
})();
