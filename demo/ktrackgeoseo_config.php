<?php
// デモの設定（https://proto.exbridge.jp/ktrackgeoseo/）。配布物の設定例は public/ktrackgeoseo_config.php.example。
define('KTGS_TITLE', 'Kurage Track & GEO & SEO');
define('KTGS_PASSWORD_HASH', '');          // デモは鍵なし
define('KTGS_SEO_PAGES', 5);
define('KTGS_CACHE_HOURS', 24);
define('KTGS_RATE_PER_HOUR', 10);          // デモは1つのIPから1時間に10回まで
define('KTGS_CANONICAL', 'https://proto.exbridge.jp/ktrackgeoseo/ktrackgeoseo.php');
define('KTGS_OG_IMAGE', 'https://proto.exbridge.jp/ktrackgeoseo/ktrackgeoseo-ogp.png');
// デモだけの案内。当社の公開先でだけ出す（配布版の設定には書かない）
define('KTGS_DEMO_FOOTER', (($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp')
  ? '<div class="demo box" style="margin-top:28px"><b>これはデモです。</b>'
  . 'このシステムは、御社のレンタルサーバーにPHPを置くだけで動きます（データベース不要）。'
  . '<div class="actions"><a class="btn main" href="https://kappstore.exbridge.jp/app.php?id=85d96326308722b1&amp;ref=ktrackgeoseo-demo">導入版（Kurage App Store）</a>'
  . '<a class="btn" href="https://exbridge.jp/ai-sns-komon.html?ref=ktrackgeoseo-demo">AI-SNS顧問（X運用×HP×Webシステム）</a></div></div>'
  : '');
