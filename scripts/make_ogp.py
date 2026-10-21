#!/usr/bin/env python3
"""Kurage Track & GEO & SEO のOGP画像 1200x630（マスコット入り・数字は焼き込まない）。
  /usr/bin/python3 scripts/make_ogp.py  → demo/ktrackgeoseo-ogp.png
"""
import base64
import pathlib

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
OUT = ROOT / "demo" / "ktrackgeoseo-ogp.png"


def data_uri(path, mime):
    return f"data:{mime};base64," + base64.b64encode(pathlib.Path(path).read_bytes()).decode()


MASCOT = data_uri("/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png", "image/png")
LOGO = data_uri("/home/kojima/work/exbridge_jp/images/logo-mark-128.png", "image/png")

HTML = f"""<!doctype html><html lang="ja"><head><meta charset="utf-8"><style>
*{{box-sizing:border-box;margin:0;padding:0}}
body{{width:1200px;height:630px;font-family:"Noto Sans CJK JP",sans-serif;background:#f3faf9;color:#1d3038;position:relative;overflow:hidden}}
.top{{position:absolute;left:0;right:0;top:0;height:10px;background:linear-gradient(90deg,#0a9a8f,#2f7fd4)}}
.l{{position:absolute;left:64px;top:66px;width:820px}}
.name{{font-size:30px;font-weight:900;color:#076f67}}
h1{{font-size:58px;line-height:1.28;font-weight:900;margin:16px 0 24px}}
h1 em{{font-style:normal;color:#076f67}}
.chips{{display:flex;gap:12px;flex-wrap:wrap}}
.chips div{{background:#fff;border:2px solid #dcebe9;border-radius:16px;padding:12px 18px;line-height:1.35}}
.chips b{{display:block;font-size:28px}}
.chips span{{font-size:17px;color:#5f7078;font-weight:700}}
.c1 b{{color:#076f67}} .c2 b{{color:#1f5fa8}} .c3 b{{color:#8a5a00}}
.sub{{margin-top:22px;font-size:22px;font-weight:700;color:#3d5566}}
.m{{position:absolute;right:40px;bottom:40px;width:250px;height:auto;object-fit:contain}}
.foot{{position:absolute;left:64px;bottom:30px;display:flex;align-items:center;gap:10px;font-size:20px;color:#5f7078;font-weight:700}}
.foot img{{width:34px;height:34px}}
</style></head><body><div class="top"></div>
<div class="l">
  <div class="name">Kurage Track &amp; GEO &amp; SEO</div>
  <h1>URLひとつで、<br><em>SEO・AEO・GEO</em>をまとめて診断</h1>
  <div class="chips">
    <div class="c1"><b>SEO</b><span>Google検索</span></div>
    <div class="c2"><b>AEO</b><span>AIの回答</span></div>
    <div class="c2"><b>GEO</b><span>AI検索</span></div>
    <div class="c3"><b>AIクローラー計測</b><span>GPTBot・ClaudeBot…</span></div>
  </div>
  <div class="sub">生成AIを使わない決まったチェック／PHPだけで動く</div>
</div>
<img class="m" src="{MASCOT}">
<div class="foot"><img src="{LOGO}">株式会社エクスブリッジ</div>
</body></html>"""

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 1200, "height": 630})
    pg.set_content(HTML, wait_until="load")
    pg.wait_for_timeout(300)
    pg.screenshot(path=str(OUT))
    b.close()
print(OUT)
