#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")"

echo "Woo Price Monitor local browser-worker installer"
echo "================================================"
echo

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker Desktop is needed to run the local browser worker."
	if command -v brew >/dev/null 2>&1; then
		echo "Installing Docker Desktop with Homebrew..."
		brew install --cask docker
	else
		echo "Homebrew was not found."
		echo "Opening the Docker Desktop download page. Install Docker Desktop, then run this installer again."
		open "https://www.docker.com/products/docker-desktop/"
		echo
		read -r -p "Press Return after Docker Desktop has been installed."
	fi
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
		echo "Open Docker Desktop manually, wait until it is running, then double-click this installer again."
		read -r -p "Press Return to close."
		exit 1
	fi
done

if [ ! -f ".lpm-worker-secret" ]; then
	if command -v openssl >/dev/null 2>&1; then
		openssl rand -hex 32 > .lpm-worker-secret
	else
		date | shasum -a 256 | awk '{print $1}' > .lpm-worker-secret
	fi
	chmod 600 .lpm-worker-secret
fi

SECRET="$(tr -d '\r\n' < .lpm-worker-secret)"

if [ ! -f "docker-compose.yml" ]; then
	cp docker-compose.example.yml docker-compose.yml
fi

perl -0pi -e "s/replace-with-a-long-random-secret/$SECRET/g" docker-compose.yml

if docker compose version >/dev/null 2>&1; then
	COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
	COMPOSE=(docker-compose)
else
	echo "Docker Compose was not found. Update Docker Desktop, then run this installer again."
	read -r -p "Press Return to close."
	exit 1
fi

echo "Building the local browser-worker. This may take a few minutes the first time..."
"${COMPOSE[@]}" build browser-worker

echo
echo "Installed successfully."
echo
echo "Next step: double-click start-local-server-mac.command"
echo
echo "WordPress settings:"
echo "Endpoint URL: http://localhost:8787"
echo "API secret:   $SECRET"
if command -v pbcopy >/dev/null 2>&1; then
	printf "%s" "$SECRET" | pbcopy
	echo
	echo "The API secret was copied to your clipboard."
fi
echo
read -r -p "Press Return to close."
