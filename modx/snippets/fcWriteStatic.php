<?php
/**
 * fcWriteStatic — пересоздаёт js/points.js и js/config.js в корне сайта из админки MODX.
 * Эти файлы читают карта на сайте и приложение /app/ (оно работает без интернета, поэтому данные — в файлах, а не в странице).
 *
 * &what=`all` | `points` | `config`. Пишет файл, только если содержимое изменилось. Возвращает, что сделано.
 * Точки — опубликованные страницы раздела fc_points_id: название, поля fc_point_address, fc_point_hours, fc_point_coords («широта, долгота»).
 * Контакты — настройки fc_phone, fc_phone_raw, fc_telegram_url, fc_max_url, fc_email (ClientConfig или системные), центр карты — fc_map_center.
 */
use MODX\Revolution\modResource;

$what = $modx->getOption('what', $scriptProperties, 'all');
$dir = MODX_BASE_PATH . 'js/';
$done = [];
$js = function ($v) { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); };
$coords = function ($s) {
    if (!preg_match('~(-?\d+(?:[.,]\d+)?)\s*[,; ]\s*(-?\d+(?:[.,]\d+)?)~', (string)$s, $m)) return null;
    return [(float)str_replace(',', '.', $m[1]), (float)str_replace(',', '.', $m[2])];
};
$write = function ($file, $text) use ($dir, &$done) {
    $path = $dir . $file;
    if (is_file($path) && file_get_contents($path) === $text) return;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (@file_put_contents($path, $text) === false) { $done[] = "не удалось записать js/$file (права на папку js/)"; return; }
    $done[] = "обновлён js/$file";
};

if ($what === 'all' || $what === 'points') {
    $parent = (int)$modx->getOption('fc_points_id');
    $list = [];
    if ($parent) {
        $q = $modx->newQuery(modResource::class, ['parent' => $parent, 'published' => 1, 'deleted' => 0]);
        $q->sortby('menuindex', 'ASC');
        foreach ($modx->getIterator(modResource::class, $q) as $r) {
            $c = $coords($r->getTVValue('fc_point_coords'));
            if (!$c) continue;   // без координат точку на карте не поставить
            $list[] = "  {\n    title: " . $js($r->get('pagetitle')) . ",\n    address: " . $js((string)$r->getTVValue('fc_point_address'))
                . ",\n    hours: " . $js((string)$r->getTVValue('fc_point_hours')) . ",\n    coords: [" . $c[0] . ", " . $c[1] . "]\n  }";
        }
    }
    $write('points.js', "/* Точки Fastcup на карте. Файл создаёт MODX из раздела «Точки» — правьте точки в админке, а не здесь. */\n"
        . "window.FASTCUP_POINTS = [\n" . implode(",\n", $list) . "\n];\n");
}

if ($what === 'all' || $what === 'config') {
    $center = $coords($modx->getOption('fc_map_center', null, '58.0105, 56.2294')) ?: [58.0105, 56.2294];
    $o = function ($k) use ($modx, $js) { return $js((string)$modx->getOption($k, null, '')); };
    $write('config.js', "/* Настройки сайта Fastcup. Файл создаёт MODX из настроек (ClientConfig → «Контакты») — правьте там, а не здесь. */\n"
        . "window.FASTCUP_CONFIG = {\n"
        . "  mapCenter: [" . $center[0] . ", " . $center[1] . "],\n"
        . "  phone: " . $o('fc_phone') . ",\n"
        . "  phoneHref: " . $o('fc_phone_raw') . ",\n"
        . "  telegram: " . $o('fc_telegram_url') . ",\n"
        . "  max: " . $o('fc_max_url') . ",\n"
        . "  email: " . $o('fc_email') . ",\n"
        . "  formEndpoint: \"\"   // заявки отправляет SendIt (пресет fastcup)\n"
        . "};\n");
}

return $done ? implode('; ', $done) : 'js/points.js и js/config.js без изменений';
