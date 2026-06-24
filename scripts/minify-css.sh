#!/bin/sh
# Minify public/css/styles.css into public/css/styles.min.css.
#
# Used by `make minify` and the Docker image build. The dashboard serves
# the minified file when present and falls back to styles.css otherwise,
# so a missing min file degrades gracefully at runtime. This script still
# fails loudly so build pipelines notice when minification breaks.
#
# The perl pass slurps the whole file (-0777) so comment removal spans
# multi-line blocks, then collapses whitespace. That is safe for this
# stylesheet (no quoted content strings), and the verification step below
# guards against structural corruption if the stylesheet grows constructs
# the regex mishandles.

set -e

SRC="public/css/styles.css"
OUT="public/css/styles.min.css"

if [ ! -f "$SRC" ]; then
    echo "ERROR: $SRC not found" >&2
    exit 1
fi

# Bundle shared tokens into the minified dashboard CSS so ?v= cache busting does
# not leave an extra unversioned @import fetch in production.
BUNDLE_SRC="${SRC}.bundle.$$"
cp "$SRC" "$BUNDLE_SRC"
sh scripts/inline-css-tokens.sh "$BUNDLE_SRC"

if ! command -v perl >/dev/null 2>&1; then
    echo "ERROR: perl is required for CSS minification" >&2
    exit 1
fi

perl -0777 -pe 's/\/\*.*?\*\///gs; s/\s+/ /g; s/\s*\{\s*/{/g; s/\s*\}\s*/}/g; s/\s*;\s*/;/g; s/\s*:\s*/:/g; s/\s*,\s*/,/g; s/^\s+|\s+$//g' "$BUNDLE_SRC" > "$OUT"

# Verify structure survived minification: brace counts must match the
# comment-stripped source (comments may legitimately contain braces),
# and no comment markers may remain in the output (an unterminated /*
# would silently swallow every rule after it).
verify=$(perl -e '
    local $/;
    open(my $s, "<", $ARGV[0]) or die "cannot read $ARGV[0]";
    open(my $m, "<", $ARGV[1]) or die "cannot read $ARGV[1]";
    my $src = <$s>; my $min = <$m>;
    $src =~ s/\/\*.*?\*\///gs;
    my $so = () = $src =~ /\{/g; my $sc = () = $src =~ /\}/g;
    my $mo = () = $min =~ /\{/g; my $mc = () = $min =~ /\}/g;
    my $co = () = $min =~ /\/\*/g; my $cc = () = $min =~ /\*\//g;
    if ($so != $mo || $sc != $mc) { print "brace mismatch: stripped src $so/$sc min $mo/$mc"; exit 0; }
    if ($co != 0 || $cc != 0) { print "comment markers remain in output: $co open, $cc close"; exit 0; }
    print "ok";
' "$BUNDLE_SRC" "$OUT")

rm -f "$BUNDLE_SRC"

if [ "$verify" != "ok" ]; then
    rm -f "$OUT"
    echo "ERROR: minified CSS failed verification ($verify); removed $OUT" >&2
    exit 1
fi

echo "Minified $SRC -> $OUT ($(wc -c < "$SRC" | tr -d ' ') -> $(wc -c < "$OUT" | tr -d ' ') bytes)"
