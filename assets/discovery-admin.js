(function () {
	'use strict';

	function relabelSkuScanActions() {
		document.querySelectorAll('input[name="lpm_discovery_action"][value="run_small_discovery"]').forEach(function (input) {
			var form = input.closest('form');
			var button = form ? form.querySelector('button') : null;

			if (button) {
				button.textContent = 'Scan monitored SKUs';
			}
		});
	}

	function addFindMatchesHelper() {
		var heading = Array.prototype.find.call(document.querySelectorAll('h2'), function (item) {
			return item.textContent.trim() === 'Find Matches';
		});

		if (!heading || document.querySelector('[data-lpm-sku-scan-helper]')) {
			return;
		}

		var helper = document.createElement('p');
		helper.setAttribute('data-lpm-sku-scan-helper', '1');
		helper.textContent = 'Add a competitor website, then scan the SKUs from Products to Monitor. The assistant will suggest matches for review before anything is added to price monitoring.';
		heading.insertAdjacentElement('afterend', helper);
	}

	document.addEventListener('DOMContentLoaded', function () {
		relabelSkuScanActions();
		addFindMatchesHelper();
	});
})();
