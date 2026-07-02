#!/usr/bin/env bash

set -euo pipefail

MODE="${1:-}"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ "${MODE}" != "dev" ] && [ "${MODE}" != "prod" ]; then
    echo "Usage: bin/vendor-mode.sh <dev|prod>" >&2
    exit 1
fi

echo "Resetting vendor directory (${MODE} mode)..."
rm -rf "${ROOT}/vendor"

COMPOSER_ARGS=(
    "--working-dir=${ROOT}"
    "--no-interaction"
    "--no-progress"
)

if [ "${MODE}" = "prod" ]; then
    COMPOSER_ARGS+=("--no-dev" "--optimize-autoloader")
fi

composer install "${COMPOSER_ARGS[@]}"
echo "Vendor directory is now in ${MODE} mode."