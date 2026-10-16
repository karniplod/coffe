<?php
/**
 * fcCup — стаканчик напитка в меню.
 * Есть своя картинка (поле fc_drink_img) — выводит её; нет — рисует стаканчик в разрезе по слоям из поля fc_drink_layers.
 *
 * Параметры: &img — путь к картинке, &layers — слои, &idx — номер в списке (для уникального id), &alt — название напитка.
 * Слои: по одному в строке, снизу вверх, «высота цвет», например «26.4 #3B1F14». Вся высота стакана — 76.6.
 */
$img = trim((string)$modx->getOption('img', $scriptProperties, ''));
$alt = (string)$modx->getOption('alt', $scriptProperties, '');
if ($img !== '') {
    if (!preg_match('~^(https?:)?//|^/~', $img)) $img = '/' . ltrim($img, '/');
    return '<img class="drink__cup drink__cup--img" src="' . htmlspecialchars($img) . '" alt="' . htmlspecialchars($alt) . '" width="84" height="105" loading="lazy">';
}

$id = 'cc' . preg_replace('~\D~', '', (string)$modx->getOption('idx', $scriptProperties, mt_rand(1000, 9999)));
$rects = [];
$y = 96.6;   // дно стакана
foreach (preg_split('~\R~u', (string)$modx->getOption('layers', $scriptProperties, '')) as $line) {
    if (!preg_match('~([\d.]+)\s*(#[0-9a-f]{3,8})~i', $line, $m)) continue;
    $h = (float)$m[1];
    $y0 = round($y - $h, 1);
    // слои чуть заходят друг на друга (+0.6), чтобы между ними не было светлой щели
    $rects[] = sprintf('<rect x="0" y="%.1f" width="80" height="%.1f" fill="%s"/>', $y0, $y == 96.6 ? $h : $h + 0.6, $m[2]);
    $y = $y0;
}
if (!$rects) $rects[] = '<rect x="0" y="20" width="80" height="76.6" fill="#3B1F14"/>';
$rects = array_reverse($rects);

return '<svg class="drink__cup" viewBox="0 0 80 100" aria-hidden="true"><defs><clipPath id="' . $id . '"><path d="M11 20h58l-6.5 76h-45z"/></clipPath></defs>'
    . '<g clip-path="url(#' . $id . ')">' . implode('', $rects) . '<path d="M11 20h58l-1 9h-56z" fill="#fff" opacity=".12"/></g>'
    . '<path class="drink__glass" d="M11 20h58l-6.5 76h-45z"/><path class="drink__steam" d="M32 14c-3-4 3-6 0-11M42 14c-3-4 3-6 0-11M52 14c-3-4 3-6 0-11"/></svg>';
