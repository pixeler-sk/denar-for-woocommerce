#!/usr/bin/env bash
# Renders the plugin assets into .wordpress-org/ (the wordpress.org naming,
# also what the update checker shows in "View details"). Needs Google Chrome.
#   assets-src/render.sh
set -euo pipefail

cd "$(dirname "$0")"
OUT=../.wordpress-org
CHROME=${CHROME:-"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"}
mkdir -p "$OUT"

shot() { # file width height scale output
	"$CHROME" --headless=new --disable-gpu --hide-scrollbars --no-first-run \
		--default-background-color=00000000 --virtual-time-budget=2000 \
		--window-size="$2,$3" --force-device-scale-factor="$4" \
		--screenshot="$OUT/$5" "file://$PWD/$1" >/dev/null 2>&1
}

shot banner.html 772 250 1 banner-772x250.png
shot banner.html 772 250 2 banner-1544x500.png
# The icon is rendered from the SVG itself; an <img> in a tiny window comes out blank.
shot icon.svg 256 256 1 icon-256x256.png
sips -z 128 128 "$OUT/icon-256x256.png" --out "$OUT/icon-128x128.png" >/dev/null
cp icon.svg "$OUT/icon.svg"

ls -l "$OUT"
