#!/bin/sh
# Builds the App Store release: build/taskpath-<version>.tar.gz with only what an
# installation needs (no sources, tests or dev tooling), and, once the Nextcloud-
# signed certificate is in place, the code signature inside the app and the
# signature of the tarball to paste into the App Store.
#   ./dev/release.sh
# Certificate files (never committed): $CERT_DIR (default ~/.nextcloud/certificates)
# taskpath.key (your private key) and taskpath.crt (signed by Nextcloud).
# Signing runs occ in the local dev container, so `docker compose up -d` first.
set -e
cd "$(dirname "$0")/.."
CERT_DIR="${CERT_DIR:-$HOME/.nextcloud/certificates}"
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' taskpath/appinfo/info.xml)
STAGE=build/release
OUT="build/taskpath-$VERSION.tar.gz"

echo "Building the Taskpath page"
(cd taskpath && npm ci --silent && npm run build --silent >/dev/null)

echo "Staging taskpath $VERSION"
rm -rf "$STAGE" && mkdir -p "$STAGE/taskpath"
for part in appinfo css img js l10n lib templates; do
  cp -R "taskpath/$part" "$STAGE/taskpath/"
done
cp COPYING "$STAGE/taskpath/"
find "$STAGE" \( -name '.DS_Store' -o -name '*.map' \) -delete

if [ -f "$CERT_DIR/taskpath.crt" ] && [ -f "$CERT_DIR/taskpath.key" ]; then
  echo "Signing the code (appinfo/signature.json)"
  docker compose exec -T nextcloud rm -rf /tmp/taskpath-release
  docker compose cp "$STAGE/taskpath" nextcloud:/tmp/taskpath-release
  docker compose cp "$CERT_DIR/taskpath.key" nextcloud:/tmp/taskpath-release.key
  docker compose cp "$CERT_DIR/taskpath.crt" nextcloud:/tmp/taskpath-release.crt
  docker compose exec -T nextcloud chown -R www-data /tmp/taskpath-release /tmp/taskpath-release.key /tmp/taskpath-release.crt
  docker compose exec -T -u www-data nextcloud php occ integrity:sign-app \
    --privateKey=/tmp/taskpath-release.key --certificate=/tmp/taskpath-release.crt --path=/tmp/taskpath-release
  docker compose cp nextcloud:/tmp/taskpath-release/appinfo/signature.json "$STAGE/taskpath/appinfo/signature.json"
  docker compose exec -T nextcloud rm -rf /tmp/taskpath-release /tmp/taskpath-release.key /tmp/taskpath-release.crt
else
  echo "No signed certificate in $CERT_DIR yet: building unsigned (fine for a manual install)"
fi

# macOS tar otherwise adds AppleDouble "._" entries for extended attributes, which
# Nextcloud sees as a second top-level folder and refuses to install.
xattr -cr "$STAGE" 2>/dev/null || true
COPYFILE_DISABLE=1 tar --no-xattrs --no-mac-metadata -czf "$OUT" -C "$STAGE" taskpath
python3 -I - "$OUT" <<'PY'
import sys, tarfile
names = tarfile.open(sys.argv[1]).getnames()
bad = [n for n in names if n != "taskpath" and not n.startswith("taskpath/") or "/._" in n or n.startswith("._")]
if bad:
    sys.exit(f"Release tarball must hold only taskpath/, found: {bad[:5]}")
PY
echo "Release: $OUT ($(du -h "$OUT" | cut -f1))"

if [ -f "$CERT_DIR/taskpath.crt" ] && [ -f "$CERT_DIR/taskpath.key" ]; then
  openssl dgst -sha512 -sign "$CERT_DIR/taskpath.key" "$OUT" | openssl base64 -A > "$OUT.sig"
  echo "App Store signature: $OUT.sig (paste its contents when uploading the release)"
fi
