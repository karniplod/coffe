<?php
/**
 * fcMenuLd — напитки из раздела «Меню» (fc_menu_id) для разметки schema.org (MenuItem) в JSON-LD:
 * название, описание (поле fc_drink_desc) и две цены — 200 мл и 300 мл (настройки fc_price_200, fc_price_300).
 * Выводит элементы через запятую, без скобок вокруг.
 */
use MODX\Revolution\modResource;

$parent = (int)$modx->getOption('fc_menu_id');
if (!$parent) return '';
$offers = [];
foreach (['200' => 'fc_price_200', '300' => 'fc_price_300'] as $ml => $key) {
    $price = preg_replace('~\D~', '', (string)$modx->getOption($key));
    if ($price !== '') $offers[] = ['@type' => 'Offer', 'name' => "$ml мл", 'price' => $price, 'priceCurrency' => 'RUB'];
}
$q = $modx->newQuery(modResource::class, ['parent' => $parent, 'published' => 1, 'deleted' => 0]);
$q->sortby('menuindex', 'ASC');
$out = [];
foreach ($modx->getIterator(modResource::class, $q) as $r) {
    $item = ['@type' => 'MenuItem', 'name' => $r->get('pagetitle')];
    $desc = trim((string)$r->getTVValue('fc_drink_desc'));
    if ($desc !== '') $item['description'] = $desc;
    if ($offers) $item['offers'] = $offers;
    $out[] = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
return implode(",\n              ", $out);
