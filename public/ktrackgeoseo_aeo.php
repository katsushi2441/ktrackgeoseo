<?php
declare(strict_types=1);
/**
 * AEO（Answer Engine Optimization）の判定。AIの回答に答えとして引用されやすい書き方かを見る。
 *
 * Kurage GEO の app/japanese_aeo.py（analyze_aeo）を PHP に移したもの（2026-10-21）。
 * 規則・閾値・重み・丸め方を揃えてあり、同じHTMLなら同じ点が出る（scripts/check_aeo_parity.py で照合）。
 *   - 日本語ページは日本語の言い回し、英語ページは英語の言い回しで採点する
 *   - 生成AIは使わない（正規表現と数え上げだけ）
 *   - 外部AI検索の掲載順位ではなく、「引用されやすい書き方になっているか」の準備度
 *
 * 公開関数: ktgs_aeo_analyze(string $html): array
 */

/** Python の round()（偶数丸め）と同じ丸め。 */
function ktgs_aeo_round(float $x, int $digits = 0): float
{
    return round($x, $digits, PHP_ROUND_HALF_EVEN);
}

/** unicodedata.normalize('NFKC') 相当。intl が無いサーバーでは全角英数・記号・空白を半角に寄せる。 */
function ktgs_aeo_normalize(string $v): string
{
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($v, Normalizer::FORM_KC);
        if (is_string($n)) { $v = $n; }
    } else {
        $v = mb_convert_kana($v, 'asKV', 'UTF-8');
    }
    return trim($v);
}

function ktgs_aeo_patterns(string $lang): array
{
    if ($lang === 'en') {
        return [
            'sentence'   => '/(*UCP)[^.!?]+[.!?]?/u',
            'question'   => '/(*UCP)(?:\?|\b(?:what|how|why|when|where|who|which|whose|is|are|was|were|can|could|do|does|did|should|will|would|pricing|price|cost|faq|vs\.?|versus|compare|comparison|guide|tutorial)\b)/iu',
            'definition' => '/(*UCP)(?:\b(?:is|are)\s+(?:a|an|the)\b|\brefers?\s+to\b|\bmeans\b|\bstands?\s+for\b|\bis\s+defined\s+as\b|\bis\s+known\s+as\b|\bdescribes?\b|\bconsists?\s+of\b)/iu',
            'assertive'  => '/(*UCP)(?:\b(?:is|are|was|were|has|have|can|will|provides?|offers?|supports?|includes?|delivers?|runs?|costs?|takes?|uses?)\b|\d|[%$€£¥])/iu',
            'source'     => '/(*UCP)(?:\b(?:source|sources|according\s+to|based\s+on|study|studies|research|report|survey|statistics|documentation|reference|cited)\b|https?:\/\/|doi)/iu',
            'absolute'   => '/(*UCP)(?:\b(?:always|never|guaranteed|guarantee|100\s*%|the\s+best|world\'?s\s+first|industry\'?s\s+first|no\.?\s*1|#1|the\s+only|everyone|anybody\s+can|risk[- ]free)\b)/iu',
            'stat'       => '/(*UCP)(?:[$€£¥]\s?[0-9][0-9,.]*|[0-9][0-9,.]*\s*(?:%|percent|x|times|users?|customers?|companies|hours?|minutes?|seconds?|days?|years?|JPY|USD|EUR))/iu',
            'intents'    => [
                'informational' => '/(*UCP)\b(?:what\s+is|how\s+to|why|guide|tutorial|documentation|docs|learn|overview|introduction|explained)\b/iu',
                'navigational'  => '/(*UCP)\b(?:login|log\s+in|sign\s+in|sign\s+up|contact|about\s+us|about|company|careers|support)\b/iu',
                'transactional' => '/(*UCP)\b(?:pricing|price|buy|purchase|order|subscribe|plan|plans|free\s+trial|get\s+started|request\s+a\s+quote|demo)\b/iu',
                'commercial'    => '/(*UCP)\b(?:vs\.?|versus|compare|comparison|alternative|alternatives|best|review|reviews|pros\s+and\s+cons|benefits)\b/iu',
            ],
            'unit' => 'words', 'sentence_range' => [8, 30], 'paragraph_range' => [15, 120],
            'answer_window' => 220, 'definition_window' => 240, 'concise' => [5, 40],
        ];
    }
    return [
        'sentence'   => '/(*UCP)[^。！？!?]+[。！？!?]?/u',
        'question'   => '/(*UCP)(?:[？?]|とは|なぜ|どうして|どのように|どうやって|いくら|料金|費用|おすすめ|選び方|違い|比較|どこ|いつ|誰|何を|何が|できますか|でしょうか)/u',
        'definition' => '/(*UCP)(?:とは[、,\s]*|を指します|を指す|のことです|のことをいう|意味します|意味する|定義されます|定義される|という(?:手法|方法|仕組み|概念|サービス))/u',
        'assertive'  => '/(*UCP)(?:です|ます|である|となります|できます|必要です|有効です|提供します|対応します|含みます|利用できます|\d|[０-９]|[%％]|円)/u',
        'source'     => '/(*UCP)(?:出典|参考|参照|引用|調査|研究|統計|報告|によると|によれば|https?:\/\/|doi|総務省|経済産業省|厚生労働省)/u',
        'absolute'   => '/(*UCP)(?:必ず|絶対(?:に)?|完全(?:に)?|確実(?:に)?|唯一|業界初|日本初|世界初|日本一|世界一|No\.?\s*1|100[%％]|間違いなく|誰でも)/u',
        'stat'       => '/(*UCP)(?:[0-9０-９]+(?:[.,．][0-9０-９]+)?\s*(?:[%％]|人|社|件|倍|円|万円|億円)|[一二三四五六七八九十百千万億]+(?:割|人|社|件|倍|円))/u',
        'intents'    => [
            'informational' => '/(*UCP)(?:とは|意味|方法|仕組み|理由|なぜ|解説|ガイド|使い方|手順|基礎)/u',
            'navigational'  => '/(*UCP)(?:ログイン|登録|問い合わせ|会社概要|運営会社|アクセス|プロフィール)/u',
            'transactional' => '/(*UCP)(?:購入|注文|申込|申し込み|契約|料金|価格|費用|見積|無料相談|資料請求|導入)/u',
            'commercial'    => '/(*UCP)(?:おすすめ|比較|違い|選び方|ランキング|評判|口コミ|メリット|デメリット|代替)/u',
        ],
        'unit' => 'chars', 'sentence_range' => [15, 80], 'paragraph_range' => [30, 260],
        'answer_window' => 160, 'definition_window' => 180, 'concise' => [20, 180],
    ];
}

function ktgs_aeo_size(string $v, string $unit): int
{
    if ($unit === 'words') {
        $p = preg_split('/(*UCP)\s+/u', trim($v), -1, PREG_SPLIT_NO_EMPTY);
        return count($p ?: []);
    }
    return mb_strlen((string)preg_replace('/(*UCP)\s+/u', '', $v), 'UTF-8');
}

/** BeautifulSoup の get_text(" ", strip=True) 相当（文字の塊を前後の空白を落として半角空白でつなぐ）。 */
function ktgs_aeo_text(?DOMNode $node): string
{
    if ($node === null) { return ''; }
    $parts = [];
    $walk = function (DOMNode $n) use (&$walk, &$parts) {
        foreach ($n->childNodes as $c) {
            if ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) {
                $t = preg_replace('/(*UCP)^\s+|\s+$/u', '', $c->nodeValue ?? '');
                if ($t !== '' && $t !== null) { $parts[] = $t; }
            } elseif ($c->nodeType === XML_ELEMENT_NODE) {
                $walk($c);
            }
        }
    };
    $walk($node);
    return ktgs_aeo_normalize(implode(' ', $parts));
}

function ktgs_aeo_ratio(float $v, int $max): int
{
    return (int)min($max, max(0, ktgs_aeo_round($v * $max)));
}

/** 本文の日本語比率と html の lang からページの言語を決める。 */
function ktgs_aeo_language(string $html, string $body): array
{
    $ja = preg_match_all('/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $body);
    $visible = mb_strlen((string)preg_replace('/(*UCP)\s+/u', '', $body), 'UTF-8');
    $ratio = $ja / max($visible, 1);
    if ($ratio >= 0.15) { return ['ja', $ratio]; }
    if ($visible >= 200) { return ['en', $ratio]; }
    $decl = preg_match('/<html[^>]*\blang\s*=\s*["\']([a-zA-Z-]+)/', $html, $m) ? strtolower($m[1]) : '';
    if (strpos($decl, 'ja') === 0) { return ['ja', $ratio]; }
    if (strpos($decl, 'en') === 0) { return ['en', $ratio]; }
    return ['unknown', $ratio];
}

function ktgs_aeo_analyze(string $html): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    $xp = new DOMXPath($dom);
    foreach (['script', 'style', 'noscript', 'svg', 'template'] as $t) {
        foreach (iterator_to_array($xp->query('//' . $t)) as $n) { $n->parentNode->removeChild($n); }
    }
    $root = $xp->query('//main')->item(0) ?: $xp->query('//article')->item(0) ?: $xp->query('//body')->item(0) ?: $dom->documentElement;
    $body = ktgs_aeo_text($root);
    $visible = mb_strlen((string)preg_replace('/(*UCP)\s+/u', '', $body), 'UTF-8');

    [$language, $ja_ratio] = ktgs_aeo_language($html, $body);
    $P = ktgs_aeo_patterns($language === 'en' ? 'en' : 'ja');
    $unit = $P['unit'];

    $headings = [];
    foreach ($xp->query('.//h1|.//h2|.//h3', $root) as $h) {
        if (ktgs_aeo_text($h) !== '') { $headings[] = $h; }
    }
    $sections = array_values(array_filter($headings, fn($h) => in_array(strtolower($h->nodeName), ['h2', 'h3'], true)));
    $paragraphs = [];
    foreach ($xp->query('.//p', $root) as $p) {
        $t = ktgs_aeo_text($p);
        if (mb_strlen($t, 'UTF-8') >= 10) { $paragraphs[] = $t; }
    }

    $answer_first = $definition = $questions = $concise = 0;
    foreach ($sections as $h) {
        $ht = ktgs_aeo_text($h);
        $next = $xp->query('following::*[self::p or self::li or self::div][1]', $h)->item(0);
        $answer = mb_substr(ktgs_aeo_text($next), 0, 240, 'UTF-8');
        $aw = mb_substr($answer, 0, $P['answer_window'], 'UTF-8');
        if ($answer !== '' && (preg_match($P['assertive'], $aw) || preg_match($P['stat'], $aw))) { $answer_first++; }
        if ($answer !== '' && preg_match($P['definition'], mb_substr($answer, 0, $P['definition_window'], 'UTF-8'))) { $definition++; }
        if (preg_match($P['question'], $ht)) {
            $questions++;
            $sz = ktgs_aeo_size($answer, $unit);
            if ($P['concise'][0] <= $sz && $sz <= $P['concise'][1]) { $concise++; }
        }
    }
    $sec_total = max(count($sections), 1);
    $answer_first_ratio = $answer_first / $sec_total;
    $definition_ratio = $definition / $sec_total;
    $question_ratio = $concise / max($questions, 1);

    preg_match_all($P['sentence'], $body, $sm);
    $min_s = $unit === 'words' ? 3 : 4;
    $sentences = [];
    foreach ($sm[0] as $s) {
        if (ktgs_aeo_size($s, $unit) >= $min_s) { $sentences[] = ktgs_aeo_normalize($s); }
    }
    $sum = 0;
    foreach ($sentences as $s) { $sum += ktgs_aeo_size($s, $unit); }
    $avg = ktgs_aeo_round($sum / max(count($sentences), 1), 1);
    [$sl, $sh] = $P['sentence_range'];
    $readable_s = count(array_filter($sentences, function ($s) use ($sl, $sh, $unit) { $z = ktgs_aeo_size($s, $unit); return $sl <= $z && $z <= $sh; }));
    $readable_ratio = $readable_s / max(count($sentences), 1);
    [$pl, $ph] = $P['paragraph_range'];
    $readable_p = count(array_filter($paragraphs, function ($p) use ($pl, $ph, $unit) { $z = ktgs_aeo_size($p, $unit); return $pl <= $z && $z <= $ph; }));
    $paragraph_ratio = $readable_p / max(count($paragraphs), 1);
    $readability = (int)ktgs_aeo_round(($readable_ratio * 0.65 + $paragraph_ratio * 0.35) * 100);

    $hits = [];
    foreach ($P['intents'] as $name => $re) { $hits[$name] = preg_match_all($re, $body); }
    $found = array_keys(array_filter($hits));
    $intent = (int)ktgs_aeo_round(count($found) / count($P['intents']) * 100);

    $stats = preg_match_all($P['stat'], $body);
    $sources = preg_match_all($P['source'], $body);
    $external = 0;
    foreach ($xp->query('.//a[@href]', $root) as $a) {
        $href = (string)$a->getAttribute('href');
        if (strpos($href, 'http://') === 0 || strpos($href, 'https://') === 0) { $external++; }
    }
    $evidence = min(100, $sources * 18 + $external * 5 + min($stats, 5) * 5);

    preg_match_all($P['absolute'], $body, $am);
    $absolute = array_map('ktgs_aeo_normalize', $am[0]);
    $unsourced = ($stats && !($sources || $external)) ? $stats : 0;
    $risk = min(100, count($absolute) * 20 + $unsourced * 12);

    $answer_score = ktgs_aeo_ratio($answer_first_ratio, 100);
    $definition_score = ktgs_aeo_ratio($definition_ratio, 100);
    $qa_score = $questions ? ktgs_aeo_ratio($question_ratio, 100) : 0;
    $total = (int)ktgs_aeo_round(
        $answer_score * 0.25 + $definition_score * 0.15 + $qa_score * 0.15 + $evidence * 0.20
        + $readability * 0.15 + $intent * 0.10 - $risk * 0.10
    );
    $total = max(0, min(100, $total));

    $labels = ['informational' => '解説', 'navigational' => '会社・問い合わせ', 'transactional' => '料金・申込', 'commercial' => '比較・選び方'];
    $size_label = $unit === 'words' ? '語' : '文字';
    $page_lang = $language === 'en' ? 'en' : 'ja';
    $rec = [];
    if ($answer_first_ratio < 0.5) { $rec[] = '各見出しの直後に、結論を1〜2文で先に書いてください。'; }
    if ($definition === 0) {
        $rec[] = $page_lang === 'en' ? '重要語に "X is a ..." / "X refers to ..." のような定義文を追加してください。' : '重要語に「○○とは〜を指します」の定義文を追加してください。';
    }
    if ($questions === 0) {
        $rec[] = $page_lang === 'en' ? '"How much does it cost?" のような利用者の質問を見出しとして追加してください。' : '「料金はいくらですか？」など、利用者の質問を見出しとして追加してください。';
    } elseif ($concise < $questions) {
        $rec[] = '質問見出しの直後に、' . $P['concise'][1] . $size_label . '以内の直接回答を置いてください。';
    }
    if ($evidence < 50) { $rec[] = '数値・比較・主張に、調査名、更新日、出典URLを添えてください。'; }
    if ($readability < 65) { $rec[] = '長い文を分割し、1段落を' . $pl . '〜' . $ph . $size_label . '程度に整理してください。'; }
    if (count($found) < 3) {
        $missing = [];
        foreach (array_keys($P['intents']) as $n) { if (!in_array($n, $found, true)) { $missing[] = $labels[$n]; } }
        $rec[] = '不足している検索意図（' . implode('、', $missing) . '）に答えるページを追加してください。';
    }
    if ($absolute || $unsourced) {
        $rec[] = $page_lang === 'en' ? '"always" "guaranteed" "#1" などの断定や出典のない数値を、検証可能な根拠付き表現へ直してください。' : '「必ず」「No.1」などの断定や出典のない数値を、検証可能な根拠付き表現へ直してください。';
    }

    return [
        'checked' => $body !== '',
        'language' => $language,
        'analyzed_as' => $page_lang,
        'score' => $total,
        'band' => $total >= 85 ? 'excellent' : ($total >= 70 ? 'good' : ($total >= 45 ? 'foundation' : 'critical')),
        'metrics' => [
            'answer_first' => ['score' => $answer_score, 'found' => $answer_first, 'total' => count($sections)],
            'definitions' => ['score' => $definition_score, 'found' => $definition, 'total' => count($sections)],
            'question_answers' => ['score' => $qa_score, 'questions' => $questions, 'concise_answers' => $concise],
            'evidence' => ['score' => $evidence, 'statistics' => $stats, 'source_signals' => $sources, 'external_links' => $external],
            'readability' => ['score' => $readability, 'sentences' => count($sentences), 'avg_sentence_chars' => $avg, 'unit' => $unit],
            'intent_coverage' => ['score' => $intent, 'found' => $found, 'hits' => $hits],
            'claim_risk' => ['score' => $risk, 'absolute_claims' => array_slice($absolute, 0, 10), 'unsourced_statistics' => $unsourced],
        ],
        'recommendations' => array_slice($rec, 0, 8),
        'source' => ['visible_characters' => $visible, 'japanese_character_ratio' => ktgs_aeo_round($ja_ratio, 3),
                     'headings' => count($headings), 'paragraphs' => count($paragraphs)],
    ];
}

/** 観点の日本語名（画面と診断書で使う）。 */
function ktgs_aeo_metric_labels(): array
{
    return [
        'answer_first' => '結論が先にあるか', 'definitions' => '定義の文', 'question_answers' => '質問と短い答え',
        'evidence' => '根拠・出典', 'readability' => '読みやすさ', 'intent_coverage' => '検索意図の網羅', 'claim_risk' => '断定・根拠なしの数字（減点）',
    ];
}
