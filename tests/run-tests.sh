#!/bin/sh
# Run every regression test in this directory with the CLI PHP.
# No WordPress, no network, no dependencies required.
set -e
cd "$(dirname "$0")"

FAILED=0
for test in test-*.php; do
    echo "==> $test"
    if ! php "$test"; then
        FAILED=1
    fi
    echo ""
done

if [ "$FAILED" -ne 0 ]; then
    echo "SOME TEST SUITES FAILED"
    exit 1
fi
echo "ALL TEST SUITES PASSED"
