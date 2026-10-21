# 設置手順

**AIエージェント（Claude Code / Codex など）に、このファイルと `skills/` を渡してください。**
手順を読んで設置まで進めます。ご自身で進める場合は以下のとおりです。

## 1. ファイルを上げる

サイトと同じサーバーに、FTPで `public/` の中身を置きます。

```
ktrackgeoseo.php          入口（URLを入れて診断する画面）
kseo.php                  SEO診断（単独の画面としても使えます）
ktrackgeo.php             計測（人とAIクローラーの訪問を記録）
ktrackgeo_dashboard.php   計測の画面
ktrackgeoseo_aeo.php      AEO診断
ktrackgeo_geo.php         GEO診断
kseo_data/.htaccess       ← 忘れると診断結果が外から読めます
ktg_data/.htaccess        ← 忘れるとログが外から読めます
ktgs_data/.htaccess       ← 忘れると診断結果が外から読めます
```

## 2. 設定ファイルを作る

3つの `*.example` をコピーして、`.example` を外した名前にします。

- `ktrackgeoseo_config.php` … 入口の設定（画面の題名・パスワード・巡るページ数・回数の上限）
- `kseo_config.php` … SEO診断の細かい設定
- `ktrackgeo_config.php` … 計測の設定。**`KTG_OWN_HOSTS` は必ず自社のホスト名に**

**社外から見える場所に置くなら、パスワードを設定してください。**

```
php scripts/make_password_hash.php 'パスワード'
```

出た文字列を `KTGS_PASSWORD_HASH`（と `KSEO_PASSWORD_HASH`・`KTG_PASSWORD_HASH`）に貼ります。

## 3. 計測を入れる（任意・AIクローラーを測るなら必須）

人のアクセスは各ページの `</head>` 直前のタグで、AIクローラーは PHP のページの一番上の
`<?php require_once __DIR__ . '/ktrackgeo.php'; ?>` で記録します。
くわしくは `skills/ktrackgeo-install/SKILL.md` を見てください。

## 4. 確認

1. `https://（サイト）/ktrackgeoseo.php` を開き、自社のURLを入れて「診断する」→ SEO・AEO・GEOの3つの点が出る
2. 「診断書（Markdown）をダウンロード」でファイルが保存される
3. `https://（サイト）/ktrackgeo.php?dashboard=1` が開く
4. `https://（サイト）/kseo_data/`・`/ktg_data/`・`/ktgs_data/` が **403 か 404**（中身が見えたら手順1をやり直す）
