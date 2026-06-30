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

	function appendText(parent, value) {
		parent.appendChild(document.createTextNode(text(value)));
	}

	function addLink(parent, href, label) {
		if (!href) {
			return;
		}
		var link = document.createElement('a');
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		link.href = href;
		link.textContent = label;
		parent.appendChild(link);
	}

	function makeBadge(status) {
		var span = document.createElement('span');
		span.className = 'lpm-manual-status lpm-manual-status-' + status;
		span.textContent = {
			queued: 'queued',
			searching: 'searching',
			found: 'found',
			no_match: 'no match',
			error: 'error',
			approved: 'active monitored link',
			rejected: 'rejected',
			cancelled: 'cancelled'
		}[status] || status;
		return span;
	}

	function replaceStatus(tr, status) {
		var cell = tr.querySelector('[data-lpm-row-status]');
		if (cell) {
			cell.replaceChildren(makeBadge(status));
		}
	}

	function renderActions(cell, row) {
		cell.replaceChildren();
		if (row.competitor_url) {
			addLink(cell, row.competitor_url, 'Open URL');
			cell.appendChild(document.createTextNode(' '));
		}
		if (row.suggestion_id) {
			var approve = document.createElement('button');
			approve.type = 'button';
			approve.className = 'button button-primary';
			approve.dataset.lpmManualApprove = row.suggestion_id;
			approve.textContent = 'Approve';
			cell.appendChild(approve);
			cell.appendChild(document.createTextNode(' '));

			var reject = document.createElement('button');
			reject.type = 'button';
			reject.className = 'button';
			reject.dataset.lpmManualReject = row.suggestion_id;
			reject.textContent = 'Reject';
			cell.appendChild(reject);
			cell.appendChild(document.createTextNode(' '));
		}
		var retest = document.createElement('button');
		retest.type = 'button';
		retest.className = 'button';
		retest.dataset.lpmManualRetest = '1';
		retest.dataset.discoveryProductId = row.discovery_product_id || '';
		retest.dataset.competitorId = row.competitor_id || '';
		retest.textContent = 'Retest';
		cell.appendChild(retest);
	}

	function appendRow(panel, row) {
		var tbody = panel.querySelector('[data-lpm-manual-results] tbody');
		if (!tbody) {
			return;
		}
		var tr = document.createElement('tr');
		tr.dataset.suggestionId = row.suggestion_id || '';
		tr.dataset.discoveryProductId = row.discovery_product_id || '';
		tr.dataset.competitorId = row.competitor_id || '';

		var our = tr.insertCell();
		var strong = document.createElement('strong');
		strong.textContent = text(row.product_title);
		our.appendChild(strong);
		our.appendChild(document.createElement('br'));
		var ourSmall = document.createElement('small');
		ourSmall.textContent = 'SKU: ' + text(row.sku) + ' | EAN: ' + text(row.gtin);
		our.appendChild(ourSmall);

		var competitor = tr.insertCell();
		appendText(competitor, row.competitor_name);

		var source = tr.insertCell();
		addLink(source, row.search_url, 'Search/source');

		var status = tr.insertCell();
		status.dataset.lpmRowStatus = '1';
		status.appendChild(makeBadge(row.status));

		var competitorProduct = tr.insertCell();
		appendText(competitorProduct, row.competitor_title);
		if (row.competitor_url) {
			competitorProduct.appendChild(document.createElement('br'));
			addLink(competitorProduct, row.competitor_url, 'Product page');
		}

		var detected = tr.insertCell();
		var detectedSmall = document.createElement('small');
		detectedSmall.textContent = 'SKU: ' + text(row.detected_sku) + ' | EAN: ' + text(row.detected_gtin);
		detected.appendChild(detectedSmall);
		detected.appendChild(document.createElement('br'));
		appendText(detected, row.detected_price);

		var confidence = tr.insertCell();
		appendText(confidence, row.confidence);
		if (row.match_type) {
			confidence.appendChild(document.createElement('br'));
			var matchType = document.createElement('small');
			matchType.textContent = row.match_type;
			confidence.appendChild(matchType);
		}
		if (row.caution) {
			confidence.appendChild(document.createElement('br'));
			var caution = document.createElement('strong');
			caution.textContent = row.caution;
			confidence.appendChild(caution);
		}

		var reason = tr.insertCell();
		appendText(reason, row.match_reason || row.error);
		if (row.details) {
			var details = document.createElement('details');
			var summary = document.createElement('summary');
			summary.textContent = 'Details';
			var pre = document.createElement('pre');
			pre.textContent = row.details;
			details.appendChild(summary);
			details.appendChild(pre);
			reason.appendChild(details);
		}

		var actions = tr.insertCell();
		actions.dataset.lpmRowActions = '1';
		renderActions(actions, row);

		tbody.appendChild(tr);
	}

	function setProgress(panel, run, message) {
		var progress = panel.querySelector('[data-lpm-manual-progress]');
		var status = panel.querySelector('[data-lpm-manual-status]');
		var counts = panel.querySelector('[data-lpm-manual-counts]');
		var bar = panel.querySelector('[data-lpm-manual-progress-bar]');
		var cancel = panel.querySelector('[data-lpm-manual-cancel]');
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
		if (cancel) {
			cancel.hidden = !(run.status === 'running');
		}
	}

	function shouldConfirmBeforeCreate(panel, productValue, competitorValue) {
		var selectedProductCount = parseInt(panel.dataset.selectedProductCount || '0', 10);
		var activeCompetitorCount = parseInt(panel.dataset.activeCompetitorCount || '0', 10);
		return (String(productValue || '0') === '0' && selectedProductCount > 1) || (String(competitorValue || '0') === '0' && activeCompetitorCount > 1);
	}

	function processRun(panel, runId) {
		if (panel.dataset.cancelRequested === '1') {
			return;
		}
		post('lpm_manual_discovery_process', { run_id: runId, batch_size: 1 }).then(function (data) {
			(data.rows || []).forEach(function (row) {
				appendRow(panel, row);
			});
			setProgress(panel, data, data.status === 'completed' ? window.LPM_DISCOVERY.i18n.complete : window.LPM_DISCOVERY.i18n.processing);
			if (data.status === 'cancelled') {
				setProgress(panel, data, window.LPM_DISCOVERY.i18n.cancelled);
				return;
			}
			if (data.status !== 'completed') {
				window.setTimeout(function () {
					processRun(panel, runId);
				}, Math.max(250, (data.wait_seconds || 0) * 1000));
			}
		}).catch(function (error) {
			setProgress(panel, { processed: 0, total: 1, found: 0, errors: 1, status: 'error' }, error.message);
		});
	}

	function startRun(panel, productValue, competitorValue, isRetest) {
		var table = panel.querySelector('[data-lpm-manual-results]');
		var tbody = table ? table.querySelector('tbody') : null;
		if (tbody && !isRetest) {
			tbody.innerHTML = '';
		}
		if (table) {
			table.hidden = false;
		}
		panel.dataset.cancelRequested = '0';
		setProgress(panel, { processed: 0, total: 1, found: 0, errors: 0, status: 'queued' }, isRetest ? 'Starting targeted retest...' : window.LPM_DISCOVERY.i18n.starting);

		var action = isRetest ? 'lpm_manual_discovery_retest' : 'lpm_manual_discovery_create';
		post(action, {
			discovery_product_id: productValue || 0,
			competitor_id: competitorValue || 0
		}).then(function (data) {
			var run = data.run;
			panel.dataset.currentRunId = run.run_id || '';
			setProgress(panel, run, run.large_run ? window.LPM_DISCOVERY.i18n.largeRun : window.LPM_DISCOVERY.i18n.processing);
			processRun(panel, run.run_id);
		}).catch(function (error) {
			setProgress(panel, { processed: 0, total: 1, found: 0, errors: 1, status: 'error' }, error.message);
		});
	}

	function startPanelRun(panel) {
		var product = panel.querySelector('[data-lpm-manual-product]');
		var competitor = panel.querySelector('[data-lpm-manual-competitor]');
		var productValue = product ? product.value : 0;
		var competitorValue = competitor ? competitor.value : 0;
		if (shouldConfirmBeforeCreate(panel, productValue, competitorValue) && !window.confirm(window.LPM_DISCOVERY.i18n.confirmLarge)) {
			return;
		}
		startRun(panel, productValue, competitorValue, false);
	}

	function cancelRun(panel) {
		var runId = panel.dataset.currentRunId || '';
		if (!runId) {
			panel.dataset.cancelRequested = '1';
			setProgress(panel, { processed: 0, total: 1, found: 0, errors: 0, status: 'cancelled' }, window.LPM_DISCOVERY.i18n.cancelled);
			return;
		}
		panel.dataset.cancelRequested = '1';
		post('lpm_manual_discovery_cancel', { run_id: runId }).then(function (data) {
			setProgress(panel, data, window.LPM_DISCOVERY.i18n.cancelled);
		}).catch(function (error) {
			window.alert(error.message);
		});
	}

	function bindPanel(panel) {
		var start = panel.querySelector('[data-lpm-manual-start]');
		var cancel = panel.querySelector('[data-lpm-manual-cancel]');
		if (start) {
			start.addEventListener('click', function () {
				startPanelRun(panel);
			});
		}
		if (cancel) {
			cancel.addEventListener('click', function () {
				cancelRun(panel);
			});
		}
		panel.addEventListener('click', function (event) {
			var approve = event.target.closest('[data-lpm-manual-approve]');
			var reject = event.target.closest('[data-lpm-manual-reject]');
			var retest = event.target.closest('[data-lpm-manual-retest]');
			if (approve) {
				post('lpm_manual_discovery_approve', { suggestion_id: approve.dataset.lpmManualApprove }).then(function (data) {
					var tr = approve.closest('tr');
					if (tr) {
						replaceStatus(tr, 'approved');
						tr.querySelector('[data-lpm-row-actions]').textContent = data.message || window.LPM_DISCOVERY.i18n.activeLink;
					}
				}).catch(function (error) {
					window.alert(error.message);
				});
			}
			if (reject) {
				post('lpm_manual_discovery_reject', { suggestion_id: reject.dataset.lpmManualReject }).then(function (data) {
					var tr = reject.closest('tr');
					if (tr) {
						replaceStatus(tr, 'rejected');
						tr.querySelector('[data-lpm-row-actions]').textContent = data.message || 'Rejected';
					}
				}).catch(function (error) {
					window.alert(error.message);
				});
			}
			if (retest) {
				var productId = retest.dataset.discoveryProductId || (retest.closest('tr') ? retest.closest('tr').dataset.discoveryProductId : '');
				var competitorId = retest.dataset.competitorId || (retest.closest('tr') ? retest.closest('tr').dataset.competitorId : '');
				if (productId && competitorId) {
					startRun(panel, productId, competitorId, true);
				} else {
					startPanelRun(panel);
				}
			}
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		Array.prototype.forEach.call(document.querySelectorAll('[data-lpm-manual-discovery-panel]'), bindPanel);
	});
})();
