#!/usr/bin/env bash
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAILED=0
COUNT=0

if ! command -v php >/dev/null 2>&1; then
	echo "php is not available on PATH."
	exit 1
fi

while IFS= read -r -d '' file; do
	COUNT=$((COUNT + 1))
	if ! php -l "$file" >/dev/null; then
		FAILED=1
	fi
done < <(find "$ROOT_DIR" -type f -name '*.php' -not -path "$ROOT_DIR/.git/*" -not -path "$ROOT_DIR/vendor/*" -print0)

if [ "$COUNT" -eq 0 ]; then
	echo "No PHP files found."
	exit 0
fi

if [ "$FAILED" -ne 0 ]; then
	echo "PHP syntax check failed."
	exit 1
fi

echo "PHP syntax check passed for $COUNT files."
