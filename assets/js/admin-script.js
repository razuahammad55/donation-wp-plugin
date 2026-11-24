/**
 * CDF Donation — admin behaviour.
 * Color pickers, style sub-tabs, donation modal, copy buttons, env toggle.
 */
(function ($) {
	'use strict';

	$(function () {
		/* ---- Color pickers ---- */
		if ($.fn.wpColorPicker) {
			$('.cdf-color-field').wpColorPicker();
		}

		/* ---- Style sub-tabs (Normal / Hover / Active) ---- */
		$('.cdf-subtab').on('click', function () {
			var target = $(this).data('subtab');
			$('.cdf-subtab').removeClass('active');
			$(this).addClass('active');
			$('.cdf-subpane').hide();
			$('#cdf-subpane-' + target).show();
		});

		/* ---- Environment toggle ---- */
		var $envSwitch = $('#cdf_env_switch');
		if ($envSwitch.length) {
			$envSwitch.on('change', function () {
				$('#cdf_env_label').text(
					this.checked
						? (window.cdfAdmin && window.cdfAdmin.liveLabel) || 'Live / Production'
						: (window.cdfAdmin && window.cdfAdmin.testLabel) || 'Test / Sandbox'
				);
				$('.cdf-env-block').toggle(!this.checked);
			});
		}

		/* ---- Copy to clipboard ---- */
		$('.cdf-copy').on('click', function () {
			var $btn = $(this);
			var text = $btn.data('copy') || '';
			var original = $btn.text();

			if (!text) {
				return;
			}

			var done = function () {
				$btn.text('Copied!');
				window.setTimeout(function () {
					$btn.text(original);
				}, 1500);
			};

			if (navigator.clipboard && window.isSecureContext) {
				navigator.clipboard.writeText(text).then(done);
			} else {
				var $temp = $('<textarea>').val(text).appendTo('body').select();
				try {
					document.execCommand('copy');
				} catch (e) {
					// Ignore: clipboard unavailable.
				}
				$temp.remove();
				done();
			}
		});

		/* ---- Donation modal ---- */
		var $modal = $('#cdf-donation-modal');
		var $body = $modal.find('.cdf-modal-body');

		function escapeHtml(value) {
			return String(value === null || value === undefined ? '' : value)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#39;');
		}

		function field(label, value, full) {
			return (
				'<div class="cdf-modal-field' + (full ? ' cdf-full' : '') + '">' +
				'<span>' + escapeHtml(label) + '</span>' +
				'<strong>' + escapeHtml(value || '—') + '</strong>' +
				'</div>'
			);
		}

		function openModal(data) {
			var symbol = (window.cdfAdmin && window.cdfAdmin.symbol) || '';
			var amount = symbol + Number(data.amount || 0).toFixed(2) + ' ' + (data.currency || '');
			var statusClass = 'cdf-status-' + String(data.status || 'completed').toLowerCase();
			var status = data.status || 'completed';

			var html =
				'<div class="cdf-modal-amount">' +
				'<div><span>' + escapeHtml((window.cdfAdmin && window.cdfAdmin.amountLabel) || 'Donation amount') + '</span><br>' +
				'<strong>' + escapeHtml(amount) + '</strong></div>' +
				'<span class="cdf-status ' + escapeHtml(statusClass) + '">' + escapeHtml(status.charAt(0).toUpperCase() + status.slice(1)) + '</span>' +
				'</div>' +
				'<div class="cdf-modal-grid">' +
				field((window.cdfAdmin && window.cdfAdmin.receiptLabel) || 'Receipt', '#' + (data.serial || data.id)) +
				field((window.cdfAdmin && window.cdfAdmin.dateLabel) || 'Date', data.date) +
				field((window.cdfAdmin && window.cdfAdmin.nameLabel) || 'Name', data.anonymous ? 'Anonymous donor' : data.name, true) +
				field((window.cdfAdmin && window.cdfAdmin.emailLabel) || 'Email', data.email, true) +
				field((window.cdfAdmin && window.cdfAdmin.phoneLabel) || 'Phone', data.phone) +
				field((window.cdfAdmin && window.cdfAdmin.gatewayLabel) || 'Gateway', data.gateway) +
				field((window.cdfAdmin && window.cdfAdmin.freqLabel) || 'Frequency', data.frequency) +
				field((window.cdfAdmin && window.cdfAdmin.envLabel) || 'Environment', data.environment) +
				field((window.cdfAdmin && window.cdfAdmin.txnLabel) || 'Transaction ID', data.transaction_id, true) +
				(data.subscription_id
					? field((window.cdfAdmin && window.cdfAdmin.subLabel) || 'Subscription ID', data.subscription_id, true)
					: '') +
				'</div>';

			$body.html(html);
			$modal.addClass('is-open').attr('aria-hidden', 'false');
			$modal.find('.cdf-modal-close').trigger('focus');
			$('body').css('overflow', 'hidden');
		}

		function closeModal() {
			$modal.removeClass('is-open').attr('aria-hidden', 'true');
			$('body').css('overflow', '');
		}

		$('.cdf-view-donation').on('click', function () {
			var raw = $(this).attr('data-donation');
			if (!raw) {
				return;
			}
			try {
				openModal(JSON.parse(raw));
			} catch (e) {
				// Ignore malformed payloads.
			}
		});

		$modal.on('click', '[data-close]', closeModal);

		$(document).on('keydown', function (event) {
			if (event.key === 'Escape' && $modal.hasClass('is-open')) {
				closeModal();
			}
		});

		/* ---- Keep modal focus inside while open ---- */
		$(document).on('keydown', function (event) {
			if (event.key !== 'Tab' || !$modal.hasClass('is-open')) {
				return;
			}
			var focusable = $modal.find('button, [href], input, select, textarea').filter(':visible');
			if (!focusable.length) {
				return;
			}
			var first = focusable[0];
			var last = focusable[focusable.length - 1];

			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		});
	});
})(window.jQuery);
