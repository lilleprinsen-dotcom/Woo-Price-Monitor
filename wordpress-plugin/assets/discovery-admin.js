(function () {
	'use strict';

	function post(action, data) {
		if (!window.LPM_DISCOVERY || !window.LPM_DISCOVERY.ajaxUrl) {
			return Promise.reject(new Error('Manual discovery assets did not load correctly. Refresh the page and try again.'));
		}
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

	function safeUrl(value) {
		try {
			var url = new URL(text(value), window.location.href);
			return ['http:', 'https:'].indexOf(url.protocol) !== -1 ? url.href : '';
		} catch (error) {
			return '';
		}
	}

	function htmlToText(value) {
		var template = document.createElement('template');
		template.innerHTML = text(value);
		return text(template.content.textContent).trim();
	}

	function debounce(fn, delay) {
		var timer = 0;
		return function () {
			var args = arguments;
			window.clearTimeout(timer);
			timer = window.setTimeout(function () {
				fn.apply(null, args);
			}, delay);
		};
	}

	function appendText(parent, value) {
		parent.appendChild(document.createTextNode(text(value)));
	}

	function addLink(parent, href, label) {
		href = safeUrl(href);
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

	function appendDefinition(list, label, value) {
		var dt = document.createElement('dt');
		dt.textContent = label;
		var dd = document.createElement('dd');
		dd.textContent = text(value) || '—';
		list.appendChild(dt);
		list.appendChild(dd);
	}

	function appendReviewImage(parent, src, label) {
		src = safeUrl(src);
		if (src) {
			var img = document.createElement('img');
			img.src = src;
			img.alt = label || '';
			img.loading = 'lazy';
			parent.appendChild(img);
			return;
		}
		var placeholder = document.createElement('span');
		placeholder.className = 'lpm-approval-image-placeholder';
		placeholder.textContent = 'No image';
		parent.appendChild(placeholder);
	}

	function closeApprovalModal() {
		var modal = document.querySelector('[data-lpm-approval-modal]');
		if (modal) {
			modal.remove();
		}
	}

	function openDiscoveryModal() {
		var modal = document.querySelector('[data-lpm-discovery-modal]');
		if (!modal) {
			return null;
		}
		modal.hidden = false;
		document.documentElement.classList.add('lpm-modal-open');
		var close = modal.querySelector('[data-lpm-close-discovery-modal]');
		if (close && close.focus) {
			close.focus();
		}
		return modal;
	}

	function closeDiscoveryModal() {
		var modal = document.querySelector('[data-lpm-discovery-modal]');
		if (modal) {
			modal.hidden = true;
		}
		document.documentElement.classList.remove('lpm-modal-open');
	}

	function approveLiveSuggestion(button) {
		post('lpm_manual_discovery_approve', { suggestion_id: button.dataset.lpmManualApprove }).then(function (data) {
			var tr = button.closest('tr');
			if (tr) {
				tr.classList.remove('lpm-discovery-result-found');
				tr.classList.add('lpm-discovery-result-approved');
				tr.dataset.status = 'approved';
				replaceStatus(tr, 'approved');
				tr.querySelector('[data-lpm-row-actions]').textContent = data.message || window.LPM_DISCOVERY.i18n.activeLink;
				var panel = tr.closest('[data-lpm-manual-discovery-panel]');
				if (panel) {
					updateRunSummary(panel, { status: 'completed', found: 0, errors: 0 }, 'Active monitored link');
				}
			}
			closeApprovalModal();
		}).catch(function (error) {
			window.alert(error.message);
		});
	}

	function showLiveApprovalModal(button) {
		var tr = button.closest('tr');
		if (!tr) {
			approveLiveSuggestion(button);
			return;
		}
		closeApprovalModal();

		var modal = document.createElement('div');
		modal.className = 'lpm-approval-modal';
		modal.dataset.lpmApprovalModal = '1';
		var dialog = document.createElement('div');
		dialog.className = 'lpm-approval-dialog';
		dialog.setAttribute('role', 'dialog');
		dialog.setAttribute('aria-modal', 'true');
		dialog.setAttribute('aria-label', 'Review competitor match before approval');
		modal.appendChild(dialog);

		var header = document.createElement('div');
		header.className = 'lpm-approval-header';
		var title = document.createElement('h2');
		title.textContent = 'Review match before approval';
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'button-link';
		close.textContent = 'Close';
		close.addEventListener('click', closeApprovalModal);
		header.appendChild(title);
		header.appendChild(close);
		dialog.appendChild(header);

		var note = document.createElement('p');
		note.className = 'lpm-approval-note';
		note.textContent = 'Approval creates an active monitored competitor link. Check identifiers, image, variant, confidence and evidence first.';
		dialog.appendChild(note);

		var grid = document.createElement('div');
		grid.className = 'lpm-approval-compare';
		var our = document.createElement('section');
		var competitor = document.createElement('section');
		var ourHeading = document.createElement('h3');
		var competitorHeading = document.createElement('h3');
		ourHeading.textContent = 'Our product';
		competitorHeading.textContent = 'Competitor product';
		our.appendChild(ourHeading);
		competitor.appendChild(competitorHeading);
		appendReviewImage(our, tr.dataset.productImageUrl || '', tr.dataset.productTitle || '');
		appendReviewImage(competitor, tr.dataset.competitorImageUrl || '', tr.dataset.competitorTitle || '');

		var ourList = document.createElement('dl');
		var competitorList = document.createElement('dl');
		appendDefinition(ourList, 'Title', tr.dataset.productTitle || '');
		appendDefinition(ourList, 'SKU', tr.dataset.sku || '');
		appendDefinition(ourList, 'EAN/GTIN', tr.dataset.gtin || '');
		appendDefinition(ourList, 'Brand', tr.dataset.brand || '');
		appendDefinition(competitorList, 'Title', tr.dataset.competitorTitle || '');
		appendDefinition(competitorList, 'SKU', tr.dataset.detectedSku || '');
		appendDefinition(competitorList, 'EAN/GTIN', tr.dataset.detectedGtin || '');
		appendDefinition(competitorList, 'Price', tr.dataset.detectedPrice || '');
		our.appendChild(ourList);
		competitor.appendChild(competitorList);
		if (tr.dataset.competitorUrl) {
			var linkWrap = document.createElement('p');
			addLink(linkWrap, tr.dataset.competitorUrl, 'Open competitor page');
			competitor.appendChild(linkWrap);
		}
		grid.appendChild(our);
		grid.appendChild(competitor);
		dialog.appendChild(grid);

		var evidence = document.createElement('section');
		evidence.className = 'lpm-approval-evidence';
		var evidenceTitle = document.createElement('h3');
		evidenceTitle.textContent = 'Evidence and warnings';
		evidence.appendChild(evidenceTitle);
		var confidence = document.createElement('p');
		confidence.innerHTML = '<strong>Confidence:</strong> ';
		var badge = makeBadge('queued');
		badge.className = 'lpm-confidence-badge lpm-confidence-' + String(tr.dataset.confidence || 'unknown').toLowerCase().replace(/[^a-z0-9]+/g, '-');
		badge.textContent = tr.dataset.confidence || 'unknown';
		confidence.appendChild(badge);
		evidence.appendChild(confidence);
		if (tr.dataset.matchType) {
			var matchType = document.createElement('p');
			matchType.innerHTML = '<strong>Match type:</strong> ';
			appendText(matchType, tr.dataset.matchType);
			evidence.appendChild(matchType);
		}
		if (tr.dataset.caution) {
			var caution = document.createElement('p');
			caution.className = 'lpm-approval-warning';
			caution.textContent = tr.dataset.caution;
			evidence.appendChild(caution);
		}
		var reason = document.createElement('p');
		reason.innerHTML = '<strong>Evidence:</strong> ';
		appendText(reason, tr.dataset.matchReason || 'No evidence text was returned.');
		evidence.appendChild(reason);
		dialog.appendChild(evidence);

		var footer = document.createElement('div');
		footer.className = 'lpm-approval-actions';
		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'button';
		cancel.textContent = 'Cancel';
		cancel.addEventListener('click', closeApprovalModal);
		var confirm = document.createElement('button');
		confirm.type = 'button';
		confirm.className = 'button button-primary';
		confirm.textContent = 'Approve as active monitored link';
		confirm.addEventListener('click', function () {
			confirm.disabled = true;
			approveLiveSuggestion(button);
		});
		footer.appendChild(cancel);
		footer.appendChild(confirm);
		dialog.appendChild(footer);

		document.body.appendChild(modal);
		confirm.focus();
	}

	function makeBadge(status) {
		var span = document.createElement('span');
		span.className = 'lpm-manual-status lpm-manual-status-' + status + ' lpm-pill';
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

	function initDiscoveryProductSearch() {
		var form = document.querySelector('[data-lpm-discovery-product-search-form]');
		var input = document.querySelector('[data-lpm-discovery-product-search-input]');
		var results = document.querySelector('[data-lpm-discovery-product-search-results]');
		var status = document.querySelector('[data-lpm-discovery-product-search-status]');

		if (!form || !input || !results) {
			return;
		}

		function setStatus(message) {
			if (status) {
				status.textContent = message;
			}
		}

		function runSearch() {
			var query = input.value.trim();
			if (!query) {
				results.replaceChildren();
				setStatus(window.LPM_DISCOVERY.i18n.productSearchHint || 'Search starts after 3 characters, or immediately for a numeric product ID.');
				return;
			}
			if (!/^\d+$/.test(query) && query.length < 3) {
				results.replaceChildren();
				setStatus(window.LPM_DISCOVERY.i18n.productSearchShort || 'Type at least 3 characters, or enter a numeric product ID.');
				return;
			}

			setStatus(window.LPM_DISCOVERY.i18n.searchingProducts || 'Searching products...');
			post('lpm_discovery_search_products', { query: query })
				.then(function (data) {
					renderDiscoveryProductResults(results, data.products || []);
					setStatus(data.message || ((data.products || []).length + ' products found.'));
				})
				.catch(function (error) {
					results.replaceChildren();
					setStatus(error.message);
				});
		}

		input.addEventListener('input', debounce(runSearch, 220));
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			runSearch();
		});
	}

	function renderDiscoveryProductResults(target, products) {
		target.replaceChildren();
		if (!products.length) {
			var empty = document.createElement('p');
			empty.className = 'description';
			empty.textContent = window.LPM_DISCOVERY.i18n.noProducts || 'No products found.';
			target.appendChild(empty);
			return;
		}

		var table = document.createElement('table');
		table.className = 'widefat striped';
		var thead = table.createTHead();
		var headRow = thead.insertRow();
		['Product', 'SKU', 'Price', 'Stock', 'Action'].forEach(function (label) {
			var th = document.createElement('th');
			th.scope = 'col';
			th.textContent = label;
			headRow.appendChild(th);
		});

		var tbody = table.createTBody();
		products.forEach(function (product) {
			var tr = tbody.insertRow();
			var productCell = tr.insertCell();
			var name = document.createElement('strong');
			name.textContent = product.name || ('Product #' + text(product.id));
			productCell.appendChild(name);
			productCell.appendChild(document.createElement('br'));
			var id = document.createElement('small');
			id.textContent = 'ID ' + text(product.id);
			productCell.appendChild(id);
			appendText(tr.insertCell(), product.sku || '');

			var price = tr.insertCell();
			price.textContent = htmlToText(product.price_html) || '—';
			if (!price.textContent.trim()) {
				price.textContent = '—';
			}

			appendText(tr.insertCell(), product.stock_status || 'unknown');

			var action = tr.insertCell();
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'button button-primary';
			button.dataset.lpmDiscoveryAddProduct = product.id || '';
			button.textContent = window.LPM_DISCOVERY.i18n.addToDiscovery || 'Add to discovery';
			action.appendChild(button);
		});

		target.appendChild(table);
	}

	function addDiscoveryProduct(button) {
		var productId = button.dataset.lpmDiscoveryAddProduct || '';
		if (!productId) {
			return;
		}

		button.disabled = true;
		post('lpm_discovery_add_product', { product_id: productId })
			.then(function (data) {
				button.textContent = window.LPM_DISCOVERY.i18n.addedToDiscovery || 'Added';
				button.classList.remove('button-primary');
				addProductToManualDiscoveryControls(data);
				var status = document.querySelector('[data-lpm-discovery-product-search-status]');
				if (status) {
					status.textContent = data.message || 'Product added to competitor discovery.';
				}
			})
			.catch(function (error) {
				button.disabled = false;
				var status = document.querySelector('[data-lpm-discovery-product-search-status]');
				if (status) {
					status.textContent = error.message;
				}
			});
	}

	function addProductToManualDiscoveryControls(data) {
		var panel = document.querySelector('[data-lpm-manual-discovery-panel]');
		var select = panel ? panel.querySelector('[data-lpm-manual-product]') : null;
		var selectedId = data && data.discovery_product_id ? String(data.discovery_product_id) : '';
		if (!panel || !select || !selectedId) {
			return;
		}

		var exists = Array.prototype.some.call(select.options, function (option) {
			return option.value === selectedId;
		});
		if (!exists) {
			var option = document.createElement('option');
			option.value = selectedId;
			option.textContent = data.product_label || ('Product #' + text(data.product_id));
			select.appendChild(option);
		}
		select.value = selectedId;

		var selectedCount = parseInt(panel.dataset.selectedProductCount || '0', 10);
		panel.dataset.selectedProductCount = String(Math.max(exists ? selectedCount : selectedCount + 1, select.options.length - 1));

		var start = panel.querySelector('[data-lpm-manual-start]');
		var competitorCount = parseInt(panel.dataset.activeCompetitorCount || '0', 10);
		if (start && competitorCount > 0) {
			start.disabled = false;
		}
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
			addLink(cell, row.competitor_url, 'Open');
			cell.appendChild(document.createTextNode(' '));
		}
		if (row.suggestion_id) {
			var approve = document.createElement('button');
			approve.type = 'button';
			approve.className = 'button button-primary';
			approve.dataset.lpmManualApprove = row.suggestion_id;
			approve.textContent = 'Approve';
			cell.appendChild(approve);

			var more = document.createElement('details');
			more.className = 'lpm-row-actions';
			var summary = document.createElement('summary');
			summary.textContent = 'More';
			more.appendChild(summary);
			var reject = document.createElement('button');
			reject.type = 'button';
			reject.className = 'button';
			reject.dataset.lpmManualReject = row.suggestion_id;
			reject.textContent = 'Reject';
			more.appendChild(reject);
			cell.appendChild(more);
		}
		var retest = document.createElement('button');
		retest.type = 'button';
		retest.className = 'button';
		retest.dataset.lpmManualRetest = '1';
		retest.dataset.discoveryProductId = row.discovery_product_id || '';
		retest.dataset.competitorId = row.competitor_id || '';
		retest.textContent = 'Retest';
		var actionDetails = cell.querySelector('details.lpm-row-actions');
		if (!actionDetails) {
			actionDetails = document.createElement('details');
			actionDetails.className = 'lpm-row-actions';
			var actionSummary = document.createElement('summary');
			actionSummary.textContent = 'More';
			actionDetails.appendChild(actionSummary);
			cell.appendChild(actionDetails);
		}
		actionDetails.appendChild(retest);
	}

	function appendRow(panel, row) {
		var tbody = panel.querySelector('[data-lpm-manual-results] tbody');
		if (!tbody) {
			return;
		}
		var tr = document.createElement('tr');
		tr.className = 'lpm-discovery-result-row lpm-discovery-result-' + (row.status || 'queued');
		tr.dataset.status = row.status || 'queued';
		tr.dataset.suggestionId = row.suggestion_id || '';
		tr.dataset.discoveryProductId = row.discovery_product_id || '';
		tr.dataset.competitorId = row.competitor_id || '';
		tr.dataset.productTitle = text(row.product_title);
		tr.dataset.sku = text(row.sku);
		tr.dataset.gtin = text(row.gtin);
		tr.dataset.brand = text(row.brand);
		tr.dataset.competitorTitle = text(row.competitor_title);
		tr.dataset.competitorUrl = text(row.competitor_url);
		tr.dataset.competitorImageUrl = text(row.competitor_image_url);
		tr.dataset.detectedSku = text(row.detected_sku);
		tr.dataset.detectedGtin = text(row.detected_gtin);
		tr.dataset.detectedPrice = text(row.detected_price);
		tr.dataset.confidence = text(row.confidence);
		tr.dataset.matchType = text(row.match_type);
		tr.dataset.caution = text(row.caution);
		tr.dataset.matchReason = text(row.match_reason);

		var our = tr.insertCell();
		var strong = document.createElement('strong');
		strong.textContent = text(row.product_title) || 'Selected product';
		our.appendChild(strong);
		our.appendChild(document.createElement('br'));
		var ourSmall = document.createElement('small');
		ourSmall.textContent = 'SKU: ' + text(row.sku) + ' | EAN: ' + text(row.gtin);
		our.appendChild(ourSmall);

		var competitor = tr.insertCell();
		appendText(competitor, row.competitor_name);

		var source = tr.insertCell();
		if (row.search_url) {
			addLink(source, row.search_url, 'Search/source');
		} else {
			appendText(source, 'Source not reported');
		}

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
		var confidenceBadge = document.createElement('span');
		confidenceBadge.className = 'lpm-confidence-badge lpm-confidence-' + String(row.confidence || 'unknown').toLowerCase().replace(/[^a-z0-9]+/g, '-');
		confidenceBadge.textContent = row.confidence || 'unknown';
		confidence.appendChild(confidenceBadge);
		if (row.match_type) {
			confidence.appendChild(document.createElement('br'));
			var matchType = document.createElement('small');
			matchType.textContent = row.match_type;
			confidence.appendChild(matchType);
		}
		if (row.caution) {
			confidence.appendChild(document.createElement('br'));
			var caution = document.createElement('strong');
			caution.className = 'lpm-discovery-caution';
			caution.textContent = row.caution;
			confidence.appendChild(caution);
		}

		var reason = tr.insertCell();
		if (row.error || row.status === 'no_match' || row.status === 'error') {
			var reasonStrong = document.createElement('strong');
			reasonStrong.textContent = text(row.error || row.match_reason || 'No match found');
			reason.appendChild(reasonStrong);
		} else {
			appendText(reason, row.match_reason || row.error);
		}
		if (row.details) {
			var details = document.createElement('details');
			details.className = 'lpm-discovery-details';
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

	function updateRunSummary(panel, run, message) {
		var summary = panel.querySelector('[data-lpm-manual-summary]');
		if (!summary) {
			return;
		}
		var rows = Array.prototype.slice.call(panel.querySelectorAll('[data-lpm-manual-results] tbody tr'));
		var issueCount = rows.filter(function (row) {
			return row.dataset.status === 'no_match' || row.dataset.status === 'error';
		}).length;
		var reviewCount = panel.querySelectorAll('[data-lpm-manual-approve]').length;
		var title = summary.querySelector('[data-lpm-manual-summary-title]');
		var copy = summary.querySelector('[data-lpm-manual-summary-copy]');
		var found = summary.querySelector('[data-lpm-manual-summary-found]');
		var review = summary.querySelector('[data-lpm-manual-summary-review]');
		var issues = summary.querySelector('[data-lpm-manual-summary-issues]');

		summary.hidden = false;
		if (title) {
			title.textContent = message || 'Searching competitor products';
		}
		if (copy) {
			if (run.status === 'completed') {
				copy.textContent = reviewCount > 0 ? 'Search complete. Review and approve only the matches that are clearly correct.' : 'Search complete. No approval-ready matches were found; open Details on rows to see why.';
			} else if (run.status === 'cancelled') {
				copy.textContent = 'Search cancelled. Any findings already created are still saved for review.';
			} else {
				copy.textContent = 'The plugin is checking competitor search pages and product pages. You can close this window; findings remain saved in Suggestions.';
			}
		}
		if (found) {
			found.textContent = String(run.found || reviewCount || 0);
		}
		if (review) {
			review.textContent = String(reviewCount);
		}
		if (issues) {
			issues.textContent = String(Math.max(issueCount, run.errors || 0));
		}
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
		updateRunSummary(panel, run, message);
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

	function startShortcut(productId, competitorId) {
		var panel = document.querySelector('[data-lpm-manual-discovery-panel]');
		if (!panel) {
			return;
		}
		openDiscoveryModal();
		var product = panel.querySelector('[data-lpm-manual-product]');
		var competitor = panel.querySelector('[data-lpm-manual-competitor]');
		if (product) {
			product.value = productId || '0';
		}
		if (competitor) {
			competitor.value = competitorId || '0';
		}
		startPanelRun(panel);
		if (!document.querySelector('[data-lpm-discovery-modal]') && panel.scrollIntoView) {
			panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
		}
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
		if (panel.dataset.lpmManualBound === '1') {
			return;
		}
		panel.dataset.lpmManualBound = '1';
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
				showLiveApprovalModal(approve);
			}
			if (reject) {
				post('lpm_manual_discovery_reject', { suggestion_id: reject.dataset.lpmManualReject }).then(function (data) {
					var tr = reject.closest('tr');
					if (tr) {
						tr.classList.remove('lpm-discovery-result-found');
						tr.classList.add('lpm-discovery-result-rejected');
						tr.dataset.status = 'rejected';
						replaceStatus(tr, 'rejected');
						tr.querySelector('[data-lpm-row-actions]').textContent = data.message || 'Rejected';
						updateRunSummary(panel, { status: 'completed', found: 0, errors: 0 }, 'Rejected');
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

	function bindDiscoveryUi() {
		if (document.documentElement.dataset.lpmDiscoveryUiBound === '1') {
			return;
		}
		document.documentElement.dataset.lpmDiscoveryUiBound = '1';
		Array.prototype.forEach.call(document.querySelectorAll('[data-lpm-manual-discovery-panel]'), bindPanel);
		document.addEventListener('click', function (event) {
			var addDiscoveryProductButton = event.target.closest('[data-lpm-discovery-add-product]');
			var productStart = event.target.closest('[data-lpm-start-product]');
			var competitorStart = event.target.closest('[data-lpm-start-competitor]');
			var closeDiscovery = event.target.closest('[data-lpm-close-discovery-modal]');
			if (addDiscoveryProductButton) {
				event.preventDefault();
				addDiscoveryProduct(addDiscoveryProductButton);
				return;
			}
			if (closeDiscovery) {
				event.preventDefault();
				closeDiscoveryModal();
				return;
			}
			if (productStart) {
				event.preventDefault();
				startShortcut(productStart.dataset.lpmStartProduct || '0', '0');
				return;
			}
			if (competitorStart) {
				event.preventDefault();
				startShortcut('0', competitorStart.dataset.lpmStartCompetitor || '0');
				return;
			}
		});
		document.addEventListener('lpm:start-discovery', function (event) {
			var detail = event.detail || {};
			startShortcut(detail.productId || '0', detail.competitorId || '0');
		});
		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') {
				closeDiscoveryModal();
			}
		});
		initDiscoveryProductSearch();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bindDiscoveryUi);
	} else {
		bindDiscoveryUi();
	}
})();
