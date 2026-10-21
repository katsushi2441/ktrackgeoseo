# Kurage Track & GEO & SEO（ktrackgeoseo）

**URLを1回入れるだけで、SEO・AEO・GEOをまとめて診断し、AIのクローラーが実際に来たかまで測る。**

[Kurage SEO](https://github.com/katsushi2441/kseo) と [Kurage Track & GEO](https://github.com/katsushi2441/ktrackgeo) を1つにした製品です。
2つの診断の中身には手を入れず、入口（`ktrackgeoseo.php`）で続けてかけて、1枚の結果にまとめます。AEOの判定は、当社の Kurage GEO（クラウド版）の日本語AEO判定を PHP に移したものです。

デモ: <https://proto.exbridge.jp/ktrackgeoseo/>

## 何をするか

| | 見るもの | ファイル |
|---|---|---|
| **SEO**（Google検索） | 10カテゴリ（題名・説明文の全角の長さ、見出し、構造化データ、OGP、表示速度、日本語サイト固有など） | `kseo.php` |
| **AEO**（AIの回答に答えとして引用されるか） | 7観点（結論が先にあるか、定義の文、質問と短い答え、根拠・出典、読みやすさ、検索意図の網羅、断定・根拠なしの数字）。日本語と英語でそれぞれの言い回しで判定 | `ktrackgeoseo_aeo.php` |
| **GEO**（AI検索に読まれるか） | 8観点（AIクローラーの許可、llms.txt、構造化データ、メタ情報、コンテンツ、更新シグナル、AI向け発見性、ブランド） | `ktrackgeo_geo.php` |
| **AIクローラーの実測** | GPTBot・ClaudeBot・PerplexityBot などの訪問、人のアクセスと流入元 | `ktrackgeo.php` ＋ `ktrackgeo_dashboard.php` |

- **判定に生成AIを使いません。**同じページなら何度測っても同じ結果です。「直したのに点が下がった」が起きません
- 直すことを、重い順・効果の大きい順に出します
- 診断書を Markdown でダウンロードでき、印刷・PDF保存にも対応しています
- **結果ページとカード画像**：診断ごとに結果ページ（`ktrackgeoseo.php?r=…`・検索エンジンには出さない）と、ドメイン名と3つの点を描いたカード画像（OGP・1200×630）を作ります。「Xに投稿」「投稿文をコピー」のボタンで、点数とURLをそのままXに貼れます。3つとも80点以上なら札が付きます
- カード画像は PHP の GD（FreeType）で作ります。文字は同梱の切り出しフォント（`ktgs_assets/`・Noto Sans CJK JP・SIL OFL 1.1）

## 動作要件

- PHP 8.0 以上（curl・dom・mbstring・gd〔FreeType〕拡張。intl があれば全角・半角の正規化が Kurage GEO と完全に揃います）
- データベース・Composer・npm・cron 不要。レンタルサーバーに FTP で置くだけ
- 外部APIを呼びません（診断する相手のサイトを読みに行くだけ）

## 設置

[INSTALL.md](INSTALL.md) を読んでください。AIエージェント（Claude Code / Codex）に渡せば設置まで進めます。

## ライセンス

MIT License。商用利用・改変・再配布・再販が自由です。

提供: 株式会社エクスブリッジ <https://exbridge.jp/>
