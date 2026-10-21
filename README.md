# Kurage Track & GEO & SEO（ktrackgeoseo）

**URLを1回入れるだけで、SEO・AEO・GEOをまとめて診断し、AIのクローラーが実際に来たかまで測る。**

[Kurage SEO](https://github.com/katsushi2441/kseo) と [Kurage Track & GEO](https://github.com/katsushi2441/ktrackgeo) を1つにした製品です。
2つの診断の中身には手を入れず、入口（`ktrackgeoseo.php`）で続けてかけて、1枚の結果にまとめます。

デモ: <https://proto.exbridge.jp/ktrackgeoseo/>

## 何をするか

| | 見るもの | ファイル |
|---|---|---|
| **SEO**（Google検索） | 10カテゴリ（題名・説明文の全角の長さ、見出し、構造化データ、OGP、表示速度、日本語サイト固有など） | `kseo.php` |
| **AEO・GEO**（AI検索・AIの回答） | 8観点（AIクローラーの許可、llms.txt、構造化データ、メタ情報、コンテンツ、更新シグナル、AI向け発見性、ブランド） | `ktrackgeo_geo.php` |
| **AIクローラーの実測** | GPTBot・ClaudeBot・PerplexityBot などの訪問、人のアクセスと流入元 | `ktrackgeo.php` ＋ `ktrackgeo_dashboard.php` |

- **判定に生成AIを使いません。**同じページなら何度測っても同じ結果です。「直したのに点が下がった」が起きません
- 直すことを、重い順・効果の大きい順に出します
- 診断書を Markdown でダウンロードでき、印刷・PDF保存にも対応しています

## 動作要件

- PHP 8.0 以上（curl 拡張）
- データベース・Composer・npm・cron 不要。レンタルサーバーに FTP で置くだけ
- 外部APIを呼びません（診断する相手のサイトを読みに行くだけ）

## 設置

[INSTALL.md](INSTALL.md) を読んでください。AIエージェント（Claude Code / Codex）に渡せば設置まで進めます。

## ライセンス

MIT License。商用利用・改変・再配布・再販が自由です。

提供: 株式会社エクスブリッジ <https://exbridge.jp/>
