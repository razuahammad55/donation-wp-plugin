/**
 * CDF Donation Form — front-end behaviour.
 * Preset amount sync, gateway selection, submit lock, inline validation.
 */
(function () {
	'use strict';

	function initForm(form) {
		var amountInput = form.querySelector('.cdf-amount-input');
		var buttons = Array.prototype.slice.call(form.querySelectorAll('.cdf-amt-btn'));
		var submit = form.querySelector('.cdf-submit-btn');
		var freqRadios = Array.prototype.slice.call(form.querySelectorAll('input[name="donation_form_type"]'));

		/* ---- Preset amounts ---- */
		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				var value = button.getAttribute('data-val');
				if (amountInput) {
					amountInput.value = value;
				}
				setActive(button);
			});
		});

		function setActive(activeButton) {
			buttons.forEach(function (button) {
				var isActive = button === activeButton;
				button.classList.toggle('active', isActive);
				button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			});
		}

		if (amountInput) {
			amountInput.addEventListener('input', function () {
				var value = parseFloat(amountInput.value);
				var match = null;
				buttons.forEach(function (button) {
					if (parseFloat(button.getAttribute('data-val')) === value) {
						match = button;
					}
				});
				buttons.forEach(function (button) {
					var isActive = button === match;
					button.classList.toggle('active', isActive);
					button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
				});
			});
		}

		/* ---- Gateway selection ---- */
		var gateways = Array.prototype.slice.call(form.querySelectorAll('.cdf-gateway-row'));
		gateways.forEach(function (row) {
			var radio = row.querySelector('input[type="radio"]');
			if (!radio) {
				return;
			}
			radio.addEventListener('change', function () {
				gateways.forEach(function (other) {
					var otherRadio = other.querySelector('input[type="radio"]');
					other.classList.toggle('active', !!(otherRadio && otherRadio.checked));
				});
			});
		});

		/* ---- Frequency switch changes button label ---- */
		var submitLabel = submit ? submit.querySelector('.cdf-submit-label') : null;
		var oneTimeText = form.getAttribute('data-onetime-text') || (submitLabel ? submitLabel.textContent : '');
		var monthlyText = form.getAttribute('data-monthly-text') || oneTimeText;

		freqRadios.forEach(function (radio) {
			radio.addEventListener('change', function () {
				if (!submitLabel || !radio.checked) {
					return;
				}
				submitLabel.textContent = radio.value === 'monthly' ? monthlyText : oneTimeText;
			});
		});

		/* ---- Submit lock + basic validation ---- */
		form.addEventListener('submit', function (event) {
			var email = form.querySelector('input[name="donor_email"]');
			var firstName = form.querySelector('input[name="donor_first"]');

			if (firstName && !firstName.value.trim()) {
				event.preventDefault();
				firstName.focus();
				return;
			}

			if (email && (email.validity && email.validity.typeMismatch || !email.value)) {
				event.preventDefault();
				email.focus();
				return;
			}

			if (amountInput) {
				var amount = parseFloat(amountInput.value);
				var min = parseFloat(amountInput.getAttribute('min')) || 0;
				var max = parseFloat(amountInput.getAttribute('max')) || Infinity;
				if (isNaN(amount) || amount < min || amount > max) {
					event.preventDefault();
					amountInput.focus();
					return;
				}
			}

			if (submit) {
				if (submit.classList.contains('is-loading')) {
					event.preventDefault();
					return;
				}
				submit.classList.add('is-loading');
				submit.setAttribute('disabled', 'disabled');
				var label = submit.querySelector('.cdf-submit-label');
				if (label) {
					label.textContent = submit.getAttribute('data-loading') || label.textContent;
				}
				// Re-enable if the browser restores the page from cache.
				window.setTimeout(function () {
					submit.classList.remove('is-loading');
					submit.removeAttribute('disabled');
				}, 20000);
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.slice
			.call(document.querySelectorAll('.cdf-form'))
			.forEach(initForm);
	});
})();
