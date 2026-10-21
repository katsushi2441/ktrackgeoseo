<?php
/**
 * GeoCheck — AIに読まれる状態になっているかを、1ファイルで診断する。
 *
 * 【何をするか】
 * 与えられたURLのHTMLと robots.txt / llms.txt を取りに行き、
 * AI検索に見つけてもらうための8つの観点で点を付ける。
 *
 * 【LLMを使わない】
 * 判定はすべて事実の確認だけで済む（タグがあるか、許可されているか、
 * 語数が足りているか）。ここに生成AIを挟むと、結果が実行ごとにぶれて
 * 「直したのに点が下がった」が起きる。診断は決定的であるべき。
 *
 * 【判定基準の出どころ】
 * Auriti-Labs/geo-optimizer-skill（MIT）の監査カテゴリと、
 * kgeo（Kurage GEO）が採用している日本語向けの重み付けに合わせている。
 * 同じURLを kgeo にかけたときと結果が食い違わないようにするため、
 * 分類名とキー名は向こうに揃えてある。
 *
 * 外部ライブラリを使わない。DOMDocument も無い環境を想定し、
 * 判定は正規表現で行う（共有サーバーでは拡張が入っていないことがある）。
 *
 * PHP 5.x でも動く構文だけを使う。
 */

if (!defined('KTG_GEO_UA')) {
    define('KTG_GEO_UA', 'Mozilla/5.0 (compatible; GeoCheck/1.0)');
}
if (!defined('KTG_GEO_TIMEOUT'))  { define('KTG_GEO_TIMEOUT', 15); }
if (!defined('KTG_GEO_MAX_SIZE')) { define('KTG_GEO_MAX_SIZE', 2 * 1024 * 1024); }

/** AI検索の主要クローラー。robots.txt でここが塞がれていると引用されない。 */
function ktg_geo_citation_bots() {
    return array('GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot',
                 'anthropic-ai', 'PerplexityBot', 'Google-Extended', 'CCBot');
}

function ktg_geo_categories() {
    return array(
        'robots'       => 'AIクローラー許可',
        'llms'         => 'llms.txt',
        'schema'       => '構造化データ',
        'meta'         => 'メタ情報',
        'content'      => 'コンテンツ',
        'signals'      => '更新・配信シグナル',
        'ai_discovery' => 'AI向け発見性',
        'brand_entity' => 'ブランド・エンティティ',
    );
}

/* ============================================================
 * 取得
 * ========================================================== */

/**
 * URLを取る。戻り値 array(本文, 実効URL, エラー)。
 *
 * 文字コードはヘッダーではなくHTMLの宣言を優先する。共有サーバーは
 * charset を返さないことが多く、決め打ちすると日本語が化ける。
 */
function ktg_geo_fetch($url) {
    $body = ''; $ctype = ''; $final = $url; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => KTG_GEO_TIMEOUT,
            CURLOPT_USERAGENT => KTG_GEO_UA, CURLOPT_SSL_VERIFYPEER => true,
        ));
        $raw = curl_exec($ch);
        if ($raw === false) { $err = curl_error($ch); }
        else {
            $body  = substr($raw, 0, KTG_GEO_MAX_SIZE);
            $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 400) { $err = 'HTTP ' . $code; }
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array(
            'timeout' => KTG_GEO_TIMEOUT, 'header' => 'User-Agent: ' . KTG_GEO_UA . "\r\n",
            'follow_location' => 1, 'max_redirects' => 5, 'ignore_errors' => true)));
        $raw = @file_get_contents($url, false, $ctx, 0, KTG_GEO_MAX_SIZE);
        if ($raw === false) { $err = '取得できませんでした'; }
        else {
            $body = $raw;
            foreach ((array)$http_response_header as $h) {
                if (stripos($h, 'content-type:') === 0) { $ctype = trim(substr($h, 13)); }
            }
        }
    }
    return array(ktg_geo_decode($body, $ctype), $final, $err);
}

/** バイト列を、宣言された文字コードで読む。 */
function ktg_geo_decode($body, $ctype) {
    if ($body === '') { return ''; }
    if (preg_match('/charset=([\w\-]+)/i', (string)$ctype, $m)) {
        $out = @mb_convert_encoding($body, 'UTF-8', $m[1]);
        if ($out !== false && $out !== '') { return $out; }
    }
    if (preg_match('/<meta[^>]+charset=["\']?([\w\-]+)/i', substr($body, 0, 4096), $m)) {
        $out = @mb_convert_encoding($body, 'UTF-8', $m[1]);
        if ($out !== false && $out !== '') { return $out; }
    }
    if (function_exists('mb_check_encoding') && mb_check_encoding($body, 'UTF-8')) { return $body; }
    return (string)@mb_convert_encoding($body, 'UTF-8', 'SJIS-win,EUC-JP,UTF-8');
}

/** タグを落として本文だけにする。 */
function ktg_geo_text($html) {
    $s = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html);
    $s = preg_replace('/<[^>]+>/', ' ', (string)$s);
    $s = html_entity_decode((string)$s, ENT_QUOTES, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** 語数。日本語は空白で区切られないので文字数から見積もる。 */
function ktg_geo_word_count($text) {
    $ja = preg_match_all('/[\x{3040}-\x{30FF}\x{4E00}-\x{9FFF}]/u', $text);
    $en = preg_match_all('/[A-Za-z]+/u', $text);
    // 日本語は約2文字で1語相当とみなす（kgeo の日本語判定に合わせる）
    return (int)round($ja / 2) + (int)$en;
}

/* ============================================================
 * 8つの判定
 * ========================================================== */

function ktg_geo_check_robots($origin) {
    list($txt, , $err) = ktg_geo_fetch($origin . '/robots.txt');
    $r = array('found' => ($err === '' && trim($txt) !== ''),
               'bots_allowed' => array(), 'bots_blocked' => array(), 'bots_missing' => array());
    foreach (ktg_geo_citation_bots() as $bot) {
        if (!$r['found']) { $r['bots_missing'][] = $bot; continue; }
        // 該当エージェントのブロックだけを見る
        if (preg_match('/User-agent:\s*' . preg_quote($bot, '/') . '\s*(.*?)(?=\nUser-agent:|\z)/is', $txt, $m)) {
            if (preg_match('/Disallow:\s*\/\s*$/im', $m[1])) { $r['bots_blocked'][] = $bot; }
            else { $r['bots_allowed'][] = $bot; }
        } else {
            $r['bots_missing'][] = $bot;   // 明示が無い＝既定で許可
        }
    }
    // 全体拒否（User-agent: * に Disallow: /）は全部塞いだ扱い
    if ($r['found'] && preg_match('/User-agent:\s*\*\s*(.*?)(?=\nUser-agent:|\z)/is', $txt, $m)
        && preg_match('/Disallow:\s*\/\s*$/im', $m[1])) {
        $r['bots_blocked'] = ktg_geo_citation_bots();
        $r['bots_missing'] = array();
    }
    $r['citation_bots_ok'] = count($r['bots_blocked']) === 0;
    $r['citation_bots_explicit'] = count($r['bots_allowed']);
    return $r;
}

function ktg_geo_check_llms($origin) {
    list($txt, , $err) = ktg_geo_fetch($origin . '/llms.txt');
    $ok = ($err === '' && trim($txt) !== '' && strpos($txt, '<html') === false);
    return array(
        'found' => $ok,
        'has_title' => $ok && preg_match('/^\s*#\s+\S/m', $txt) === 1,
        'has_summary' => $ok && preg_match('/^\s*>\s+\S/m', $txt) === 1,
        'has_links' => $ok && preg_match('/\[[^\]]+\]\([^)]+\)/', $txt) === 1,
        'length' => strlen($txt),
    );
}

function ktg_geo_check_schema($html) {
    $types = array(); $raw = array();
    if (preg_match_all('#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $m)) {
        foreach ($m[1] as $block) {
            $raw[] = $block;
            if (preg_match_all('/"@type"\s*:\s*"([^"]+)"/', $block, $t)) {
                foreach ($t[1] as $ty) { $types[$ty] = true; }
            }
        }
    }
    $has = function ($name) use ($types) { return isset($types[$name]); };
    return array(
        'found_types' => array_keys($types),
        'any_schema_found' => count($types) > 0,
        'has_organization' => $has('Organization') || $has('Corporation') || $has('LocalBusiness'),
        'has_website'  => $has('WebSite'),
        'has_faq'      => $has('FAQPage'),
        'has_article'  => $has('Article') || $has('BlogPosting') || $has('NewsArticle'),
        'has_product'  => $has('Product') || $has('Service') || $has('Offer'),
        'has_breadcrumb' => $has('BreadcrumbList'),
        'has_sameas'   => preg_match('/"sameAs"\s*:/', implode(' ', $raw)) === 1,
    );
}

function ktg_geo_check_meta($html, $url) {
    $get = function ($pattern) use ($html) {
        return preg_match($pattern, $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8')) : '';
    };
    $title = $get('#<title[^>]*>(.*?)</title>#is');
    $desc  = $get('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)/i');
    if ($desc === '') { $desc = $get('/<meta[^>]+content=["\']([^"\']*)["\'][^>]+name=["\']description["\']/i'); }
    $canon = $get('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']*)/i');
    $ogt   = $get('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)/i');
    $ogd   = $get('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)/i');
    $ogi   = $get('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']*)/i');
    $robots = $get('/<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']*)/i');
    return array(
        'has_title' => $title !== '', 'title_text' => $title,
        'title_length' => mb_strlen($title, 'UTF-8'),
        'has_description' => $desc !== '', 'description_text' => $desc,
        'description_length' => mb_strlen($desc, 'UTF-8'),
        'has_canonical' => $canon !== '', 'canonical_url' => $canon,
        'has_og_title' => $ogt !== '', 'has_og_description' => $ogd !== '', 'has_og_image' => $ogi !== '',
        'noindex' => stripos($robots, 'noindex') !== false,
    );
}

function ktg_geo_check_content($html) {
    $text = ktg_geo_text($html);
    $h1 = preg_match_all('#<h1[^>]*>(.*?)</h1>#is', $html, $m1);
    $hn = preg_match_all('#<h[2-4][^>]*>#i', $html);
    return array(
        'has_h1' => $h1 > 0,
        'h1_count' => (int)$h1,
        'h1_text' => $h1 ? trim(ktg_geo_text($m1[1][0])) : '',
        'heading_count' => (int)$hn,
        'has_heading_hierarchy' => $h1 > 0 && $hn > 0,
        'word_count' => ktg_geo_word_count($text),
        'has_numbers' => preg_match('/\d/', $text) === 1,
        'numbers_count' => preg_match_all('/\d+/', $text),
        'has_lists_or_tables' => preg_match('#<(ul|ol|table)\b#i', $html) === 1,
        'external_links_count' => preg_match_all('#<a[^>]+href=["\']https?://#i', $html),
    );
}

function ktg_geo_check_signals($html) {
    $lang = preg_match('/<html[^>]+lang=["\']([^"\']+)/i', $html, $m) ? $m[1] : '';
    $rss  = preg_match('#<link[^>]+type=["\']application/(rss|atom)\+xml#i', $html) === 1;
    $fresh = '';
    if (preg_match('/"dateModified"\s*:\s*"([^"]+)"/', $html, $m))       { $fresh = $m[1]; }
    elseif (preg_match('/"datePublished"\s*:\s*"([^"]+)"/', $html, $m))  { $fresh = $m[1]; }
    elseif (preg_match('/<time[^>]+datetime=["\']([^"\']+)/i', $html, $m)) { $fresh = $m[1]; }
    return array(
        'has_lang' => $lang !== '', 'lang_value' => $lang,
        'has_rss' => $rss,
        'has_freshness' => $fresh !== '', 'freshness_date' => $fresh,
    );
}

function ktg_geo_check_ai_discovery($origin, $html, $schema) {
    list($wk, , $wkerr) = ktg_geo_fetch($origin . '/.well-known/ai.txt');
    $n = 0;
    $has_wk = ($wkerr === '' && trim($wk) !== '' && strpos($wk, '<html') === false);
    if ($has_wk) { $n++; }
    $has_summary = preg_match('/<meta[^>]+name=["\']description["\']/i', $html) === 1;
    if ($has_summary) { $n++; }
    if (!empty($schema['has_faq'])) { $n++; }
    if (!empty($schema['has_product'])) { $n++; }
    return array(
        'has_well_known_ai' => $has_wk,
        'has_summary' => $has_summary,
        'has_faq' => !empty($schema['has_faq']),
        'has_service' => !empty($schema['has_product']),
        'endpoints_found' => $n,
    );
}

function ktg_geo_check_brand($html, $meta, $schema) {
    $names = array();
    if ($meta['title_text'] !== '') { $names[] = $meta['title_text']; }
    if (preg_match('/"name"\s*:\s*"([^"]+)"/', $html, $m)) { $names[] = $m[1]; }
    $consistent = true;
    if (count($names) >= 2) {
        // タイトルに組織名が含まれていれば一致とみなす（区切り記号は無視）
        $a = preg_replace('/[\s|｜\-–—・]/u', '', $names[0]);
        $b = preg_replace('/[\s|｜\-–—・]/u', '', $names[1]);
        $consistent = ($b !== '' && mb_strpos($a, $b, 0, 'UTF-8') !== false);
    }
    $body = strtolower($html);
    return array(
        'brand_name_consistent' => $consistent,
        'names_found' => $names,
        'has_sameas' => !empty($schema['has_sameas']),
        'has_about_link' => preg_match('#href=["\'][^"\']*(about|company|kaisha|会社)#i', $html) === 1,
        'has_contact_info' => preg_match('#href=["\'][^"\']*(contact|inquiry|お問い合わせ)#iu', $html) === 1
                              || preg_match('/tel:|mailto:/i', $html) === 1,
        'has_wikipedia' => strpos($body, 'wikipedia.org') !== false,
        'has_geo_schema' => !empty($schema['has_organization'])
                            && preg_match('/"address"\s*:/', $html) === 1,
    );
}

/* ============================================================
 * 採点
 *
 * 満点100。配点は「AIに引用されるために欠かせない順」に置く。
 * robots で塞いでいたら他が満点でも引用されないので、ここを最も重くする。
 * ========================================================== */

function ktg_geo_score($r) {
    $cat = array();

    $cat['robots'] = !$r['robots']['citation_bots_ok'] ? 0
                   : ($r['robots']['found'] ? 20 : 16);   // robots.txt 自体が無いのは減点だが致命ではない

    $l = $r['llms'];
    $cat['llms'] = !$l['found'] ? 0
                 : 6 + ($l['has_title'] ? 2 : 0) + ($l['has_summary'] ? 2 : 0) + ($l['has_links'] ? 2 : 0);

    $s = $r['schema'];
    $cat['schema'] = ($s['has_organization'] ? 6 : 0) + ($s['has_website'] ? 4 : 0)
                   + ($s['has_faq'] ? 3 : 0) + ($s['has_product'] || $s['has_article'] ? 2 : 0)
                   + ($s['has_breadcrumb'] ? 1 : 0);

    $m = $r['meta'];
    $cat['meta'] = ($m['has_title'] ? 3 : 0) + ($m['has_description'] ? 4 : 0)
                 + ($m['has_canonical'] ? 3 : 0)
                 + ($m['has_og_title'] && $m['has_og_description'] ? 3 : 0)
                 + ($m['has_og_image'] ? 2 : 0);
    if ($m['noindex']) { $cat['meta'] = 0; }   // 検索避けは意図的なので0

    $c = $r['content'];
    $cat['content'] = ($c['has_h1'] ? 4 : 0) + ($c['word_count'] >= 300 ? 5 : ($c['word_count'] >= 150 ? 2 : 0))
                    + ($c['heading_count'] >= 2 ? 3 : 0) + ($c['has_lists_or_tables'] ? 2 : 0)
                    + ($c['has_numbers'] ? 1 : 0);

    $g = $r['signals'];
    $cat['signals'] = ($g['has_lang'] ? 3 : 0) + ($g['has_freshness'] ? 4 : 0) + ($g['has_rss'] ? 3 : 0);

    $a = $r['ai_discovery'];
    $cat['ai_discovery'] = min(10, $a['endpoints_found'] * 3 + ($a['has_well_known_ai'] ? 1 : 0));

    $b = $r['brand_entity'];
    $cat['brand_entity'] = ($b['brand_name_consistent'] ? 3 : 0) + ($b['has_contact_info'] ? 3 : 0)
                         + ($b['has_about_link'] ? 2 : 0) + ($b['has_sameas'] ? 2 : 0);

    $total = 0;
    foreach ($cat as $v) { $total += $v; }
    return array('categories' => $cat, 'score' => min(100, $total),
                 'band' => $total >= 80 ? 'good' : ($total >= 55 ? 'fair' : ($total >= 30 ? 'weak' : 'critical')));
}

/** 直すべきことを、効果の大きい順に日本語で返す。 */
function ktg_geo_recommendations($r) {
    $a = array();
    if (!$r['robots']['citation_bots_ok']) {
        $a[] = 'robots.txt でAI検索クローラーを拒否しています。引用されたいなら '
             . implode(' / ', $r['robots']['bots_blocked']) . ' の許可を検討してください。';
    }
    if ($r['meta']['noindex']) { $a[] = 'meta robots に noindex が入っています。意図的でなければ外してください。'; }
    if (!$r['llms']['found'])  { $a[] = 'サイトの要点と主要ページを整理した /llms.txt を設置してください。'; }
    elseif (!$r['llms']['has_summary']) { $a[] = '/llms.txt の3行目に「> 」で始まる1文要約を入れてください。'; }
    if (!$r['schema']['has_organization']) { $a[] = 'Organization の JSON-LD を追加し、組織名・URL・ロゴを明示してください。'; }
    if (!$r['schema']['has_website'])      { $a[] = 'WebSite の JSON-LD を追加してサイトの正式名称とURLを示してください。'; }
    if (!$r['meta']['has_description'])    { $a[] = 'ページ内容を端的に表す meta description を追加してください。'; }
    if (!$r['meta']['has_canonical'])      { $a[] = '重複URLを避けるため canonical URL を設定してください。'; }
    if (!$r['content']['has_h1'])          { $a[] = 'ページの主題を1つの H1 見出しで明示してください。'; }
    if ($r['content']['word_count'] < 300) { $a[] = '根拠・具体例・数値を加え、AIが引用しやすい本文量にしてください（現在およそ ' . $r['content']['word_count'] . ' 語）。'; }
    if (!$r['signals']['has_freshness'])   { $a[] = 'dateModified などで更新日を機械可読にしてください。'; }
    if (!$r['signals']['has_lang'])        { $a[] = '<html lang="ja"> を指定して言語を明示してください。'; }
    if (!$r['brand_entity']['brand_name_consistent']) { $a[] = 'title と構造化データでブランド名の表記を統一してください。'; }
    if (!$r['brand_entity']['has_contact_info'])      { $a[] = '問い合わせ先を明記してください。事業者が特定できるとAIに引用されやすくなります。'; }
    if ($r['ai_discovery']['endpoints_found'] === 0)  { $a[] = 'FAQ やサービス概要の構造化データを整備し、AIが要点を拾えるようにしてください。'; }
    return array_slice($a, 0, 12);
}

/* ============================================================
 * 入口
 * ========================================================== */

/** URLを1件診断する。 */
function ktg_geo_audit($url) {
    $url = trim((string)$url);
    if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . $url; }
    $p = @parse_url($url);
    if (!$p || empty($p['host']) || !empty($p['user'])) {
        return array('error' => 'URLの形式が正しくありません');
    }
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');

    list($html, $final, $err) = ktg_geo_fetch($url);
    if ($err !== '' || $html === '') {
        return array('error' => '対象ページを取得できませんでした' . ($err !== '' ? '（' . $err . '）' : ''));
    }

    $r = array('url' => $final, 'checked_at' => time());
    $r['robots']  = ktg_geo_check_robots($origin);
    $r['llms']    = ktg_geo_check_llms($origin);
    $r['schema']  = ktg_geo_check_schema($html);
    $r['meta']    = ktg_geo_check_meta($html, $final);
    $r['content'] = ktg_geo_check_content($html);
    $r['signals'] = ktg_geo_check_signals($html);
    $r['ai_discovery'] = ktg_geo_check_ai_discovery($origin, $html, $r['schema']);
    $r['brand_entity'] = ktg_geo_check_brand($html, $r['meta'], $r['schema']);

    $sc = ktg_geo_score($r);
    $r['categories'] = $sc['categories'];
    $r['score'] = $sc['score'];
    $r['band']  = $sc['band'];
    $r['recommendations'] = ktg_geo_recommendations($r);
    return $r;
}
