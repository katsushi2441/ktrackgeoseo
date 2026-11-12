<?php
// SEO・AEO・GEO の診断をコマンドから1回で行い、結果を JSON に保存する。
// 使い方: php scripts/audit_cli.php <URL> <出力フォルダ> [ページ数の上限=10]
// 画面のファイル（public/*.php）は読み込むと HTML を出すので、出力は捨てて関数だけ使う。
// 見るのは、ふつうの訪問者がページを開くのと同じ、外から見える範囲だけ（GET のみ）。
if ($argc < 3) { fwrite(STDERR, "usage: php scripts/audit_cli.php <URL> <outdir> [max_pages]\n"); exit(2); }
$url = $argv[1]; $out = rtrim($argv[2], '/'); $max = (int)($argv[3] ?? 10);
@mkdir($out, 0775, true);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = 'cli'; $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SCRIPT_NAME'] = '/kseo.php';
define('KSEO_DATA_DIR', $out . '/kseo_data');
ob_start();
foreach (['kseo.php', 'ktrackgeo_geo.php', 'ktrackgeoseo_aeo.php'] as $f) { require __DIR__ . '/../public/' . $f; }
ob_end_clean();
$seo = kseo_run_audit($url, $max);
$geo = ktg_geo_audit($url);
$ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: KurageSEO/1.0 (+https://kseo.exbridge.jp/)\r\n"]]);
$html = (string)@file_get_contents($url, false, $ctx);
file_put_contents($out . '/top.html', $html);
$aeo = $html !== '' ? ktgs_aeo_analyze($html) : ['error' => 'トップページを取得できませんでした'];
file_put_contents($out . '/result.json', json_encode(['url' => $url, 'checked_at' => date('c'), 'seo' => $seo, 'aeo' => $aeo, 'geo' => $geo], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
printf("SEO %s / AEO %s / GEO %s → %s/result.json\n", (is_array($seo["score"] ?? null) ? ($seo["score"]["overall"] ?? "-") : ($seo["score"] ?? "-")), $aeo['score'] ?? '-', $geo['score'] ?? '-', $out);
