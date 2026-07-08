(function () {
	'use strict';

	var config = window.LPM_ADMIN || {};
	var drawerData = null;
	var drawerTab = 'summary';
	var drawerChartCompetitor = 'all';
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

	function escapeAttr(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	function safeUrl(value) {
		try {
			var url = new URL(String(value || ''), window.location.href);
			return ['http:', 'https:'].indexOf(url.protocol) !== -1 ? url.href : '';
		} catch (error) {
			return '';
		}
	}

	function htmlToText(value) {
		var template = document.createElement('template');
		template.innerHTML = String(value || '');
		return (template.content.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function safeProductImageHtml(html) {
		var template = document.createElement('template');
		template.innerHTML = String(html || '');
		var image = template.content.querySelector('img');
		var src = image ? safeUrl(image.getAttribute('src') || image.getAttribute('data-src') || '') : '';
		if (!src) {
			return '<span class="lpm-thumb-placeholder"></span>';
		}

		return '<img src="' + escapeAttr(src) + '" alt="' + escapeHtml(image.getAttribute('alt') || '') + '" loading="lazy">';
	}

	function safeLinkHtml(url, label) {
		url = safeUrl(url);
		return url ? '<a href="' + escapeAttr(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label) + '</a>' : '';
	}

	function priceFieldOptions() {
		return [
			['', 'Use competitor default'],
			['sale_price_first', 'Sale price first'],
			['sale_price', 'Sale price only'],
			['regular_price', 'Regular price only'],
			['price_selector', 'Current price selector'],
			['lowest_price', 'Lowest detected price']
		];
	}

	function renderPriceFieldOptions(selected) {
		selected = selected || '';

		return priceFieldOptions().map(function (option) {
			return '<option value="' + escapeHtml(option[0]) + '"' + (option[0] === selected ? ' selected' : '') + '>' + escapeHtml(option[1]) + '</option>';
		}).join('');
	}

	function isSelectablePriceField(field) {
		return priceFieldOptions().some(function (option) {
			return option[0] && option[0] === field;
		});
	}

	function displayPriceField(row) {
		var field = row && row.price_field ? String(row.price_field) : '';

		if (field && !isSelectablePriceField(field)) {
			return field;
		}

		return (row && (row.price_field_label || row.price_field)) || '—';
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
					'<td>' + safeProductImageHtml(product.thumbnail) + '</td>',
					'<td>' + escapeHtml(product.name) + '</td>',
					'<td>' + escapeHtml(product.id) + '</td>',
					'<td>' + escapeHtml(product.sku || '—') + '</td>',
					'<td>' + escapeHtml(htmlToText(product.price_html) || '—') + '</td>',
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
			var competitorSetupButton = event.target.closest('[data-lpm-open-competitors]');
			var openButton = event.target.closest('[data-lpm-open-product]');
			var row = event.target.closest('[data-lpm-monitored-row]');

			if (addButton) {
				event.preventDefault();
				addProduct(addButton.getAttribute('data-lpm-add-product'), addButton);
				return;
			}

			if (competitorSetupButton) {
				event.preventDefault();
				window.location.href = config.competitorsUrl || 'admin.php?page=lilleprinsen-price-monitor&tab=competitors';
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
				button.classList.add('button-primary');
				if (data.discovery_product_id) {
					button.removeAttribute('data-lpm-add-product');
					if (parseInt(data.active_competitor_count || '0', 10) > 0) {
						button.setAttribute('data-lpm-start-product', String(data.discovery_product_id));
						button.textContent = 'Find matches';
						toast('Searching active competitors now...', 'success');
						document.dispatchEvent(new CustomEvent('lpm:start-discovery', {
							detail: {
								productId: String(data.discovery_product_id),
								competitorId: '0'
							}
						}));
					} else {
						button.removeAttribute('data-lpm-start-product');
						button.setAttribute('data-lpm-open-competitors', '1');
						button.textContent = 'Add competitor';
						toast('Product is ready. Add a competitor and discovery will start automatically.', 'success');
					}
				} else {
					button.textContent = 'Added';
				}
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

		document.addEventListener('change', function (event) {
			var filter = event.target.closest('[data-lpm-price-chart-filter]');

			if (!filter || !drawerData) {
				return;
			}

			drawerChartCompetitor = filter.value || 'all';
			renderDrawer();
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
				drawerChartCompetitor = 'all';
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
		var market = getMarketSummary();

		return [
			'<div class="lpm-drawer-summary">',
			'<div class="lpm-product-cell">' + (product.thumbnail || '<span class="lpm-thumb-placeholder"></span>') + '<span><strong>' + escapeHtml(product.name || ('Product #' + monitored.product_id)) + '</strong><small>ID ' + escapeHtml(monitored.product_id) + ' · SKU ' + escapeHtml(monitored.sku || product.sku || '—') + '</small></span></div>',
			'<div class="lpm-drawer-metrics">',
			metric('Current price', product.price || '—'),
			metric('Market low', market.lowestPrice ? (market.lowestPrice + ' ' + market.currency) : '—'),
			metric('Below us', market.belowCount),
			metric('Stock', product.stock_status || 'unknown'),
			metric('Competitor availability', market.inStockCount + ' in · ' + market.outOfStockCount + ' out · ' + market.unknownStockCount + ' unknown'),
			metric('Active links', market.activeCount),
			metric('Suggestions', (drawerData.suggestions || []).length),
			'</div>',
			renderMarketPosition(market),
			market.outOfStockCount ? '<div class="lpm-inline-notice">Out-of-stock competitor prices are tracked in history, but excluded from market-low alerts and position calculations.</div>' : '',
			market.equalCount ? '<div class="lpm-inline-ok">Market note: ' + escapeHtml(market.equalCount) + ' competitor link(s) are currently at the same price. No price-change alert is needed for equal prices.</div>' : '',
			session ? '<div class="lpm-inline-notice">Active price match session: matched ' + escapeHtml(session.matched_price) + ' at ' + escapeHtml(session.matched_at) + '</div>' : '',
			renderPriceChart(drawerData.chart_observations || []),
			product.edit_url ? '<p><a class="button" href="' + escapeHtml(product.edit_url) + '">Open WooCommerce product</a></p>' : '',
			'</div>'
		].join('');
	}

	function getMarketSummary() {
		var product = drawerData.product || {};
		var current = parseFloat(product.price);
		var active = (drawerData.competitor_links || []).filter(function (link) {
			return link.enabled !== 0 && link.match_type !== 'not_comparable';
		});
		var inStockCount = active.filter(function (link) {
			return normalizeStockStatus(link.last_stock_status) === 'in_stock';
		}).length;
		var outOfStockCount = active.filter(function (link) {
			return normalizeStockStatus(link.last_stock_status) === 'out_of_stock';
		}).length;
		var unknownStockCount = active.length - inStockCount - outOfStockCount;
		var prices = active.map(function (link) {
			return {
				price: parseFloat(link.last_price),
				currency: link.last_currency || '',
				stock: normalizeStockStatus(link.last_stock_status)
			};
		}).filter(function (entry) {
			return Number.isFinite(entry.price) && entry.price > 0 && entry.stock !== 'out_of_stock';
		});
		var lowest = prices.reduce(function (best, entry) {
			return !best || entry.price < best.price ? entry : best;
		}, null);
		var belowCount = Number.isFinite(current) ? prices.filter(function (entry) {
			return entry.price < current;
		}).length : 0;
		var equalCount = Number.isFinite(current) ? prices.filter(function (entry) {
			return Math.abs(entry.price - current) < 0.0001;
		}).length : 0;
		var sorted = prices.map(function (entry) {
			return entry.price;
		});
		if (Number.isFinite(current) && current > 0) {
			sorted.push(current);
		}
		sorted.sort(function (a, b) {
			return a - b;
		});
		var rank = Number.isFinite(current) && current > 0 ? sorted.filter(function (price) {
			return price < current - 0.0001;
		}).length + 1 : 0;
		var average = prices.length ? prices.reduce(function (sum, entry) {
			return sum + entry.price;
		}, 0) / prices.length : null;
		var aboveLowPercent = lowest && Number.isFinite(current) && current > 0 && lowest.price > 0 ? ((current - lowest.price) / lowest.price) * 100 : null;
		var averagePercent = Number.isFinite(current) && average && average > 0 ? ((current - average) / average) * 100 : null;
		var position = marketPositionText(rank, sorted.length, aboveLowPercent, averagePercent);

		return {
			activeCount: active.length,
			belowCount: belowCount,
			equalCount: equalCount,
			lowestPrice: lowest ? lowest.price : null,
			currency: lowest ? lowest.currency : '',
			rank: rank,
			totalRanked: sorted.length,
			averagePrice: average,
			aboveLowPercent: aboveLowPercent,
			averagePercent: averagePercent,
			positionText: position.text,
			positionTone: position.tone,
			inStockCount: inStockCount,
			outOfStockCount: outOfStockCount,
			unknownStockCount: Math.max(0, unknownStockCount)
		};
	}

	function normalizeStockStatus(status) {
		status = String(status || '').toLowerCase().replace(/[\s-]+/g, '_');

		if (['outofstock', 'out_of_stock', 'sold_out', 'utsolgt', 'ikke_pa_lager', 'ikke_paa_lager'].indexOf(status) !== -1) {
			return 'out_of_stock';
		}

		if (['instock', 'in_stock', 'available', 'pa_lager', 'paa_lager'].indexOf(status) !== -1) {
			return 'in_stock';
		}

		return 'unknown';
	}

	function stockStatusLabel(status) {
		status = normalizeStockStatus(status);

		if (status === 'in_stock') {
			return 'In stock';
		}
		if (status === 'out_of_stock') {
			return 'Out of stock';
		}
		return 'Stock unknown';
	}

	function stockStatusBadge(status) {
		var normalized = normalizeStockStatus(status);
		return '<span class="lpm-stock-badge lpm-stock-badge-' + escapeHtml(normalized) + '">' + escapeHtml(stockStatusLabel(normalized)) + '</span>';
	}

	function marketPositionText(rank, total, aboveLowPercent, averagePercent) {
		if (!rank || !total) {
			return { text: 'Market position unknown', tone: 'muted' };
		}
		if (rank === 1) {
			return { text: 'You are cheapest', tone: 'ok' };
		}
		if (rank === 2) {
			return { text: '2nd cheapest', tone: 'warning' };
		}
		if (Number.isFinite(aboveLowPercent) && aboveLowPercent > 0) {
			return { text: Math.round(aboveLowPercent) + '% above market low', tone: aboveLowPercent >= 10 ? 'danger' : 'warning' };
		}
		if (Number.isFinite(averagePercent) && averagePercent < 0) {
			return { text: 'Below market average', tone: 'ok' };
		}
		return { text: rank + ordinalSuffix(rank) + ' cheapest', tone: 'warning' };
	}

	function ordinalSuffix(value) {
		if (value % 100 >= 11 && value % 100 <= 13) {
			return 'th';
		}
		return { 1: 'st', 2: 'nd', 3: 'rd' }[value % 10] || 'th';
	}

	function renderMarketPosition(market) {
		var averageLabel = market.averagePrice ? market.averagePrice.toFixed(2) + ' ' + market.currency : '—';
		var lowGap = Number.isFinite(market.aboveLowPercent) ? market.aboveLowPercent.toFixed(1) + '%' : '—';
		var averageGap = Number.isFinite(market.averagePercent) ? (market.averagePercent < 0 ? 'below ' : 'above ') + Math.abs(market.averagePercent).toFixed(1) + '%' : '—';

		return [
			'<section class="lpm-market-position lpm-market-position-' + escapeHtml(market.positionTone || 'muted') + '">',
			'<div><span>Market position</span><strong>' + escapeHtml(market.positionText || 'Market position unknown') + '</strong></div>',
			'<dl>',
			'<dt>Rank</dt><dd>' + escapeHtml(market.rank ? (market.rank + ' of ' + market.totalRanked) : '—') + '</dd>',
			'<dt>Above market low</dt><dd>' + escapeHtml(lowGap) + '</dd>',
			'<dt>Market average</dt><dd>' + escapeHtml(averageLabel) + '</dd>',
			'<dt>Vs average</dt><dd>' + escapeHtml(averageGap) + '</dd>',
			'</dl>',
			'</section>'
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
		var currentProfile = profiles.find(function (profile) {
			return Number(profile.id) === Number(link.competitor_id);
		});
		var defaultLabel = currentProfile && currentProfile.monitored_price_field_label ? currentProfile.monitored_price_field_label : link.profile_price_field_label;

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
			'<label>Price for comparison<select name="price_field_override">' + renderPriceFieldOptions(link.price_field_override || '') + '</select>' + (defaultLabel ? '<small>Default: ' + escapeHtml(defaultLabel) + '</small>' : '') + '</label>',
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
			var priceFieldLine = 'Using ' + (link.effective_price_field_label || 'Sale price first') + (link.price_field_override ? ' · product override' : ' · competitor default');
			var availabilityNote = normalizeStockStatus(link.last_stock_status) === 'out_of_stock' ? '<small class="lpm-warning-text">Excluded from market alerts while out of stock.</small>' : '';

			return [
				'<article class="lpm-drawer-list-item">',
				'<div><strong>' + escapeHtml(link.competitor_name) + '</strong><small>' + escapeHtml(link.match_type) + ' · last ' + escapeHtml(link.last_price || '—') + ' ' + escapeHtml(link.last_currency || '') + ' · ' + stockStatusLabel(link.last_stock_status) + '</small><small>' + escapeHtml(priceFieldLine) + '</small>' + availabilityNote + renderLatestRead(link) + '</div>',
				safeLinkHtml(link.competitor_url, 'Open'),
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

	function renderLatestRead(link) {
		var latest = (link.recent_observations || [])[0];

		if (!latest || !latest.success) {
			return '';
		}

		return '<small>Read: ' + escapeHtml(displayPriceField(latest)) + ' · regular ' + escapeHtml(latest.observed_regular_price || '—') + ' · sale ' + escapeHtml(latest.observed_sale_price || '—') + ' · ' + stockStatusLabel(latest.stock_status) + '</small>';
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
		return renderPriceChart(drawerData.chart_observations || []) + renderObservations(drawerData.observations || []);
	}

	function chartTooltipText(row) {
		return {
			site: row.competitor_name || ('Link #' + text(row.competitor_link_id)),
			price: text(row.observed_price) + (row.currency ? ' ' + row.currency : ''),
			date: row.checked_at || row.created_at || '—'
		};
	}

	function renderPriceChart(observations) {
		var product = drawerData.product || {};
		var ourPrice = parseFloat(product.price);
		var hasOurPrice = Number.isFinite(ourPrice) && ourPrice > 0;
		var successful = (observations || []).filter(function (row) {
			var price = parseFloat(row.observed_price);
			return row.success && Number.isFinite(price) && price > 0 && (drawerChartCompetitor === 'all' || String(row.competitor_link_id) === String(drawerChartCompetitor));
		}).sort(function (a, b) {
			return new Date(a.checked_at || a.created_at).getTime() - new Date(b.checked_at || b.created_at).getTime();
		});

		if (!observations.length && !hasOurPrice) {
			return '<section class="lpm-price-chart"><div class="lpm-chart-header"><h3>Price history</h3></div><p class="lpm-empty">No logged competitor prices yet.</p></section>';
		}

		var competitors = getChartCompetitors(observations);
		var width = 680;
		var height = 260;
		var pad = 34;
		var prices = successful.map(function (row) {
			return parseFloat(row.observed_price);
		});
		if (hasOurPrice) {
			prices.push(ourPrice);
		}
		var min = prices.length ? Math.min.apply(null, prices) : 0;
		var max = prices.length ? Math.max.apply(null, prices) : 0;
		var range = max > min ? max - min : 1;
		var groups = groupChartRows(successful);
		var times = successful.map(function (row) {
			return new Date(row.checked_at || row.created_at).getTime();
		}).filter(function (time) {
			return Number.isFinite(time);
		});
		var minTime = times.length ? Math.min.apply(null, times) : 0;
		var maxTime = times.length ? Math.max.apply(null, times) : minTime;
		function pointX(row, fallbackIndex) {
			var time = new Date(row.checked_at || row.created_at).getTime();
			if (!Number.isFinite(time) || maxTime <= minTime) {
				return successful.length <= 1 ? width / 2 : pad + (fallbackIndex / Math.max(1, successful.length - 1)) * (width - pad * 2);
			}
			return pad + ((time - minTime) / (maxTime - minTime)) * (width - pad * 2);
		}
		function pointY(price) {
			return height - pad - ((price - min) / range) * (height - pad * 2);
		}
		var lines = Object.keys(groups).map(function (id, index) {
			var rows = groups[id];
			var points = rows.map(function (row, rowIndex) {
				var x = pointX(row, successful.indexOf(row));
				var y = pointY(parseFloat(row.observed_price));
				return [x.toFixed(1), y.toFixed(1)].join(',');
			}).join(' ');
			var color = chartColor(index);

			return '<polyline fill="none" stroke="' + color + '" stroke-width="3" points="' + escapeHtml(points) + '"></polyline>' + rows.map(function (row, rowIndex) {
				var parts = points.split(' ')[rowIndex].split(',');
				var tooltip = chartTooltipText(row);
				return '<circle cx="' + escapeHtml(parts[0]) + '" cy="' + escapeHtml(parts[1]) + '" r="4.5" fill="' + color + '" tabindex="0" role="img" aria-label="' + escapeAttr(tooltip.site + ', ' + tooltip.price + ', ' + tooltip.date) + '" class="lpm-chart-point lpm-chart-point-' + escapeHtml(normalizeStockStatus(row.stock_status)) + '" data-lpm-chart-point="1" data-site="' + escapeAttr(tooltip.site) + '" data-price="' + escapeAttr(tooltip.price) + '" data-date="' + escapeAttr(tooltip.date) + '"></circle>';
			}).join('');
		}).join('');
		var ourLine = '';
		if (hasOurPrice) {
			var ourY = pointY(ourPrice).toFixed(1);
			ourLine = '<line class="lpm-chart-our-price-line" x1="' + pad + '" y1="' + ourY + '" x2="' + (width - pad) + '" y2="' + ourY + '"><title>Our current WooCommerce price · ' + escapeHtml(product.price) + '</title></line>';
		}
		var latest = successful.length ? successful[successful.length - 1] : null;

		return [
			'<section class="lpm-price-chart">',
			'<div class="lpm-chart-header"><div><h3>Price history</h3><p>Our current WooCommerce price plus logged competitor prices over time. Filter to inspect one competitor.</p></div>',
			'<label>Competitor <select data-lpm-price-chart-filter><option value="all">All competitors</option>' + competitors.map(function (competitor) {
				return '<option value="' + escapeHtml(competitor.id) + '"' + (String(competitor.id) === String(drawerChartCompetitor) ? ' selected' : '') + '>' + escapeHtml(competitor.name) + '</option>';
			}).join('') + '</select></label></div>',
			'<p class="lpm-chart-hint">Hover or focus a point to see site, price and date.</p>',
			(successful.length || hasOurPrice) ? '<svg class="lpm-chart-svg" viewBox="0 0 ' + width + ' ' + height + '" role="img" aria-label="Product and competitor price history"><line class="lpm-chart-axis" x1="' + pad + '" y1="' + (height - pad) + '" x2="' + (width - pad) + '" y2="' + (height - pad) + '"></line><line class="lpm-chart-axis" x1="' + pad + '" y1="' + pad + '" x2="' + pad + '" y2="' + (height - pad) + '"></line>' + ourLine + lines + '</svg>' : '<p class="lpm-empty">No successful prices for this filter.</p>',
			'<div class="lpm-chart-meta"><span>Our price: ' + escapeHtml(hasOurPrice ? product.price : '—') + '</span><span>Low: ' + escapeHtml(min || '—') + '</span><span>High: ' + escapeHtml(max || '—') + '</span><span>Latest competitor: ' + escapeHtml(latest ? ((latest.observed_price || '—') + ' ' + (latest.currency || '') + ' at ' + (latest.checked_at || latest.created_at)) : '—') + '</span></div>',
			renderChartLegend(groups, hasOurPrice),
			'</section>'
		].join('');
	}

	function getChartCompetitors(observations) {
		var seen = {};

		return observations.reduce(function (list, row) {
			var id = String(row.competitor_link_id || '');

			if (!id || seen[id]) {
				return list;
			}

			seen[id] = true;
			list.push({
				id: id,
				name: row.competitor_name || ('Link #' + id)
			});
			return list;
		}, []);
	}

	function groupChartRows(rows) {
		return rows.reduce(function (groups, row) {
			var id = String(row.competitor_link_id || 'unknown');
			groups[id] = groups[id] || [];
			groups[id].push(row);
			return groups;
		}, {});
	}

	function renderChartLegend(groups, hasOurPrice) {
		var ids = Object.keys(groups);

		if (!ids.length && !hasOurPrice) {
			return '';
		}

		var items = hasOurPrice ? ['<span><i class="lpm-chart-our-price-key"></i>Our current price</span>'] : [];
		items = items.concat(ids.map(function (id, index) {
			var row = groups[id][0] || {};
			return '<span><i style="background:' + chartColor(index) + '"></i>' + escapeHtml(row.competitor_name || ('Link #' + id)) + '</span>';
		}));

		return '<div class="lpm-chart-legend">' + items.join('') + '</div>';
	}

	function chartColor(index) {
		return ['#2271b1', '#008a20', '#b32d2e', '#8a4b00', '#6f42c1', '#007c89'][index % 6];
	}

	function chartTooltip() {
		var tooltip = document.querySelector('[data-lpm-chart-tooltip]');
		if (!tooltip) {
			tooltip = document.createElement('div');
			tooltip.className = 'lpm-chart-tooltip';
			tooltip.dataset.lpmChartTooltip = '1';
			document.body.appendChild(tooltip);
		}
		return tooltip;
	}

	function positionChartTooltip(tooltip, point, event) {
		var rect = point.getBoundingClientRect();
		var x = event && Number.isFinite(event.clientX) ? event.clientX : rect.left + rect.width / 2;
		var y = event && Number.isFinite(event.clientY) ? event.clientY : rect.top;
		tooltip.style.left = Math.min(window.innerWidth - 220, Math.max(12, x + 12)) + 'px';
		tooltip.style.top = Math.max(12, y + 14) + 'px';
	}

	function showChartTooltip(point, event) {
		var tooltip = chartTooltip();
		tooltip.innerHTML = '<strong>' + escapeHtml(point.dataset.site || 'Competitor') + '</strong>' +
			'<span>' + escapeHtml(point.dataset.price || '—') + '</span>' +
			'<small>' + escapeHtml(point.dataset.date || '—') + '</small>';
		tooltip.hidden = false;
		positionChartTooltip(tooltip, point, event);
	}

	function hideChartTooltip() {
		var tooltip = document.querySelector('[data-lpm-chart-tooltip]');
		if (tooltip) {
			tooltip.hidden = true;
		}
	}

	function initChartTooltips() {
		document.addEventListener('mouseover', function (event) {
			var point = event.target.closest && event.target.closest('[data-lpm-chart-point]');
			if (point) {
				showChartTooltip(point, event);
			}
		});
		document.addEventListener('mousemove', function (event) {
			var point = event.target.closest && event.target.closest('[data-lpm-chart-point]');
			var tooltip = document.querySelector('[data-lpm-chart-tooltip]');
			if (point && tooltip && !tooltip.hidden) {
				positionChartTooltip(tooltip, point, event);
			}
		});
		document.addEventListener('mouseout', function (event) {
			if (event.target.closest && event.target.closest('[data-lpm-chart-point]')) {
				hideChartTooltip();
			}
		});
		document.addEventListener('focusin', function (event) {
			var point = event.target.closest && event.target.closest('[data-lpm-chart-point]');
			if (point) {
				showChartTooltip(point, null);
			}
		});
		document.addEventListener('focusout', function (event) {
			if (event.target.closest && event.target.closest('[data-lpm-chart-point]')) {
				hideChartTooltip();
			}
		});
	}

	function renderObservations(observations) {
		if (!observations.length) {
			return '<p class="lpm-empty">No recent checks.</p>';
		}

		return '<table class="lpm-compact-table"><thead><tr><th>Time</th><th>Price</th><th>Stock</th><th>Used field</th><th>Regular</th><th>Sale</th><th>Method</th><th>Status</th><th>Error</th></tr></thead><tbody>' + observations.map(function (row) {
			return '<tr><td>' + escapeHtml(row.checked_at || row.created_at) + '</td><td>' + escapeHtml(row.observed_price || '—') + ' ' + escapeHtml(row.currency || '') + '</td><td>' + stockStatusBadge(row.stock_status) + '</td><td>' + escapeHtml(displayPriceField(row)) + '</td><td>' + escapeHtml(row.observed_regular_price || '—') + '</td><td>' + escapeHtml(row.observed_sale_price || '—') + '</td><td>' + escapeHtml(row.extraction_method || '—') + '</td><td>' + (row.success ? 'Success' : 'Failed') + '</td><td>' + escapeHtml(row.error_message || '') + '</td></tr>';
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
				openSuggestionApprovalReview(approve.getAttribute('data-lpm-approve-suggestion'), approve);
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
			if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey || event.target.closest('input, textarea, select, [contenteditable="true"]')) {
				return;
			}

			if (event.key.toLowerCase() === 'a' && selectedSuggestionStatus === 'pending') {
				event.preventDefault();
				openSuggestionApprovalReview(selectedSuggestionId, null);
			}

			if (event.key.toLowerCase() === 'r' && ['pending', 'blocked'].indexOf(selectedSuggestionStatus) !== -1) {
				event.preventDefault();
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
			'<div class="lpm-product-cell">' + safeProductImageHtml(product.thumbnail) + '<span><strong>' + escapeHtml(product.name || ('Product #' + suggestion.product_id)) + '</strong><small>ID ' + escapeHtml(suggestion.product_id) + '</small></span></div>',
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

	function closeSuggestionApprovalReview() {
		var modal = document.querySelector('[data-lpm-approval-modal]');
		if (modal) {
			modal.remove();
		}
	}

	function approvalValue(label, value) {
		return '<dt>' + escapeHtml(label) + '</dt><dd>' + escapeHtml(value || '—') + '</dd>';
	}

	function approvalImage(html, label) {
		var image = safeProductImageHtml(html);
		return image.indexOf('<img ') === 0 ? image : '<span class="lpm-approval-image-placeholder">' + escapeHtml(label || 'No image') + '</span>';
	}

	function renderSuggestionApprovalReview(product, suggestion, session) {
		var warnings = suggestion.warnings || suggestion.reason || '';
		return [
			'<div class="lpm-approval-modal" data-lpm-approval-modal="1">',
			'<div class="lpm-approval-dialog" role="dialog" aria-modal="true" aria-label="' + escapeHtml('Review price suggestion before approval') + '">',
			'<div class="lpm-approval-header"><h2>' + escapeHtml('Review before approval') + '</h2><button type="button" class="button-link" data-lpm-close-approval-review>' + escapeHtml('Close') + '</button></div>',
			'<p class="lpm-approval-note">' + escapeHtml('Dry-run approval records your decision only. WooCommerce price will not be changed from this action.') + '</p>',
			'<div class="lpm-approval-compare">',
			'<section><h3>' + escapeHtml('Our product') + '</h3>' + approvalImage(product.thumbnail, 'No image') + '<dl>',
			approvalValue('Title', product.name || ('Product #' + suggestion.product_id)),
			approvalValue('SKU', product.sku || ''),
			approvalValue('Current price', suggestion.current_price || ''),
			approvalValue('Stock', product.stock_status || ''),
			'</dl></section>',
			'<section><h3>' + escapeHtml('Competitor product') + '</h3><span class="lpm-approval-image-placeholder">' + escapeHtml('Image not stored') + '</span><dl>',
			approvalValue('Competitor', suggestion.competitor_name || ''),
			approvalValue('URL', suggestion.competitor_url || ''),
			approvalValue('Competitor price', suggestion.competitor_price || ''),
			approvalValue('Suggested price', suggestion.suggested_price || ''),
			'</dl>' + (suggestion.competitor_url ? '<p>' + safeLinkHtml(suggestion.competitor_url, 'Open competitor page') + '</p>' : '') + '</section>',
			'</div>',
			'<section class="lpm-approval-evidence"><h3>' + escapeHtml('Warnings and evidence') + '</h3>',
			'<p><strong>' + escapeHtml('Suggestion type:') + '</strong> ' + escapeHtml(suggestion.suggestion_type || '') + '</p>',
			'<p><strong>' + escapeHtml('Reason:') + '</strong> ' + escapeHtml(suggestion.reason || '') + '</p>',
			warnings ? '<p class="lpm-approval-warning">' + escapeHtml(warnings) + '</p>' : '',
			session ? '<p><strong>' + escapeHtml('Recovery session:') + '</strong> ' + escapeHtml('original active ' + (session.original_active_price || '—') + ', matched ' + (session.matched_price || '—')) + '</p>' : '',
			'</section>',
			'<div class="lpm-approval-actions"><button type="button" class="button" data-lpm-close-approval-review>' + escapeHtml('Cancel') + '</button><button type="button" class="button button-primary" data-lpm-confirm-approval="' + escapeHtml(suggestion.id || '') + '">' + escapeHtml('Record dry-run approval') + '</button></div>',
			'</div></div>'
		].join('');
	}

	function openSuggestionApprovalReview(suggestionId, button) {
		if (button) {
			button.disabled = true;
		}
		ajax('lpm_load_suggestion_details', { suggestion_id: suggestionId })
			.then(function (data) {
				closeSuggestionApprovalReview();
				var wrapper = document.createElement('div');
				wrapper.innerHTML = renderSuggestionApprovalReview(data.product || {}, data.suggestion || {}, data.active_session || null);
				document.body.appendChild(wrapper.firstElementChild);
				var confirm = document.querySelector('[data-lpm-confirm-approval]');
				if (confirm) {
					confirm.focus();
				}
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

	function reviewSuggestion(action, suggestionId, button, approve, skipConfirm) {
		var message = approve ? (config.i18n && config.i18n.confirmApprove) : (config.i18n && config.i18n.confirmReject);

		if (!skipConfirm && !window.confirm(message || 'Confirm this action?')) {
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

	function platformPresets() {
		return {
			auto: {
				label: 'Auto-detect',
				description: 'Enter a domain or search URL and the wizard will suggest a setup.',
				templates: []
			},
			woo: {
				label: 'WooCommerce',
				description: 'WordPress/Woo search usually supports product-only search with post_type=product.',
				templates: ['?post_type=product&s={query}', '?s={query}']
			},
			magento: {
				label: 'Magento',
				description: 'Magento catalog search usually uses catalogsearch/result/?q=.',
				templates: ['catalogsearch/result/?q={query}']
			},
			shopify: {
				label: 'Shopify',
				description: 'Shopify storefronts usually expose /search?q= product results.',
				templates: ['search?q={query}', 'search?type=product&q={query}']
			},
			algolia: {
				label: 'Algolia',
				description: 'Use the normal HTML search page first; the plugin can use exposed public Algolia settings when available.',
				templates: ['?s={query}', 'search?q={query}'],
				js: true
			},
			voyado: {
				label: 'Voyado Elevate',
				description: 'Voyado often appears on Magento search pages and may need the external browser worker for full rendering.',
				templates: ['catalogsearch/result/?q={query}&origin=ORGANIC', 'catalogsearch/result/?q={query}'],
				js: true
			},
			custom: {
				label: 'Custom / unknown',
				description: 'Start with generic search URLs, then use Test search setup after saving.',
				templates: ['search?q={query}', '?s={query}']
			}
		};
	}

	function deriveSearchTemplate(rawUrl) {
		var url;
		var keys = ['q', 'query', 's', 'search', 'text', 'term'];

		try {
			url = new URL(String(rawUrl || ''), window.location.href);
		} catch (error) {
			return '';
		}

		if (['http:', 'https:'].indexOf(url.protocol) === -1) {
			return '';
		}

		for (var index = 0; index < keys.length; index += 1) {
			if (url.searchParams.has(keys[index])) {
				url.searchParams.set(keys[index], '{query}');
				return url.href.replace('%7Bquery%7D', '{query}');
			}
		}

		return '';
	}

	function detectPlatform(domain, searchUrl, selected) {
		var presets = platformPresets();
		var haystack = [domain, searchUrl].join(' ').toLowerCase();

		if (selected && selected !== 'auto') {
			return selected;
		}

		if (haystack.indexOf('elevate-api.cloud') !== -1 || haystack.indexOf('voyado') !== -1 || haystack.indexOf('origin=organic') !== -1) {
			return 'voyado';
		}
		if (haystack.indexOf('algolia') !== -1) {
			return 'algolia';
		}
		if (haystack.indexOf('myshopify.com') !== -1 || haystack.indexOf('/products/') !== -1 || haystack.indexOf('/search?') !== -1 && haystack.indexOf('type=product') !== -1) {
			return 'shopify';
		}
		if (haystack.indexOf('catalogsearch/result') !== -1 || haystack.indexOf('magento') !== -1) {
			return 'magento';
		}
		if (haystack.indexOf('post_type=product') !== -1 || haystack.indexOf('?s=') !== -1 || haystack.indexOf('woocommerce') !== -1) {
			return 'woo';
		}

		return presets[selected] ? selected : 'custom';
	}

	function renderPlatformPreview(wizard) {
		var select = wizard.querySelector('[data-lpm-platform-select]');
		var domain = wizard.closest('form').querySelector('[data-lpm-platform-domain]');
		var search = wizard.querySelector('[data-lpm-platform-search-url]');
		var badge = wizard.querySelector('[data-lpm-platform-badge]');
		var summary = wizard.querySelector('[data-lpm-platform-summary]');
		var preview = wizard.querySelector('[data-lpm-platform-preview]');
		var presets = platformPresets();
		var platform = detectPlatform(domain ? domain.value : '', search ? search.value : '', select ? select.value : 'auto');
		var preset = presets[platform] || presets.custom;
		var templates = preset.templates.slice();
		var derived = deriveSearchTemplate(search ? search.value : '');

		if (derived && templates.indexOf(derived) === -1) {
			templates.unshift(derived);
		}

		if (badge) {
			badge.textContent = preset.label;
			badge.className = 'lpm-pill ' + (preset.js ? 'lpm-pill-warning' : 'lpm-pill-ok');
		}
		if (summary) {
			summary.textContent = preset.description + (preset.js ? ' JavaScript-heavy search may need the external browser worker.' : '');
		}
		if (!preview) {
			return;
		}

		preview.querySelectorAll('[data-lpm-platform-generated]').forEach(function (node) {
			node.remove();
		});

		if (templates.length) {
			var list = document.createElement('ul');
			list.dataset.lpmPlatformGenerated = '1';
			templates.slice(0, 4).forEach(function (template) {
				var item = document.createElement('li');
				var code = document.createElement('code');
				code.textContent = template;
				item.appendChild(code);
				list.appendChild(item);
			});
			preview.appendChild(list);
		}
	}

	function initPlatformWizard() {
		document.querySelectorAll('[data-lpm-platform-wizard]').forEach(function (wizard) {
			var form = wizard.closest('form');
			var fields = [
				wizard.querySelector('[data-lpm-platform-select]'),
				wizard.querySelector('[data-lpm-platform-search-url]'),
				form ? form.querySelector('[data-lpm-platform-domain]') : null
			].filter(Boolean);

			fields.forEach(function (field) {
				field.addEventListener('input', function () {
					renderPlatformPreview(wizard);
				});
				field.addEventListener('change', function () {
					renderPlatformPreview(wizard);
				});
			});
			renderPlatformPreview(wizard);
		});
	}

	document.addEventListener('click', function (event) {
		var close = event.target.closest('[data-lpm-close-approval-review]');
		var confirm = event.target.closest('[data-lpm-confirm-approval]');
		if (close) {
			event.preventDefault();
			closeSuggestionApprovalReview();
		}
		if (confirm) {
			event.preventDefault();
			confirm.disabled = true;
			reviewSuggestion('lpm_approve_suggestion_dry_run', confirm.getAttribute('data-lpm-confirm-approval'), confirm, true, true);
			closeSuggestionApprovalReview();
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		initDeleteConfirmations();
		initProductSearch();
		initProductActions();
		initDrawer();
		initApprovals();
		initPlatformWizard();
		initChartTooltips();
	});
})();
