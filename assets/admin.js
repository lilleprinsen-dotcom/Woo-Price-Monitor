(function () {
	'use strict';

	var config = window.LPM_ADMIN || {};
	var drawerData = null;
	var drawerTab = 'summary';
	var selectedSuggestionId = 0;
	var selectedSuggestionStatus = '';

	function text(value) {
		return value === null || value === undefined || value === '' ? '—' : String(value);
	}

	function escapeHtml(value) {
		return text(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function ajax(action, data) {
		var form = new FormData();
		form.append('action', action);
		form.append('nonce', config.nonce || '');

		Object.keys(data || {}).forEach(function (key) {
			form.append(key, data[key]);
		});

		return window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		}).then(function (response) {
			return response.json().then(function (json) {
				if (!response.ok || !json.success) {
					throw new Error((json.data && json.data.message) || (config.i18n && config.i18n.error) || 'Request failed.');
				}

				return json.data || {};
			});
		});
	}

	function toast(message, type) {
		var region = document.querySelector('.lpm-toast-region');

		if (!region) {
			return;
		}

		var item = document.createElement('div');
		item.className = 'lpm-toast lpm-toast-' + (type || 'success');
		item.textContent = message;
		region.appendChild(item);

		window.setTimeout(function () {
			item.classList.add('is-hiding');
			window.setTimeout(function () {
				item.remove();
			}, 220);
		}, 4200);
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

	function initDeleteConfirmations() {
		document.querySelectorAll('.link-delete').forEach(function (button) {
			button.addEventListener('click', function (event) {
				if (!window.confirm('Remove this item from the active workflow?')) {
					event.preventDefault();
				}
			});
		});
	}

	function initProductSearch() {
		var form = document.querySelector('[data-lpm-product-search-form]');
		var input = document.querySelector('[data-lpm-product-search-input]');
		var results = document.querySelector('[data-lpm-product-search-results]');
		var status = document.querySelector('[data-lpm-product-search-status]');

		if (!form || !input || !results) {
			return;
		}

		function runSearch() {
			var query = input.value.trim();

			if (!query) {
				results.innerHTML = '';
				return;
			}

			if (!/^\d+$/.test(query) && query.length < 3) {
				if (status) {
					status.textContent = 'Type at least 3 characters, or enter a numeric product ID.';
				}
				return;
			}

			if (status) {
				status.textContent = (config.i18n && config.i18n.searching) || 'Searching...';
			}

			ajax('lpm_search_products', { query: query })
				.then(function (data) {
					renderProductResults(results, data.products || []);
					if (status) {
						status.textContent = data.message || ((data.products || []).length + ' result(s), limited to 20.');
					}
				})
				.catch(function (error) {
					if (status) {
						status.textContent = error.message;
					}
				});
		}

		input.addEventListener('input', debounce(runSearch, 280));
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			runSearch();
		});
	}

	function renderProductResults(target, products) {
		if (!products.length) {
			target.innerHTML = '<div class="lpm-results"><p class="lpm-empty">' + escapeHtml((config.i18n && config.i18n.noProducts) || 'No products found.') + '</p></div>';
			return;
		}

		target.innerHTML = [
			'<div class="lpm-results">',
			'<h3>Search results</h3>',
			'<table class="lpm-compact-table lpm-product-table"><thead><tr>',
			'<th>Image</th><th>Product name</th><th>Product ID</th><th>SKU</th><th>Current price</th><th>Stock status</th><th>Action</th>',
			'</tr></thead><tbody>',
			products.map(function (product) {
				return [
					'<tr>',
					'<td>' + (product.thumbnail || '<span class="lpm-thumb-placeholder"></span>') + '</td>',
					'<td>' + escapeHtml(product.name) + '</td>',
					'<td>' + escapeHtml(product.id) + '</td>',
					'<td>' + escapeHtml(product.sku || '—') + '</td>',
					'<td>' + (product.price_html || '—') + '</td>',
					'<td>' + escapeHtml(product.stock_status || 'unknown') + '</td>',
					'<td><button type="button" class="button button-small" data-lpm-add-product="' + escapeHtml(product.id) + '">Add to monitoring</button></td>',
					'</tr>'
				].join('');
			}).join(''),
			'</tbody></table></div>'
		].join('');
	}

	function initProductActions() {
		document.addEventListener('click', function (event) {
			var addButton = event.target.closest('[data-lpm-add-product]');
			var openButton = event.target.closest('[data-lpm-open-product]');
			var row = event.target.closest('[data-lpm-monitored-row]');

			if (addButton) {
				event.preventDefault();
				addProduct(addButton.getAttribute('data-lpm-add-product'), addButton);
				return;
			}

			if (openButton) {
				event.preventDefault();
				openDrawer(openButton.getAttribute('data-lpm-open-product'));
				return;
			}

			if (row && !event.target.closest('a, button, input, select, textarea, form')) {
				openDrawer(row.getAttribute('data-lpm-monitored-row'));
			}
		});

		document.addEventListener('keydown', function (event) {
			var row = event.target.closest && event.target.closest('[data-lpm-monitored-row]');

			if (row && (event.key === 'Enter' || event.key === ' ')) {
				event.preventDefault();
				openDrawer(row.getAttribute('data-lpm-monitored-row'));
			}
		});

		document.addEventListener('submit', function (event) {
			var form = event.target.closest('[data-lpm-add-monitoring-form]');

			if (!form) {
				return;
			}

			event.preventDefault();
			addProduct(new FormData(form).get('product_id'), form.querySelector('button[type="submit"]'));
		});
	}

	function addProduct(productId, button) {
		if (!productId || !button) {
			return;
		}

		button.disabled = true;
		ajax('lpm_add_product_to_monitoring', { product_id: productId })
			.then(function (data) {
				toast(data.message || 'Product added to monitoring.', 'success');
				button.textContent = 'Added';
				button.classList.add('button-primary');
			})
			.catch(function (error) {
				toast(error.message, 'error');
			})
			.finally(function () {
				button.disabled = false;
			});
	}

	function initDrawer() {
		document.querySelectorAll('[data-lpm-drawer-close]').forEach(function (button) {
			button.addEventListener('click', closeDrawer);
		});

		document.querySelectorAll('[data-lpm-drawer-tab]').forEach(function (button) {
			button.addEventListener('click', function () {
				drawerTab = button.getAttribute('data-lpm-drawer-tab') || 'summary';
				renderDrawer();
			});
		});

		document.addEventListener('submit', function (event) {
			var form = event.target.closest('[data-lpm-drawer-competitor-form]');

			if (!form || !drawerData) {
				return;
			}

			event.preventDefault();
			saveDrawerCompetitor(form);
		});

		document.addEventListener('click', function (event) {
			var edit = event.target.closest('[data-lpm-edit-link]');
			var test = event.target.closest('[data-lpm-test-link]');
			var suggestion = event.target.closest('[data-lpm-create-suggestion]');

			if (edit) {
				fillCompetitorForm(parseInt(edit.getAttribute('data-lpm-edit-link'), 10));
			}

			if (test) {
				runLinkAction(test, 'lpm_test_competitor_link', 'competitor_link_id');
			}

			if (suggestion) {
				runLinkAction(suggestion, 'lpm_create_suggestion', 'competitor_link_id');
			}
		});
	}

	function openDrawer(monitoredProductId) {
		var drawer = document.querySelector('.lpm-drawer');
		var backdrop = document.querySelector('.lpm-drawer-backdrop');
		var body = document.querySelector('[data-lpm-drawer-body]');

		if (!drawer || !body) {
			return;
		}

		drawer.classList.add('is-open');
		drawer.setAttribute('aria-hidden', 'false');
		if (backdrop) {
			backdrop.hidden = false;
		}
		body.innerHTML = '<p class="lpm-empty">' + escapeHtml((config.i18n && config.i18n.loading) || 'Loading...') + '</p>';

		ajax('lpm_load_monitored_product_details', { monitored_product_id: monitoredProductId })
			.then(function (data) {
				drawerData = data;
				drawerTab = 'summary';
				renderDrawer();
			})
			.catch(function (error) {
				body.innerHTML = '<p class="lpm-empty">' + escapeHtml(error.message) + '</p>';
			});
	}

	function closeDrawer() {
		var drawer = document.querySelector('.lpm-drawer');
		var backdrop = document.querySelector('.lpm-drawer-backdrop');

		if (drawer) {
			drawer.classList.remove('is-open');
			drawer.setAttribute('aria-hidden', 'true');
		}

		if (backdrop) {
			backdrop.hidden = true;
		}
	}

	function renderDrawer() {
		var title = document.querySelector('[data-lpm-drawer-title]');
		var body = document.querySelector('[data-lpm-drawer-body]');

		document.querySelectorAll('[data-lpm-drawer-tab]').forEach(function (button) {
			button.classList.toggle('is-active', button.getAttribute('data-lpm-drawer-tab') === drawerTab);
		});

		if (!drawerData || !body) {
			return;
		}

		if (title) {
			title.textContent = (drawerData.product && drawerData.product.name) || ('Product #' + drawerData.monitored_product.product_id);
		}

		var renderers = {
			summary: renderDrawerSummary,
			competitors: renderDrawerCompetitors,
			rules: renderDrawerRules,
			history: renderDrawerHistory,
			suggestions: renderDrawerSuggestions,
			logs: renderDrawerLogs
		};

		body.innerHTML = (renderers[drawerTab] || renderDrawerSummary)();
	}

	function renderDrawerSummary() {
		var product = drawerData.product || {};
		var monitored = drawerData.monitored_product || {};
		var session = drawerData.active_session;

		return [
			'<div class="lpm-drawer-summary">',
			'<div class="lpm-product-cell">' + (product.thumbnail || '<span class="lpm-thumb-placeholder"></span>') + '<span><strong>' + escapeHtml(product.name || ('Product #' + monitored.product_id)) + '</strong><small>ID ' + escapeHtml(monitored.product_id) + ' · SKU ' + escapeHtml(monitored.sku || product.sku || '—') + '</small></span></div>',
			'<div class="lpm-drawer-metrics">',
			metric('Current price', product.price || '—'),
			metric('Stock', product.stock_status || 'unknown'),
			metric('Competitors', (drawerData.competitor_links || []).length),
			metric('Suggestions', (drawerData.suggestions || []).length),
			'</div>',
			session ? '<div class="lpm-inline-notice">Active price match session: matched ' + escapeHtml(session.matched_price) + ' at ' + escapeHtml(session.matched_at) + '</div>' : '',
			product.edit_url ? '<p><a class="button" href="' + escapeHtml(product.edit_url) + '">Open WooCommerce product</a></p>' : '',
			'</div>'
		].join('');
	}

	function metric(label, value) {
		return '<div class="lpm-mini-card"><span>' + escapeHtml(label) + '</span><strong>' + escapeHtml(value) + '</strong></div>';
	}

	function renderDrawerCompetitors() {
		return [
			renderCompetitorForm(),
			'<h3>Competitor links</h3>',
			renderCompetitorLinks(drawerData.competitor_links || [])
		].join('');
	}

	function renderCompetitorForm(link) {
		link = link || {};
		var profiles = drawerData.profiles || [];

		return [
			'<form class="lpm-drawer-form" data-lpm-drawer-competitor-form>',
			'<input type="hidden" name="monitored_product_id" value="' + escapeHtml(drawerData.monitored_product.id) + '">',
			'<input type="hidden" name="competitor_link_id" value="' + escapeHtml(link.id || '') + '">',
			'<label>Competitor name<input name="competitor_name" type="text" value="' + escapeHtml(link.competitor_name || '') + '" required></label>',
			'<label>Competitor URL<input name="competitor_url" type="url" value="' + escapeHtml(link.competitor_url || '') + '" required></label>',
			'<label>Profile<select name="competitor_id"><option value="0">No profile</option>' + profiles.map(function (profile) {
				return '<option value="' + escapeHtml(profile.id) + '"' + (Number(profile.id) === Number(link.competitor_id) ? ' selected' : '') + '>' + escapeHtml(profile.name) + '</option>';
			}).join('') + '</select></label>',
			'<label>Match type<select name="match_type">' + ['unknown', 'exact', 'similar', 'different_variant', 'bundle', 'not_comparable'].map(function (type) {
				return '<option value="' + type + '"' + (type === (link.match_type || 'unknown') ? ' selected' : '') + '>' + type + '</option>';
			}).join('') + '</select></label>',
			'<label class="lpm-checkbox-row"><input name="enabled" type="checkbox" value="1"' + (link.enabled === 0 ? '' : ' checked') + '> Enabled</label>',
			'<label class="lpm-checkbox-row"><input name="is_primary" type="checkbox" value="1"' + (link.is_primary ? ' checked' : '') + '> Primary competitor</label>',
			'<button type="submit" class="button button-primary">' + (link.id ? 'Update competitor link' : 'Add competitor link') + '</button>',
			'</form>'
		].join('');
	}

	function renderCompetitorLinks(links) {
		if (!links.length) {
			return '<p class="lpm-empty">No competitor links yet.</p>';
		}

		return '<div class="lpm-drawer-list">' + links.map(function (link) {
			return [
				'<article class="lpm-drawer-list-item">',
				'<div><strong>' + escapeHtml(link.competitor_name) + '</strong><small>' + escapeHtml(link.match_type) + ' · last ' + escapeHtml(link.last_price || '—') + ' ' + escapeHtml(link.last_currency || '') + '</small></div>',
				'<a href="' + escapeHtml(link.competitor_url) + '" target="_blank" rel="noopener noreferrer">Open</a>',
				'<div class="lpm-actions">',
				'<button type="button" class="button button-small" data-lpm-edit-link="' + escapeHtml(link.id) + '">Edit</button>',
				'<button type="button" class="button button-small" data-lpm-test-link="' + escapeHtml(link.id) + '">Test check</button>',
				link.last_price ? '<button type="button" class="button button-small" data-lpm-create-suggestion="' + escapeHtml(link.id) + '">Create suggestion</button>' : '',
				'</div>',
				renderObservations(link.recent_observations || []),
				'</article>'
			].join('');
		}).join('') + '</div>';
	}

	function fillCompetitorForm(linkId) {
		var link = (drawerData.competitor_links || []).find(function (item) {
			return Number(item.id) === Number(linkId);
		});
		var form = document.querySelector('[data-lpm-drawer-competitor-form]');

		if (form && link) {
			form.outerHTML = renderCompetitorForm(link);
		}
	}

	function saveDrawerCompetitor(form) {
		var button = form.querySelector('button[type="submit"]');
		var data = Object.fromEntries(new FormData(form).entries());
		data.enabled = form.querySelector('[name="enabled"]').checked ? '1' : '';
		data.is_primary = form.querySelector('[name="is_primary"]').checked ? '1' : '';

		button.disabled = true;
		ajax('lpm_save_competitor_link', data)
			.then(function (result) {
				drawerData.competitor_links = result.competitor_links || [];
				toast(result.message || 'Competitor link saved.', 'success');
				renderDrawer();
			})
			.catch(function (error) {
				toast(error.message, 'error');
			})
			.finally(function () {
				button.disabled = false;
			});
	}

	function runLinkAction(button, action, key) {
		var data = {};
		data[key] = button.getAttribute(button.hasAttribute('data-lpm-test-link') ? 'data-lpm-test-link' : 'data-lpm-create-suggestion');
		button.disabled = true;

		ajax(action, data)
			.then(function (result) {
				if (result.competitor_links) {
					drawerData.competitor_links = result.competitor_links;
				}
				if (result.suggestions) {
					drawerData.suggestions = result.suggestions;
				}
				toast(result.message || 'Action completed.', result.result && result.result.success === false ? 'warning' : 'success');
				renderDrawer();
			})
			.catch(function (error) {
				toast(error.message, 'error');
			})
			.finally(function () {
				button.disabled = false;
			});
	}

	function renderDrawerRules() {
		var monitored = drawerData.monitored_product || {};

		return '<dl class="lpm-detail-grid">' +
			detail('Enabled', monitored.enabled ? 'Yes' : 'No') +
			detail('Priority', monitored.priority) +
			detail('Strategy', monitored.strategy) +
			detail('Min margin', monitored.min_margin_percent) +
			detail('Min price', monitored.min_price) +
			detail('Check frequency', monitored.check_frequency_hours + ' h') +
			'</dl>';
	}

	function renderDrawerHistory() {
		return renderObservations(drawerData.observations || []);
	}

	function renderObservations(observations) {
		if (!observations.length) {
			return '<p class="lpm-empty">No recent checks.</p>';
		}

		return '<table class="lpm-compact-table"><thead><tr><th>Time</th><th>Price</th><th>Method</th><th>Status</th><th>Error</th></tr></thead><tbody>' + observations.map(function (row) {
			return '<tr><td>' + escapeHtml(row.checked_at || row.created_at) + '</td><td>' + escapeHtml(row.observed_price || '—') + ' ' + escapeHtml(row.currency || '') + '</td><td>' + escapeHtml(row.extraction_method || '—') + '</td><td>' + (row.success ? 'Success' : 'Failed') + '</td><td>' + escapeHtml(row.error_message || '') + '</td></tr>';
		}).join('') + '</tbody></table>';
	}

	function renderDrawerSuggestions() {
		var suggestions = drawerData.suggestions || [];

		if (!suggestions.length) {
			return '<p class="lpm-empty">No recent suggestions.</p>';
		}

		return '<div class="lpm-drawer-list">' + suggestions.map(function (suggestion) {
			return '<article class="lpm-drawer-list-item"><strong>' + escapeHtml(suggestion.suggestion_type) + '</strong><small>' + escapeHtml(suggestion.status) + ' · suggested ' + escapeHtml(suggestion.suggested_price) + '</small><p>' + escapeHtml(suggestion.reason || '') + '</p></article>';
		}).join('') + '</div>';
	}

	function renderDrawerLogs() {
		var logs = drawerData.logs || [];

		if (!logs.length) {
			return '<p class="lpm-empty">No recent logs.</p>';
		}

		return '<div class="lpm-drawer-list">' + logs.map(function (log) {
			return '<article class="lpm-drawer-list-item"><strong>' + escapeHtml(log.event) + '</strong><small>' + escapeHtml(log.level) + ' · ' + escapeHtml(log.created_at) + '</small><p>' + escapeHtml(log.message || '') + '</p></article>';
		}).join('') + '</div>';
	}

	function detail(label, value) {
		return '<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value) + '</dd>';
	}

	function initApprovals() {
		var details = document.querySelector('[data-lpm-suggestion-details]');

		if (details && details.getAttribute('data-lpm-initial-suggestion')) {
			loadSuggestionDetails(details.getAttribute('data-lpm-initial-suggestion'));
		}

		document.addEventListener('click', function (event) {
			var row = event.target.closest('[data-lpm-suggestion-row]');
			var view = event.target.closest('[data-lpm-view-suggestion]');
			var approve = event.target.closest('[data-lpm-approve-suggestion]');
			var reject = event.target.closest('[data-lpm-reject-suggestion]');

			if (view) {
				event.preventDefault();
				loadSuggestionDetails(view.getAttribute('data-lpm-view-suggestion'));
				return;
			}

			if (approve) {
				event.preventDefault();
				reviewSuggestion('lpm_approve_suggestion_dry_run', approve.getAttribute('data-lpm-approve-suggestion'), approve, true);
				return;
			}

			if (reject) {
				event.preventDefault();
				reviewSuggestion('lpm_reject_suggestion', reject.getAttribute('data-lpm-reject-suggestion'), reject, false);
				return;
			}

			if (row && !event.target.closest('a, button, input, select, textarea, form')) {
				loadSuggestionDetails(row.getAttribute('data-lpm-suggestion-row'));
			}
		});

		document.addEventListener('keydown', function (event) {
			if (!selectedSuggestionId) {
				return;
			}

			if (event.key.toLowerCase() === 'a' && selectedSuggestionStatus === 'pending') {
				reviewSuggestion('lpm_approve_suggestion_dry_run', selectedSuggestionId, null, true);
			}

			if (event.key.toLowerCase() === 'r' && ['pending', 'blocked'].indexOf(selectedSuggestionStatus) !== -1) {
				reviewSuggestion('lpm_reject_suggestion', selectedSuggestionId, null, false);
			}
		});
	}

	function loadSuggestionDetails(suggestionId) {
		var details = document.querySelector('[data-lpm-suggestion-details]');

		if (!details || !suggestionId) {
			return;
		}

		details.innerHTML = '<p class="lpm-empty">' + escapeHtml((config.i18n && config.i18n.loading) || 'Loading...') + '</p>';

		ajax('lpm_load_suggestion_details', { suggestion_id: suggestionId })
			.then(function (data) {
				var suggestion = data.suggestion || {};
				var product = data.product || {};
				var session = data.active_session || null;
				selectedSuggestionId = Number(suggestion.id || 0);
				selectedSuggestionStatus = suggestion.status || '';
				document.querySelectorAll('[data-lpm-suggestion-row]').forEach(function (row) {
					row.classList.toggle('is-selected', Number(row.getAttribute('data-lpm-suggestion-row')) === selectedSuggestionId);
				});
				details.innerHTML = renderSuggestionDetails(product, suggestion, session);
			})
			.catch(function (error) {
				details.innerHTML = '<p class="lpm-empty">' + escapeHtml(error.message) + '</p>';
			});
	}

	function renderSuggestionDetails(product, suggestion, session) {
		return [
			'<div class="lpm-suggestion-detail-grid">',
			'<div class="lpm-product-cell">' + (product.thumbnail || '<span class="lpm-thumb-placeholder"></span>') + '<span><strong>' + escapeHtml(product.name || ('Product #' + suggestion.product_id)) + '</strong><small>ID ' + escapeHtml(suggestion.product_id) + '</small></span></div>',
			metric('Current', suggestion.current_price),
			metric('Competitor', suggestion.competitor_price),
			metric('Suggested', suggestion.suggested_price),
			metric('Margin after', suggestion.margin_after_change || '—'),
			'</div>',
			'<p><strong>Reason:</strong> ' + escapeHtml(suggestion.reason || '') + '</p>',
			suggestion.warnings ? '<p><strong>Warnings:</strong> ' + escapeHtml(suggestion.warnings) + '</p>' : '',
			session ? '<p><strong>Recovery session:</strong> original active ' + escapeHtml(session.original_active_price || '—') + ', matched ' + escapeHtml(session.matched_price || '—') + '</p>' : ''
		].join('');
	}

	function reviewSuggestion(action, suggestionId, button, approve) {
		var message = approve ? (config.i18n && config.i18n.confirmApprove) : (config.i18n && config.i18n.confirmReject);

		if (!window.confirm(message || 'Confirm this action?')) {
			return;
		}

		if (button) {
			button.disabled = true;
		}

		ajax(action, { suggestion_id: suggestionId })
			.then(function (data) {
				var row = document.querySelector('[data-lpm-suggestion-row="' + suggestionId + '"]');
				if (row && data.suggestion) {
					row.setAttribute('data-lpm-suggestion-status', data.suggestion.status || '');
					row.classList.add('is-reviewed');
				}
				toast(data.message || 'Review recorded.', 'success');
				loadSuggestionDetails(suggestionId);
			})
			.catch(function (error) {
				toast(error.message, 'error');
			})
			.finally(function () {
				if (button) {
					button.disabled = false;
				}
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		initDeleteConfirmations();
		initProductSearch();
		initProductActions();
		initDrawer();
		initApprovals();
	});
})();
