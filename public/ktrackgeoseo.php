<?php
declare(strict_types=1);
/**
 * Kurage Track & GEO & SEO（ktrackgeoseo）— 入口の画面。
 *
 * URLを1回入れると、次の3つの診断を続けてかけ、1枚の結果にまとめる。
 *   - SEO（Google検索）      … kseo.php の診断（10カテゴリ・決定論）
 *   - AEO（AIの回答への引用）… ktrackgeoseo_aeo.php（Kurage GEO の japanese_aeo.py を移したもの・7観点・決定論）
 *   - GEO（AI検索に読まれるか）… ktrackgeo_geo.php の診断（8観点・決定論）
 * 実際にAIのクローラーや人が来たかの実測は、ktrackgeo.php（計測）とダッシュボードで見る。
 *
 * 2つの製品（Kurage SEO と Kurage Track & GEO）の診断の中身には手を入れていない。
 * ここは「入口とまとめ役」だけ。判定に生成AIは使わない（同じページなら何度測っても同じ結果）。
 *
 * PHP 8.0 以上 / データベース不要 / Composer・npm 不要
 */

if (is_file(__DIR__ . '/ktrackgeoseo_config.php')) {
    require_once __DIR__ . '/ktrackgeoseo_config.php';
}
if (!defined('KTGS_TITLE'))         { define('KTGS_TITLE', 'Kurage Track & GEO & SEO'); }
if (!defined('KTGS_PASSWORD_HASH')) { define('KTGS_PASSWORD_HASH', ''); }   // 空なら鍵なし
if (!defined('KTGS_SEO_PAGES'))     { define('KTGS_SEO_PAGES', 5); }        // SEO診断で巡るページ数（多いほど遅い）
if (!defined('KTGS_CACHE_HOURS'))   { define('KTGS_CACHE_HOURS', 24); }     // 同じURLの結果を使い回す時間
if (!defined('KTGS_RATE_PER_HOUR')) { define('KTGS_RATE_PER_HOUR', 20); }   // 1つのIPから1時間に新しく診断できる回数（0で無制限）
if (!defined('KTGS_DATA_DIR'))      { define('KTGS_DATA_DIR', __DIR__ . '/ktgs_data'); }
if (!defined('KTGS_DEMO_FOOTER'))   { define('KTGS_DEMO_FOOTER', ''); }     // デモだけで出す案内（HTML）
if (!defined('KTGS_LITE'))          { define('KTGS_LITE', false); }         // true で点数と観点ごとの点だけを出す（直すこと・診断書を出さない。デモ版用）
if (!defined('KTGS_CANONICAL'))     { define('KTGS_CANONICAL', ''); }       // 公開URL（設定するとcanonical・OGPを出す）
if (!defined('KTGS_OG_IMAGE'))      { define('KTGS_OG_IMAGE', ''); }        // OGP画像のURL（1200×630）

// 2つの診断を部品として読む。kseo.php は KSEO_AS_LIBRARY があると画面を出さずに戻る。
define('KSEO_AS_LIBRARY', true);
require_once __DIR__ . '/kseo.php';
require_once __DIR__ . '/ktrackgeo_geo.php';
require_once __DIR__ . '/ktrackgeoseo_aeo.php';   // AEO（Kurage GEO の判定を移したもの）
// この画面の訪問も計測に入れる（ktrackgeo.php は require されると1件記録して戻る）
if (is_file(__DIR__ . '/ktrackgeo.php')) {
    require_once __DIR__ . '/ktrackgeo.php';
}

date_default_timezone_set('Asia/Tokyo');
@set_time_limit(180);
session_start();

function ktgs_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function ktgs_self(): string
{
    $s = (string)($_SERVER['SCRIPT_NAME'] ?? 'ktrackgeoseo.php');
    return basename($s);
}

// --- 鍵（任意）
$locked = KTGS_PASSWORD_HASH !== '';
if ($locked && isset($_POST['password'])) {
    if (password_verify((string)$_POST['password'], KTGS_PASSWORD_HASH)) {
        $_SESSION['ktgs_ok'] = 1;
        header('Location: ' . ktgs_self());
        exit;
    }
}
$authed = !$locked || !empty($_SESSION['ktgs_ok']);

// --- 保存（同じURLは KTGS_CACHE_HOURS のあいだ使い回す）
function ktgs_dir(): string
{
    if (!is_dir(KTGS_DATA_DIR)) { @mkdir(KTGS_DATA_DIR, 0755, true); }
    return KTGS_DATA_DIR;
}
function ktgs_key(string $url): string { return substr(sha1(strtolower(rtrim($url, '/'))), 0, 16); }
function ktgs_load(string $key): ?array
{
    $f = ktgs_dir() . '/r-' . preg_replace('/[^0-9a-f]/', '', $key) . '.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function ktgs_save(string $key, array $d): void
{
    file_put_contents(ktgs_dir() . '/r-' . $key . '.json', json_encode($d, JSON_UNESCAPED_UNICODE));
}
/** 新しい診断の回数を IP（塩付きハッシュ）ごとに数える。超えたら false。 */
function ktgs_rate_ok(): bool
{
    if (KTGS_RATE_PER_HOUR <= 0) { return true; }
    $id = substr(sha1('ktgs|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . __DIR__), 0, 16);
    $f = ktgs_dir() . '/rate-' . $id . '.json';
    $now = time();
    $t = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $t = array_values(array_filter($t, fn($x) => is_int($x) && $x > $now - 3600));
    if (count($t) >= KTGS_RATE_PER_HOUR) { return false; }
    $t[] = $now;
    file_put_contents($f, json_encode($t));
    return true;
}

/** 2つの診断をかける。どちらかが失敗しても、取れた方は出す。 */
function ktgs_run(string $url): array
{
    $seo = kseo_run_audit($url, (int)KTGS_SEO_PAGES);
    $geo = ktg_geo_audit($url);
    [$html, , $err] = ktg_geo_fetch($url);
    if (!class_exists('DOMDocument')) {
        $aeo = ['error' => 'このサーバーのPHPに dom 拡張が無いため、AEOは診断できませんでした'];
    } else {
        $aeo = ($err !== '' || $html === '') ? ['error' => '対象ページを取得できませんでした'] : ktgs_aeo_analyze($html);
    }
    return ['url' => $url, 'checked_at' => time(), 'seo' => $seo, 'aeo' => $aeo, 'geo' => $geo];
}

function ktgs_band_label(int $score): string
{
    if ($score >= 80) { return '良好'; }
    if ($score >= 55) { return 'まずまず'; }
    if ($score >= 30) { return '弱い'; }
    return '要対応';
}

/** 結果を Markdown の診断書にする（SEO の診断書の後ろに AEO・GEO を足す）。 */
function ktgs_markdown(array $r): string
{
    $out = [];
    $out[] = '# Kurage Track & GEO & SEO 診断書';
    $out[] = '';
    $out[] = '- 対象: ' . $r['url'];
    $out[] = '- 診断日時: ' . date('Y-m-d H:i', (int)$r['checked_at']) . '（日本時間）';
    if (empty($r['seo']['error'])) { $out[] = '- SEO（Google検索）: ' . $r['seo']['score']['overall'] . ' / 100'; }
    if (empty($r['aeo']['error'])) { $out[] = '- AEO（AIの回答への引用）: ' . $r['aeo']['score'] . ' / 100'; }
    if (empty($r['geo']['error'])) { $out[] = '- GEO（AI検索に読まれるか）: ' . $r['geo']['score'] . ' / 100'; }
    $out[] = '';
    if (empty($r['seo']['error'])) {
        $md = kseo_report_markdown($r['seo']);
        $out[] = preg_replace('/^# SEO診断書/m', '## SEO（Google検索）', $md);
    } else {
        $out[] = '## SEO（Google検索）';
        $out[] = '診断できませんでした: ' . $r['seo']['error'];
    }
    $out[] = '';
    $out[] = '## AEO（AIの回答への引用されやすさ）';
    if (!empty($r['aeo']) && empty($r['aeo']['error'])) {
        $out[] = '';
        $out[] = '| 観点 | 点 |';
        $out[] = '|---|---|';
        foreach (ktgs_aeo_metric_labels() as $k => $label) { $out[] = '| ' . $label . ' | ' . $r['aeo']['metrics'][$k]['score'] . ' |'; }
        $out[] = '';
        $out[] = '### 直すこと';
        foreach ($r['aeo']['recommendations'] as $i => $a) { $out[] = ($i + 1) . '. ' . $a; }
    } else {
        $out[] = '診断できませんでした' . (!empty($r['aeo']['error']) ? ': ' . $r['aeo']['error'] : '');
    }
    $out[] = '';
    $out[] = '## GEO（AI検索に読まれるか）';
    if (empty($r['geo']['error'])) {
        $out[] = '';
        $out[] = '| 観点 | 点 |';
        $out[] = '|---|---|';
        foreach (ktg_geo_categories() as $k => $label) {
            $out[] = '| ' . $label . ' | ' . ($r['geo']['categories'][$k] ?? 0) . ' |';
        }
        $out[] = '';
        $out[] = '### 直すこと（効果の大きい順）';
        foreach ($r['geo']['recommendations'] as $i => $a) { $out[] = ($i + 1) . '. ' . $a; }
    } else {
        $out[] = '診断できませんでした: ' . $r['geo']['error'];
    }
    $out[] = '';
    $out[] = '## AIのクローラーが実際に来ているか';
    $out[] = 'この診断は「読める状態か」を見るものです。GPTBot・ClaudeBot などが実際に来たかは、計測（ktrackgeo.php）をサイトに入れると記録できます。';
    $out[] = '';
    $out[] = '判定はすべて実測値にもとづく決定論的なチェックで、生成AIは使っていません。';
    return implode("\n", $out);
}

// --- 結果ページとカード画像（Xに貼るため）
/** このシステムの公開URL（og:image などに絶対URLが要る）。 */
function ktgs_base_url(): string
{
    if (KTGS_CANONICAL !== '') { return KTGS_CANONICAL; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (string)($_SERVER['SCRIPT_NAME'] ?? '/ktrackgeoseo.php');
}
/** [SEO, AEO, GEO] の点。診断できなかったものは null。 */
function ktgs_scores(array $r): array
{
    return [
        empty($r['seo']['error']) && isset($r['seo']['score']['overall']) ? (int)$r['seo']['score']['overall'] : null,
        !empty($r['aeo']) && empty($r['aeo']['error']) ? (int)$r['aeo']['score'] : null,
        empty($r['geo']['error']) && isset($r['geo']['score']) ? (int)$r['geo']['score'] : null,
    ];
}
function ktgs_host(array $r): string
{
    $h = (string)parse_url((string)$r['url'], PHP_URL_HOST);
    return preg_replace('/^www\./i', '', $h);
}
function ktgs_all_good(array $r): bool
{
    foreach (ktgs_scores($r) as $v) { if ($v === null || $v < 80) { return false; } }
    return true;
}
/** Xに貼る文（URLは別に付ける）。 */
function ktgs_share_text(array $r): string
{
    [$s, $a, $g] = ktgs_scores($r);
    $f = fn($v) => $v === null ? '-' : (string)$v;
    // ドメイン名の「.」は全角にする。半角のままだと X が自動でリンクにし、そのサイトのOGPが出てしまう
    $host = str_replace('.', '．', ktgs_host($r));
    $head = ktgs_all_good($r) ? $host . ' は、SEO・AEO・GEO の3つとも80点以上でした' : $host . ' を、SEO・AEO・GEO で診断しました';
    return '【' . KTGS_TITLE . "】\n" . $head . "\nSEO " . $f($s) . ' ／ AEO ' . $f($a) . ' ／ GEO ' . $f($g)
        . "\nGoogle検索にもAI検索にも強いか、URLを入れるだけで診断できます\n#SEO #AEO #GEO";
}
/** 結果カード（1200×630のPNG）。文字は ktgs_assets/ の切り出しフォント（scripts/make_card_fonts.py）。 */
function ktgs_card_png(array $r): string
{
    $W = 1200; $H = 630;
    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    $c = fn($hex) => imagecolorallocate($im, hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    $paper = $c('#f3faf9'); $ink = $c('#1d3038'); $muted = $c('#5f7078'); $line = $c('#dcebe9'); $white = $c('#ffffff');
    $tealD = $c('#076f67'); $purple = $c('#7a3fa8'); $blueD = $c('#1f5fa8'); $gold = $c('#8a5a00'); $goldBg = $c('#fff4d6'); $goldLine = $c('#e3b440');
    imagefilledrectangle($im, 0, 0, $W, $H, $paper);
    for ($x = 0; $x < $W; $x++) {   // 上の帯（青緑→青）
        $t = $x / $W;
        $col = imagecolorallocate($im, (int)(10 + (47 - 10) * $t), (int)(154 + (127 - 154) * $t), (int)(143 + (212 - 143) * $t));
        imageline($im, $x, 0, $x, 9, $col);
    }
    $B = __DIR__ . '/ktgs_assets/ktgs-card-bold.otf';
    $K = __DIR__ . '/ktgs_assets/ktgs-card-black.otf';
    $text = function ($size, $x, $y, $col, $font, $str, $align = 'l') use ($im) {
        $bb = imagettfbbox($size, 0, $font, $str);
        $w = $bb[2] - $bb[0];
        if ($align === 'c') { $x -= (int)($w / 2); } elseif ($align === 'r') { $x -= $w; }
        imagettftext($im, $size, 0, (int)$x, (int)$y, $col, $font, $str);
        return $w;
    };
    $round = function ($x1, $y1, $x2, $y2, $rad, $fill, $border = null) use ($im) {
        foreach ([[$border, 0], [$fill, $border !== null ? 2 : 0]] as [$col, $in]) {
            if ($col === null) { continue; }
            $a = $x1 + $in; $b = $y1 + $in; $c2 = $x2 - $in; $d = $y2 - $in; $r = max(1, $rad - $in);
            imagefilledrectangle($im, $a + $r, $b, $c2 - $r, $d, $col);
            imagefilledrectangle($im, $a, $b + $r, $c2, $d - $r, $col);
            foreach ([[$a + $r, $b + $r], [$c2 - $r, $b + $r], [$a + $r, $d - $r], [$c2 - $r, $d - $r]] as [$cx, $cy]) {
                imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $col);
            }
        }
    };
    $text(26, 64, 74, $tealD, $B, 'SEO・AEO・GEO 診断結果');
    if (ktgs_all_good($r)) {   // 3つとも80点以上の札
        $label = '3つとも80点以上';
        $bb = imagettfbbox(24, 0, $B, $label); $w = $bb[2] - $bb[0];
        $round($W - 64 - $w - 44, 40, $W - 64, 94, 27, $goldBg, $goldLine);
        $text(24, $W - 64 - 22, 77, $gold, $B, $label, 'r');
    }
    $host = ktgs_host($r);   // ドメイン名は入る大きさまで縮める
    for ($size = 60; $size > 24; $size -= 2) {
        $bb = imagettfbbox($size, 0, $K, $host);
        if ($bb[2] - $bb[0] <= 1072) { break; }
    }
    $text($size, 64, 168, $ink, $K, $host);
    $boxes = [['SEO', 'Google検索', $tealD], ['AEO', 'AIの回答', $purple], ['GEO', 'AI検索', $blueD]];
    $scores = ktgs_scores($r);
    foreach ($boxes as $i => [$lab, $sub, $col]) {
        $x = 64 + $i * 300; $y = 214;
        $round($x, $y, $x + 276, $y + 300, 22, $white, $line);
        $cx = $x + 138;
        $text(30, $cx, $y + 58, $col, $K, $lab, 'c');
        $text(18, $cx, $y + 92, $muted, $B, $sub, 'c');
        $v = $scores[$i];
        $text(88, $cx, $y + 212, $col, $K, $v === null ? '-' : (string)$v, 'c');
        $text(18, $cx, $y + 248, $muted, $B, '/ 100', 'c');
        if ($v !== null) { $text(20, $cx, $y + 282, $ink, $B, ktgs_band_label($v), 'c'); }
    }
    $mascot = __DIR__ . '/ktgs_assets/mascot.png';
    if (is_file($mascot) && ($m = @imagecreatefrompng($mascot))) {
        imagecopy($im, $m, $W - imagesx($m) - 24, $H - imagesy($m) - 14, 0, 0, imagesx($m), imagesy($m));
    }
    $text(18, 64, 586, $muted, $B, 'Kurage Track & GEO & SEO で診断・' . date('Y年n月j日', (int)$r['checked_at']));
    ob_start(); imagepng($im, null, 6); imagedestroy($im);
    return (string)ob_get_clean();
}

// --- 受け付け
$error = '';
$result = null;
// --- カード画像（?og=キー）と結果ページ（?r=キー）
$share = false;
if (isset($_GET['og'])) {
    $key = preg_replace('/[^0-9a-f]/', '', (string)$_GET['og']);
    $r = $key !== '' ? ktgs_load($key) : null;
    if (!$r || !function_exists('imagettftext')) { http_response_code(404); exit; }
    $png = ktgs_dir() . '/og-' . $key . '-' . (int)$r['checked_at'] . '.png';
    if (!is_file($png)) {
        foreach (glob(ktgs_dir() . '/og-' . $key . '-*.png') ?: [] as $old) { @unlink($old); }
        file_put_contents($png, ktgs_card_png($r));
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    readfile($png);
    exit;
}
if (isset($_GET['r'])) {
    $key = preg_replace('/[^0-9a-f]/', '', (string)$_GET['r']);
    $result = $key !== '' ? ktgs_load($key) : null;
    if ($result) { $share = true; } else { http_response_code(404); $error = 'この診断結果は見つかりませんでした。もう一度診断してください。'; }
}

$url_in = trim((string)($_GET['url'] ?? $_POST['url'] ?? ''));
if ($authed && $url_in !== '') {
    [$ok, $checked] = kseo_validate_url($url_in);
    if (!$ok) {
        $error = $checked;
    } else {
        $key = ktgs_key($checked);
        $cached = ktgs_load($key);
        $fresh = $cached && isset($cached['aeo']) && (time() - (int)$cached['checked_at']) < KTGS_CACHE_HOURS * 3600;
        if ($fresh && empty($_POST['again'])) {
            $result = $cached;
        } elseif (!ktgs_rate_ok()) {
            $error = '1時間に診断できる回数を超えました。しばらくしてからお試しください。';
            $result = $cached;
        } else {
            $result = ktgs_run($checked);
            if (empty($result['seo']['error']) || empty($result['geo']['error']) || empty($result['aeo']['error'])) {
                ktgs_save($key, $result);
            }
        }
        if ($result && ($_GET['format'] ?? '') === 'md' && !KTGS_LITE) {
            $host = preg_replace('/[^a-z0-9.-]/i', '', (string)parse_url($result['url'], PHP_URL_HOST));
            header('Content-Type: text/markdown; charset=UTF-8');
            header('Content-Disposition: attachment; filename="ktrackgeoseo-' . $host . '-' . date('Ymd', (int)$result['checked_at']) . '.md"');
            echo ktgs_markdown($result);
            exit;
        }
    }
}

$sev_label = ['critical' => '重大', 'warning' => '警告', 'info' => '改善余地'];
$self = ktgs_self();
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php if ($share): [$ss, $sa, $sg] = ktgs_scores($result); $sh = ktgs_host($result); $f = fn($v) => $v === null ? '-' : $v;
  $st = $sh . 'の診断結果｜SEO ' . $f($ss) . '・AEO ' . $f($sa) . '・GEO ' . $f($sg);
  $sd = $sh . ' を、Google検索のSEO、AIの回答に引用されるAEO、AI検索に読まれるGEOで診断した結果です（' . date('Y年n月j日', (int)$result['checked_at']) . '）。あなたのサイトもURLを入れるだけで診断できます。';
  $su = ktgs_base_url() . '?r=' . ktgs_key($result['url']); $si = ktgs_base_url() . '?og=' . ktgs_key($result['url']) . '&v=' . (int)$result['checked_at']; ?>
<title><?= ktgs_h($st) ?></title>
<meta name="description" content="<?= ktgs_h($sd) ?>">
<meta name="robots" content="noindex,follow">
<meta property="og:type" content="article">
<meta property="og:site_name" content="<?= ktgs_h(KTGS_TITLE) ?>">
<meta property="og:title" content="<?= ktgs_h($st) ?>">
<meta property="og:description" content="<?= ktgs_h($sd) ?>">
<meta property="og:url" content="<?= ktgs_h($su) ?>">
<meta property="og:image" content="<?= ktgs_h($si) ?>"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta property="og:locale" content="ja_JP">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= ktgs_h($si) ?>">
<?php else: ?>
<title><?= ktgs_h(KTGS_TITLE) ?>｜SEO・AEO・GEO診断</title>
<?php $ktgs_desc = 'URLを1回入れるだけで、Google検索のSEO、AIの回答に引用されるAEO、AI検索に読まれるGEOをまとめて診断。生成AIを使わない決まったチェックで、直すことを順に出します。'; ?>
<meta name="description" content="<?= ktgs_h($ktgs_desc) ?>">
<?php endif; ?>
<?php if (!$share && KTGS_CANONICAL !== ''): ?>
<link rel="canonical" href="<?= ktgs_h(KTGS_CANONICAL) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= ktgs_h(KTGS_TITLE) ?>">
<meta property="og:title" content="<?= ktgs_h(KTGS_TITLE) ?>｜SEO・AEO・GEO診断">
<meta property="og:description" content="<?= ktgs_h($ktgs_desc) ?>">
<meta property="og:url" content="<?= ktgs_h(KTGS_CANONICAL) ?>">
<meta property="og:locale" content="ja_JP">
<?php if (KTGS_OG_IMAGE !== ''): ?><meta property="og:image" content="<?= ktgs_h(KTGS_OG_IMAGE) ?>"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@graph' => [
  ['@type' => 'WebSite', 'name' => KTGS_TITLE, 'url' => KTGS_CANONICAL, 'potentialAction' => ['@type' => 'SearchAction', 'target' => KTGS_CANONICAL . '?url={url}', 'query-input' => 'required name=url']],
  ['@type' => 'FAQPage', 'mainEntity' => [
    ['@type' => 'Question', 'name' => 'SEO・AEO・GEOの違いは？', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'SEOはGoogle検索の結果に出るための対策、AEOはAIの回答に答えとして引用されるための対策（結論が先か、定義の文、質問と短い答え、出典など）、GEOはChatGPTやPerplexityなどAI検索に読まれ紹介されるための対策（AIクローラーの許可、llms.txt、構造化データなど）です。このシステムは3つをURLひとつで続けて診断します。']],
    ['@type' => 'Question', 'name' => '診断に生成AIを使いますか？', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '使いません。判定はすべて実測値にもとづく決まったチェックなので、同じページなら何度測っても同じ結果になります。']],
    ['@type' => 'Question', 'name' => 'AIのクローラーが来ているかも分かりますか？', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => '同じシステムの計測（ktrackgeo.php）をサイトに入れると、GPTBot・ClaudeBot・PerplexityBotなどの訪問を記録できます。Google Analyticsはボットを除くので、ここは見えません。']],
  ]],
]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
<style>
:root{--ink:#1d3038;--muted:#5f7078;--paper:#f3faf9;--line:#dcebe9;--teal:#0a9a8f;--teal-d:#076f67;--blue:#2f7fd4;--blue-d:#1f5fa8;--red:#c0392b;--gold:#8a5a00}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;line-height:1.8;font-size:16px}
a{color:var(--teal-d)}
.wrap{width:min(1000px,calc(100% - 32px));margin:0 auto}
header{background:#fff;border-bottom:1px solid var(--line)}
header .wrap{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 0;flex-wrap:wrap}
.brand{font-weight:900;color:var(--ink);text-decoration:none;font-size:17px}
.brand small{display:block;font-size:12px;color:var(--muted);font-weight:700}
.nav{display:flex;gap:8px;flex-wrap:wrap;font-size:13px}
.nav a{padding:6px 12px;border:1px solid var(--line);border-radius:999px;text-decoration:none;background:#fff}
h1{font-size:clamp(22px,4.6vw,34px);line-height:1.4;margin:26px 0 8px}
.lead{color:var(--muted);margin:0 0 16px}
form.q{display:flex;gap:8px;flex-wrap:wrap;background:#fff;border:1px solid var(--line);border-radius:16px;padding:14px}
form.q input[type=url],form.q input[type=text]{flex:1 1 260px;min-width:0;font-size:16px;padding:12px 14px;border:1px solid #c9dcd9;border-radius:10px}
form.q button{background:var(--teal);color:#fff;border:0;border-radius:999px;padding:12px 24px;font-weight:900;font-size:16px;cursor:pointer}
.note{font-size:13px;color:var(--muted)}
.err{background:#fdecea;border:1px solid #f3b9b2;color:#8a2a1f;border-radius:12px;padding:10px 14px;margin:14px 0}
.scores{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:20px 0}
.sc{background:#fff;border:1px solid var(--line);border-radius:16px;padding:16px;text-align:center}
.sc .k{font-size:13px;color:var(--muted);font-weight:800}
.sc .v{font-size:40px;font-weight:900;line-height:1.2}
.sc .v small{font-size:16px;color:var(--muted)}
.sc .b{font-size:13px;font-weight:800}
.sc.seo .v{color:var(--teal-d)} .sc.aeo .v{color:#7a3fa8} .sc.geo .v{color:var(--blue-d)}
h2{font-size:21px;margin:30px 0 8px}
.cats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px}
.cat{background:#fff;border:1px solid var(--line);border-radius:12px;padding:8px 10px;font-size:13.5px;display:flex;justify-content:space-between;gap:8px}
.cat b{font-size:15px}
.list{background:#fff;border:1px solid var(--line);border-radius:14px;padding:6px 16px}
.item{border-bottom:1px solid var(--line);padding:10px 0}
.item:last-child{border-bottom:0}
.item .t{font-weight:800}
.item .e,.item .a{font-size:13.5px;color:var(--muted)}
.tag{display:inline-block;font-size:11.5px;font-weight:900;border-radius:999px;padding:1px 9px;margin-right:6px;vertical-align:1px}
.tag.critical{background:#fdecea;color:var(--red)} .tag.warning{background:#fff4d6;color:var(--gold)} .tag.info{background:#e7f0fb;color:var(--blue-d)}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}
.btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--teal);background:#fff;color:var(--teal-d);border-radius:999px;padding:9px 18px;font-weight:900;text-decoration:none;font-size:14px;cursor:pointer;font-family:inherit}
.btn.main{background:var(--teal);color:#fff}
.box{background:#fff;border:1px solid var(--line);border-radius:14px;padding:14px 16px}

.share{background:#fff;border:2px solid var(--teal);border-radius:16px;padding:16px;margin:22px 0}
.share h2{margin:0 0 10px;font-size:19px}
.share img{display:block;width:100%;max-width:600px;height:auto;border-radius:12px;border:1px solid var(--line)}
.share pre{white-space:pre-wrap;word-break:break-all;font-family:inherit;font-size:14px;background:var(--paper);border-radius:10px;padding:10px 12px;margin:12px 0}
.share .bt{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.share .xb{background:#111;border-color:#111;color:#fff}
.share .ok{font-size:13px;color:var(--teal-d);font-weight:800}

.cta-diag{background:linear-gradient(135deg,#e6f6f3,#eaf2fc);border:2px solid var(--teal);border-radius:18px;padding:18px 18px 8px;margin:22px 0}
.cta-diag h2{margin:0 0 6px;font-size:21px;color:var(--teal-d)}
.cta-diag p{margin:0 0 12px;font-size:14.5px;color:var(--ink)}
.running{display:none;align-items:center;gap:12px;background:#fff;border:2px solid var(--teal);border-radius:14px;padding:14px 16px;margin:14px 0;font-weight:800;color:var(--teal-d)}
.running.on{display:flex}
.running .spin{width:26px;height:26px;border:4px solid #cfe9e6;border-top-color:var(--teal);border-radius:50%;animation:ktgs-spin .9s linear infinite;flex:none}
.running small{display:block;font-weight:700;color:var(--muted);font-size:13px}
@keyframes ktgs-spin{to{transform:rotate(360deg)}}
button[disabled]{opacity:.55;cursor:wait}
footer{margin-top:40px;padding:20px 0;border-top:1px solid var(--line);font-size:13px;color:var(--muted);background:#fff}
@media(max-width:640px){.scores{grid-template-columns:1fr}}
@media print{header nav,form.q,.actions,.demo{display:none}body{background:#fff}.sc,.list,.cat,.box{break-inside:avoid}}
</style>
</head>
<body>
<header><div class="wrap">
  <a class="brand" href="<?= ktgs_h($self) ?>"><?= ktgs_h(KTGS_TITLE) ?><small>SEO・AEO・GEO診断とAIクローラー計測</small></a>
  <nav class="nav">
    <a href="ktrackgeo.php?dashboard=1">計測（アクセス・AIクローラー）</a>
    <a href="kseo.php">SEO診断の詳細</a>
  </nav>
</div></header>
<main class="wrap">
<?php if (!$authed): ?>
  <h1>ログイン</h1>
  <form class="q" method="post"><input type="password" name="password" placeholder="パスワード" required><button type="submit">開く</button></form>
<?php else: ?>
  <h1><?= $share ? ktgs_h(ktgs_host($result)) . ' のSEO・AEO・GEO診断結果' : 'URLひとつで、SEO・AEO・GEOをまとめて診断' ?></h1>
<?php if (!$share): ?>
  <p class="lead">Google検索に出るための<b>SEO</b>、AIの回答に答えとして引用されるための<b>AEO</b>、ChatGPT・Perplexity・Google AI OverviewsなどのAI検索に読まれ紹介されるための<b>GEO</b>を、続けて診断します。判定に生成AIは使わないので、同じページなら何度測っても同じ結果になります。</p>
  <form class="q js-run" method="get" action="<?= ktgs_h($self) ?>">
    <input type="text" name="url" inputmode="url" placeholder="https://example.co.jp/" value="<?= ktgs_h($url_in) ?>" required>
    <button type="submit">診断する</button>
  </form>
  <div class="running" id="running" role="status" aria-live="polite"><span class="spin"></span><span>診断しています。そのままお待ちください<small id="running-sub">SEO・AEO・GEOを順に調べています。1分ほどかかることがあります（<span id="running-sec">0</span>秒）</small></span></div>
  <p class="note">SEOはトップから最大<?= (int)KTGS_SEO_PAGES ?>ページを巡ります。1分ほどかかることがあります。同じURLの結果は<?= (int)KTGS_CACHE_HOURS ?>時間使い回します。</p>
<?php else: ?>
  <p class="lead"><?= ktgs_h(ktgs_host($result)) ?> を、Google検索のSEO・AIの回答のAEO・AI検索のGEOで診断した結果です。</p>
<?php endif; ?>
  <?php if ($error !== ''): ?><div class="err"><?= ktgs_h($error) ?></div><?php endif; ?>

  <?php if ($result): $seo = $result['seo']; $geo = $result['geo']; $aeo = $result['aeo'] ?? null; ?>
    <p class="note">対象：<a href="<?= ktgs_h($result['url']) ?>" rel="nofollow noopener" target="_blank"><?= ktgs_h($result['url']) ?></a>　診断：<?= ktgs_h(date('Y年n月j日 H:i', (int)$result['checked_at'])) ?></p>
    <div class="scores">
      <div class="sc seo"><div class="k">SEO（Google検索）</div>
        <?php if (empty($seo['error'])): $s = (int)$seo['score']['overall']; ?>
          <div class="v"><?= $s ?><small> / 100</small></div><div class="b"><?= ktgs_h(ktgs_band_label($s)) ?></div>
        <?php else: ?><div class="b">診断できませんでした</div><?php endif; ?>
      </div>
      <div class="sc aeo"><div class="k">AEO（AIの回答）</div>
        <?php if (!empty($aeo) && empty($aeo['error'])): $a = (int)$aeo['score']; ?>
          <div class="v"><?= $a ?><small> / 100</small></div><div class="b"><?= ktgs_h(ktgs_band_label($a)) ?></div>
        <?php else: ?><div class="b">診断できませんでした</div><?php endif; ?>
      </div>
      <div class="sc geo"><div class="k">GEO（AI検索）</div>
        <?php if (empty($geo['error'])): $g = (int)$geo['score']; ?>
          <div class="v"><?= $g ?><small> / 100</small></div><div class="b"><?= ktgs_h(ktgs_band_label($g)) ?></div>
        <?php else: ?><div class="b">診断できませんでした</div><?php endif; ?>
      </div>
    </div>

    <?php if ($share): ?>
    <div class="cta-diag" id="diag">
      <h2>あなたのサイトも、URLを入れるだけで診断できます</h2>
      <p>Google検索のSEO、AIの回答に引用されるAEO、AI検索に読まれるGEOの3つを、続けて診断します。結果はこのページのように、Xに貼れるカードになります。</p>
  <form class="q js-run" method="get" action="<?= ktgs_h($self) ?>">
    <input type="text" name="url" inputmode="url" placeholder="例: https://example.co.jp/" value="" required>
    <button type="submit">自分のサイトを診断する</button>
  </form>
  <div class="running" id="running" role="status" aria-live="polite"><span class="spin"></span><span>診断しています。そのままお待ちください<small id="running-sub">SEO・AEO・GEOを順に調べています。1分ほどかかることがあります（<span id="running-sec">0</span>秒）</small></span></div>
  <p class="note">SEOはトップから最大<?= (int)KTGS_SEO_PAGES ?>ページを巡ります。1分ほどかかることがあります。同じURLの結果は<?= (int)KTGS_CACHE_HOURS ?>時間使い回します。</p>
    </div>
    <?php endif; ?>

    <?php $rk = ktgs_key($result['url']); $rurl = ktgs_base_url() . '?r=' . $rk; $stext = ktgs_share_text($result); ?>
    <div class="share" id="share">
      <h2><?= ktgs_all_good($result) ? '3つとも80点以上です。この結果をXで紹介しませんか' : 'この結果を共有する' ?><?= $share ? '<small style="display:block;font-size:13px;color:#5f7078;font-weight:700">（このサイトの持ち主の方へ）</small>' : '' ?></h2>
      <img src="<?= ktgs_h(ktgs_base_url() . '?og=' . $rk . '&v=' . (int)$result['checked_at']) ?>" alt="<?= ktgs_h(ktgs_host($result)) ?>のSEO・AEO・GEO診断結果のカード" width="1200" height="630" loading="lazy">
      <pre class="stext"><?= ktgs_h($stext . "
" . $rurl) ?></pre>
      <div class="bt">
        <a class="btn xb" target="_blank" rel="noopener" href="https://x.com/intent/post?<?= ktgs_h(http_build_query(['text' => $stext, 'url' => $rurl . '#ref=x-share-ktgs'])) ?>">𝕏 Xに投稿</a>
        <button class="btn cp" type="button" data-copy="<?= ktgs_h($stext . "
" . $rurl . '#ref=copy-ktgs') ?>">投稿文をコピー</button>
        <a class="btn" href="<?= ktgs_h($rurl) ?>">結果のページ</a>
        <span class="ok" hidden>コピーしました</span>
      </div>
      <p class="note">Xに貼ると、このカードの画像が表示されます。結果のページは検索エンジンには出しません。</p>
    </div>
    <script>
    document.querySelectorAll('.share .cp').forEach(function(b){b.addEventListener('click',function(){
      var t=b.getAttribute('data-copy'),ok=b.parentNode.querySelector('.ok');
      function done(){ok.hidden=false;setTimeout(function(){ok.hidden=true},2000)}
      function fb(){var a=document.createElement('textarea');a.value=t;document.body.appendChild(a);a.select();try{document.execCommand('copy');done()}catch(e){}document.body.removeChild(a)}
      if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(t).then(done,fb)}else{fb()}
    })});
    </script>

    <?php if (function_exists('ktgs_demo_after_share')) { echo ktgs_demo_after_share($result, $share); } // 設定ファイルで足す案内（無ければ何も出ない） ?>

    <?php if (empty($seo['error'])): ?>
      <h2>SEO（Google検索）のカテゴリ</h2>
      <div class="cats">
        <?php foreach ($seo['score']['categories'] as $c): ?><div class="cat"><span><?= ktgs_h($c['label']) ?></span><b><?= (int)$c['score'] ?></b></div><?php endforeach; ?>
      </div>
      <?php if (!KTGS_LITE): ?>
      <h2>SEO：直すこと（重い順・上位10件）</h2>
      <div class="list">
        <?php $shown = 0; foreach ($seo['findings'] as $f): if ($shown++ >= 10) break; ?>
          <div class="item"><div class="t"><span class="tag <?= ktgs_h($f['severity']) ?>"><?= ktgs_h($sev_label[$f['severity']] ?? $f['severity']) ?></span><?= ktgs_h($f['message']) ?></div>
            <?php if ($f['evidence'] !== ''): ?><div class="e">実測：<?= ktgs_h($f['evidence']) ?></div><?php endif; ?>
            <div class="a">直し方：<?= ktgs_h($f['action']) ?></div></div>
        <?php endforeach; if (!$seo['findings']): ?><div class="item">大きな問題は見つかりませんでした。</div><?php endif; ?>
      </div>
      <?php if (count($seo['findings']) > 10): ?><p class="note">ほかに<?= count($seo['findings']) - 10 ?>件あります。すべては診断書（Markdown）に入っています。</p><?php endif; ?>
      <?php endif; ?>
    <?php else: ?><div class="err">SEO：<?= ktgs_h($seo['error']) ?></div><?php endif; ?>

    <?php if (!empty($aeo) && empty($aeo['error'])): ?>
      <h2>AEO（AIの回答への引用されやすさ）の観点</h2>
      <div class="cats">
        <?php foreach (ktgs_aeo_metric_labels() as $k => $label): ?><div class="cat"><span><?= ktgs_h($label) ?></span><b><?= (int)$aeo['metrics'][$k]['score'] ?></b></div><?php endforeach; ?>
      </div>
      <?php if (!KTGS_LITE): ?>
      <h2>AEO：直すこと</h2>
      <div class="list">
        <?php foreach ($aeo['recommendations'] as $x): ?><div class="item"><div class="t"><?= ktgs_h($x) ?></div></div><?php endforeach; ?>
        <?php if (!$aeo['recommendations']): ?><div class="item">大きな問題は見つかりませんでした。</div><?php endif; ?>
      </div>
      <?php endif; ?>
      <p class="note"><?= ktgs_h($aeo['analyzed_as'] === 'en' ? '英語のページとして判定しました。' : '日本語のページとして判定しました。') ?>外部のAI検索での掲載順位ではなく、引用されやすい書き方になっているかの準備度です。</p>
    <?php elseif (!empty($aeo['error'])): ?><div class="err">AEO：<?= ktgs_h($aeo['error']) ?></div><?php endif; ?>

    <?php if (empty($geo['error'])): ?>
      <h2>GEO（AI検索に読まれるか）の観点</h2>
      <div class="cats">
        <?php foreach (ktg_geo_categories() as $k => $label): ?><div class="cat"><span><?= ktgs_h($label) ?></span><b><?= (int)($geo['categories'][$k] ?? 0) ?></b></div><?php endforeach; ?>
      </div>
      <?php if (!KTGS_LITE): ?>
      <h2>GEO：直すこと（効果の大きい順）</h2>
      <div class="list">
        <?php foreach ($geo['recommendations'] as $a): ?><div class="item"><div class="t"><?= ktgs_h($a) ?></div></div><?php endforeach; ?>
        <?php if (!$geo['recommendations']): ?><div class="item">大きな問題は見つかりませんでした。</div><?php endif; ?>
      </div>
      <?php endif; ?>
    <?php else: ?><div class="err">GEO：<?= ktgs_h($geo['error']) ?></div><?php endif; ?>

    <?php if (KTGS_LITE): ?>
    <div class="box"><b>デモ版のため、点数と観点ごとの点の概要だけを表示しています。</b>どこを直すか（SEO・AEO・GEOの項目ごとの実測と直し方）と診断書（Markdown）のダウンロードは、導入版でお使いいただけます。</div>
    <?php endif; ?>

    <h2>AIのクローラーは、実際に来ているか</h2>
    <div class="box">この診断は「AIに読める状態か」を見るものです。GPTBot・ClaudeBot・PerplexityBot などが実際に読みに来たかは、計測（ktrackgeo.php）をサイトに入れると記録できます。Google Analytics はボットを除くので、ここは見えません。</div>

    <div class="actions">
      <?php if (!KTGS_LITE): ?><a class="btn main" href="<?= ktgs_h($self) ?>?url=<?= rawurlencode($result['url']) ?>&amp;format=md">診断書（Markdown）をダウンロード</a><?php endif; ?>
      <button class="btn" type="button" onclick="window.print()">印刷・PDFで保存</button>
      <form class="js-run" method="post" action="<?= ktgs_h($self) ?>?url=<?= rawurlencode($result['url']) ?>" style="display:inline"><input type="hidden" name="again" value="1"><button class="btn" type="submit">いま測り直す</button></form>
    </div>
    <p class="note">判定はすべて実測値にもとづく決定論的なチェックで、生成AIは使っていません。</p>
  <?php endif; ?>
<?php endif; ?>
<?php if ($share && $result): ?>
  <div class="cta-diag" style="text-align:center"><h2>あなたのサイトは何点？</h2><a class="btn main" href="#diag" style="font-size:16px;padding:12px 26px">自分のサイトを診断する</a></div>
<?php endif; ?>
<?= KTGS_DEMO_FOOTER ?>
</main>
<footer><div class="wrap"><?= ktgs_h(KTGS_TITLE) ?>（Kurage SEO と Kurage Track &amp; GEO を1つにしたもの・MIT License）</div></footer>
<script>
// 診断中はボタンを押せなくし、待っていることが分かるようにする（診断は1分ほどかかる）
document.querySelectorAll('form.js-run').forEach(function (f) {
  f.addEventListener('submit', function () {
    f.querySelectorAll('button').forEach(function (b) { b.disabled = true; b.textContent = '診断中…'; });
    document.querySelectorAll('form.js-run button').forEach(function (b) { b.disabled = true; });
    var box = document.getElementById('running');
    if (box) {
      box.classList.add('on');
      box.scrollIntoView({ block: 'center', behavior: 'smooth' });
      var t = 0, sec = document.getElementById('running-sec'), sub = document.getElementById('running-sub');
      setInterval(function () {
        t++; if (sec) sec.textContent = t;
        if (t === 45 && sub) sub.firstChild.textContent = 'ページの多いサイトは時間がかかります。もう少しお待ちください（';
      }, 1000);
    }
  });
});
// 戻るボタンで戻ったときに押せないままにならないようにする
window.addEventListener('pageshow', function (e) {
  if (e.persisted) {
    document.querySelectorAll('form.js-run button').forEach(function (b) { b.disabled = false; });
    var box = document.getElementById('running'); if (box) box.classList.remove('on');
  }
});
</script>
</body>
</html>
