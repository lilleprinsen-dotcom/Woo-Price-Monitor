# Competitor Price Assistant

Competitor Price Assistant helps store staff find competitor product pages for the WooCommerce products they choose. Discovery is separate from approved price monitoring: it creates suggested matches, and regular monitoring starts only after an admin approves a suggestion.

The normal workflow is simple:

1. Select the products you want to monitor.
2. Add a competitor website.
3. Click **Scan monitored SKUs**.
4. Review and approve suggested matches.

The assistant searches the competitor website for the SKUs you selected, checks possible competitor product pages, and suggests matches when it finds the same SKU/EAN/MPN or strong product evidence.

## Select Products

Discovery never scans the full catalog by default. Add only the products that should be eligible for competitor matching.

You can add products from:

- The product edit screen: check **Include in competitor discovery**.
- The WooCommerce product list: use the bulk action **Include in competitor discovery**.
- **WooCommerce > Competitor Prices > Products to Monitor**: paste SKUs, product IDs, or variation IDs.

Variation identifiers are preferred over parent product identifiers. Parent values are used only when the variation value is empty.

The selected-products page shows product name, SKU, EAN/GTIN, EAN source, brand, pending suggestions, and last discovery run. Identifier counts and duplicate warnings are shown on the overview page.

## EAN/GTIN Source

Open **WooCommerce > Competitor Prices > Advanced Settings** and choose **Where is EAN/GTIN stored?**

Options:

- Product SKU
- Built-in product GTIN/global unique ID field, if available
- Custom field / product meta key
- Do not use EAN/GTIN

If **Custom field / product meta key** is selected, enter **EAN/GTIN meta key**. Examples: `_alg_ean`, `_wpm_gtin_code`, `_global_unique_id`, `ean`, `gtin`, `barcode`.

Use **Test EAN/GTIN source** on **Products to Monitor**. The test only checks selected discovery products, not the full catalog.

## Add Or Test A Competitor

Open **WooCommerce > Competitor Prices > Find Matches**.

1. Choose an existing competitor or create a new one.
2. Paste one competitor product URL.
3. Click **Test Product Page**.
4. Review detected values.

The assistant shows:

- Product title
- SKU
- EAN/GTIN
- MPN
- Brand
- Regular price
- Sale price
- Currency
- Stock status
- Image
- Canonical URL

Source labels are plain-language labels such as **Structured product data**, **Product meta tag**, **Page content**, **Image URL**, and **Custom competitor rule**.

## Scan Monitored SKUs

Use **Scan monitored SKUs** for the Reprice-style workflow: the plugin takes the SKUs from **Products to Monitor**, searches the competitor website, queues possible product pages, reads identifiers and prices, then creates suggested matches.

The button is shown in **WooCommerce > Competitor Prices > Find Matches**, in the **Competitors** table. After clicking it, wait a minute and open **Suggested Matches**. Matches are still review-only until an admin approves them.

The default scan is intentionally bounded:

- It searches only explicitly selected products.
- It uses only a few common competitor search URLs per SKU.
- It stays on the competitor domain by default.
- It leaves part of the request batch for reading product pages and creating suggestions.
- It never scans the full WooCommerce catalog automatically.

Common search formats are tried automatically, including WooCommerce-style `?s=SKU`, generic search pages, and Magento-style catalog search pages. Advanced users can add competitor-specific search URL templates in competitor notes, for example:

```json
{"search_url_templates":["search?q={sku}","finn?q={sku}"]}
```

## Add Discovery Sources

Use **Add page with many products** when the competitor search does not find enough pages or when you know a useful category/listing/sitemap page.

Supported source types:

- Example product URL
- Page with many products
- Sitemap URL

By default, source discovery stays on the competitor domain, removes common tracking parameters, deduplicates URLs, skips cart/checkout/account/search/filter URLs, and only queues URLs that look like product pages.

Manual discovery and SKU scanning both use bounded Action Scheduler batches with request limits, page limits, delays, locks, and resume-friendly stored state.

## Review Suggested Matches

Open **WooCommerce > Competitor Prices > Suggested Matches**.

Each suggestion shows:

- Our product and identifiers
- Competitor product title and URL
- Competitor price and stock
- Plain-language explanation
- Confidence label

Confidence labels:

- **High confidence**: exact EAN/GTIN, exact SKU, or exact MPN with the same brand.
- **Medium confidence**: same brand and very similar product title.
- **Low confidence**: similar title or weak identifier evidence.

Use **Approve** to create or update the existing competitor link used by regular price monitoring. Use **Reject** to keep the same suggestion from reappearing unless product identifiers or competitor page content changes. Use **Retest** to read the competitor page again before deciding.

No suggestions are auto-approved by default.

## Manual Product URL Add

On a WooCommerce product edit screen, use **Competitor Price Assistant > Add competitor URL**.

When the product is saved, the plugin tests the URL and shows:

- **Safe match** when SKU/EAN is found on the competitor page.
- **Unverified match** when SKU/EAN is not found. Check **Add even if unverified** to add anyway.
- **Failed** when the page cannot be read.

Accepted URLs create or update the existing competitor link structure used by regular price monitoring.

## Health Status

The overview shows competitor health:

- Working
- Needs attention
- Paused
- Blocked / request failed
- Extraction changed
- No recent successful checks

Health includes last run, success/failure counts, pending suggestions, approved monitored links, and the last plain-language issue. Competitors are paused after repeated discovery failures based on the configured threshold.

## Advanced Settings

Useful settings:

- Weekly discovery jobs: off unless enabled.
- SKU scanning: on by default for selected products.
- Max SKU searches per run: default 5.
- Search URL attempts per SKU: default 2.
- Search URL templates: editable under **Advanced Settings** for competitors with unusual search pages.
- Max product pages per competitor run: default 50.
- Max requests per batch: default 25.
- Request delay: default 3 seconds.
- Same-domain safety: enabled by default.
- Advanced URL include/exclude/product patterns.
- Advanced fallback meta keys for EAN/GTIN, MPN, and brand.

Competitor-specific technical extraction rules can be stored as JSON in competitor notes for advanced users. Normal admins should not need them.

## Performance Notes

Discovery is designed for stores with large catalogs. It only uses explicitly selected products for matching, normally around 100 to 300 products. The selected product table caches normalized identifiers so matching does not query all product meta on every run.

Background jobs process selected SKU searches, explicit source URLs, and known product URLs in bounded batches. One failed competitor does not block existing approved price monitoring.

## Safety Notes

The plugin uses the WordPress HTTP API only. It allows HTTP/HTTPS, blocks obvious local/private/reserved targets where possible, validates ports, checks DNS-resolved addresses where PHP allows it, and keeps same-domain discovery on by default.

The assistant does not bypass bot protection, CAPTCHA, JavaScript-only product pages, or blocked requests. It does not run a browser inside WordPress, use proxy rotation, scrape Google, or change WooCommerce product prices automatically.
