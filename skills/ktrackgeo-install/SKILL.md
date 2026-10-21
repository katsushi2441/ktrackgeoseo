---
name: ktrackgeo-install
description: Kurage Track & GEO をレンタルサーバーへ設置し、計測タグとAIクローラー計測を有効にする。設置・初期設定・動作確認まで。
---

# ktrackgeo の設置

このスキルは **Kurage Track & GEO** を設置するためのものです。
利用者は非エンジニアの可能性があります。専門用語は避け、ひとつずつ確認しながら進めてください。

## 進め方

### 1. 置き場所を決める

`public/` の中の次の3つを、サイトと同じサーバーにFTPで置きます。

- `ktrackgeo.php`
- `ktrackgeo_dashboard.php`
- `ktrackgeo_geo.php`

サイトのトップと同じ階層に置くのが最も簡単です。

### 2. 設定ファイルを作る

`ktrackgeo_config.php.example` を `ktrackgeo_config.php` にコピーし、次を埋めます。

| 項目 | 何を入れるか |
|---|---|
| `KTG_OWN_HOSTS` | 自分のサイトのホスト名（例 `example.co.jp`）。**必ず入れる** |
| `KTG_GEO_TARGETS` | 診断したいURL。空なら上のトップを見る |
| `KTG_PASSWORD_HASH` | 管理画面のパスワード。空なら鍵なしで開く |
| `KTG_TITLE` | 画面のタイトル |

`KTG_OWN_HOSTS` を入れないと、**サイト内の移動が「外部からの流入」に混ざり**、
どこから来たのかが分からなくなります。ここは必ず確認してください。

パスワードのハッシュは次で作ります。

```
php scripts/make_password_hash.php 'パスワード'
```

### 3. データの置き場を守る

`ktg_data/.htaccess` も一緒に上げてください。これが無いと、
**ログがWebから直接読めてしまいます**。

### 4. 計測タグを入れる（人のアクセス）

各ページの `</head>` の直前に入れます。`example.co.jp` は自分のサイトに置き換えます。

```html
<script>(function(){var s=document.createElement('script');
s.src='https://example.co.jp/ktrackgeo.php?url='+encodeURIComponent(location.href)
+'&ref='+encodeURIComponent(document.referrer)+'&t='+encodeURIComponent(document.title);
document.head.appendChild(s)})();</script>
```

`&t=` はページ名です。入れておくと画面に「どのページか」が名前で出ます。

### 5. AIクローラーの計測を入れる（重要）

**手順4だけでは、GPTBot や ClaudeBot の訪問は測れません。**
クローラーは JavaScript を実行しないためです。

PHPのページなら、**ファイルの一番上**（`<?php` の直後、何か出力する前）にこれを入れます。

```php
<?php require_once __DIR__ . '/ktrackgeo.php'; ?>
```

静的HTMLのページしか無い場合は、**AIクローラーは測れません**。
その旨を利用者にはっきり伝えてください。黙って0件を見せると
「AIに読まれていない」と誤解されます。

### 6. 動作を確認する

必ず次の3つを確認してから「できました」と伝えてください。

1. `https://（サイト）/ktrackgeo.php?dashboard=1` が開く
2. 自分でサイトを開いたあと、画面のページビューが増える
3. `https://（サイト）/ktg_data/` にブラウザでアクセスして **403 か 404 になる**
   （中身が見えたら手順3をやり直す）

## つまずいたとき

| 症状 | 原因 |
|---|---|
| 画面が真っ白 | PHPのバージョンが古い。レンタルサーバーの設定でPHP 7.4以上にする |
| 文字が化ける | サーバーが文字コードを返していない。`.htaccess` に `AddDefaultCharset UTF-8` |
| 数字が増えない | タグのURLが違う。ブラウザの開発者ツールで404が出ていないか見る |
| AIが0件のまま | 手順5をやっていない。あるいは静的HTMLのみのサイト |
| 「サイト内の移動」が0で外部流入が多すぎる | `KTG_OWN_HOSTS` が未設定 |
