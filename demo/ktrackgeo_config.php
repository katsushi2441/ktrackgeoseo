<?php
/** デモサイトの設定。 */
define('KTG_TITLE', 'Kurage Track & GEO & SEO — 計測（デモ）');
define('KTG_PASSWORD_HASH', '');   // デモは鍵なしで公開
define('KTG_OWN_HOSTS', 'proto.exbridge.jp');
define('KTG_GEO_TARGETS', 'https://uchinai.exbridge.jp/,https://kappstore.exbridge.jp/');
define('KTG_KEEP_MONTHS', 12);
define('KTG_CACHE_SECONDS', 300);
define('KTG_INTERNAL_KEY', '');
// デモは訪問者が見るページが管理画面そのものなので、閲覧を記録する
define('KTG_LOG_DASHBOARD_VIEWS', true);
