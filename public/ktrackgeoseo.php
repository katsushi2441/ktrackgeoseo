<?php
declare(strict_types=1);
/**
 * Kurage Track & GEO & SEO（ktrackgeoseo）— 入口の画面。
 *
 * URLを1回入れると、次の2つの診断を続けてかけ、1枚の結果にまとめる。
 *   - SEO（Google検索）  … kseo.php の診断（10カテゴリ・決定論）
 *   - AEO・GEO（AI検索） … ktrackgeo_geo.php の診断（8観点・決定論）
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
if (!defined('KTGS_CANONICAL'))     { define('KTGS_CANONICAL', ''); }       // 公開URL（設定するとcanonical・OGPを出す）
if (!defined('KTGS_OG_IMAGE'))      { define('KTGS_OG_IMAGE', ''); }        // OGP画像のURL（1200×630）

// 2つの診断を部品として読む。kseo.php は KSEO_AS_LIBRARY があると画面を出さずに戻る。
define('KSEO_AS_LIBRARY', true);
require_once __DIR__ . '/kseo.php';
require_once __DIR__ . '/ktrackgeo_geo.php';
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
    return ['url' => $url, 'checked_at' => time(), 'seo' => $seo, 'geo' => $geo];
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
    if (empty($r['geo']['error'])) { $out[] = '- AEO・GEO（AI検索）: ' . $r['geo']['score'] . ' / 100'; }
    $out[] = '';
    if (empty($r['seo']['error'])) {
        $md = kseo_report_markdown($r['seo']);
        $out[] = preg_replace('/^# SEO診断書/m', '## SEO（Google検索）', $md);
    } else {
        $out[] = '## SEO（Google検索）';
        $out[] = '診断できませんでした: ' . $r['seo']['error'];
    }
    $out[] = '';
    $out[] = '## AEO・GEO（AI検索）';
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

// --- 受け付け
$error = '';
$result = null;
$url_in = trim((string)($_GET['url'] ?? $_POST['url'] ?? ''));
if ($authed && $url_in !== '') {
    [$ok, $checked] = kseo_validate_url($url_in);
    if (!$ok) {
        $error = $checked;
    } else {
        $key = ktgs_key($checked);
        $cached = ktgs_load($key);
        $fresh = $cached && (time() - (int)$cached['checked_at']) < KTGS_CACHE_HOURS * 3600;
        if ($fresh && empty($_POST['again'])) {
            $result = $cached;
        } elseif (!ktgs_rate_ok()) {
            $error = '1時間に診断できる回数を超えました。しばらくしてからお試しください。';
            $result = $cached;
        } else {
            $result = ktgs_run($checked);
            if (empty($result['seo']['error']) || empty($result['geo']['error'])) {
                ktgs_save($key, $result);
            }
        }
        if ($result && ($_GET['format'] ?? '') === 'md') {
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
<title><?= ktgs_h(KTGS_TITLE) ?>｜SEO・AEO・GEO診断</title>
<?php $ktgs_desc = 'URLを1回入れるだけで、Google検索向けのSEOと、AI検索向けのAEO・GEOをまとめて診断。生成AIを使わない決まったチェックで、直すことを効果の大きい順に出します。AIクローラーの実測もできます。'; ?>
<meta name="description" content="<?= ktgs_h($ktgs_desc) ?>">
<?php if (KTGS_CANONICAL !== ''): ?>
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
    ['@type' => 'Question', 'name' => 'SEO・AEO・GEOの違いは？', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'SEOはGoogle検索の結果に出るための対策、AEOはAIの回答に答えとして引用されるための対策、GEOはChatGPTやPerplexityなどAI検索に読まれ、紹介されるための対策です。このシステムは3つをURLひとつで続けて診断します。']],
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
.sc.seo .v{color:var(--teal-d)} .sc.geo .v{color:var(--blue-d)} .sc.trk .v{color:var(--gold);font-size:22px;padding:12px 0}
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
  <h1>URLひとつで、SEO・AEO・GEOをまとめて診断</h1>
  <p class="lead">Google検索向けのSEOと、AI検索（ChatGPT・Perplexity・Google AI Overviewsなど）に引用されるためのAEO・GEOを、続けて診断します。判定に生成AIは使わないので、同じページなら何度測っても同じ結果になります。</p>
  <form class="q" method="get" action="<?= ktgs_h($self) ?>">
    <input type="text" name="url" inputmode="url" placeholder="https://example.co.jp/" value="<?= ktgs_h($url_in) ?>" required>
    <button type="submit">診断する</button>
  </form>
  <p class="note">SEOはトップから最大<?= (int)KTGS_SEO_PAGES ?>ページを巡ります。1分ほどかかることがあります。同じURLの結果は<?= (int)KTGS_CACHE_HOURS ?>時間使い回します。</p>
  <?php if ($error !== ''): ?><div class="err"><?= ktgs_h($error) ?></div><?php endif; ?>

  <?php if ($result): $seo = $result['seo']; $geo = $result['geo']; ?>
    <p class="note">対象：<a href="<?= ktgs_h($result['url']) ?>" rel="nofollow noopener" target="_blank"><?= ktgs_h($result['url']) ?></a>　診断：<?= ktgs_h(date('Y年n月j日 H:i', (int)$result['checked_at'])) ?></p>
    <div class="scores">
      <div class="sc seo"><div class="k">SEO（Google検索）</div>
        <?php if (empty($seo['error'])): $s = (int)$seo['score']['overall']; ?>
          <div class="v"><?= $s ?><small> / 100</small></div><div class="b"><?= ktgs_h(ktgs_band_label($s)) ?></div>
        <?php else: ?><div class="b">診断できませんでした</div><?php endif; ?>
      </div>
      <div class="sc geo"><div class="k">AEO・GEO（AI検索）</div>
        <?php if (empty($geo['error'])): $g = (int)$geo['score']; ?>
          <div class="v"><?= $g ?><small> / 100</small></div><div class="b"><?= ktgs_h(ktgs_band_label($g)) ?></div>
        <?php else: ?><div class="b">診断できませんでした</div><?php endif; ?>
      </div>
      <div class="sc trk"><div class="k">AIクローラーの実測</div>
        <div class="v">計測を入れると<br>記録できます</div><div class="b"><a href="ktrackgeo.php?dashboard=1">計測の画面を見る →</a></div>
      </div>
    </div>

    <?php if (empty($seo['error'])): ?>
      <h2>SEO（Google検索）のカテゴリ</h2>
      <div class="cats">
        <?php foreach ($seo['score']['categories'] as $c): ?><div class="cat"><span><?= ktgs_h($c['label']) ?></span><b><?= (int)$c['score'] ?></b></div><?php endforeach; ?>
      </div>
      <h2>SEO：直すこと（重い順・上位10件）</h2>
      <div class="list">
        <?php $shown = 0; foreach ($seo['findings'] as $f): if ($shown++ >= 10) break; ?>
          <div class="item"><div class="t"><span class="tag <?= ktgs_h($f['severity']) ?>"><?= ktgs_h($sev_label[$f['severity']] ?? $f['severity']) ?></span><?= ktgs_h($f['message']) ?></div>
            <?php if ($f['evidence'] !== ''): ?><div class="e">実測：<?= ktgs_h($f['evidence']) ?></div><?php endif; ?>
            <div class="a">直し方：<?= ktgs_h($f['action']) ?></div></div>
        <?php endforeach; if (!$seo['findings']): ?><div class="item">大きな問題は見つかりませんでした。</div><?php endif; ?>
      </div>
      <?php if (count($seo['findings']) > 10): ?><p class="note">ほかに<?= count($seo['findings']) - 10 ?>件あります。すべては診断書（Markdown）に入っています。</p><?php endif; ?>
    <?php else: ?><div class="err">SEO：<?= ktgs_h($seo['error']) ?></div><?php endif; ?>

    <?php if (empty($geo['error'])): ?>
      <h2>AEO・GEO（AI検索）の観点</h2>
      <div class="cats">
        <?php foreach (ktg_geo_categories() as $k => $label): ?><div class="cat"><span><?= ktgs_h($label) ?></span><b><?= (int)($geo['categories'][$k] ?? 0) ?></b></div><?php endforeach; ?>
      </div>
      <h2>AEO・GEO：直すこと（効果の大きい順）</h2>
      <div class="list">
        <?php foreach ($geo['recommendations'] as $a): ?><div class="item"><div class="t"><?= ktgs_h($a) ?></div></div><?php endforeach; ?>
        <?php if (!$geo['recommendations']): ?><div class="item">大きな問題は見つかりませんでした。</div><?php endif; ?>
      </div>
    <?php else: ?><div class="err">AEO・GEO：<?= ktgs_h($geo['error']) ?></div><?php endif; ?>

    <h2>AIのクローラーは、実際に来ているか</h2>
    <div class="box">この診断は「AIに読める状態か」を見るものです。GPTBot・ClaudeBot・PerplexityBot などが実際に読みに来たかは、計測（ktrackgeo.php）をサイトに入れると記録できます。Google Analytics はボットを除くので、ここは見えません。</div>

    <div class="actions">
      <a class="btn main" href="<?= ktgs_h($self) ?>?url=<?= rawurlencode($result['url']) ?>&amp;format=md">診断書（Markdown）をダウンロード</a>
      <button class="btn" type="button" onclick="window.print()">印刷・PDFで保存</button>
      <form method="post" action="<?= ktgs_h($self) ?>?url=<?= rawurlencode($result['url']) ?>" style="display:inline"><input type="hidden" name="again" value="1"><button class="btn" type="submit">いま測り直す</button></form>
    </div>
    <p class="note">判定はすべて実測値にもとづく決定論的なチェックで、生成AIは使っていません。</p>
  <?php endif; ?>
<?php endif; ?>
<?= KTGS_DEMO_FOOTER ?>
</main>
<footer><div class="wrap"><?= ktgs_h(KTGS_TITLE) ?>（Kurage SEO と Kurage Track &amp; GEO を1つにしたもの・MIT License）</div></footer>
</body>
</html>
