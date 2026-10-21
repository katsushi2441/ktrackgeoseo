#!/usr/bin/env python3
"""結果カード（OGP画像）用のフォントを、使う文字だけに切り出す。
  /usr/bin/python3 scripts/make_card_fonts.py  → public/ktgs_assets/ktgs-card-{bold,black}.otf と OFL.txt

カードに描く日本語は決まった言葉だけ（ドメイン名と数字は半角）なので、その文字と半角英数記号だけを残す。
Noto Sans CJK JP（SIL Open Font License 1.1）。カードの文言を変えたら、CARD_TEXT に足して作り直す。
"""
import gzip
import pathlib
import re

from fontTools import subset
from fontTools.ttLib import TTCollection

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "public" / "ktgs_assets"
SRC = {"bold": "/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc",
       "black": "/usr/share/fonts/opentype/noto/NotoSansCJK-Black.ttc"}
# カードに描く日本語（ktrackgeoseo.php の ktgs_card_png と揃える）
CARD_TEXT = ("SEO・AEO・GEO 診断結果 Google検索 AIの回答 AI検索 良好 まずまず 弱い 要対応 "
             "3つとも80点以上 で診断 年月日 点 ／・…")
ASCII = "".join(chr(c) for c in range(0x20, 0x7F))


def main():
    OUT.mkdir(parents=True, exist_ok=True)
    text = CARD_TEXT + ASCII
    for name, path in SRC.items():
        font = TTCollection(path).fonts[0]   # 0 = Noto Sans CJK JP
        opts = subset.Options()
        opts.layout_features = ["*"]
        opts.name_IDs = ["*"]
        opts.notdef_outline = True
        sub = subset.Subsetter(opts)
        sub.populate(text=text)
        sub.subset(font)
        dst = OUT / f"ktgs-card-{name}.otf"
        font.save(str(dst))
        print(dst, dst.stat().st_size, "bytes")
    lic = gzip.open("/usr/share/doc/fonts-noto-cjk/copyright", "rt").read() if pathlib.Path("/usr/share/doc/fonts-noto-cjk/copyright.gz").exists() \
        else open("/usr/share/doc/fonts-noto-cjk/copyright", encoding="utf-8").read()
    m = re.search(r"SIL OPEN FONT LICENSE Version 1\.1.*", lic, re.S)
    (OUT / "OFL.txt").write_text("Noto Sans CJK JP (subset for result cards)\nCopyright 2014-2021 Adobe (http://www.adobe.com/), with Reserved Font Name 'Source'.\n\n"
                                 + re.sub(r"^ ?\.?$", "", m.group(0) if m else "", flags=re.M), encoding="utf-8")
    print(OUT / "OFL.txt")


if __name__ == "__main__":
    main()
