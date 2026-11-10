#!/usr/bin/env bash
# Makes src/fonts/alexandria-arabic-latin.woff2, the site's only font file, from Alexandria's variable font (SIL Open
# Font License, https://github.com/google/fonts/tree/main/ofl/alexandria): weights 400 to 700, the Arabic letters with
# the space, the digits, Latin letters and punctuation in the same file, and only the features the site uses (Arabic
# joining and marks, kerning, ligatures, tabular and lining figures).
#   pip install fonttools brotli
#   scripts/subset-font.sh path/to/Alexandria[wght].ttf
set -euo pipefail
source="${1:?Usage: scripts/subset-font.sh path/to/Alexandria[wght].ttf}"
out="$(dirname "$0")/../src/fonts/alexandria-arabic-latin.woff2"
work="$(mktemp -d)"
trap 'rm -r "$work"' EXIT

fonttools varLib.instancer "$source" wght=400:700 -o "$work/alexandria-400-700.ttf" -q
pyftsubset "$work/alexandria-400-700.ttf" \
  --unicodes="U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD,U+0600-06FF,U+0750-077F,U+0870-0891,U+0897-08E1,U+08E3-08FF,U+200C-200E,U+2010-2011,U+204F,U+2E41,U+FB50-FDFF,U+FE70-FE74,U+FE76-FEFC" \
  --layout-features="ccmp,locl,isol,init,medi,fina,rlig,liga,calt,rclt,curs,kern,mark,mkmk,tnum,lnum,pnum" \
  --flavor=woff2 \
  --output-file="$out"
ls -l "$out"
