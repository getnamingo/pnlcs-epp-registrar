#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
tag="${1:?Usage: scripts/build-release.sh VERSION}"
[[ "$tag" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Invalid version' >&2; exit 1; }
test -f EPP/namingo/vendor/autoload.php
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/modules/Registrars/EPP" dist
cp -a EPP/. "$stage/modules/Registrars/EPP/"
cp README.md LICENSE "$stage/modules/Registrars/EPP/"
archive_dir="$(pwd)/dist"
rm -f "$archive_dir/pnlcs-epp-${tag}.zip" "$archive_dir/pnlcs-epp-${tag}.tar.gz"
(cd "$stage" && zip -qr "$archive_dir/pnlcs-epp-${tag}.zip" modules)
tar -czf "$archive_dir/pnlcs-epp-${tag}.tar.gz" -C "$stage" modules
