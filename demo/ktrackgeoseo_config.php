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
  . '<a class="btn" href="https://exbridge.jp/ai-sns-komon.html#ref=ktrackgeoseo-demo">AI-SNS顧問（X運用×HP×Webシステム）</a></div></div>'
  // 最上部の共通ヘッダー（左=公式サイト／右=導入版・代理店・AI-IT顧問・AI-SNS顧問）と最下部の代理店募集。中身は kurage の partner-bar.js の1か所で管理
  . '<script src=https://kurage.exbridge.jp/partner-bar.js defer></script>'
  : '');


// 診断した人を AI-SNS顧問 へつなぐ枠。共有の枠のすぐ下に出す（当社の公開先だけ）。
// 見つかった「直すこと」の件数を出して、「その直しを顧問の15時間でやる」とつなげる。
function ktgs_demo_after_share(array $r, bool $share): string
{
  if (($_SERVER['HTTP_HOST'] ?? '') !== 'proto.exbridge.jp') { return ''; }
  $e = static function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
  $host = (string)(parse_url((string)$r['url'], PHP_URL_HOST) ?: $r['url']);
  $nseo = 0;
  foreach ((array)($r['seo']['findings'] ?? []) as $f) { if (in_array($f['severity'] ?? '', ['critical', 'warning'], true)) { $nseo++; } }
  $naeo = count((array)($r['aeo']['recommendations'] ?? []));
  $ngeo = count((array)($r['geo']['recommendations'] ?? []));
  $total = $nseo + $naeo + $ngeo;
  $ref = $share ? 'ktgs-share-komon' : 'ktgs-result-komon';
  $lp = 'https://exbridge.jp/ai-sns-komon.html#ref=' . $ref;
  $contact = 'https://exbridge.jp/contact.php?' . http_build_query(['ref' => $ref . '-trial', 'subject' => 'AI-SNS顧問（1か月お試し）について／診断：' . $host]);

  if ($share) {
    $h = 'Google検索とAI検索に強いサイトを、Xで人を呼べるサイトに';
    $lead = 'このシステムは、株式会社エクスブリッジの<b>AI-SNS顧問</b>で使っているものです。診断で見つかった直しをホームページに入れ、Xで信用のある人とつながって、サイトに人を呼ぶところまで引き受けます。';
  } elseif (function_exists('ktgs_all_good') && ktgs_all_good($r)) {
    $h = 'サイトは整っています。次は、Xから人を呼ぶ番です';
    $lead = $e($host) . ' は3つとも80点以上で、検索にもAIにも読まれる準備ができています。'
      . ($total > 0 ? '残りの直し' . $total . '件も顧問の時間の中で片づけつつ、' : '')
      . 'AI-SNS顧問では、Xで信用のある人とつながり、このサイトに人を呼んで、問い合わせ・採用・PRにつなげます。';
  } elseif ($total > 0) {
    $h = 'この' . $total . '件の直し、AI-SNS顧問の15時間でやります';
    $lead = $e($host) . ' で、直したほうがいいことが <b>SEO ' . $nseo . '件・AEO ' . $naeo . '件・GEO ' . $ngeo . '件</b> 見つかりました。'
      . '下の「直すこと」を、AI-SNS顧問の月15時間の中で、AIを使って直します。直したらこの診断で測り直し、点数で確かめます。';
  } else {
    $h = 'サイトは整っています。次は、Xから人を呼ぶ番です';
    $lead = $e($host) . ' は、検索にもAIにも読まれる準備ができています。AI-SNS顧問では、Xで信用のある人とつながり、このサイトに人を呼んで、問い合わせ・採用・PRにつなげます。';
  }

  return '<section class="komon" aria-label="AI-SNS顧問のご案内">'
    . '<style>'
    . '.komon{background:#fff;border:2px solid #0a8a80;border-radius:18px;padding:18px;margin:22px 0;position:relative;overflow:hidden}'
    . '.komon .kh{display:flex;gap:14px;align-items:flex-start}'
    . '.komon .kh img{width:84px;height:auto;object-fit:contain;flex:none}'
    . '.komon .tag2{display:inline-block;background:#0a8a80;color:#fff;font-size:12px;font-weight:900;border-radius:999px;padding:2px 10px}'
    . '.komon h2{margin:6px 0 6px;font-size:21px;line-height:1.45;color:#0b3b37}'
    . '.komon p{margin:0;font-size:14.5px}'
    . '.komon .g3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0}'
    . '.komon .g3 div{background:#f3faf9;border:1px solid #dcebe9;border-radius:14px;padding:12px;font-size:13.5px;line-height:1.7}'
    . '.komon .g3 b{display:block;font-size:15px;color:#076f67;margin-bottom:2px}'
    . '.komon .try{background:#fff8e6;border:1px solid #efd9ad;border-radius:14px;padding:12px 14px;font-size:14px}'
    . '.komon .try b.p{font-size:20px;color:#5f4b14}'
    . '.komon .camp{margin-top:6px;font-size:13.5px}'
    . '.komon .acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}'
    . '.komon .acts .btn.main{background:#0a8a80;border-color:#0a8a80}'
    . '@media(max-width:640px){.komon .g3{grid-template-columns:1fr}.komon .kh{display:block}.komon .kh img{float:right;width:60px;margin:0 0 4px 8px}.komon h2{font-size:19px}}'
    . '</style>'
    . '<div class="kh"><img src="ktgs_assets/mascot.png" width="300" height="300" alt="">'
    . '<div><span class="tag2">AI-SNS顧問（X運用 × ホームページ × Webシステム）</span>'
    . '<h2>' . $h . '</h2><p>' . $lead . '</p></div></div>'
    . '<div class="g3">'
    . '<div><b>ホームページを直す</b>診断の「直すこと」、LPの作成、Webシステムの開発まで。AI生成とバイブコーディングで、月15時間の中で進めます。</div>'
    . '<div><b>Xで人を呼ぶ</b>つながる相手の設計、AIが拾う返信候補、当社代表のXからの後押し。国会議員や経営者とつながってきたやり方で支援します。</div>'
    . '<div><b>数字を一緒に見る</b>報告書は作りません。Xのアナリティクスと Search Console を御社で見られるようにして、打ち合わせで次の手を決めます。</div>'
    . '</div>'
    . '<div class="try"><b class="p">まず1か月だけ 15時間 100,000円</b>（税別・先払い）<br>通常は月15時間・税別150,000円。続けるかどうかは、1か月使ってから決められます。'
    . '<div class="camp">🎁 <b>今だけ：</b>このシステム（Kurage Track &amp; GEO &amp; SEO）を御社のサイトに導入し、AI検索のクローラーが来ているかまで見られるようにします。</div></div>'
    . '<div class="acts"><a class="btn main" href="' . $e($contact) . '" style="font-size:15px;padding:11px 22px">この診断結果をもとに相談する</a>'
    . '<a class="btn" href="' . $e($lp) . '">AI-SNS顧問の内容を見る</a></div>'
    . '</section>';
}
