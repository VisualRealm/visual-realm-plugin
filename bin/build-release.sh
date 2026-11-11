#!/usr/bin/env bash
# Build visual-realm.zip and its Ed25519 signature for a GitHub release.
#
# Usage: VR_SIGNING_KEY_FILE=/path/to/vr-release-signing.secret bin/build-release.sh [out_dir]
# Upload both dist/visual-realm.zip and dist/visual-realm.zip.sig to the release.
set -euo pipefail

here="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$here/dist}"
key="${VR_SIGNING_KEY_FILE:?Set VR_SIGNING_KEY_FILE to the signing key file}"

version="$(grep -oE "VR_PLUGIN_VERSION', '[0-9.]+" "$here/visual-realm.php" | grep -oE '[0-9.]+$')"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

mkdir -p "$stage/visual-realm" "$out"
(cd "$here" && tar --exclude=.git --exclude=bin --exclude=dist --exclude=.gitignore --exclude='*.DS_Store' -cf - .) | (cd "$stage/visual-realm" && tar -xf -)
rm -f "$out/visual-realm.zip" "$out/visual-realm.zip.sig"
(cd "$stage" && zip -qr "$out/visual-realm.zip" visual-realm)

php -r '
$sk  = base64_decode(trim(file_get_contents($argv[1])), true);
$zip = file_get_contents($argv[2]);
file_put_contents($argv[2] . ".sig", base64_encode(sodium_crypto_sign_detached($zip, $sk)));
$pk  = sodium_crypto_sign_publickey_from_secretkey($sk);
if (!sodium_crypto_sign_verify_detached(base64_decode(file_get_contents($argv[2] . ".sig")), $zip, $pk)) { fwrite(STDERR, "signature self-check failed\n"); exit(1); }
echo "public key: " . base64_encode($pk) . "\n";
' "$key" "$out/visual-realm.zip"

echo "built $out/visual-realm.zip (v$version) and visual-realm.zip.sig"
