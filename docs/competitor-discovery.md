# Competitor Price Assistant

Competitor Price Assistant helps store staff find competitor product pages for the WooCommerce products they choose. Discovery is separate from approved price monitoring: it creates suggested matches, and regular monitoring starts only after an admin approves a suggestion.

## Select Products

Discovery never scans the full catalog by default. Add only the products that should be eligible for competitor matching.

You can add products from:

- The product edit screen: check **Include in competitor discovery**.
- The WooCommerce product list: use the bulk action **Include in competitor discovery**.
- **WooCommerce > Competitor Prices > Products to Monitor**: paste SKUs into **Add products by SKU**.

The selected-products page shows SKU, EAN/GTIN, brand, pending suggestions, last discovery run, and status. Identifier counts and duplicate warnings are shown on the overview page.

## Add Or Test A Competitor

Open **WooCommerce > Competitor Prices > Find Matches**.

1. Choose an existing competitor or create a new one.
2. Enter the competitor name and website/domain when creating a new competitor.
3. Paste one competitor product URL.
4. Click **Test Product Page**.

The assistant shows the values it found:

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

Source labels are plain-language labels such as **Structured product data**, **Product meta tag**, **Page content**, and **Image URL**. Technical details stay hidden unless the page could not be read.

## Review Suggested Matches

Open **WooCommerce > Competitor Prices > Suggested Matches**.

Each suggestion explains why it was suggested and shows a confidence label:

- **High confidence**: exact EAN/GTIN, exact SKU, or exact MPN with the same brand.
- **Medium confidence**: same brand and very similar product title.
- **Low confidence**: similar title or weak identifier evidence.

Use **Approve** to convert the suggestion into the existing competitor link structure used by regular price monitoring. Use **Reject** to keep the same suggestion from reappearing unless product identifiers or competitor page data changes.

No suggestions are auto-approved by default.

## Advanced Settings

Open **WooCommerce > Competitor Prices > Advanced Settings**.

Useful settings:

- Weekly discovery jobs: off unless enabled.
- Max product pages per competitor run: default 50.
- Request delay: default 3 seconds.
- EAN/GTIN fallback meta keys: examples include `_alg_ean`, `_wpm_gtin_code`, `_global_unique_id`, `ean`, `gtin`, and `barcode`.
- MPN and brand meta keys.
- Same-domain safety: enabled by default.

Variation-level identifiers are preferred over parent product identifiers when WooCommerce exposes them through the product object or configured meta keys.

## Performance Notes

Discovery is designed for stores with large catalogs. It only uses explicitly selected products for matching, normally around 100 to 300 products. The selected product table caches normalized identifiers so matching does not query all product meta on every run.

Background jobs refresh known competitor product pages in bounded batches. One failed competitor page does not block existing approved price monitoring.

## Limitations

The assistant does not bypass bot protection, CAPTCHA, JavaScript-only product pages, or blocked requests. It does not run a browser inside WordPress, use proxy rotation, scrape Google, or change WooCommerce product prices automatically.
