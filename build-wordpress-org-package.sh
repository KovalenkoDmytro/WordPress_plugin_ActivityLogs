#!/bin/sh

set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BUILD_ROOT="$ROOT_DIR/wordpress-org-build"
TRUNK_DIR="$BUILD_ROOT/trunk"

rm -rf "$TRUNK_DIR"
mkdir -p "$TRUNK_DIR/assets" "$TRUNK_DIR/includes" "$TRUNK_DIR/languages"

cp "$ROOT_DIR/wp-logs-viewer.php" "$TRUNK_DIR/"
cp "$ROOT_DIR/readme.txt" "$TRUNK_DIR/"
cp "$ROOT_DIR/assets/admin.css" "$TRUNK_DIR/assets/"
cp "$ROOT_DIR/assets/admin.js" "$TRUNK_DIR/assets/"
cp "$ROOT_DIR/includes/admin_show_page.php" "$TRUNK_DIR/includes/"
cp "$ROOT_DIR/includes/data_base_queries.php" "$TRUNK_DIR/includes/"
cp "$ROOT_DIR/languages/index.php" "$TRUNK_DIR/languages/"

printf '%s\n' "WordPress.org package prepared at: $TRUNK_DIR"
