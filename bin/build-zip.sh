#!/usr/bin/env bash
#
# Builds the distributable plugin ZIP.
#
# Usage: bin/build-zip.sh [output-name.zip]
#
# Stages a clean copy of the runtime tree (respecting .distignore), runs a
# production composer install inside the stage, and zips it with the plugin
# slug as the top-level directory so WordPress installs it correctly.

set -euo pipefail

SLUG="webmultipliers-wp-artifact-canvas"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZIP_NAME="${1:-${SLUG}.zip}"
STAGE="$(mktemp -d)"

trap 'rm -rf "${STAGE}"' EXIT

echo "Staging clean tree..."
mkdir -p "${STAGE}/${SLUG}"
rsync -a "${ROOT}/" "${STAGE}/${SLUG}/" \
    --exclude-from="${ROOT}/.distignore" \
    --exclude "vendor"

echo "Installing production dependencies..."
cp "${ROOT}/composer.json" "${ROOT}/composer.lock" "${STAGE}/${SLUG}/"
composer install \
    --working-dir="${STAGE}/${SLUG}" \
    --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "${STAGE}/${SLUG}/composer.json" "${STAGE}/${SLUG}/composer.lock"

echo "Verifying staged tree..."
for required in \
    "${STAGE}/${SLUG}/${SLUG}.php" \
    "${STAGE}/${SLUG}/src/Plugin.php" \
    "${STAGE}/${SLUG}/blocks/artifact/block.json" \
    "${STAGE}/${SLUG}/vendor/autoload.php" \
    "${STAGE}/${SLUG}/uninstall.php" \
    "${STAGE}/${SLUG}/README.md"; do
    if [ ! -e "${required}" ]; then
        echo "ERROR: staged tree missing required entry: ${required}" >&2
        exit 1
    fi
done

echo "Zipping..."
rm -f "${ROOT}/${ZIP_NAME}"
( cd "${STAGE}" && zip -rq "${ROOT}/${ZIP_NAME}" "${SLUG}" )

echo "Built ${ZIP_NAME} ($(du -h "${ROOT}/${ZIP_NAME}" | cut -f1))"
