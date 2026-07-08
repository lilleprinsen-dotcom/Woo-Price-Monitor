# No-IP Browser Worker Setup With Cloudflare Tunnel

This setup runs the Woo Price Monitor browser worker on a DigitalOcean Ubuntu droplet without opening a public worker port and without allowlisting the WordPress server IP.

Cloudflare Tunnel keeps an outbound-only connection from the droplet to Cloudflare. WordPress calls a stable HTTPS hostname, for example `https://lpm-worker.example.com`, and Cloudflare routes that request to the local worker container.

The worker still requires the Woo Price Monitor HMAC secret. The tunnel only removes the need to find or maintain Cloudways/WordPress IP firewall rules.

## 1. Create The Tunnel In Cloudflare

1. Open Cloudflare Zero Trust.
2. Go to **Networks** / **Tunnels**.
3. Create a tunnel, for example `woo-price-monitor-worker`.
4. Choose **Docker** as the environment.
5. Copy the tunnel token.
6. Add a public hostname:
   - Hostname: `lpm-worker.yourdomain.com`
   - Service type: `HTTP`
   - Service URL: `browser-worker:8787`

Do not use `localhost:8787` when running with this repository's Docker Compose file. `cloudflared` runs in a separate container and reaches the worker by Docker service name: `browser-worker:8787`.

## 2. Run The Installer On Ubuntu

SSH into the droplet and run:

```bash
cd /tmp
curl -fsSL https://raw.githubusercontent.com/lilleprinsen-dotcom/Woo-Price-Monitor/main/install-worker-cloudflare-tunnel-ubuntu.sh -o install-worker-cloudflare-tunnel-ubuntu.sh
chmod +x install-worker-cloudflare-tunnel-ubuntu.sh
./install-worker-cloudflare-tunnel-ubuntu.sh
```

Paste the Cloudflare Tunnel token when asked.

The installer will:

- install Docker if needed
- clone/update the repo in `/opt/Woo-Price-Monitor`
- generate a long `LPM_WORKER_SECRET`
- save secrets in `/opt/Woo-Price-Monitor/.env.worker`
- start `browser-worker` and `cloudflared`

## 3. Configure WordPress

In Woo Price Monitor settings:

- Enable external browser worker
- Worker endpoint URL: `https://lpm-worker.yourdomain.com`
- Worker API secret: the secret printed by the installer

Then use the plugin's test connection button.

## Useful Commands

```bash
cd /opt/Woo-Price-Monitor

# Status
docker compose --env-file .env.worker -f docker-compose.cloudflare-tunnel.yml ps

# Logs
docker compose --env-file .env.worker -f docker-compose.cloudflare-tunnel.yml logs -f

# Restart
docker compose --env-file .env.worker -f docker-compose.cloudflare-tunnel.yml restart

# Update later
git pull
docker compose --env-file .env.worker -f docker-compose.cloudflare-tunnel.yml up -d --build

# Print worker secret again
grep '^LPM_WORKER_SECRET=' .env.worker | cut -d= -f2-
```

## Why This Avoids IP Problems

- No inbound DigitalOcean port needs to be open for `8787`.
- No Cloudways, staging, or production IP must be allowlisted.
- Moving WordPress to a new server does not require a droplet firewall change.
- You only keep the WordPress worker endpoint and secret in sync.

## Security Notes

- Keep `.env.worker` private.
- Do not commit tunnel tokens or worker secrets.
- Keep the worker endpoint secret configured in WordPress.
- Worker results still require normal Woo Price Monitor approval. The worker never updates WooCommerce prices and never auto-approves matches.
