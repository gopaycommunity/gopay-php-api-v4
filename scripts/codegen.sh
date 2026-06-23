#!/usr/bin/env bash
# Regenerate PHP model classes from the vendored OpenAPI spec.
# Run: composer codegen
#
# Uses @openapitools/openapi-generator-cli v2.39 in Docker mode (no local Java needed).
# Generator version and Docker settings are in openapitools.json.
# Docker image openapitools/openapi-generator-cli:v7.9.0 must be available locally
# or pullable from Docker Hub.
#
# When generation completes, review the diff in src/Generated/ carefully:
#   - Ensure namespace matches GoPay\Payments\Generated\Model
#   - Ensure ModelInterface.fromArray(array): static is preserved
#
# The entire src/Generated/ directory is excluded from PHPStan analysis (phpstan.neon).

set -euo pipefail
cd "$(dirname "$0")/.."

TMP_DIR=".codegen-tmp"
GEN_DIR="src/Generated"

echo "→ Fetching latest spec from payments-api.beta.gopay.com ..."
curl -fsSL http://api-docs.gopay.com/spec/en/payments.yaml -o spec/payments.yaml

echo "→ Generating PHP models from spec/payments.yaml ..."

# The CLI reads openapitools.json for generator config and Docker settings.
# useDocker:true causes it to volume-mount spec/ and .codegen-tmp into the container.
npx @openapitools/openapi-generator-cli@2.39.0 generate

rm -rf "$GEN_DIR"
mkdir -p "$GEN_DIR"

if [ -d "$TMP_DIR/lib/Model" ]; then
  cp -r "$TMP_DIR/lib/Model" "$GEN_DIR/"
fi
for f in ObjectSerializer.php ModelInterface.php HeaderSelector.php; do
  if [ -f "$TMP_DIR/lib/$f" ]; then
    cp "$TMP_DIR/lib/$f" "$GEN_DIR/"
  fi
done

rm -rf "$TMP_DIR"
echo "✓ Codegen complete → $GEN_DIR"
