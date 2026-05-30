#!/bin/sh

set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PLUGINS_DIR="$(dirname -- "$ROOT_DIR")"
BUILD_ROOT="$ROOT_DIR/wordpress-org-build"
TRUNK_DIR="$BUILD_ROOT/trunk"
ASSETS_DIR="$BUILD_ROOT/assets"
PACKAGE_SLUG="dk-user-activity-logger"
# Installable plugin folder is built one level up, next to this plugin, so the local
# WordPress (which mounts app/plugins) sees it as a standalone plugin for Plugin Check.
PACKAGE_DIR="$PLUGINS_DIR/$PACKAGE_SLUG"
ZIP_PATH="$BUILD_ROOT/$PACKAGE_SLUG.zip"

rm -rf "$TRUNK_DIR"
rm -rf "$ASSETS_DIR"
rm -rf "$PACKAGE_DIR"
rm -f "$ZIP_PATH"
mkdir -p "$TRUNK_DIR/assets" "$TRUNK_DIR/includes" "$TRUNK_DIR/languages" "$ASSETS_DIR"
mkdir -p "$PACKAGE_DIR/assets" "$PACKAGE_DIR/includes" "$PACKAGE_DIR/languages"

# Strip the " (Dev)" suffix from the plugin name so the distributed plugin keeps the
# clean "DK User Activity Logger" name while the source copy stays distinguishable locally.
sed 's/^\( \* Plugin Name: DK User Activity Logger\) (Dev)$/\1/' \
    "$ROOT_DIR/activity-logger-site-owners.php" > "$TRUNK_DIR/activity-logger-site-owners.php"
cp "$ROOT_DIR/readme.txt" "$TRUNK_DIR/"
cp "$ROOT_DIR/assets/admin.css" "$TRUNK_DIR/assets/"
cp "$ROOT_DIR/assets/admin.js" "$TRUNK_DIR/assets/"
cp "$ROOT_DIR/includes/"*.php "$TRUNK_DIR/includes/"
cp "$ROOT_DIR/languages/index.php" "$TRUNK_DIR/languages/"
cp "$ROOT_DIR/assets/icon-128x128.png" "$ASSETS_DIR/"
cp "$ROOT_DIR/assets/icon-256x256.png" "$ASSETS_DIR/"
cp "$ROOT_DIR/assets/banner-772x250.png" "$ASSETS_DIR/"

sed 's/^\( \* Plugin Name: DK User Activity Logger\) (Dev)$/\1/' \
    "$ROOT_DIR/activity-logger-site-owners.php" > "$PACKAGE_DIR/activity-logger-site-owners.php"
cp "$ROOT_DIR/readme.txt" "$PACKAGE_DIR/"
cp "$ROOT_DIR/assets/admin.css" "$PACKAGE_DIR/assets/"
cp "$ROOT_DIR/assets/admin.js" "$PACKAGE_DIR/assets/"
cp "$ROOT_DIR/includes/"*.php "$PACKAGE_DIR/includes/"
cp "$ROOT_DIR/languages/index.php" "$PACKAGE_DIR/languages/"

(
    cd "$PLUGINS_DIR"
    zip -rq "$ZIP_PATH" "$PACKAGE_SLUG"
)

printf '%s\n' "WordPress.org SVN trunk prepared at: $TRUNK_DIR"
printf '%s\n' "Installable plugin folder prepared at: $PACKAGE_DIR"
printf '%s\n' "Submission zip prepared at: $ZIP_PATH"
