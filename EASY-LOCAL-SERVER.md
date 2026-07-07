# Easy Local Browser Worker

This starts the optional local browser-worker server for Woo Price Monitor. Use it for competitors where the normal checker cannot see products because the site loads results with JavaScript.

## What To Click

Mac:

1. Double-click `install-local-server-mac.command`.
2. When it finishes, double-click `start-local-server-mac.command`.

Windows:

1. Double-click `install-local-server-windows.bat`.
2. When it finishes, double-click `start-local-server-windows.bat`.

## What It Starts

The local server runs at:

```text
http://localhost:8787
```

The scripts create a private shared secret in:

```text
.lpm-worker-secret
```

Do not share that secret publicly.

## WordPress Settings

In wp-admin:

1. Go to Woo Price Monitor settings.
2. Enable External browser worker.
3. Worker endpoint URL:

```text
http://localhost:8787
```

4. Worker API secret: copy the secret printed by the start script.
5. On each competitor that needs it, set External browser worker to allow search pages, product pages, or both.

The worker never approves matches and never updates WooCommerce prices.

## If Docker Is Not Running

Open Docker Desktop first, wait until it says it is running, then double-click the start file again.

