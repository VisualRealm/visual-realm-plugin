#!/usr/bin/env bash
# Build a throwaway WordPress + SQLite site for the test suites.
# Usage: tests/setup-site.sh <site_dir>
set -euo pipefail
site="${1:?site dir}"
here="$(cd "$(dirname "$0")" && pwd)"
wp_version="${WP_VERSION:-6.9}"
sqlite_version="${SQLITE_PLUGIN_VERSION:-3.1.2}"

mkdir -p "$site" && site="$(cd "$site" && pwd)"
if [ ! -f "$site/wp/wp-settings.php" ]; then
  tmp="$(mktemp -d)"
  if [ "$wp_version" = latest ]; then wp_url="https://wordpress.org/latest.zip"; else wp_url="https://wordpress.org/wordpress-${wp_version}.zip"; fi
  curl -fsSL "$wp_url" -o "$tmp/wp.zip"
  unzip -q "$tmp/wp.zip" -d "$tmp"
  mv "$tmp/wordpress" "$site/wp"
  if [ "$sqlite_version" = latest ]; then sq_url="https://downloads.wordpress.org/plugin/sqlite-database-integration.zip"; else sq_url="https://downloads.wordpress.org/plugin/sqlite-database-integration.${sqlite_version}.zip"; fi
  curl -fsSL "$sq_url" -o "$tmp/sqlite.zip"
  unzip -q "$tmp/sqlite.zip" -d "$site/wp/wp-content/plugins"
  rm -rf "$tmp"
fi

plug="$site/wp/wp-content/plugins/sqlite-database-integration"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$plug#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g" \
  "$plug/db.copy" > "$site/wp/wp-content/db.php"
cp "$here/site/wp-config.php" "$site/wp/wp-config.php"
mkdir -p "$site/wp/wp-content/mu-plugins"
cp "$here/site/vr-test-stubs.php" "$site/wp/wp-content/mu-plugins/"
cp "$here/site/router.php" "$here/site/install.php" "$here/site/activate.php" "$site/"
php "$site/install.php"
echo "site ready at $site"
