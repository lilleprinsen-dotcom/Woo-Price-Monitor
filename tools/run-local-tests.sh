#!/usr/bin/env bash
set -u

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FAILED=0
TIMEOUT_SECONDS="${LPM_TEST_TIMEOUT_SECONDS:-20}"

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
	"$ROOT_DIR/tools/test-discovery-product-admin.php"
	"$ROOT_DIR/tools/test-discovery-services.php"
	"$ROOT_DIR/tools/test-sku-search-discovery.php"
	"$ROOT_DIR/tools/test-manual-discovery.php"
)

COMPLETED=()

for test_file in "${TESTS[@]}"; do
	test_name="$(basename "$test_file")"
	echo "Running ${test_name}..."
	if ! php -d max_execution_time="$TIMEOUT_SECONDS" "$test_file"; then
		FAILED=1
		echo "Test block failed or timed out: ${test_name}"
		echo "Completed before failure: ${COMPLETED[*]:-(none)}"
		echo "Timeout limit per PHP test file: ${TIMEOUT_SECONDS}s"
		continue
	fi
	COMPLETED+=("$test_name")
done

if [ "$FAILED" -ne 0 ]; then
	echo "Local tests failed."
	exit 1
fi

echo "Local tests passed."
