import test from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { readFile } from 'node:fs/promises';
import { createHmac, createHash } from 'node:crypto';
import { createServer, closeWorker } from '../src/server.js';

process.env.NODE_ENV = 'test';
process.env.LPM_WORKER_SECRET = 'test-secret';
process.env.LPM_ALLOW_PRIVATE_HOSTS = '1';

let nonceCounter = 0;

function listen(server) {
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve(server.address().port)));
}

async function fixtureServer() {
  const server = http.createServer(async (req, res) => {
    const file = req.url?.startsWith('/products/') ? 'product.html' : 'search.html';
    const body = await readFile(new URL(`../fixtures/${file}`, import.meta.url), 'utf8');
    res.writeHead(200, { 'content-type': 'text/html; charset=utf-8' });
    res.end(body);
  });
  const port = await listen(server);
  return { server, port };
}

function signedBody(payload) {
	const body = JSON.stringify(payload);
	const timestamp = String(Math.floor(Date.now() / 1000));
	const nonce = `test-nonce-${++nonceCounter}`;
  const bodyHash = createHash('sha256').update(body).digest('hex');
  const signature = createHmac('sha256', 'test-secret').update(`${timestamp}.${nonce}.${bodyHash}`).digest('hex');
  return {
    body,
    headers: {
      'content-type': 'application/json',
      'x-lpm-timestamp': timestamp,
      'x-lpm-nonce': nonce,
      'x-lpm-body-sha256': bodyHash,
      'x-lpm-signature': signature
    }
  };
}

function post(port, path, payload, signed = true) {
  const request = signed ? signedBody(payload) : { body: JSON.stringify(payload), headers: { 'content-type': 'application/json' } };
  return fetch(`http://127.0.0.1:${port}${path}`, {
    method: 'POST',
    headers: request.headers,
    body: request.body
  }).then(async (response) => ({ status: response.status, json: await response.json() }));
}

test('health endpoint reports ready', async (t) => {
  const server = createServer();
  const port = await listen(server);
  t.after(async () => {
    server.close();
    await closeWorker();
  });
  const response = await fetch(`http://127.0.0.1:${port}/health`);
  assert.equal(response.status, 200);
  assert.equal((await response.json()).ready, true);
});

test('HMAC auth is required', async (t) => {
  const server = createServer();
  const port = await listen(server);
  t.after(async () => {
    server.close();
    await closeWorker();
  });
  const response = await post(port, '/v1/search', { url: 'https://example.com', competitorDomain: 'example.com' }, false);
  assert.equal(response.status, 401);
});

test('HMAC nonce replay is rejected', async (t) => {
  const server = createServer();
  const port = await listen(server);
  t.after(async () => {
    server.close();
    await closeWorker();
  });
  const request = signedBody({ url: 'https://other.example/search', competitorDomain: 'example.com' });
  const first = await fetch(`http://127.0.0.1:${port}/v1/search`, {
    method: 'POST',
    headers: request.headers,
    body: request.body
  });
  assert.notEqual(first.status, 401);
  const replay = await fetch(`http://127.0.0.1:${port}/v1/search`, {
    method: 'POST',
    headers: request.headers,
    body: request.body
  });
  assert.equal(replay.status, 401);
});

test('rejects wrong-domain URLs', async (t) => {
  const server = createServer();
  const port = await listen(server);
  t.after(async () => {
    server.close();
    await closeWorker();
  });
  const response = await post(port, '/v1/search', { url: 'https://other.example/search', competitorDomain: 'example.com' });
  assert.equal(response.status, 400);
});

test('extracts JS-rendered search product cards and product data', async (t) => {
  const worker = createServer();
  const workerPort = await listen(worker);
  const fixture = await fixtureServer();
  t.after(async () => {
    worker.close();
    fixture.server.close();
    await closeWorker();
  });

  const search = await post(workerPort, '/v1/search', {
    url: `http://127.0.0.1:${fixture.port}/search?q=thule`,
    competitorDomain: '127.0.0.1',
    maxCandidates: 1,
    timeoutMs: 8000
  });
  assert.equal(search.status, 200);
  assert.equal(search.json.success, true);
  assert.equal(search.json.candidates.length, 1);
  assert.match(search.json.candidates[0].title, /Thule/);

  const product = await post(workerPort, '/v1/product', {
    url: `http://127.0.0.1:${fixture.port}/products/thule-bag-black`,
    competitorDomain: '127.0.0.1',
    extractionHints: {},
    timeoutMs: 8000
  });
  assert.equal(product.status, 200);
  assert.equal(product.json.success, true);
  assert.equal(product.json.sku, '20110754');
  assert.equal(product.json.gtin, '872299049660');
  assert.equal(product.json.monitored_price, 3499);
});
