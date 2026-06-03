(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.link-delete').forEach(function (button) {
			button.addEventListener('click', function (event) {
				if (!window.confirm('Remove this item from the active workflow?')) {
					event.preventDefault();
				}
			});
		});
	});
})();
