#!/bin/sh

set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
BUILD_ROOT="$ROOT_DIR/wordpress-org-build"
TRUNK_DIR="$BUILD_ROOT/trunk"
ASSETS_DIR="$BUILD_ROOT/assets"
PACKAGE_SLUG="dk-user-activity-logger"
PACKAGE_DIR="$BUILD_ROOT/$PACKAGE_SLUG"
ZIP_PATH="$BUILD_ROOT/$PACKAGE_SLUG.zip"

rm -rf "$TRUNK_DIR"
rm -rf "$ASSETS_DIR"
rm -rf "$PACKAGE_DIR"
rm -f "$ZIP_PATH"
mkdir -p "$TRUNK_DIR/assets" "$TRUNK_DIR/includes" "$TRUNK_DIR/languages" "$ASSETS_DIR"
mkdir -p "$PACKAGE_DIR/assets" "$PACKAGE_DIR/includes" "$PACKAGE_DIR/languages"

cp "$ROOT_DIR/activity-logger-site-owners.php" "$TRUNK_DIR/"
cp "$ROOT_DIR/readme.txt" "$TRUNK_DIR/"
cp "$ROOT_DIR/assets/admin.css" "$TRUNK_DIR/assets/"
cp "$ROOT_DIR/assets/admin.js" "$TRUNK_DIR/assets/"
cp "$ROOT_DIR/includes/"*.php "$TRUNK_DIR/includes/"
cp "$ROOT_DIR/languages/index.php" "$TRUNK_DIR/languages/"
cp "$ROOT_DIR/assets/icon-128x128.png" "$ASSETS_DIR/"
cp "$ROOT_DIR/assets/icon-256x256.png" "$ASSETS_DIR/"
cp "$ROOT_DIR/assets/banner-772x250.png" "$ASSETS_DIR/"

cp "$ROOT_DIR/activity-logger-site-owners.php" "$PACKAGE_DIR/"
cp "$ROOT_DIR/readme.txt" "$PACKAGE_DIR/"
cp "$ROOT_DIR/assets/admin.css" "$PACKAGE_DIR/assets/"
cp "$ROOT_DIR/assets/admin.js" "$PACKAGE_DIR/assets/"
cp "$ROOT_DIR/includes/"*.php "$PACKAGE_DIR/includes/"
cp "$ROOT_DIR/languages/index.php" "$PACKAGE_DIR/languages/"

(
    cd "$BUILD_ROOT"
    zip -rq "$ZIP_PATH" "$PACKAGE_SLUG"
)

printf '%s\n' "WordPress.org package prepared at: $TRUNK_DIR"
printf '%s\n' "Submission zip prepared at: $ZIP_PATH"
