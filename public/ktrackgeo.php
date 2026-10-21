<?php
/**
 * Kurage Track & GEO — 1ファイルで完結するアクセス解析。
 *
 * 【方針】外に何も出さない・何にも依存しない
 *   - データベース不要（月ごとのテキストログに追記するだけ）
 *   - 外部ライブラリ不要（グラフは自前のSVG。CDNも読まない）
 *   - 外部APIを呼ばない（呼ぶと相手が落ちたとき自分の画面が止まる）
 *   - 認証はパスワード1つ（他システムの認証基盤に依存させない）
 *
 * 【計測の入り口は2つ】
 *   1. JSタグ  … <script>で ktrackgeo.php?url=... を叩く。静的HTMLでも使える。
 *                ただし JavaScript を実行しない相手は絶対に拾えない。
 *   2. PHP同梱 … ページ先頭で require するとサーバー側で記録する。
 *                こちらは検索エンジンやAIのクローラーも拾える。
 *
 *   AIクローラーを見たいなら 2 が要る。1 だけでは原理的に測れない
 *   （この点を取り違えると「AIに読まれていない」と誤診する）。
 *
 * 【個人情報】IPは保存しない。訪問者の区別に必要なのは「同じ人か」だけで、
 *   誰かを特定する必要は無いため、ソルト付きハッシュの先頭12桁だけを残す。
 *
 * PHP 5.x でも動く構文だけを使う。
 */

/* ============================================================
 * 設定
 * ========================================================== */
if (file_exists(__DIR__ . '/ktrackgeo_config.php')) {
    require_once __DIR__ . '/ktrackgeo_config.php';
}

// 管理画面のパスワード（password_hash で作った文字列）。空なら管理画面を開かない。
if (!defined('KTG_PASSWORD_HASH')) { define('KTG_PASSWORD_HASH', ''); }
// ログの置き場。Webから直接読めない場所を指定するのが望ましい。
if (!defined('KTG_LOG_DIR'))       { define('KTG_LOG_DIR', __DIR__); }
// 何か月分を残すか。過ぎたものは自動で消す（際限なく増えると必ず読めなくなる）。
if (!defined('KTG_KEEP_MONTHS'))   { define('KTG_KEEP_MONTHS', 24); }
// サーバー間で呼ぶときの合鍵（転送ページからIP・UAを代理で渡す用途）。
// **既定は空**にしておくこと。値を配ると全設置先で同じ鍵になり、
// 任意のIP・UAで計測を偽装できてしまう。使う場合は設置ごとに生成する。
if (!defined('KTG_INTERNAL_KEY'))  { define('KTG_INTERNAL_KEY', ''); }
if (!defined('KTG_TITLE'))         { define('KTG_TITLE', 'アクセス解析'); }
// 自サイトのホスト名（カンマ区切り）。参照元の集計で「サイト内の移動」と
// 「外に見つけてもらった流入」を分けるのに使う。混ぜると外部流入が埋もれる。
if (!defined('KTG_OWN_HOSTS'))     { define('KTG_OWN_HOSTS', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ''); }
if (!defined('KTG_TIMEZONE'))      { define('KTG_TIMEZONE', 'Asia/Tokyo'); }
// 集計結果を持ち回る秒数。0で無効。
if (!defined('KTG_CACHE_SECONDS'))  { define('KTG_CACHE_SECONDS', 300); }
// GEO診断の対象。空なら自サイトのトップを見る。
if (!defined('KTG_GEO_TARGETS'))   { define('KTG_GEO_TARGETS', ''); }

date_default_timezone_set(KTG_TIMEZONE);

/* ============================================================
 * 小物
 * ========================================================== */
function ktg_h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** ログの区切り文字と改行を落とす。混ざると行がずれて全部読めなくなる。 */
function ktg_clean($v) {
    return str_replace(array('|', "\n", "\r", "\t"), ' ', trim((string)$v));
}

/**
 * 訪問者の識別子。
 *
 * IPそのものは残さない。同じ人かどうかが分かれば足りるので、
 * サーバーごとに固定のソルトを付けたハッシュの先頭だけを持つ。
 * ソルトは初回に自動生成してファイルに置く（消すと過去と繋がらなくなる）。
 */
function ktg_visitor_id($ip) {
    static $salt = null;
    if ($salt === null) {
        $f = KTG_LOG_DIR . '/.ktg_salt';
        $salt = @file_get_contents($f);
        if (!$salt) {
            $salt = bin2hex(function_exists('random_bytes') ? random_bytes(16) : pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand()));
            @file_put_contents($f, $salt);
            @chmod($f, 0600);
        }
        $salt = trim($salt);
    }
    if ((string)$ip === '') { return '-'; }
    return substr(hash('sha256', $salt . '|' . $ip), 0, 12);
}

/**
 * ブラウザが実際にタグを実行したことを示す印。
 *
 * go.php がこのCookieを読んで、クリックが人によるものかを判定している
 * （消すと go.php の click_quality が常に raw に落ちる）。
 * 値は時刻だけで、個人を特定する情報は入れない。
 */
function ktg_set_seen_cookie() {
    if (headers_sent()) { return; }
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    header('Set-Cookie: kurage_st_seen=' . time()
        . '; Path=/; Max-Age=2592000; SameSite=Lax' . ($secure ? '; Secure' : '') . '; HttpOnly', false);
}

/* ============================================================
 * 相手の判別
 *
 * 「人／検索エンジン／AI／その他ボット」の4つに分ける。
 * 従来はボットを捨てていたが、それだと **AIに読まれているかが永久に見えない**。
 * 捨てずに記録し、集計で分ける。
 * ========================================================== */

/** AI関連のクローラー。ここが見たくてボットを残している。 */
function ktg_ai_bots() {
    return array(
        'gptbot' => 'GPTBot (OpenAI)',
        'oai-searchbot' => 'OAI-SearchBot (ChatGPT検索)',
        'chatgpt-user' => 'ChatGPT-User',
        'claudebot' => 'ClaudeBot (Anthropic)',
        'claude-web' => 'Claude-Web',
        'anthropic-ai' => 'Anthropic-AI',
        'perplexitybot' => 'PerplexityBot',
        'perplexity-user' => 'Perplexity-User',
        'google-extended' => 'Google-Extended (Gemini)',
        'ccbot' => 'CCBot (Common Crawl)',
        'bytespider' => 'Bytespider (ByteDance)',
        'meta-externalagent' => 'Meta-ExternalAgent',
        'applebot-extended' => 'Applebot-Extended',
        'cohere-ai' => 'Cohere-AI',
        'diffbot' => 'Diffbot',
        'amazonbot' => 'Amazonbot',
        'youbot' => 'YouBot',
        'timpibot' => 'Timpibot',
        'omgili' => 'Omgili',
    );
}

/** 検索エンジンのクローラー。 */
function ktg_search_bots() {
    return array(
        'googlebot' => 'Googlebot',
        'google-safety' => 'Google-Safety',
        'google-read-aloud' => 'Google Read Aloud',
        'googleother' => 'GoogleOther',
        'bingbot' => 'Bingbot',
        'duckduckbot' => 'DuckDuckBot',
        'yandexbot' => 'YandexBot',
        'baiduspider' => 'Baiduspider',
        'applebot' => 'Applebot',
        'naver' => 'Naver',
        'petalbot' => 'PetalBot',
    );
}

/** それ以外のボットを見分ける語。 */
function ktg_other_bot_words() {
    return array(
        'bot', 'crawler', 'spider', 'slurp', 'crawl', 'mediapartners',
        'curl', 'wget', 'python', 'httpclient', 'scrapy', 'headless',
        'phantom', 'selenium', 'playwright', 'puppeteer', 'http_request',
        'facebookexternalhit', 'twitterbot', 'slackbot', 'discordbot', 'linebot',
        'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot', 'dataforseo',
        'kgrowth', 'monitoring', 'uptime', 'pingdom', 'zabbix',
    );
}

/**
 * @return array array(種別, 表示名)
 *   種別は human / ai / search / bot
 */
function ktg_classify_ua($ua) {
    $u = strtolower(trim((string)$ua));
    if ($u === '') { return array('bot', '(UAなし)'); }
    foreach (ktg_ai_bots() as $key => $label) {
        if (strpos($u, $key) !== false) { return array('ai', $label); }
    }
    foreach (ktg_search_bots() as $key => $label) {
        if (strpos($u, $key) !== false) { return array('search', $label); }
    }
    foreach (ktg_other_bot_words() as $word) {
        if (strpos($u, $word) !== false) { return array('bot', ktg_short_ua($ua)); }
    }
    return array('human', ktg_device_of($u));
}

/** 端末のざっくり分類。人の内訳を見るのに使う。 */
function ktg_device_of($u) {
    if (strpos($u, 'iphone') !== false || strpos($u, 'android') !== false
        || strpos($u, 'mobile') !== false) { return 'スマートフォン'; }
    if (strpos($u, 'ipad') !== false || strpos($u, 'tablet') !== false) { return 'タブレット'; }
    return 'パソコン';
}

function ktg_short_ua($ua) {
    $ua = trim((string)$ua);
    if (preg_match('#([A-Za-z0-9_.\-]+bot[A-Za-z0-9_.\-]*)#i', $ua, $m)) { return $m[1]; }
    return mb_strimwidth($ua, 0, 40, '…', 'UTF-8');
}

/* ============================================================
 * ログの読み書き
 *
 * 1行 = 日時 | 訪問者ID | URL | 参照元 | UA | ページ名
 * 末尾のページ名は後から足した項目なので、無い行も読める。
 * ========================================================== */

function ktg_log_path($ym = null) {
    if ($ym === null) { $ym = date('Y-m'); }
    return KTG_LOG_DIR . '/access-' . $ym . '.log';
}

/** 読む対象のログ。古い形式の access.log も混ぜて読む。 */
function ktg_log_files() {
    $files = glob(KTG_LOG_DIR . '/access-*.log');
    if (!is_array($files)) { $files = array(); }
    sort($files);
    $legacy = KTG_LOG_DIR . '/access.log';
    if (file_exists($legacy)) { array_unshift($files, $legacy); }
    return $files;
}

/** 保存期間を過ぎたログを消す。放っておくと必ず読めなくなる。 */
function ktg_prune_logs() {
    $limit = date('Y-m', strtotime('-' . (int)KTG_KEEP_MONTHS . ' months'));
    foreach (glob(KTG_LOG_DIR . '/access-*.log') as $f) {
        if (preg_match('/access-(\d{4}-\d{2})\.log$/', $f, $m) && $m[1] < $limit) { @unlink($f); }
    }
}

function ktg_write($visitor, $url, $ref, $ua, $title) {
    $line = date('Y-m-d H:i:s') . ' | ' . ktg_clean($visitor) . ' | ' . ktg_clean($url)
          . ' | ' . ktg_clean($ref) . ' | ' . ktg_clean($ua) . ' | ' . ktg_clean($title) . "\n";
    $path = ktg_log_path();
    $new  = !file_exists($path);
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    if ($new) { @chmod($path, 0600); ktg_prune_logs(); }
}

/* ============================================================
 * 集計
 * ========================================================== */

/**
 * ログを1行ずつ読んで数える。
 * file() で丸ごと配列にするとメモリを食い潰すので、必ずストリームで読む。
 */
function ktg_aggregate($since_ts) {
    // 集計は毎回ログを全部読む。鍵を掛けない運用だと開かれるたびに走るので、
    // 少しの間だけ結果を持ち回る。取りこぼしても次の更新で反映される。
    $cache = KTG_LOG_DIR . '/.ktg_cache_' . md5((string)$since_ts) . '.json';
    if (KTG_CACHE_SECONDS > 0 && file_exists($cache)
        && (time() - filemtime($cache)) < KTG_CACHE_SECONDS) {
        $hit = json_decode((string)@file_get_contents($cache), true);
        if (is_array($hit)) { return $hit; }
    }
    $out = ktg_aggregate_scan($since_ts);
    if (KTG_CACHE_SECONDS > 0) {
        @file_put_contents($cache, json_encode($out), LOCK_EX);
        @chmod($cache, 0600);
    }
    return $out;
}

function ktg_aggregate_scan($since_ts) {
    $a = array(
        'per_day' => array(),        // 日 => 人のPV
        'bot_per_day' => array(),    // 日 => ボットの訪問
        'pages' => array(),          // URL => array(pv, title, visitors[])
        'refs' => array(),           // 外部からの流入だけ
        'internal' => 0,             // サイト内の移動（外部流入と混ぜない）
        'direct' => 0,               // 参照元なし（ブックマーク・直接入力・アプリ内）
        'sessions' => 0,             // 訪問回数（30分あいたら別の訪問と数える）
        'devices' => array(),
        'ai' => array(),             // AI名 => 件数
        'ai_pages' => array(),       // URL => 件数
        'search' => array(),
        'bots' => array(),
        'clicks' => array(),         // 外部リンクのクリック
        'kinds' => array('human' => 0, 'ai' => 0, 'search' => 0, 'bot' => 0),
        'visitors' => array(),
        'last_ai' => array(),
        'lines' => 0,
    );
    // 同じ人が続けて見ているのか、来直したのかを分ける。
    // 参照元は「訪問の入口」で1回だけ数える。ページを開くたびに数えると、
    // 再読み込みや戻る操作のぶん流入が水増しされる（実測で約1割）。
    $last_seen = array();
    $GAP = 1800;
    foreach (ktg_log_files() as $file) {
        $fh = @fopen($file, 'r');
        if (!$fh) { continue; }
        while (($line = fgets($fh)) !== false) {
            $p = explode(' | ', rtrim($line, "\n"));
            if (count($p) < 5) { continue; }
            $ts = strtotime($p[0]);
            if (!$ts || ($since_ts !== null && $ts < $since_ts)) { continue; }
            $a['lines']++;

            $date    = substr($p[0], 0, 10);
            $visitor = $p[1];
            $url     = $p[2];
            $ref     = $p[3];
            $ua      = $p[4];
            $title   = isset($p[5]) ? $p[5] : '';

            list($kind, $label) = ktg_classify_ua($ua);
            $a['kinds'][$kind]++;

            $is_entry = false;
            if ($kind === 'human') {
                $key = ($visitor !== '' && $visitor !== '-') ? $visitor : 'anon';
                $is_entry = !isset($last_seen[$key])
                         || ($ts - $last_seen[$key]) > $GAP
                         || $ts < $last_seen[$key];   // 時刻が巻き戻ったら別の訪問とみなす
                $last_seen[$key] = $ts;
                if ($is_entry) { $a['sessions']++; }
            }

            if ($kind === 'human') {
                if (!isset($a['per_day'][$date])) { $a['per_day'][$date] = 0; }
                $a['per_day'][$date]++;
                if (!isset($a['devices'][$label])) { $a['devices'][$label] = 0; }
                $a['devices'][$label]++;
                if ($visitor !== '' && $visitor !== '-') { $a['visitors'][$visitor] = true; }
            } else {
                if (!isset($a['bot_per_day'][$date])) { $a['bot_per_day'][$date] = 0; }
                $a['bot_per_day'][$date]++;
            }
            if ($kind === 'ai') {
                if (!isset($a['ai'][$label])) { $a['ai'][$label] = 0; }
                $a['ai'][$label]++;
                $a['last_ai'][$label] = $p[0];
                $key = ktg_page_label($url);
                if ($key !== '') {
                    if (!isset($a['ai_pages'][$key])) { $a['ai_pages'][$key] = 0; }
                    $a['ai_pages'][$key]++;
                }
            } elseif ($kind === 'search') {
                if (!isset($a['search'][$label])) { $a['search'][$label] = 0; }
                $a['search'][$label]++;
            } elseif ($kind === 'bot') {
                if (!isset($a['bots'][$label])) { $a['bots'][$label] = 0; }
                $a['bots'][$label]++;
            }

            // ページ別（人のぶんだけ数える。ボットを混ぜると人気ページが歪む）
            if ($kind === 'human' && $url !== '' && strpos($url, 'ktrackgeo.php') === false) {
                $key = ktg_is_redirect_url($url) ? '' : ktg_page_label($url);
                if ($key !== '') {
                    if (!isset($a['pages'][$key])) {
                        $a['pages'][$key] = array('pv' => 0, 'title' => '', 'v' => array());
                    }
                    $a['pages'][$key]['pv']++;
                    if ($title !== '' && $a['pages'][$key]['title'] === '') {
                        $a['pages'][$key]['title'] = $title;
                    }
                    if ($visitor !== '' && $visitor !== '-') { $a['pages'][$key]['v'][$visitor] = true; }
                }
                $click = ktg_click_of($url);
                if ($click !== null) {
                    $ck = $click['to'] . "\t" . $click['what'];
                    if (!isset($a['clicks'][$ck])) {
                        $a['clicks'][$ck] = array('to' => $click['to'], 'what' => $click['what'],
                                                  'n' => 0, 'human' => 0, 'last' => '');
                    }
                    $a['clicks'][$ck]['n']++;
                    $is_human_click = ($click['human'] === null) ? ($ref !== '') : $click['human'];
                    if ($is_human_click) { $a['clicks'][$ck]['human']++; }
                    $a['clicks'][$ck]['last'] = $p[0];
                }
            }

            // 訪問の入口のときだけ、どこから来たかを数える
            if ($is_entry && strpos($ref, 'ktrackgeo.php') === false) {
                if ($ref === '') {
                    $a['direct']++;
                } elseif (ktg_is_own_host($ref)) {
                    $a['internal']++;
                } else {
                    $r = ktg_ref_label($ref);
                    if ($r !== '') {
                        if (!isset($a['refs'][$r])) { $a['refs'][$r] = 0; }
                        $a['refs'][$r]++;
                    }
                }
            }
        }
        fclose($fh);
    }
    ksort($a['per_day']);
    ksort($a['bot_per_day']);
    arsort($a['refs']);
    arsort($a['ai']);
    arsort($a['ai_pages']);
    arsort($a['search']);
    arsort($a['bots']);
    arsort($a['devices']);
    uasort($a['pages'], function ($x, $y) { return $y['pv'] - $x['pv']; });
    uasort($a['clicks'], function ($x, $y) { return $y['n'] - $x['n']; });
    return $a;
}

/** 転送だけを行うページか（/go.php?to=... のような中継）。 */
function ktg_is_redirect_url($url) {
    return ktg_click_of($url) !== null;
}

/** URLをページ名に落とす（ドメインを除いたパス＋クエリ）。 */
function ktg_page_label($url) {
    $u = parse_url(urldecode((string)$url));
    if (!$u) { return ''; }
    $path = isset($u['path']) ? $u['path'] : '/';
    $q    = isset($u['query']) ? ('?' . $u['query']) : '';
    return mb_strimwidth($path . $q, 0, 120, '…', 'UTF-8');
}

/** 自分のサイトからの遷移か。 */
function ktg_is_own_host($url) {
    $u = parse_url(urldecode((string)$url));
    if (!$u || empty($u['host'])) { return false; }
    $host = strtolower(preg_replace('/^www\./', '', $u['host']));
    foreach (explode(',', KTG_OWN_HOSTS) as $own) {
        $own = strtolower(trim(preg_replace('/^www\./', '', $own)));
        if ($own !== '' && $host === $own) { return true; }
    }
    return false;
}

/**
 * 参照元のラベル。
 *
 * 【断定しないこと】Google は Referrer-Policy により、こちらへは
 * "https://www.google.com/" というドメインだけを渡してくる。パスも検索語も
 * 落ちるので、**自然検索・Discover・ニュース・広告の区別がつかない**。
 * これを一律「Google検索」と表示すると Search Console と食い違い、
 * 見る人が数字を信用できなくなる。分からないものは分からないと書く。
 *
 * 参照元に検索の形（/search や q= ）が残っている相手だけ「検索」と言い切る。
 */
function ktg_ref_label($ref) {
    $u = parse_url(urldecode((string)$ref));
    if (!$u || empty($u['host'])) { return ''; }
    $host  = strtolower(preg_replace('/^www\./', '', $u['host']));
    $path  = isset($u['path']) ? $u['path'] : '';
    $query = isset($u['query']) ? $u['query'] : '';
    $is_search = (strpos($path, '/search') !== false)
              || preg_match('/(^|&)(q|p|query|k)=/', $query);

    $services = array(
        'google.com' => 'Google', 'google.co.jp' => 'Google', 'search.google.com' => 'Google',
        'news.google.com' => 'Googleニュース',
        'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo',
        'yahoo.co.jp' => 'Yahoo!', 'search.yahoo.co.jp' => 'Yahoo!',
        'baidu.com' => 'Baidu', 'm.baidu.com' => 'Baidu',
    );
    if (isset($services[$host])) {
        // 検索だと分かるならそう書く。分からないなら断定しない
        return $services[$host] . ($is_search ? '検索' : '（検索・その他）');
    }
    $known = array(
        't.co' => 'X (Twitter)', 'x.com' => 'X (Twitter)', 'twitter.com' => 'X (Twitter)',
        'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT',
        'claude.ai' => 'Claude', 'perplexity.ai' => 'Perplexity',
        'gemini.google.com' => 'Gemini',
        'facebook.com' => 'Facebook', 'l.facebook.com' => 'Facebook',
        'threads.com' => 'Threads', 'l.threads.com' => 'Threads',
        'hatena.ne.jp' => 'はてな', 'b.hatena.ne.jp' => 'はてブ',
        'blogspot.com' => 'Blogger', 'note.com' => 'note', 'zenn.dev' => 'Zenn',
    );
    return isset($known[$host]) ? $known[$host] : $host;
}

/**
 * 外部リンクのクリック。/go.php?to=...&product=... のような
 * 転送ページを通したクリックを拾う。転送ページの名前は問わない。
 */
function ktg_click_of($url) {
    $u = parse_url(urldecode((string)$url));
    if (!$u || empty($u['query'])) { return null; }
    $q = array();
    parse_str($u['query'], $q);
    $to = '';
    foreach (array('to', 'click', 'dest', 'target') as $k) {
        if (!empty($q[$k])) { $to = (string)$q[$k]; break; }
    }
    if ($to === '') { return null; }
    $what = '';
    foreach (array('product', 'asin', 'item', 'id', 'q') as $k) {
        if (!empty($q[$k])) { $what = (string)$q[$k]; break; }
    }
    // 転送ページが付ける品質の印。参照元かCookieで人と判断できたものだけを
    // 「人のクリック」として数える。付いていない古い記録は参照元の有無で見る。
    $human = isset($q['click_quality']) ? ($q['click_quality'] === 'likely_human') : null;
    return array('to' => mb_strimwidth($to, 0, 40, '…', 'UTF-8'),
                 'what' => mb_strimwidth($what, 0, 60, '…', 'UTF-8'),
                 'human' => $human);
}

/* ============================================================
 * 画面の部品（グラフは自前のSVG。CDNを読まない）
 * ========================================================== */

/** 折れ線。人とボットを重ねて描く。 */
function ktg_svg_line($human, $bot, $w = 1040, $h = 220) {
    $days = array_keys($human + $bot);
    sort($days);
    if (!$days) { return '<p class="none">データがありません。</p>'; }
    $max = 1;
    foreach ($days as $d) {
        $max = max($max, isset($human[$d]) ? $human[$d] : 0, isset($bot[$d]) ? $bot[$d] : 0);
    }
    $n = count($days);
    $pad = 34;
    $iw = $w - $pad * 2; $ih = $h - $pad * 2;
    $pt = function ($series) use ($days, $n, $max, $pad, $iw, $ih) {
        $out = array();
        foreach ($days as $i => $d) {
            $x = $pad + ($n <= 1 ? $iw / 2 : $iw * $i / ($n - 1));
            $y = $pad + $ih - $ih * ((isset($series[$d]) ? $series[$d] : 0) / $max);
            $out[] = round($x, 1) . ',' . round($y, 1);
        }
        return implode(' ', $out);
    };
    $s = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">';
    for ($i = 0; $i <= 4; $i++) {
        $y = $pad + $ih * $i / 4;
        $s .= '<line x1="' . $pad . '" y1="' . $y . '" x2="' . ($w - $pad) . '" y2="' . $y . '" class="grid"/>';
        $s .= '<text x="4" y="' . ($y + 4) . '" class="ax">' . number_format(round($max * (4 - $i) / 4)) . '</text>';
    }
    $s .= '<polyline class="ln bot" points="' . $pt($bot) . '"/>';
    $s .= '<polyline class="ln human" points="' . $pt($human) . '"/>';
    $s .= '<text x="' . $pad . '" y="' . ($h - 8) . '" class="ax">' . ktg_h($days[0]) . '</text>';
    $s .= '<text x="' . ($w - $pad) . '" y="' . ($h - 8) . '" class="ax" text-anchor="end">'
        . ktg_h($days[$n - 1]) . '</text>';
    return $s . '</svg>';
}

/** 横棒。件数の多い順に並べる。 */
function ktg_bars($rows, $limit = 12) {
    if (!$rows) { return '<p class="none">データがありません。</p>'; }
    $max = max($rows) ?: 1;
    $s = '<div class="bars">';
    $i = 0;
    foreach ($rows as $label => $n) {
        if ($i++ >= $limit) { break; }
        $s .= '<div class="bar"><span class="bl" title="' . ktg_h($label) . '">' . ktg_h($label) . '</span>'
            . '<span class="bt"><i style="width:' . round($n / $max * 100, 1) . '%"></i></span>'
            . '<span class="bn">' . number_format($n) . '</span></div>';
    }
    return $s . '</div>';
}

/* ============================================================
 * GEO診断の履歴
 *
 * 点数だけを残す。直したら上がる／下がったことが分かればよく、
 * 判定の中身は毎回取り直せる。全部保存すると際限なく膨らむ。
 * ========================================================== */

function ktg_geo_history_path() { return KTG_LOG_DIR . '/geo-history.json'; }

function ktg_geo_history() {
    $raw = @file_get_contents(ktg_geo_history_path());
    $d = $raw ? json_decode($raw, true) : null;
    return is_array($d) ? $d : array();
}

function ktg_geo_record($url, $result) {
    $h = ktg_geo_history();
    if (!isset($h[$url])) { $h[$url] = array(); }
    $h[$url][] = array(
        'at' => time(),
        'score' => isset($result['score']) ? (int)$result['score'] : 0,
        'band' => isset($result['band']) ? $result['band'] : '',
        'categories' => isset($result['categories']) ? $result['categories'] : array(),
    );
    // 直近30回だけ残す
    if (count($h[$url]) > 30) { $h[$url] = array_slice($h[$url], -30); }
    @file_put_contents(ktg_geo_history_path(), json_encode($h, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod(ktg_geo_history_path(), 0600);
    return $h[$url];
}

/** 診断する対象のURL一覧。設定が空なら自サイトのトップ。 */
function ktg_geo_targets() {
    $t = array_filter(array_map('trim', explode(',', KTG_GEO_TARGETS)));
    if ($t) { return $t; }
    $own = array_filter(array_map('trim', explode(',', KTG_OWN_HOSTS)));
    return $own ? array('https://' . $own[0] . '/') : array();
}

/* ============================================================
 * ここから処理の振り分け
 * ========================================================== */

// (A) PHPページに require されたとき＝サーバー側で1件記録して戻る。
//     クローラーもここで拾える（JSタグでは絶対に拾えない）。
if (basename((string)(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '')) !== basename(__FILE__)) {
    $u = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
       . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
       . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    ktg_write(ktg_visitor_id(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
        $u, isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
        isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
        defined('KTG_PAGE_TITLE') ? KTG_PAGE_TITLE : '');
    return;
}

// (B) 管理画面
if (isset($_GET['dashboard'])) {
    // 通常の導入では管理画面の閲覧は記録しない（自分の閲覧で数字が濁るため）。
    // ただしデモサイトは「訪問者が見るページ＝管理画面」なので、設定で明示した
    // ときだけ1件記録する。これが無いとデモの数字が公開日から永遠に増えない
    // （2026-08-07で止まって見えた実障害。計測自体は正常だった）。
    if (defined('KTG_LOG_DASHBOARD_VIEWS') && KTG_LOG_DASHBOARD_VIEWS) {
        $u = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
           . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
           . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
        ktg_write(ktg_visitor_id(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
            $u, isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '',
            isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
            'デモ管理画面');
    }
    require __DIR__ . '/ktrackgeo_dashboard.php';
    exit;
}

// (C) 計測エンドポイント（JSタグ、および go.php からのサーバー間呼び出し）
$internal_ok = (KTG_INTERNAL_KEY !== '' && isset($_GET['ktg_key'])
                && hash_equals(KTG_INTERNAL_KEY, (string)$_GET['ktg_key']));

$url = '';
if (isset($_GET['url']) && $_GET['url'] !== '') {
    $url = filter_var($_GET['url'], FILTER_SANITIZE_URL);
    if (!preg_match('#^https?://#i', $url)) { $url = ''; }
}
$ref = '';
if (isset($_GET['ref']) && $_GET['ref'] !== '') {
    $ref = filter_var($_GET['ref'], FILTER_SANITIZE_URL);
    if (!preg_match('#^https?://#i', $ref)) { $ref = ''; }
} elseif (isset($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
}
$ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
// 合鍵があるときだけ、代理で渡された訪問者の情報を信用する（go.php 用）
if ($internal_ok) {
    if (!empty($_GET['ua'])) { $ua = (string)$_GET['ua']; }
    if (!empty($_GET['ip'])) { $ip = (string)$_GET['ip']; }
}
$title = isset($_GET['t']) ? mb_strimwidth((string)$_GET['t'], 0, 120, '…', 'UTF-8') : '';

if (!$internal_ok) { ktg_set_seen_cookie(); }
ktg_write(ktg_visitor_id($ip), $url, $ref, $ua, $title);

header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: no-store, max-age=0');
echo '/* tracked */';
exit;
