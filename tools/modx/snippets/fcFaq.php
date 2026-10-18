<?php
/**
 * fcFaq — частые вопросы из поля главной страницы (по умолчанию fc_faq).
 * В поле — по одному вопросу в строке: «Вопрос | Ответ».
 *
 * &mode=`html` (по умолчанию) — раскрывающиеся блоки <details> для страницы;
 * &mode=`ld` — вопросы для разметки FAQPage в JSON-LD (через запятую, без скобок вокруг).
 */
$tv = $modx->getOption('tv', $scriptProperties, 'fc_faq');
$mode = $modx->getOption('mode', $scriptProperties, 'html');
$res = $modx->resource;
if (!$res) return '';
$raw = (string)$res->getTVValue($tv);

$items = [];
foreach (preg_split('~\R~u', $raw) as $line) {   // u — иначе \R режет русскую «х» (байт 0x85 PHP принимает за перевод строки)
    $parts = array_map('trim', explode('|', $line, 2));
    if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') $items[] = $parts;
}

if ($mode === 'ld') {
    $out = [];
    foreach ($items as [$q, $a]) {
        $out[] = json_encode(['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return str_replace('{"', '{ "', implode(",\n          ", $out));   // пробел после «{» — совместимость с Fenom
}

$html = '';
foreach ($items as [$q, $a]) {
    $html .= "        <details>\n          <summary>" . htmlspecialchars($q) . "</summary>\n          <p>" . htmlspecialchars($a) . "</p>\n        </details>\n";
}
return $html;
