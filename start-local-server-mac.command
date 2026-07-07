#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "Starting Woo Price Monitor local browser worker"
echo "=============================================="
echo

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker Desktop is not installed."
	echo "Double-click install-local-server-mac.command first."
	read -r -p "Press Return to close."
	exit 1
fi

if [ ! -f ".lpm-worker-secret" ] || [ ! -f "docker-compose.yml" ]; then
	echo "The local server has not been installed yet."
	echo "Double-click install-local-server-mac.command first."
	read -r -p "Press Return to close."
	exit 1
fi

if command -v open >/dev/null 2>&1; then
	open -a Docker >/dev/null 2>&1 || true
fi

echo "Waiting for Docker Desktop to be ready..."
for i in {1..60}; do
	if docker info >/dev/null 2>&1; then
		break
	fi
	sleep 3
	if [ "$i" -eq 60 ]; then
		echo "Docker Desktop did not become ready."
		echo "Open Docker Desktop manually, wait until it is running, then start again."
		read -r -p "Press Return to close."
		exit 1
	fi
done

SECRET="$(tr -d '\r\n' < .lpm-worker-secret)"

if docker compose version >/dev/null 2>&1; then
	COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
	COMPOSE=(docker-compose)
else
	echo "Docker Compose was not found. Update Docker Desktop, then start again."
	read -r -p "Press Return to close."
	exit 1
fi

echo
echo "Server will run at: http://localhost:8787"
echo "WordPress API secret: $SECRET"
echo
echo "Keep this window open while using the worker."
echo "To stop the worker, press Control+C or close this window."
echo

"${COMPOSE[@]}" up --build browser-worker
