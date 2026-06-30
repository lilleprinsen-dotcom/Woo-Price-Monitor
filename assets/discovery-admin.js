(function () {
	'use strict';

	function post(action, data) {
		var form = new window.FormData();
		form.append('action', action);
		form.append('nonce', window.LPM_DISCOVERY ? window.LPM_DISCOVERY.nonce : '');
		Object.keys(data || {}).forEach(function (key) {
			form.append(key, data[key]);
		});

		return window.fetch(window.LPM_DISCOVERY.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		}).then(function (response) {
			return response.json();
		}).then(function (json) {
			if (!json || !json.success) {
				throw new Error(json && json.data && json.data.message ? json.data.message : window.LPM_DISCOVERY.i18n.error);
			}
			return json.data;
		});
	}

	function text(value) {
		return value === null || value === undefined ? '' : String(value);
	}

	function badge(status) {
		var label = {
			queued: 'queued',
			searching: 'searching',
			found: 'found',
			no_match: 'no match',
			error: 'error',
			approved: 'active monitored link',
			rejected: 'rejected'
		}[status] || status;
		return '<span class="lpm-manual-status lpm-manual-status-' + status + '">' + label + '</span>';
	}

	function renderActions(row) {
		var html = '';
		if (row.competitor_url) {
			html += '<a class="button" target="_blank" rel="noopener noreferrer" href="' + encodeURI(row.competitor_url) + '">Open URL</a> ';
		}
		if (row.suggestion_id) {
			html += '<button type="button" class="button button-primary" data-lpm-manual-approve="' + row.suggestion_id + '">Approve</button> ';
			html += '<button type="button" class="button" data-lpm-manual-reject="' + row.suggestion_id + '">Reject</button> ';
			html += '<button type="button" class="button" data-lpm-manual-retest>Retest</button>';
		}
		return html;
	}

	function appendRow(panel, row) {
		var tbody = panel.querySelector('[data-lpm-manual-results] tbody');
		if (!tbody) {
			return;
		}
		var tr = document.createElement('tr');
		tr.setAttribute('data-suggestion-id', row.suggestion_id || '');
		tr.innerHTML = [
			'<td><strong>' + escapeHtml(row.product_title) + '</strong><br><small>SKU: ' + escapeHtml(row.sku) + '<br>EAN: ' + escapeHtml(row.gtin) + '</small></td>',
			'<td>' + escapeHtml(row.competitor_name) + '</td>',
			'<td>' + (row.search_url ? '<a target="_blank" rel="noopener noreferrer" href="' + encodeURI(row.search_url) + '">Search/source</a>' : '') + '</td>',
			'<td data-lpm-row-status>' + badge(row.status) + '</td>',
			'<td>' + escapeHtml(row.competitor_title) + (row.competitor_url ? '<br><a target="_blank" rel="noopener noreferrer" href="' + encodeURI(row.competitor_url) + '">Product page</a>' : '') + '</td>',
			'<td><small>SKU: ' + escapeHtml(row.detected_sku) + '<br>EAN: ' + escapeHtml(row.detected_gtin) + '</small><br>' + escapeHtml(row.detected_price) + '</td>',
			'<td>' + escapeHtml(row.confidence) + '</td>',
			'<td>' + escapeHtml(row.match_reason || row.error) + (row.details ? '<details><summary>Details</summary><pre>' + escapeHtml(row.details) + '</pre></details>' : '') + '</td>',
			'<td data-lpm-row-actions>' + renderActions(row) + '</td>'
		].join('');
		tbody.appendChild(tr);
	}

	function escapeHtml(value) {
		return text(value).replace(/[&<>"']/g, function (char) {
			return {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;'
			}[char];
		});
	}

	function setProgress(panel, run, message) {
		var progress = panel.querySelector('[data-lpm-manual-progress]');
		var status = panel.querySelector('[data-lpm-manual-status]');
		var counts = panel.querySelector('[data-lpm-manual-counts]');
		var bar = panel.querySelector('[data-lpm-manual-progress-bar]');
		if (progress) {
			progress.hidden = false;
		}
		if (status) {
			status.textContent = message || run.status;
		}
		if (counts) {
			counts.textContent = run.processed + ' / ' + run.total + ' processed, ' + run.found + ' found, ' + run.errors + ' errors';
		}
		if (bar) {
			bar.max = Math.max(1, run.total || 1);
			bar.value = run.processed || 0;
		}
	}

	function processRun(panel, runId) {
		post('lpm_manual_discovery_process', { run_id: runId, batch_size: 1 }).then(function (data) {
			(data.rows || []).forEach(function (row) {
				appendRow(panel, row);
			});
			setProgress(panel, data, data.status === 'completed' ? window.LPM_DISCOVERY.i18n.complete : window.LPM_DISCOVERY.i18n.processing);
			if (data.status !== 'completed') {
				window.setTimeout(function () {
					processRun(panel, runId);
				}, Math.max(250, (data.wait_seconds || 0) * 1000));
			}
		}).catch(function (error) {
			setProgress(panel, { processed: 0, total: 1, found: 0, errors: 1, status: 'error' }, error.message);
		});
	}

	function startRun(panel) {
		var product = panel.querySelector('[data-lpm-manual-product]');
		var competitor = panel.querySelector('[data-lpm-manual-competitor]');
		var table = panel.querySelector('[data-lpm-manual-results]');
		var tbody = table ? table.querySelector('tbody') : null;
		if (tbody) {
			tbody.innerHTML = '';
		}
		if (table) {
			table.hidden = false;
		}
		setProgress(panel, { processed: 0, total: 1, found: 0, errors: 0, status: 'queued' }, window.LPM_DISCOVERY.i18n.starting);

		post('lpm_manual_discovery_create', {
			discovery_product_id: product ? product.value : 0,
			competitor_id: competitor ? competitor.value : 0
		}).then(function (data) {
			var run = data.run;
			if (run.large_run && ! window.confirm(window.LPM_DISCOVERY.i18n.confirmLarge)) {
				setProgress(panel, run, 'Cancelled');
				return;
			}
			setProgress(panel, run, run.large_run ? window.LPM_DISCOVERY.i18n.largeRun : window.LPM_DISCOVERY.i18n.processing);
			processRun(panel, run.run_id);
		}).catch(function (error) {
			setProgress(panel, { processed: 0, total: 1, found: 0, errors: 1, status: 'error' }, error.message);
		});
	}

	function bindPanel(panel) {
		var start = panel.querySelector('[data-lpm-manual-start]');
		if (start) {
			start.addEventListener('click', function () {
				startRun(panel);
			});
		}
		panel.addEventListener('click', function (event) {
			var approve = event.target.closest('[data-lpm-manual-approve]');
			var reject = event.target.closest('[data-lpm-manual-reject]');
			var retest = event.target.closest('[data-lpm-manual-retest]');
			if (approve) {
				post('lpm_manual_discovery_approve', { suggestion_id: approve.getAttribute('data-lpm-manual-approve') }).then(function (data) {
					var tr = approve.closest('tr');
					if (tr) {
						tr.querySelector('[data-lpm-row-status]').innerHTML = badge('approved');
						tr.querySelector('[data-lpm-row-actions]').innerHTML = '<strong>' + escapeHtml(data.message || window.LPM_DISCOVERY.i18n.activeLink) + '</strong>';
					}
				}).catch(function (error) {
					window.alert(error.message);
				});
			}
			if (reject) {
				post('lpm_manual_discovery_reject', { suggestion_id: reject.getAttribute('data-lpm-manual-reject') }).then(function (data) {
					var tr = reject.closest('tr');
					if (tr) {
						tr.querySelector('[data-lpm-row-status]').innerHTML = badge('rejected');
						tr.querySelector('[data-lpm-row-actions]').innerHTML = escapeHtml(data.message || 'Rejected');
					}
				}).catch(function (error) {
					window.alert(error.message);
				});
			}
			if (retest) {
				var currentPanel = retest.closest('[data-lpm-manual-discovery-panel]');
				if (currentPanel) {
					startRun(currentPanel);
				}
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-lpm-manual-discovery-panel]'), bindPanel);
	});
})();
