<?php
/*
 * Установщик сайта Fastcup для MODX 3 — запускатель.
 * Сначала проверяет версию PHP и только потом подключает основной код (fc-install.inc.php, нужен PHP 7.4+):
 * иначе старый PHP молча упал бы на разборе файла. Как запускать — в INSTALL.md и в начале fc-install.inc.php.
 */
if (PHP_SAPI === 'cli') {
    ini_set('display_errors', '1');   // в консоли хостинга показ ошибок часто выключен — без этого сбой выглядит как пустой вывод
    error_reporting(E_ALL);
}
if (version_compare(PHP_VERSION, '7.4.0', '<')) {
    $msg = 'ОСТАНОВЛЕНО: установщику нужен PHP 7.4 или новее (для MODX 3 — 8.1+), а запущен PHP ' . PHP_VERSION . ".\n"
         . "В консоли Beget запустите нужную версию полным путём, например:\n"
         . "  /usr/local/php/cgi/8.3/bin/php fc-install.php --dry\n"
         . "Какие версии есть: ls /usr/local/php/cgi/\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); exit(2); }
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}
if (!is_file(dirname(__FILE__) . '/fc-install.inc.php')) {
    $msg = "ОСТАНОВЛЕНО: рядом нет файла fc-install.inc.php — загрузите папку установщика целиком.\n";
    if (PHP_SAPI === 'cli') { fwrite(STDERR, $msg); exit(2); }
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}
require dirname(__FILE__) . '/fc-install.inc.php';
