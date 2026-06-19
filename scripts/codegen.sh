#!/usr/bin/env bash
# Regenerate PHP model classes from the vendored OpenAPI spec.
# Run: composer codegen
#
# NOTE: The models in src/Generated/Model/ are currently HAND-WRITTEN (not
# generated) because the openapi-generator-cli toolchain had issues at the time
# of initial development:
#   - npm package @openapitools/openapi-generator-cli printed minified source to
#     stdout instead of running (broken npm package v2.38.0)
#   - Java is not installed locally (stub only)
#   - Docker image openapitools/openapi-generator-cli downloads the JAR at startup
#     over the network and hung indefinitely in the dev environment
#
# When the toolchain is available, uncomment the generation block below and run
# `composer codegen`. It will OVERWRITE the hand-written files in src/Generated/.
# Review the diff carefully before committing — ensure ModelInterface.fromArray()
# compatibility is preserved and the namespace is correct.
#
# Current hand-written models:
#   src/Generated/ModelInterface.php
#   src/Generated/Model/ChargeAction.php
#   src/Generated/Model/LinkDetails.php
#   src/Generated/Model/PaymentChargeResponse.php
#   src/Generated/Model/PaymentChargeStatusResponse.php
#   src/Generated/Model/PaymentDetails.php
#   src/Generated/Model/PermanentCardTokenDetails.php
#   src/Generated/Model/QrPaymentDetails.php
#   src/Generated/Model/RecurrenceDetails.php
#   src/Generated/Model/RefundDetails.php

set -euo pipefail
cd "$(dirname "$0")/.."

echo "ℹ️  Models are currently hand-written in src/Generated/Model/."
echo "   Run composer codegen only when the generator toolchain is confirmed working."
echo "   See scripts/codegen.sh for details."

exit 0

# ---- GENERATION BLOCK (uncomment when generator toolchain is available) -----
#
# SPEC="spec/payments.yaml"
# TMP_DIR="$(mktemp -d)"
# GEN_DIR="src/Generated"
#
# echo "→ Generating PHP models from $SPEC ..."
#
# npx --yes @openapitools/openapi-generator-cli generate \
#   -g php \
#   -i "$SPEC" \
#   -o "$TMP_DIR" \
#   --skip-validate-spec \
#   --global-property "models,supportingFiles=ObjectSerializer.php:ModelInterface.php:HeaderSelector.php" \
#   --additional-properties "invokerPackage=GoPay\\\\Payments\\\\Generated,modelPackage=Model,phpLegacySupport=false,variableNamingConvention=camelCase"
#
# rm -rf "$GEN_DIR"
# mkdir -p "$GEN_DIR"
#
# if [ -d "$TMP_DIR/lib/Model" ]; then
#   cp -r "$TMP_DIR/lib/Model" "$GEN_DIR/"
# fi
# for f in ObjectSerializer.php ModelInterface.php HeaderSelector.php; do
#   if [ -f "$TMP_DIR/lib/$f" ]; then
#     cp "$TMP_DIR/lib/$f" "$GEN_DIR/"
#   fi
# done
#
# rm -rf "$TMP_DIR"
# echo "✓ Codegen complete → $GEN_DIR"
