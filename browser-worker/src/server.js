import http from 'node:http';
import crypto from 'node:crypto';
import dns from 'node:dns/promises';
import net from 'node:net';
import { URL } from 'node:url';
import { chromium } from 'playwright';

const PORT = Number(process.env.PORT || 8787);
const MAX_BODY_BYTES = Number(process.env.LPM_ALLOWED_MAX_BODY_BYTES || 65536);
const SIGNATURE_TOLERANCE_SECONDS = Number(process.env.LPM_SIGNATURE_TOLERANCE_SECONDS || 300);
const DEFAULT_TIMEOUT_MS = 20000;
const DEFAULT_MAX_CANDIDATES = 8;
const MAX_CONCURRENT_RENDERS = Number(process.env.LPM_MAX_CONCURRENT_RENDERS || 2);

let browserPromise;
let server;
let activeRenders = 0;
const renderQueue = [];
const seenNonces = new Map();

function jsonResponse(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store',
    'content-length': Buffer.byteLength(body)
  });
  res.end(body);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(new Error('Request body is too large.'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

function timingSafeEqual(left, right) {
  const leftBuffer = Buffer.from(String(left || ''), 'hex');
  const rightBuffer = Buffer.from(String(right || ''), 'hex');
  return leftBuffer.length === rightBuffer.length && crypto.timingSafeEqual(leftBuffer, rightBuffer);
}

function verifySignature(headers, body) {
  const secret = process.env.LPM_WORKER_SECRET || '';
  if (!secret) {
    return { ok: false, error: 'Worker secret is not configured.' };
  }
  const timestamp = String(headers['x-lpm-timestamp'] || '');
  const nonce = String(headers['x-lpm-nonce'] || '');
  const bodyHash = String(headers['x-lpm-body-sha256'] || '');
  const signature = String(headers['x-lpm-signature'] || '');
  if (!timestamp || !nonce || !bodyHash || !signature) {
    return { ok: false, error: 'Missing request signature headers.' };
  }
  const now = Math.floor(Date.now() / 1000);
  const age = Math.abs(now - Number(timestamp));
  if (!Number.isFinite(age) || age > SIGNATURE_TOLERANCE_SECONDS) {
    return { ok: false, error: 'Request signature timestamp is stale.' };
  }
  const actualBodyHash = crypto.createHash('sha256').update(body).digest('hex');
  if (!timingSafeEqual(bodyHash, actualBodyHash)) {
    return { ok: false, error: 'Request body hash does not match.' };
  }
  const expected = crypto.createHmac('sha256', secret).update(`${timestamp}.${nonce}.${bodyHash}`).digest('hex');
  if (!timingSafeEqual(signature, expected)) {
    return { ok: false, error: 'Request signature is invalid.' };
  }
  cleanupNonceCache(now);
  const nonceKey = `${timestamp}.${nonce}`;
  if (seenNonces.has(nonceKey)) {
    return { ok: false, error: 'Request signature nonce has already been used.' };
  }
  seenNonces.set(nonceKey, now + SIGNATURE_TOLERANCE_SECONDS);
  return { ok: true };
}

function cleanupNonceCache(now = Math.floor(Date.now() / 1000)) {
  for (const [key, expiresAt] of seenNonces.entries()) {
    if (expiresAt <= now) {
      seenNonces.delete(key);
    }
  }
}

function normalizeDomain(domain) {
  return String(domain || '').trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/.*$/, '');
}

function isPrivateHost(hostname) {
  const host = String(hostname || '').toLowerCase();
  return host === 'localhost' || isPrivateAddress(host);
}

function isPrivateAddress(address) {
  const value = String(address || '').toLowerCase();
  if (net.isIP(value) === 6) {
    return value === '::1' || value.startsWith('fc') || value.startsWith('fd') || value.startsWith('fe80:');
  }
  if (net.isIP(value) !== 4) {
    return false;
  }
  return value === '127.0.0.1'
    || value.startsWith('127.')
    || value.startsWith('10.')
    || value.startsWith('192.168.')
    || value.startsWith('169.254.')
    || /^172\.(1[6-9]|2\d|3[0-1])\./.test(value)
    || value.startsWith('0.');
}

function allowPrivateHosts() {
  return process.env.LPM_ALLOW_PRIVATE_HOSTS === '1' || process.env.NODE_ENV === 'test';
}

async function validateCompetitorUrl(rawUrl, competitorDomain) {
  let parsed;
  try {
    parsed = new URL(String(rawUrl || ''));
  } catch {
    return { ok: false, error: 'URL is invalid.' };
  }
  if (!['http:', 'https:'].includes(parsed.protocol)) {
    return { ok: false, error: 'Only HTTP and HTTPS URLs are allowed.' };
  }
  const domain = normalizeDomain(competitorDomain);
  const host = parsed.hostname.toLowerCase();
  if (!domain || (host !== domain && !host.endsWith(`.${domain}`))) {
    return { ok: false, error: 'URL does not match the configured competitor domain.' };
  }
  if (!allowPrivateHosts()) {
    if (isPrivateHost(host)) {
      return { ok: false, error: 'Private network URLs are not allowed.' };
    }
    try {
      const records = await dns.lookup(host, { all: true, verbatim: true });
      if (records.some((record) => isPrivateAddress(record.address))) {
        return { ok: false, error: 'Private network DNS targets are not allowed.' };
      }
    } catch {
      return { ok: false, error: 'Could not resolve competitor hostname safely.' };
    }
  }
  return { ok: true, url: parsed.toString(), domain };
}

async function withRenderSlot(callback) {
  if (activeRenders >= MAX_CONCURRENT_RENDERS) {
    await new Promise((resolve) => renderQueue.push(resolve));
  }
  activeRenders += 1;
  try {
    return await callback();
  } finally {
    activeRenders = Math.max(0, activeRenders - 1);
    const next = renderQueue.shift();
    if (next) {
      next();
    }
  }
}

async function getBrowser() {
  if (!browserPromise) {
    browserPromise = chromium.launch({ headless: true });
  }
  return browserPromise;
}

async function newPage(timeoutMs) {
  const browser = await getBrowser();
  const context = await browser.newContext({
    userAgent: 'Woo Price Monitor Browser Worker/0.1',
    viewport: { width: 1365, height: 900 },
    javaScriptEnabled: true
  });
  await context.route('**/*', (route) => {
    const type = route.request().resourceType();
    if (['font', 'media'].includes(type)) {
      route.abort().catch(() => {});
      return;
    }
    route.continue().catch(() => {});
  });
  const page = await context.newPage();
  page.setDefaultTimeout(timeoutMs);
  page.setDefaultNavigationTimeout(timeoutMs);
  return { page, context };
}

function capNumber(value, fallback, min, max) {
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, Math.floor(number)));
}

async function renderPage(url, timeoutMs) {
  const diagnostics = [];
  const { page, context } = await newPage(timeoutMs);
  try {
    const started = Date.now();
    const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: timeoutMs });
    diagnostics.push(`Rendered ${page.url()} with HTTP ${response ? response.status() : 'unknown'}.`);
    await Promise.race([
      page.waitForLoadState('networkidle', { timeout: Math.min(timeoutMs, 4000) }).catch(() => {}),
      page.locator('[itemtype*="schema.org/Product"], [data-product-id], [data-product-sku], .product-item, .product-card, article').first().waitFor({ timeout: Math.min(timeoutMs, 6000) }).catch(() => {}),
      page.getByText(/(?:kr|nok|,-|\d[\d\s,.]+\s*NOK)/i).first().waitFor({ timeout: Math.min(timeoutMs, 6000) }).catch(() => {})
    ]);
    diagnostics.push(`Rendered page in ${Date.now() - started} ms.`);
    return { page, context, diagnostics };
  } catch (error) {
    await context.close().catch(() => {});
    throw error;
  }
}

async function extractSearchCandidates(page, competitorDomain, maxCandidates) {
  return await page.evaluate(({ competitorDomain, maxCandidates }) => {
    const domain = String(competitorDomain || '').toLowerCase();
    const moneyPattern = /(?:kr|nok)\s*[\d\s.,]+|[\d\s.,]+\s*(?:kr|nok)|[\d\s.,]+,-/i;

    function absUrl(value) {
      try {
        return new URL(value, location.href).toString();
      } catch {
        return '';
      }
    }

    function sameDomain(url) {
      try {
        const host = new URL(url).hostname.toLowerCase();
        return host === domain || host.endsWith(`.${domain}`);
      } catch {
        return false;
      }
    }

    function text(el) {
      return (el ? el.textContent || '' : '').replace(/\s+/g, ' ').trim();
    }

    function image(el) {
      const img = el.querySelector('img');
      return img ? absUrl(img.currentSrc || img.src || img.getAttribute('data-src') || '') : '';
    }

    function price(el) {
      const explicit = el.querySelector('[class*="price"], [data-price], [itemprop="price"]');
      const value = explicit ? (explicit.getAttribute('content') || explicit.getAttribute('data-price') || text(explicit)) : '';
      const matched = value.match(moneyPattern) || text(el).match(moneyPattern);
      return matched ? matched[0] : '';
    }

    function titleFrom(el, anchor) {
      const titleEl = el.querySelector('[class*="name"], [class*="title"], [itemprop="name"], h2, h3, h4');
      return text(titleEl) || text(anchor) || anchor.getAttribute('aria-label') || anchor.getAttribute('title') || '';
    }

    const selector = [
      '[itemtype*="schema.org/Product"]',
      '[data-product-id]',
      '[data-product-sku]',
      '[data-product-name]',
      '.product-item',
      '.product-card',
      '.product-tile',
      '.product',
      'li[class*="product"]',
      'article'
    ].join(',');
    const nodes = Array.from(document.querySelectorAll(selector));
    const seen = new Set();
    const candidates = [];

    for (const node of nodes) {
      const anchors = Array.from(node.querySelectorAll('a[href]'));
      for (const anchor of anchors) {
        const url = absUrl(anchor.getAttribute('href'));
        if (!url || !sameDomain(url) || seen.has(url)) {
          continue;
        }
        const title = titleFrom(node, anchor);
        const visiblePrice = price(node);
        const hasProductSignal = title || visiblePrice || image(node) || node.getAttribute('data-product-id') || node.getAttribute('data-product-sku');
        if (!hasProductSignal) {
          continue;
        }
        seen.add(url);
        candidates.push({
          url,
          title,
          image: image(node),
          price: visiblePrice,
          source: 'rendered_product_card'
        });
        break;
      }
      if (candidates.length >= maxCandidates) {
        break;
      }
    }

    return candidates.slice(0, maxCandidates);
  }, { competitorDomain, maxCandidates });
}

function parsePrice(value) {
  const raw = String(value || '').replace(/\u00a0/g, ' ');
  const matched = raw.match(/(?:kr|nok)?\s*([0-9][0-9\s.,]*)(?:,-|\s*(?:kr|nok))?/i);
  if (!matched) {
    return null;
  }
  let normalized = matched[1].replace(/\s/g, '');
  if (normalized.includes(',') && !normalized.includes('.')) {
    normalized = normalized.replace(',', '.');
  } else if (normalized.includes(',') && normalized.includes('.') && normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
    normalized = normalized.replace(/\./g, '').replace(',', '.');
  } else {
    normalized = normalized.replace(/,/g, '');
  }
  const number = Number(normalized);
  return Number.isFinite(number) ? number : null;
}

async function safeText(page, selector) {
  if (!selector) {
    return '';
  }
  try {
    return (await page.locator(selector).first().textContent({ timeout: 1000 }) || '').replace(/\s+/g, ' ').trim();
  } catch {
    return '';
  }
}

async function extractProduct(page, hints) {
  return await page.evaluate(() => {
    function text(el) {
      return (el ? el.textContent || '' : '').replace(/\s+/g, ' ').trim();
    }
    function attr(selector, name) {
      const el = document.querySelector(selector);
      return el ? el.getAttribute(name) || '' : '';
    }
    function absUrl(value) {
      try {
        return new URL(value, location.href).toString();
      } catch {
        return '';
      }
    }
    function allJsonLdProducts() {
      const products = [];
      for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
        try {
          const data = JSON.parse(script.textContent || 'null');
          const items = Array.isArray(data) ? data : [data];
          for (const item of items) {
            if (!item) continue;
            const graph = Array.isArray(item['@graph']) ? item['@graph'] : [item];
            for (const node of graph) {
              const type = Array.isArray(node['@type']) ? node['@type'].join(' ') : String(node['@type'] || '');
              if (/Product/i.test(type)) products.push(node);
            }
          }
        } catch {
          // Ignore invalid JSON-LD from competitor pages.
        }
      }
      return products;
    }
    const json = allJsonLdProducts()[0] || {};
    const offers = Array.isArray(json.offers) ? json.offers[0] || {} : json.offers || {};
    const metaPrice = attr('meta[property="product:price:amount"], meta[itemprop="price"]', 'content');
    const title = json.name || attr('meta[property="og:title"]', 'content') || text(document.querySelector('h1')) || document.title || '';
    const image = Array.isArray(json.image) ? json.image[0] : json.image || attr('meta[property="og:image"]', 'content') || '';
    const bodyText = text(document.body);
    const stockText = `${offers.availability || ''} ${bodyText}`.toLowerCase();
    const skuMatch = bodyText.match(/\b(?:SKU|Varenr|Artikkelnummer|Produktnummer)\s*[:#]?\s*([A-Z0-9._-]{3,})/i);
    const eanMatch = bodyText.match(/\b(?:EAN|GTIN)\s*[:#]?\s*(\d{8,14})\b/i);
    return {
      url: location.href,
      canonicalUrl: attr('link[rel="canonical"]', 'href') ? absUrl(attr('link[rel="canonical"]', 'href')) : location.href,
      title,
      sku: json.sku || skuMatch?.[1] || '',
      gtin: json.gtin13 || json.gtin14 || json.gtin || eanMatch?.[1] || '',
      mpn: json.mpn || '',
      brand: typeof json.brand === 'string' ? json.brand : json.brand?.name || '',
      rawPrice: offers.price || metaPrice || bodyText.match(/(?:kr|nok)\s*[\d\s.,]+|[\d\s.,]+\s*(?:kr|nok)|[\d\s.,]+,-/i)?.[0] || '',
      currency: offers.priceCurrency || attr('meta[property="product:price:currency"]', 'content') || 'NOK',
      stockStatus: /outofstock|ikke på lager|utsolgt|sold out/i.test(stockText) ? 'out_of_stock' : (/instock|på lager|lager/i.test(stockText) ? 'in_stock' : 'unknown'),
      imageUrl: image ? absUrl(image) : ''
    };
  }).then(async (data) => {
    const priceSelectorText = await safeText(page, hints.priceSelector || hints.salePriceSelector || hints.regularPriceSelector || '');
    const skuSelectorText = await safeText(page, hints.skuSelector || '');
    const gtinSelectorText = await safeText(page, hints.gtinSelector || '');
    const stockSelectorText = await safeText(page, hints.stockSelector || '');
    const selectedPrice = parsePrice(priceSelectorText) ?? parsePrice(data.rawPrice);
    return {
      ...data,
      sku: skuSelectorText || data.sku,
      gtin: gtinSelectorText || data.gtin,
      monitored_price: selectedPrice,
      salePrice: selectedPrice,
      regularPrice: selectedPrice,
      stockStatus: /ikke på lager|utsolgt|sold out/i.test(stockSelectorText) ? 'out_of_stock' : (/på lager|lager|in stock/i.test(stockSelectorText) ? 'in_stock' : data.stockStatus),
      price_candidates: selectedPrice === null ? [] : [{ value: selectedPrice, source: priceSelectorText ? 'selector' : 'rendered_visible_text', field: 'sale_price' }]
    };
  });
}

async function handleSearch(payload) {
  const validation = await validateCompetitorUrl(payload.url, payload.competitorDomain);
  if (!validation.ok) {
    return { status: 400, body: { success: false, error: validation.error } };
  }
  const timeoutMs = capNumber(payload.timeoutMs, DEFAULT_TIMEOUT_MS, 3000, 60000);
  const maxCandidates = capNumber(payload.maxCandidates, DEFAULT_MAX_CANDIDATES, 1, 25);
  return await withRenderSlot(async () => {
    const { page, context, diagnostics } = await renderPage(validation.url, timeoutMs);
    try {
      const candidates = await extractSearchCandidates(page, validation.domain, maxCandidates);
      if (!candidates.length) {
        diagnostics.push('No rendered product-card candidates were found.');
      }
      return {
        status: 200,
        body: {
          success: true,
          finalUrl: page.url(),
          candidates,
          diagnostics
        }
      };
    } finally {
      await context.close().catch(() => {});
    }
  });
}

async function handleProduct(payload) {
  const validation = await validateCompetitorUrl(payload.url, payload.competitorDomain);
  if (!validation.ok) {
    return { status: 400, body: { success: false, error: validation.error } };
  }
  const timeoutMs = capNumber(payload.timeoutMs, DEFAULT_TIMEOUT_MS, 3000, 60000);
  return await withRenderSlot(async () => {
    const { page, context, diagnostics } = await renderPage(validation.url, timeoutMs);
    try {
      const data = await extractProduct(page, payload.extractionHints || {}, payload.expected || {});
      if (data.monitored_price === null) {
        diagnostics.push('Rendered page did not expose a readable price.');
      }
      return {
        status: 200,
        body: {
          success: true,
          ...data,
          diagnostics
        }
      };
    } finally {
      await context.close().catch(() => {});
    }
  });
}

async function route(req, res) {
  if (req.method === 'GET' && req.url === '/health') {
    try {
      await getBrowser();
      jsonResponse(res, 200, { success: true, ready: true, browser: 'chromium' });
    } catch (error) {
      jsonResponse(res, 503, { success: false, ready: false, error: error.message });
    }
    return;
  }

  if (req.method !== 'POST' || !['/v1/search', '/v1/product'].includes(req.url || '')) {
    jsonResponse(res, 404, { success: false, error: 'Not found.' });
    return;
  }

  let body = '';
  try {
    body = await readBody(req);
  } catch (error) {
    jsonResponse(res, 413, { success: false, error: error.message });
    return;
  }

  const signature = verifySignature(req.headers, body);
  if (!signature.ok) {
    jsonResponse(res, 401, { success: false, error: signature.error });
    return;
  }

  let payload;
  try {
    payload = JSON.parse(body);
  } catch {
    jsonResponse(res, 400, { success: false, error: 'Malformed JSON body.' });
    return;
  }

  try {
    const result = req.url === '/v1/search' ? await handleSearch(payload) : await handleProduct(payload);
    jsonResponse(res, result.status, result.body);
  } catch (error) {
    jsonResponse(res, 502, { success: false, error: error.message || 'Browser worker failed.' });
  }
}

export function createServer() {
  return http.createServer((req, res) => {
    route(req, res).catch((error) => jsonResponse(res, 500, { success: false, error: error.message }));
  });
}

if (process.argv[1] === new URL(import.meta.url).pathname) {
  server = createServer();
  server.listen(PORT, () => {
    console.log(`Woo Price Monitor browser worker listening on ${PORT}`);
  });
}

export async function closeWorker() {
  if (server) {
    await new Promise((resolve) => server.close(resolve));
  }
  if (browserPromise) {
    const browser = await browserPromise;
    await browser.close();
    browserPromise = undefined;
  }
  seenNonces.clear();
  activeRenders = 0;
  renderQueue.splice(0).forEach((resolve) => resolve());
}
