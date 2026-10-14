#!/usr/bin/env bash
# Build the Slackware package and the .plg into dist/.
#   ./build.sh            version = today (YYYY.MM.DD)
#   ./build.sh 2026.10.14a
set -euo pipefail

cd "$(dirname "$0")"
NAME=power-guard
VERSION=${1:-$(date +%Y.%m.%d)}
PKG="${NAME}-${VERSION}-x86_64-1"
DIST=dist
STAGE="$DIST/stage"

rm -rf "$STAGE"
mkdir -p "$STAGE/install"
cp -r src/. "$STAGE/"
printf '%s\n' "$VERSION" > "$STAGE/usr/local/emhttp/plugins/$NAME/VERSION"

cat > "$STAGE/install/slack-desc" <<EOF
$NAME: Power Guard $VERSION
$NAME:
$NAME: Hardware-aware power and performance tuning for Unraid.
$NAME: Detect, recommend, preview, apply and revert.
$NAME:
$NAME: https://github.com/Knallli/unraid-power-guard
$NAME:
$NAME:
$NAME:
$NAME:
$NAME:
EOF

# Normalise line endings in case the checkout converted them.
find "$STAGE" -type f \( -name '*.php' -o -name '*.page' -o -name '*.js' -o -name '*.css' -o -path '*/bin/*' -o -path '*/event/*' \) \
  -exec sed -i 's/\r$//' {} +

tar --owner=0 --group=0 --numeric-owner --mode='u+rwX,go+rX,go-w' --sort=name \
  -C "$STAGE" -czf "$DIST/$PKG.tgz" install usr
MD5=$(md5sum "$DIST/$PKG.tgz" | cut -d' ' -f1)

# CHANGES block from CHANGELOG.md (XML-escaped).
CHANGES=$(sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g' CHANGELOG.md 2>/dev/null || echo "###$VERSION")

CHANGES="$CHANGES" awk -v v="$VERSION" -v m="$MD5" '
  { gsub(/@VERSION@/, v); gsub(/@MD5@/, m) }
  /@CHANGES@/ { print ENVIRON["CHANGES"]; next }
  { print }
' plugin/$NAME.plg > "$DIST/$NAME.plg"

rm -rf "$STAGE"
echo "Built $DIST/$PKG.tgz ($MD5)"
echo "      $DIST/$NAME.plg"
