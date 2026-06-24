#!/bin/sh
# Inline aviationwx-tokens.css into a parent stylesheet that @imports it.
#
# Used by scripts/minify-css.sh (dashboard) and the Docker image build (embed
# widgets) so versioned parent CSS does not trigger a separate unversioned
# tokens fetch in production.

set -e

TARGET="${1:-}"
if [ -z "$TARGET" ] || [ ! -f "$TARGET" ]; then
    echo "ERROR: usage: inline-css-tokens.sh <css-file>" >&2
    exit 1
fi

TOKENS="public/css/aviationwx-tokens.css"
if [ ! -f "$TOKENS" ]; then
    echo "ERROR: $TOKENS not found" >&2
    exit 1
fi

if ! grep -q "@import url('aviationwx-tokens.css');" "$TARGET"; then
    echo "No aviationwx-tokens @import in $TARGET; skipping inline"
    exit 0
fi

if ! command -v perl >/dev/null 2>&1; then
    echo "ERROR: perl is required for CSS token inlining" >&2
    exit 1
fi

TMP="${TARGET}.tokens-inline.$$"
trap 'rm -f "$TMP"' EXIT HUP INT TERM

perl -0777 -pe "
    my \$tokens = do { local \$/; open my \$fh, '<', '$TOKENS' or die \$!; <\$fh> };
    s/\@import url\\('aviationwx-tokens.css'\\);\\s*/\$tokens/;
" "$TARGET" > "$TMP"

mv "$TMP" "$TARGET"
echo "Inlined $TOKENS into $TARGET"
