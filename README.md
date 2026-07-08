# Woo Price Monitor

Woo Price Monitor is now organized as a monorepo with two production parts:

- `wordpress-plugin/` contains the WordPress/WooCommerce plugin.
- `browser-worker/` contains the optional Dockerized Node + Playwright renderer for JS-heavy competitor pages.

The plugin remains safe by default. It uses the internal checker unless the external worker is enabled globally and allowed for a specific competitor. Worker results still go through the existing match scoring, suggestion, approval and monitored-link flow. The worker never approves matches and never updates WooCommerce prices.

## Optional Browser Worker

### Easy Click Setup

For non-technical local testing, use the click files in this folder:

- Mac: double-click `install-local-server-mac.command`, then `start-local-server-mac.command`.
- Windows: double-click `install-local-server-windows.bat`, then `start-local-server-windows.bat`.

See `EASY-LOCAL-SERVER.md` for the simple step-by-step version.

### DigitalOcean / Ubuntu Without Finding WordPress IP

For staging and production droplets, the easiest setup is Cloudflare Tunnel:

- no DigitalOcean port `8787` exposed publicly
- no Cloudways/WordPress IP allowlist
- stable HTTPS endpoint such as `https://lpm-worker.example.com`

See `CLOUDFLARE-TUNNEL.md` or run the Ubuntu installer:

```bash
cd /tmp
curl -fsSL https://raw.githubusercontent.com/lilleprinsen-dotcom/Woo-Price-Monitor/main/install-worker-cloudflare-tunnel-ubuntu.sh -o install-worker-cloudflare-tunnel-ubuntu.sh
chmod +x install-worker-cloudflare-tunnel-ubuntu.sh
./install-worker-cloudflare-tunnel-ubuntu.sh
```

Start a local worker:

```bash
cp docker-compose.example.yml docker-compose.yml
docker compose up --build browser-worker
```

Configure the plugin in wp-admin:

1. Woo Price Monitor settings: enable External browser worker.
2. Set endpoint to `http://your-worker-host:8787`.
3. Set the same long shared secret as `LPM_WORKER_SECRET`.
4. On each JS-heavy competitor profile, open External browser worker and choose whether search pages, product pages, or both may use the worker.

The worker exposes:

- `GET /health`
- `POST /v1/search`
- `POST /v1/product`

Requests are signed with HMAC headers and rejected when stale, malformed, oversized, or outside the configured competitor domain.

## Local QA

```bash
npm run worker:check
cd wordpress-plugin && ./tools/run-local-tests.sh
```

Install worker dependencies before running worker tests:

```bash
cd browser-worker
npm install
npm test
```

## Safety Rules

- Only selected discovery products are searched.
- No full WooCommerce catalog scans.
- Discovery remains bounded and paginated.
- Competitor request timeouts and limits remain enforced.
- Matches are never auto-approved.
- Real WooCommerce price updates remain disabled by default.
- JavaScript rendering is only used for competitors explicitly configured to allow the worker.
