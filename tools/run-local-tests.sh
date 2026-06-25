#!/usr/bin/env bash
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAILED=0

if ! command -v php >/dev/null 2>&1; then
	echo "php is not available on PATH."
	exit 1
fi

TESTS=(
	"$ROOT_DIR/tools/test-price-parser.php"
	"$ROOT_DIR/tools/test-pricing-rules.php"
	"$ROOT_DIR/tools/test-price-recovery.php"
	"$ROOT_DIR/tools/test-group-suggestions.php"
	"$ROOT_DIR/tools/test-approval-tokens.php"
	"$ROOT_DIR/tools/test-price-match-display.php"
	"$ROOT_DIR/tools/test-discovery-services.php"
	"$ROOT_DIR/tools/test-sku-search-discovery.php"
)

for test_file in "${TESTS[@]}"; do
	if ! php "$test_file"; then
		FAILED=1
	fi
done

if [ "$FAILED" -ne 0 ]; then
	echo "Local tests failed."
	exit 1
fi

echo "Local tests passed."
