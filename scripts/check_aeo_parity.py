#!/usr/bin/env python3
"""AEO判定の照合。同じHTMLを Kurage GEO（Python・app/japanese_aeo.py）と ktrackgeoseo（PHP・ktrackgeoseo_aeo.php）にかけて比べる。

  /home/kojima/work/kgeo/.venv/bin/python scripts/check_aeo_parity.py [URL ...]

HTMLは1回だけ取得してファイルに置き、両方に同じものを渡す（取得のたびに中身が変わるページで比べないため）。
点と7つの観点の点・件数が全部一致したら OK。1つでも違えば中身を出して終了コード1。
"""
import json
import pathlib
import subprocess
import sys
import tempfile
import urllib.request

sys.path.insert(0, "/home/kojima/work/kgeo")
from app.japanese_aeo import analyze_aeo  # noqa: E402

ROOT = pathlib.Path(__file__).resolve().parent.parent
PHP = """<?php require %r; $h = file_get_contents($argv[1]); echo json_encode(ktgs_aeo_analyze($h), JSON_UNESCAPED_UNICODE);"""

URLS = sys.argv[1:] or [
    "https://exbridge.jp/",
    "https://exbridge.jp/ai-sns-komon.html",
    "https://exbridge.jp/ai-it-komon.html",
    "https://exbridge.jp/vibeblog/2026-10-20-seikatsuhogo-iryohi-keizai.html",
    "https://kurage.exbridge.jp/",
    "https://xb4g.com/giin/tracker/",
    "https://www.python.org/",
]


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (ktrackgeoseo parity check)"})
    with urllib.request.urlopen(req, timeout=30) as r:
        raw = r.read()
    for enc in ("utf-8", "cp932", "euc-jp"):
        try:
            return raw.decode(enc)
        except UnicodeDecodeError:
            continue
    return raw.decode("utf-8", "replace")


def pick(r):
    m = r["metrics"]
    return {
        "score": r["score"], "language": r["language"],
        **{f"{k}.score": v["score"] for k, v in m.items()},
        "answer_first.found": m["answer_first"]["found"], "sections": m["answer_first"]["total"],
        "definitions.found": m["definitions"]["found"],
        "questions": m["question_answers"]["questions"], "concise": m["question_answers"]["concise_answers"],
        "statistics": m["evidence"]["statistics"], "sources": m["evidence"]["source_signals"], "external": m["evidence"]["external_links"],
        "sentences": m["readability"]["sentences"], "avg": m["readability"]["avg_sentence_chars"],
        "headings": r["source"]["headings"], "paragraphs": r["source"]["paragraphs"],
        "recs": len(r["recommendations"]),
    }


def main():
    php_file = tempfile.NamedTemporaryFile("w", suffix=".php", delete=False)
    php_file.write(PHP % str(ROOT / "public" / "ktrackgeoseo_aeo.php"))
    php_file.close()
    bad = 0
    for url in URLS:
        html = fetch(url)
        with tempfile.NamedTemporaryFile("w", suffix=".html", delete=False, encoding="utf-8") as f:
            f.write(html)
        py = pick(analyze_aeo(html))
        out = subprocess.run(["php", php_file.name, f.name], capture_output=True, text=True)
        if out.returncode != 0:
            print(f"NG  {url}  PHPが失敗: {out.stderr[:300]}")
            bad += 1
            continue
        ph = pick(json.loads(out.stdout))
        diff = {k: (py[k], ph[k]) for k in py if py[k] != ph[k]}
        if diff:
            bad += 1
            print(f"NG  {url}  Python {py['score']} / PHP {ph['score']}")
            for k, (a, b) in diff.items():
                print(f"      {k}: Python={a}  PHP={b}")
        else:
            print(f"OK  {url}  {py['score']}点（{py['language']}）")
    return 1 if bad else 0


if __name__ == "__main__":
    raise SystemExit(main())
