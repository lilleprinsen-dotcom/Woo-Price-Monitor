(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var tabs = document.querySelectorAll('[data-lpm-tab-target]');
		var panels = document.querySelectorAll('[data-lpm-tab-panel]');

		if (!tabs.length || !panels.length) {
			return;
		}

		function activateTab(tabKey, updateUrl) {
			tabs.forEach(function (tab) {
				var isActive = tab.getAttribute('data-lpm-tab-target') === tabKey;
				tab.classList.toggle('is-active', isActive);
				tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
			});

			panels.forEach(function (panel) {
				panel.classList.toggle('is-active', panel.getAttribute('data-lpm-tab-panel') === tabKey);
			});

			if (updateUrl && window.history && window.history.replaceState) {
				var url = new URL(window.location.href);
				url.searchParams.set('tab', tabKey);
				window.history.replaceState({}, '', url.toString());
			}
		}

		tabs.forEach(function (tab) {
			tab.addEventListener('click', function (event) {
				var tabKey = tab.getAttribute('data-lpm-tab-target');

				if (!tabKey) {
					return;
				}

				event.preventDefault();
				activateTab(tabKey, true);
			});
		});
	});
})();
