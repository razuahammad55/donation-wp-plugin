/**
 * CDF Donation Form — front-end behaviour.
 *
 * Two amount modes:
 *  - default (data-custom-mode="0"): clicking a preset fills the number input.
 *  - custom  (data-custom-mode="1"): the input keeps its placeholder until the
 *    donor types; a typed amount overrides and unselects the preset.
 */
(function () {
	'use strict';

	function initForm(form) {
		var amountInput = form.querySelector('.cdf-amount-input');
		var presetField = form.querySelector('.cdf-preset-amount');
		var buttons = Array.prototype.slice.call(form.querySelectorAll('.cdf-amt-btn'));
		var submit = form.querySelector('.cdf-submit-btn');
		var freqRadios = Array.prototype.slice.call(form.querySelectorAll('input[name="donation_form_type"]'));
		var customMode = form.getAttribute('data-custom-mode') === '1';
		var defaultAmount = parseFloat(form.getAttribute('data-default-amount')) || 0;

		if (!amountInput || !buttons.length) {
			return;
		}

		/* ---- Preset selection helpers ---- */
		function setActive(activeButton) {
			buttons.forEach(function (button) {
				var isActive = button === activeButton;
				button.classList.toggle('active', isActive);
				button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
			});
		}

		function setPresetValue(value) {
			if (presetField) {
				presetField.value = value === null || value === undefined ? '' : String(value);
			}
		}

		function defaultButton() {
			var found = null;
			buttons.forEach(function (button) {
				if (parseFloat(button.getAttribute('data-val')) === defaultAmount) {
					found = button;
				}
			});
			return found || buttons[0];
		}

		/* ---- Preset clicks ---- */
		buttons.forEach(function (button) {
			button.addEventListener('click', function () {
				var value = button.getAttribute('data-val');
				setActive(button);
				setPresetValue(value);

				if (customMode) {
					// Choosing a preset clears any typed override.
					amountInput.value = '';
				} else {
					amountInput.value = value;
				}
			});
		});

		/* ---- Number input ---- */
		amountInput.addEventListener('input', function () {
			var typed = parseFloat(amountInput.value);
			var hasTyped = !isNaN(typed) && typed > 0;

			if (customMode) {
				if (hasTyped) {
					// Custom amount wins over the preset.
					setActive(null);
					setPresetValue('');
				} else {
					// Empty again: fall back to the default preset.
					var fallback = defaultButton();
					setActive(fallback);
					setPresetValue(fallback ? fallback.getAttribute('data-val') : '');
				}
				return;
			}

			// Default mode: keep the button highlight in sync with the value.
			var match = null;
			buttons.forEach(function (button) {
				if (parseFloat(button.getAttribute('data-val')) === typed) {
					match = button;
				}
			});
			setActive(match);
			setPresetValue(match ? match.getAttribute('data-val') : '');
		});

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

		/* ---- Submit validation + lock ---- */
		form.addEventListener('submit', function (event) {
			var email = form.querySelector('input[name="donor_email"]');
			var firstName = form.querySelector('input[name="donor_first"]');

			if (firstName && !firstName.value.trim()) {
				event.preventDefault();
				firstName.focus();
				return;
			}

			if (email && ((email.validity && email.validity.typeMismatch) || !email.value)) {
				event.preventDefault();
				email.focus();
				return;
			}

			var typed = parseFloat(amountInput.value);
			var preset = presetField ? parseFloat(presetField.value) : NaN;
			var effective = !isNaN(typed) && typed > 0 ? typed : preset;
			var min = parseFloat(amountInput.getAttribute('min')) || 0;
			var max = parseFloat(amountInput.getAttribute('max')) || Infinity;

			if (isNaN(effective) || effective <= 0) {
				event.preventDefault();
				amountInput.focus();
				return;
			}

			if (effective < min || effective > max) {
				event.preventDefault();
				amountInput.focus();
				return;
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
				window.setTimeout(function () {
					submit.classList.remove('is-loading');
					submit.removeAttribute('disabled');
				}, 20000);
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.slice.call(document.querySelectorAll('.cdf-form')).forEach(initForm);
	});
})();
