#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/resources/data/wilayah/ibnux"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "Fetching ibnux/data-indonesia (shallow)…"
git clone --depth 1 https://github.com/ibnux/data-indonesia.git "$TMP/data-indonesia"

rm -rf "$DEST"
mkdir -p "$DEST"
cp "$TMP/data-indonesia/provinsi.json" "$DEST/"
cp -R "$TMP/data-indonesia/kabupaten" "$TMP/data-indonesia/kecamatan" "$TMP/data-indonesia/kelurahan" "$DEST/"

echo "Wrote wilayah JSON under $DEST"
du -sh "$DEST" "$DEST"/* 2>/dev/null || true
find "$DEST" -type f | wc -l
