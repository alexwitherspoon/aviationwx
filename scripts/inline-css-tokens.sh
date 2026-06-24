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

if ! grep -qE "@import[[:space:]]+url\\([[:space:]]*['\"]aviationwx-tokens\\.css['\"][[:space:]]*\\)" "$TARGET"; then
    echo "ERROR: expected aviationwx-tokens @import in $TARGET" >&2
    exit 1
fi

if ! command -v perl >/dev/null 2>&1; then
    echo "ERROR: perl is required for CSS token inlining" >&2
    exit 1
fi

TMP="${TARGET}.tokens-inline.$$"
trap 'rm -f "$TMP"' EXIT HUP INT TERM

perl -0777 -pe "
    my \$tokens = do { local \$/; open my \$fh, '<', '$TOKENS' or die \$!; <\$fh> };
    my \$count = () = s/\@import\s+url\s*\(\s*['\"]aviationwx-tokens\.css['\"]\s*\)\s*;\s*/\$tokens/g;
    die \"ERROR: expected exactly one aviationwx-tokens @import, replaced \$count\\n\" if \$count != 1;
" "$TARGET" > "$TMP"

mv "$TMP" "$TARGET"
echo "Inlined $TOKENS into $TARGET"
