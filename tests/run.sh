#!/usr/bin/env bash
# From the repo root: bash tests/run.sh
set -e
cd "$(dirname "$0")/.."
for t in tests/*.test.php; do php "$t" | grep -v '^ok' | grep . && exit 1 || true; php "$t" > /dev/null; done
for f in jouver-stripe.php includes/*.php; do php -l "$f" > /dev/null; done
echo "all tests passed"
