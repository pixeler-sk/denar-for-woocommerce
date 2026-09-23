#!/usr/bin/env bash
# Builds the distributable plugin into build/<target>/denar-for-woocommerce
# and zips it.
#
#   bin/build.sh github 0.1.2   -> build/denar-for-woocommerce-0.1.2.zip
#   bin/build.sh wporg  0.1.2   -> build/denar-for-woocommerce-wporg-0.1.2.zip
#
# github = release asset for Plugin Update Checker (with lib/ and SelfHosted).
# wporg  = wordpress.org: no self-updater (guideline 8), no bundled translation
#          (language packs from translate.wordpress.org).
set -euo pipefail

TARGET=${1:?target github|wporg}
VERSION=${2:?version}
SLUG=denar-for-woocommerce

cd "$(dirname "$0")/.."
ROOT=$PWD
OUT="build/$TARGET/$SLUG"
rm -rf "build/$TARGET"
mkdir -p "$OUT"

# Only runtime files; everything else stays in the repository.
cp -R denar-for-woocommerce.php uninstall.php readme.txt src languages "$OUT/"

case "$TARGET" in
	github)
		cp -R lib "$OUT/"
		ZIP="$SLUG-$VERSION.zip"
		;;
	wporg)
		rm "$OUT/src/SelfHosted.php"
		rm -f "$OUT"/languages/*.po "$OUT"/languages/*.mo
		ZIP="$SLUG-wporg-$VERSION.zip"
		;;
	*)
		echo "Unknown target: $TARGET" >&2
		exit 1
		;;
esac

# The zip must have the slug directory on top, or WordPress unpacks an
# update next to the installed plugin.
(cd "build/$TARGET" && rm -f "$ROOT/build/$ZIP" && zip -rqX "$ROOT/build/$ZIP" "$SLUG")
echo "build/$ZIP"
