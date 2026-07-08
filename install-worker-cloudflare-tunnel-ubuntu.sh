#!/usr/bin/env bash
set -euo pipefail

REPO_URL="https://github.com/lilleprinsen-dotcom/Woo-Price-Monitor.git"
INSTALL_DIR="/opt/Woo-Price-Monitor"

echo "Woo Price Monitor browser-worker + Cloudflare Tunnel installer"
echo
echo "You need a Cloudflare Tunnel token before running this."
echo "In Cloudflare: Zero Trust / Networks / Tunnels / Create tunnel / Docker."
echo

if [[ ${EUID} -eq 0 ]]; then
  echo "Run this script as a normal sudo user, not directly as root."
  exit 1
fi

read -r -p "Paste Cloudflare Tunnel token: " CLOUDFLARE_TUNNEL_TOKEN
if [[ -z "${CLOUDFLARE_TUNNEL_TOKEN}" ]]; then
  echo "Tunnel token is required."
  exit 1
fi

echo
echo "Installing Docker and dependencies..."
sudo apt update
sudo apt install -y ca-certificates curl git openssl

if ! command -v docker >/dev/null 2>&1; then
  sudo install -m 0755 -d /etc/apt/keyrings
  sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  sudo chmod a+r /etc/apt/keyrings/docker.asc
  sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: $(. /etc/os-release && echo "${UBUNTU_CODENAME:-$VERSION_CODENAME}")
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
  sudo apt update
  sudo apt install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

sudo systemctl enable --now docker

echo
echo "Installing repository in ${INSTALL_DIR}..."
if [[ -d "${INSTALL_DIR}/.git" ]]; then
  cd "${INSTALL_DIR}"
  sudo git pull --ff-only
else
  sudo git clone "${REPO_URL}" "${INSTALL_DIR}"
fi
sudo chown -R "${USER}:${USER}" "${INSTALL_DIR}"
cd "${INSTALL_DIR}"

if [[ -f .env.worker ]]; then
  echo ".env.worker already exists. Keeping existing worker secret."
  EXISTING_SECRET="$(grep '^LPM_WORKER_SECRET=' .env.worker | cut -d= -f2- || true)"
  LPM_WORKER_SECRET="${EXISTING_SECRET:-$(openssl rand -hex 32)}"
else
  LPM_WORKER_SECRET="$(openssl rand -hex 32)"
fi

cat > .env.worker <<EOF
LPM_WORKER_SECRET=${LPM_WORKER_SECRET}
CLOUDFLARE_TUNNEL_TOKEN=${CLOUDFLARE_TUNNEL_TOKEN}
EOF
chmod 600 .env.worker

echo
echo "Starting browser-worker behind Cloudflare Tunnel..."
docker compose --env-file .env.worker -f docker-compose.cloudflare-tunnel.yml up -d --build

echo
echo "Done."
echo
echo "Use this secret in the Woo Price Monitor WordPress settings:"
echo "${LPM_WORKER_SECRET}"
echo
echo "Use your Cloudflare public hostname as the Worker endpoint URL, for example:"
echo "https://lpm-worker.example.com"
echo
echo "Check status:"
echo "cd ${INSTALL_DIR} && docker compose --env-file .env.worker -f docker-compose.cloudflare-tunnel.yml ps"
echo
echo "Show logs:"
echo "cd ${INSTALL_DIR} && docker compose --env-file .env.worker -f docker-compose.cloudflare-tunnel.yml logs -f"
