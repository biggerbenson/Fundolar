(function () {
	'use strict';

	var cfg = typeof fundolarForm === 'undefined' ? null : fundolarForm;
	if (!cfg) {
		return;
	}

	var nonceExpiresAt = 0;
	var NONCE_TTL_MS = 45000;

	function ensureFreshNonce() {
		if (!cfg.restUrl) {
			return Promise.resolve();
		}
		if (Date.now() < nonceExpiresAt) {
			return Promise.resolve();
		}
		var url = cfg.restUrl.replace(/\/?$/, '/') + 'bootstrap';
		url += (url.indexOf('?') === -1 ? '?' : '&') + '_fundolar_ts=' + Date.now();
		return fetch(url, {
			method: 'GET',
			credentials: 'same-origin',
			cache: 'no-store',
			headers: { Accept: 'application/json' },
		})
			.then(function (r) {
				if (!r.ok) {
					throw new Error('bootstrap_http');
				}
				return r.text().then(function (text) {
					try {
						return JSON.parse(text);
					} catch (e) {
						throw new Error('bootstrap_json');
					}
				});
			})
			.then(function (d) {
				if (d && d.nonce) {
					cfg.nonce = d.nonce;
				}
				if (d && d.rest_nonce) {
					cfg.restNonce = d.rest_nonce;
				}
				nonceExpiresAt = Date.now() + NONCE_TTL_MS;
			})
			.catch(function () {
				nonceExpiresAt = Date.now() + 8000;
			});
	}

	function $(sel, ctx) {
		return (ctx || document).querySelector(sel);
	}

	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = src;
			s.async = true;
			s.onload = function () {
				resolve();
			};
			s.onerror = reject;
			document.head.appendChild(s);
		});
	}

	function apiPost(path, body, retriedInvalidNonce) {
		return ensureFreshNonce().then(function () {
			var headers = {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			};
			if (cfg.restNonce) {
				headers['X-WP-Nonce'] = cfg.restNonce;
			}
			var payload = Object.assign({ nonce: cfg.nonce || '' }, body);
			return fetch(cfg.restUrl.replace(/\/?$/, '/') + path.replace(/^\//, ''), {
				method: 'POST',
				credentials: 'same-origin',
				cache: 'no-store',
				headers: headers,
				body: JSON.stringify(payload),
			}).then(function (r) {
				return r.text().then(function (text) {
					var j = null;
					if (text) {
						try {
							j = JSON.parse(text);
						} catch (e) {
							j = null;
						}
					}
					if (!r.ok) {
						var msg =
							(j && (j.message || (j.data && j.data.message))) || cfg.i18n.error;
						var code = j && j.code ? String(j.code) : '';
						if (
							!retriedInvalidNonce &&
							(code === 'fundolar_nonce' ||
								/invalid security token/i.test(String(msg)))
						) {
							nonceExpiresAt = 0;
							return ensureFreshNonce().then(function () {
								return apiPost(path, body, true);
							});
						}
						throw new Error(msg);
					}
					if (!j) {
						throw new Error(cfg.i18n.error);
					}
					return j;
				});
			});
		});
	}

	ensureFreshNonce().then(function () {
		var root = document.querySelector('[data-fundolar-root]');
		if (!root) {
			return;
		}

		function getReturnUrl() {
			try {
				var u = String(window.location.href || '').split('#')[0];
				return u || cfg.returnUrl || '';
			} catch (e) {
				return cfg.returnUrl || '';
			}
		}

		var form = $('#fundolar-donate-form', root);
		var msgEl = $('#fundolar-message', root);
		var cardWrap = $('#fundolar-card-element', root);
		var paypalWrap = $('#fundolar-paypal-container', root);
		var submitBtn = $('.fundolar-submit', root);
		var stripeInstance = null;
		var cardElement = null;
		var paypalRendered = false;

		if (!form) {
			return;
		}

		function setMsg(text, kind) {
			msgEl.textContent = text || '';
			msgEl.classList.remove('is-error', 'is-success');
			if (kind) {
				msgEl.classList.add(kind);
			}
		}

		function selectedGateway() {
			var g = form.querySelector('input[name="gateway"]:checked');
			return g ? g.value : '';
		}

		function roundMoney2(v) {
			var n = Number(v);
			if (!isFinite(n) || n <= 0) {
				return 0;
			}
			return Math.round(n * 100) / 100;
		}

		function normalizeCurrency(code) {
			return String(code || '').trim().toUpperCase().slice(0, 3);
		}

		function getFxRates() {
			var fallback = {
				USD: 1,
				EUR: 0.92,
				GBP: 0.79,
				NGN: 1550,
				KES: 130,
				GHS: 15.5,
				ZAR: 18.8,
				UGX: 3800,
				TZS: 2580,
				RWF: 1320,
				ZMW: 27,
				MWK: 1730,
				BIF: 2880,
			};
			if (!cfg.fxRates || typeof cfg.fxRates !== 'object') {
				return fallback;
			}
			var out = {};
			Object.keys(cfg.fxRates).forEach(function (k) {
				var key = normalizeCurrency(k);
				var val = Number(cfg.fxRates[k]);
				if (!key || !isFinite(val) || val <= 0) {
					return;
				}
				out[key] = val;
			});
			if (!out.USD) {
				out.USD = 1;
			}
			return out;
		}

		function rateForCurrency(code) {
			var rates = getFxRates();
			var key = normalizeCurrency(code);
			return rates[key] && isFinite(rates[key]) ? Number(rates[key]) : 1;
		}

		function convertCurrency(amount, fromCode, toCode) {
			var fromRate = rateForCurrency(fromCode);
			var toRate = rateForCurrency(toCode);
			var n = Number(amount);
			if (!isFinite(n) || n <= 0 || fromRate <= 0 || toRate <= 0) {
				return 0;
			}
			var usd = n / fromRate;
			return usd * toRate;
		}

		var amtInput = $('#fundolar-custom-amount', root);
		var curSel = $('#fundolar-currency', root);
		var coverCb = $('#fundolar-cover-fees', root);
		var baseCurrency = normalizeCurrency(cfg.fxBaseCurrency || 'USD') || 'USD';
		var lastCurrency = normalizeCurrency((curSel && curSel.value) || cfg.currency || baseCurrency);
		var currentUsdAmount = 0;

		function selectedCurrencySymbol() {
			if (!curSel || !curSel.options || curSel.selectedIndex < 0) {
				return '$';
			}
			var opt = curSel.options[curSel.selectedIndex];
			return (opt && opt.getAttribute('data-symbol')) || '$';
		}

		function getFeeRate() {
			var r =
				typeof cfg.platformFeeRate === 'number'
					? cfg.platformFeeRate
					: parseFloat(cfg.platformFeeRate);
			if (!isFinite(r) || r < 0 || r >= 1) {
				return 0.035;
			}
			return r;
		}

		function getBaseAmount() {
			var v = amtInput ? parseFloat(amtInput.value) : NaN;
			return isFinite(v) && v > 0 ? v : 0;
		}

		function coverFeesDelta(base) {
			var r = getFeeRate();
			if (base <= 0 || r <= 0 || r >= 1) {
				return 0;
			}
			return base / (1 - r) - base;
		}

		function getPayableAmount() {
			var base = getBaseAmount();
			if (base <= 0) {
				return 0;
			}
			if (coverCb && coverCb.checked) {
				var r = getFeeRate();
				if (r >= 1) {
					return roundMoney2(base);
				}
				return roundMoney2(base / (1 - r));
			}
			return roundMoney2(base);
		}

		function formatMoneyShort(amount) {
			var sym = selectedCurrencySymbol();
			var s = amount.toFixed(2);
			if (s.slice(-3) === '.00') {
				s = s.slice(0, -3);
			}
			return sym + s;
		}

		function updateCoverFeesSum() {
			var el = $('#fundolar-cover-fees-sum', root);
			if (!el) {
				return;
			}
			var base = getBaseAmount();
			if (base <= 0) {
				el.textContent = '—';
				return;
			}
			el.textContent = formatMoneyShort(coverFeesDelta(base));
		}

		function ensurePresetUsdData() {
			root.querySelectorAll('.fundolar-preset').forEach(function (btn) {
				var usdVal = btn.getAttribute('data-usd-amount');
				if (usdVal !== null && usdVal !== '') {
					return;
				}
				var raw = btn.getAttribute('data-amount');
				if (raw === null || raw === '') {
					return;
				}
				btn.setAttribute('data-usd-amount', String(raw));
			});
		}

		function updatePresetLabels() {
			var selectedCur = normalizeCurrency((curSel && curSel.value) || cfg.currency || baseCurrency);
			var sym = selectedCurrencySymbol();
			root.querySelectorAll('.fundolar-preset').forEach(function (btn) {
				var a = btn.getAttribute('data-usd-amount');
				if (a === null || a === '') {
					return;
				}
				var usdAmount = parseFloat(a);
				if (!isFinite(usdAmount) || usdAmount <= 0) {
					return;
				}
				var converted = roundMoney2(convertCurrency(usdAmount, baseCurrency, selectedCur));
				btn.setAttribute('data-amount', String(converted));
				btn.textContent =
					sym +
					(Math.round(converted) === converted
						? String(Math.round(converted))
						: converted.toFixed(2));
			});
		}

		function syncUsdAmountFromInput(sourceCurrency) {
			if (!amtInput) {
				return;
			}
			var entered = parseFloat(amtInput.value);
			if (!isFinite(entered) || entered <= 0) {
				return;
			}
			currentUsdAmount = convertCurrency(entered, sourceCurrency || lastCurrency, baseCurrency);
		}

		function setCustomAmountFromUsd(usdAmount, targetCurrency) {
			if (!amtInput) {
				return;
			}
			var usd = Number(usdAmount);
			if (!isFinite(usd) || usd <= 0) {
				return;
			}
			var converted = roundMoney2(convertCurrency(usd, baseCurrency, targetCurrency || lastCurrency));
			if (converted > 0) {
				amtInput.value = converted.toFixed(2);
			}
		}

		function donorFullName() {
			var f = ($('#fundolar-first-name', root) && $('#fundolar-first-name', root).value) || '';
			var l = ($('#fundolar-last-name', root) && $('#fundolar-last-name', root).value) || '';
			return (String(f).trim() + ' ' + String(l).trim()).trim();
		}

		function validateDonorInputs() {
			var first = $('#fundolar-first-name', root);
			var emailInput = $('#fundolar-email', root);
			if (first && typeof first.checkValidity === 'function' && !first.checkValidity()) {
				if (typeof first.reportValidity === 'function') {
					first.reportValidity();
				}
				return false;
			}
			if (emailInput && typeof emailInput.checkValidity === 'function' && !emailInput.checkValidity()) {
				if (typeof emailInput.reportValidity === 'function') {
					emailInput.reportValidity();
				}
				return false;
			}
			return true;
		}

		function validateAmountInput() {
			var amountInput = $('#fundolar-custom-amount', root);
			if (amountInput && typeof amountInput.checkValidity === 'function' && !amountInput.checkValidity()) {
				if (typeof amountInput.reportValidity === 'function') {
					amountInput.reportValidity();
				}
				return false;
			}
			return true;
		}

		function refreshGatewayAvailabilityByCurrency() {
			var currency = ($('#fundolar-currency', root).value || '').trim().toUpperCase();
			var allowPaystack = currency === 'KES';
			var pesapalList = Array.isArray(cfg.pesapalCurrencies)
				? cfg.pesapalCurrencies.map(function (c) {
						return String(c || '').trim().toUpperCase();
				  })
				: ['UGX', 'KES', 'TZS', 'NGN', 'GHS', 'RWF', 'ZMW', 'MWK', 'BIF'];
			var allowPesapal = pesapalList.indexOf(currency) !== -1;
			var selectedInput = form.querySelector('input[name="gateway"]:checked');
			form.querySelectorAll('input[name="gateway"]').forEach(function (input) {
				var tile = input.closest('.fundolar-gateway');
				if (input.value === 'paystack' && !allowPaystack) {
					input.checked = false;
					input.disabled = true;
					if (tile) {
						tile.style.display = 'none';
					}
					return;
				}
				if (input.value === 'pesapal' && !allowPesapal) {
					input.checked = false;
					input.disabled = true;
					if (tile) {
						tile.style.display = 'none';
					}
					return;
				}
				input.disabled = false;
				if (tile) {
					tile.style.display = '';
				}
			});
			if (!selectedInput || selectedInput.disabled) {
				var fallback = form.querySelector('input[name="gateway"]:not(:disabled)');
				if (fallback) {
					fallback.checked = true;
				}
			}
		}

		function syncPresets() {
			var custom = $('#fundolar-custom-amount', root);
			root.querySelectorAll('.fundolar-preset').forEach(function (btn) {
				btn.addEventListener('click', function () {
					root.querySelectorAll('.fundolar-preset').forEach(function (b) {
						b.classList.remove('is-selected');
					});
					btn.classList.add('is-selected');
					custom.value = btn.getAttribute('data-amount') || '';
					syncUsdAmountFromInput(lastCurrency);
					updateCoverFeesSum();
				});
			});
		}

		function ensureStripe() {
			if (window.Stripe) {
				return Promise.resolve();
			}
			return loadScript('https://js.stripe.com/v3/');
		}

		function mountStripe() {
			var pk = cfg.stripePk && String(cfg.stripePk).trim();
			if (!pk) {
				setMsg(cfg.i18n.needsKeys || cfg.i18n.error, 'is-error');
				return Promise.reject(new Error('fundolar_no_stripe_key'));
			}
			return ensureStripe().then(function () {
				stripeInstance = window.Stripe(cfg.stripePk);
				var elements = stripeInstance.elements();
				if (cardElement) {
					try {
						cardElement.unmount();
					} catch (e) {}
				}
				cardElement = elements.create('card', { hidePostalCode: true });
				cardWrap.innerHTML = '';
				cardElement.mount(cardWrap);
				cardWrap.hidden = false;
			});
		}

		function destroyStripeUi() {
			if (cardElement) {
				try {
					cardElement.unmount();
				} catch (e) {}
				cardElement = null;
			}
			cardWrap.hidden = true;
			cardWrap.innerHTML = '';
		}

		function ensurePaypal() {
			var cid = cfg.paypalClient && String(cfg.paypalClient).trim();
			if (!cid) {
				setMsg(cfg.i18n.needsKeys || cfg.i18n.error, 'is-error');
				return Promise.reject(new Error('fundolar_no_paypal_client'));
			}
			var cur = $('#fundolar-currency', root).value || cfg.currency || 'USD';
			var src =
				'https://www.paypal.com/sdk/js?client-id=' +
				encodeURIComponent(String(cfg.paypalClient).trim()) +
				'&currency=' +
				encodeURIComponent(cur) +
				'&intent=capture';
			if (window.paypal) {
				return Promise.resolve();
			}
			return loadScript(src);
		}

		function renderPaypalButtons() {
			paypalWrap.hidden = false;
			submitBtn.hidden = true;
			if (paypalRendered) {
				return;
			}
			paypalRendered = true;
			window.paypal
				.Buttons({
					style: { layout: 'vertical', shape: 'rect' },
					createOrder: function () {
						setMsg('');
						if (!validateDonorInputs()) {
							setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
							return Promise.reject(new Error(cfg.i18n.validation || cfg.i18n.error));
						}
						if (!validateAmountInput()) {
							setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
							return Promise.reject(new Error(cfg.i18n.validation || cfg.i18n.error));
						}
						var name = donorFullName();
						var email = ($('#fundolar-email', root).value || '').trim();
						if (!name || !email) {
							setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
							return Promise.reject(new Error(cfg.i18n.validation || cfg.i18n.error));
						}
						var amount = getPayableAmount();
						var currency = ($('#fundolar-currency', root).value || '').trim().toUpperCase().slice(0, 3);
						if (amount <= 0 || currency.length !== 3) {
							setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
							return Promise.reject(new Error(cfg.i18n.validation || cfg.i18n.error));
						}
						return apiPost('init-payment', {
							gateway: 'paypal',
							name: name,
							email: email,
							amount: amount,
							currency: currency,
							return_url: getReturnUrl(),
						}).then(function (data) {
							return data.order_id;
						});
					},
					onApprove: function (data) {
						return apiPost('paypal/capture', { order_id: data.orderID }).then(function () {
							setMsg(cfg.i18n.success, 'is-success');
						});
					},
					onError: function (err) {
						var m = cfg.i18n.error;
						if (err && err.message) {
							m = String(err.message);
						}
						setMsg(m, 'is-error');
					},
				})
				.render('#fundolar-paypal-container');
		}

		function onGatewayChange() {
			refreshGatewayAvailabilityByCurrency();
			var g = selectedGateway();
			destroyStripeUi();
			paypalWrap.innerHTML = '';
			paypalWrap.hidden = true;
			submitBtn.hidden = false;
			paypalRendered = false;

			if (g === 'stripe') {
				mountStripe().catch(function () {
					setMsg(cfg.i18n.error, 'is-error');
				});
			} else if (g === 'paypal') {
				ensurePaypal()
					.then(function () {
						renderPaypalButtons();
					})
					.catch(function () {
						setMsg(cfg.i18n.error, 'is-error');
					});
			}
		}

		form.querySelectorAll('input[name="gateway"]').forEach(function (r) {
			r.addEventListener('change', onGatewayChange);
		});

		if (coverCb) {
			coverCb.addEventListener('change', updateCoverFeesSum);
		}
		if (amtInput) {
			amtInput.addEventListener('input', updateCoverFeesSum);
			amtInput.addEventListener('change', updateCoverFeesSum);
			amtInput.addEventListener('input', function () {
				syncUsdAmountFromInput(lastCurrency);
			});
			amtInput.addEventListener('change', function () {
				syncUsdAmountFromInput(lastCurrency);
			});
		}
		if (curSel) {
			curSel.addEventListener('change', function () {
				var newCurrency = normalizeCurrency(curSel.value || baseCurrency);
				syncUsdAmountFromInput(lastCurrency);
				updatePresetLabels();
				setCustomAmountFromUsd(currentUsdAmount, newCurrency);
				updateCoverFeesSum();
				refreshGatewayAvailabilityByCurrency();
				onGatewayChange();
				lastCurrency = newCurrency;
			});
		}

		ensurePresetUsdData();
		syncPresets();
		updatePresetLabels();
		syncUsdAmountFromInput(lastCurrency);
		setCustomAmountFromUsd(currentUsdAmount, lastCurrency);
		updateCoverFeesSum();
		refreshGatewayAvailabilityByCurrency();
		onGatewayChange();

		form.addEventListener('submit', function (e) {
			var g = selectedGateway();
			if (g === 'paypal') {
				e.preventDefault();
				return;
			}
			e.preventDefault();
			setMsg('');

			if (!validateDonorInputs()) {
				setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
				return;
			}
			if (!validateAmountInput()) {
				setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
				return;
			}

			var name = donorFullName();
			var email = $('#fundolar-email', root).value.trim();
			if (!name) {
				setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
				return;
			}
			var amount = getPayableAmount();
			var currency = ($('#fundolar-currency', root).value || '').trim().toUpperCase().slice(0, 3);
			if (amount <= 0 || currency.length !== 3) {
				setMsg(cfg.i18n.validation || cfg.i18n.error, 'is-error');
				return;
			}

			if (g === 'stripe') {
				submitBtn.disabled = true;
				apiPost('init-payment', {
					gateway: 'stripe',
					name: name,
					email: email,
					amount: amount,
					currency: currency,
					return_url: getReturnUrl(),
				})
					.then(function (data) {
						if (!stripeInstance || !cardElement) {
							throw new Error(cfg.i18n.error);
						}
						return stripeInstance.confirmCardPayment(data.client_secret, {
							payment_method: {
								card: cardElement,
								billing_details: { name: name, email: email },
							},
						});
					})
					.then(function (result) {
						if (result.error) {
							throw new Error(result.error.message || cfg.i18n.error);
						}
						var pid = result.paymentIntent && result.paymentIntent.id;
						if (!pid) {
							throw new Error(cfg.i18n.error);
						}
						return apiPost('stripe/sync-intent', { payment_intent: pid });
					})
					.then(function () {
						setMsg(cfg.i18n.success, 'is-success');
					})
					.catch(function (err) {
						setMsg(err.message || cfg.i18n.error, 'is-error');
					})
					.finally(function () {
						submitBtn.disabled = false;
					});
				return;
			}

			if (g === 'paystack' || g === 'flutterwave') {
				if (g === 'paystack' && currency !== 'KES') {
					setMsg(cfg.i18n.paystackKesOnly || cfg.i18n.error, 'is-error');
					return;
				}
				submitBtn.disabled = true;
				apiPost('init-payment', {
					gateway: g,
					name: name,
					email: email,
					amount: amount,
					currency: currency,
					return_url: getReturnUrl(),
				})
					.then(function (data) {
						if (data.authorization_url) {
							window.location.href = data.authorization_url;
						} else {
							throw new Error(cfg.i18n.error);
						}
					})
					.catch(function (err) {
						setMsg(err.message || cfg.i18n.error, 'is-error');
						submitBtn.disabled = false;
					});
				return;
			}

			if (g === 'pesapal') {
				var allowedPesapalCurrencies = Array.isArray(cfg.pesapalCurrencies)
					? cfg.pesapalCurrencies.map(function (c) {
							return String(c || '').trim().toUpperCase();
					  })
					: ['UGX', 'KES', 'TZS', 'NGN', 'GHS', 'RWF', 'ZMW', 'MWK', 'BIF'];
				if (allowedPesapalCurrencies.indexOf(currency) === -1) {
					setMsg(cfg.i18n.pesapalCurrencyOnly || cfg.i18n.error, 'is-error');
					return;
				}
				submitBtn.disabled = true;
				apiPost('init-payment', {
					gateway: g,
					name: name,
					email: email,
					amount: amount,
					currency: currency,
					return_url: getReturnUrl(),
				})
					.then(function (data) {
						if (data && data.authorization_url) {
							window.location.href = data.authorization_url;
							return;
						}
						throw new Error((data && data.message) || cfg.i18n.error);
					})
					.catch(function (err) {
						setMsg(err.message || cfg.i18n.error, 'is-error');
						submitBtn.disabled = false;
					});
				return;
			}

			setMsg(cfg.i18n.error, 'is-error');
		});
	});
})();
