<?php
/**
 * Установщик сайта Fastcup для MODX 3 — основной код. Собирается tools/modx_build.py из tools/modx/fc-install.inc.php.tpl — руками не править.
 * Запускается через fc-install.php (он проверяет версию PHP), напрямую этот файл не открывать.
 *
 * Что делает (всё повторяемо: второй запуск ничего не задублирует):
 *   1. ставит дополнения pdoTools, FormIt, ClientConfig (modx.com) и SendIt (modstore.pro), если их нет;
 *   2. копирует файлы сайта из папки site/ в корень (стили, скрипты, картинки, офлайн-карту, приложение /app/);
 *   3. создаёт категорию «Fastcup» с шаблонами, чанками, сниппетами, плагином и полями (TV);
 *   4. настройки: контакты и цены — в ClientConfig (группы «Контакты», «Цены и карта»), без него — в системные настройки fc_*;
 *   5. страницы: главная, раздел «Меню» с напитками, раздел «Точки», «Политика конфиденциальности», robots.txt, sitemap.xml;
 *   6. пресет формы SendIt (заявки — на почту из настройки fc_email), человекопонятные адреса, .htaccess;
 *   7. js/points.js и js/config.js из админки (их читают карта и приложение).
 *
 * Где лежит папка установщика: в корне сайта (/fastcup-modx/, запуск из браузера) или в core/components/fastcup/ (запуск из SSH).
 *
 * Ключи (консоль / браузер):
 *   --dry / ?dry=1               пробный прогон: проверки и список действий, база и файлы не меняются
 *   --update / ?update=1         перезаписать уже созданные страницы (тексты главной, напитки, точки); без ключа их не трогаем
 *   --no-extras / ?noextras=1    не ставить дополнения (если их нет — будут предупреждения)
 *   --no-files / ?nofiles=1      не копировать файлы сайта из site/
 *   --no-tune / ?notune=1        не трогать настройки MODX (адреса, кэш, .htaccess)
 *   --no-clientconfig / ?nocc=1  контакты — в системные настройки, даже если установлен ClientConfig
 *   --static / ?static=1         элементы читают код из файлов этой папки (папку тогда не удалять)
 *   --force / ?force=1           продолжить, несмотря на конфликты имён
 *   --root=путь                  корень сайта, если скрипт не находит MODX сам
 *
 * Безопасность: проверки до любых изменений; перед перезаписью — резервная копия в core/cache/fc-install-backup/<дата>/;
 * ошибка одного шага не останавливает остальные; журнал — core/cache/logs/fc-install-<дата>.log.
 * В консоли код выхода: 0 — без ошибок, 1 — были ошибки, 2 — проверки не пройдены.
 */
@set_time_limit(900);
@ini_set('memory_limit', '512M');
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
    $msg = "Установка прервана ошибкой PHP: {$e['message']} ({$e['file']}:{$e['line']}).\nУже сделанное сохранено; повторный запуск ничего не задублирует.";
    if (PHP_SAPI === 'cli') fwrite(STDERR, $msg . "\n"); else echo '<pre style="color:#b3261e">' . htmlspecialchars($msg) . '</pre>';
});
$cli = PHP_SAPI === 'cli';
$args = $argv ?? [];
$opt = function ($cliKey, $getKey) use ($cli, $args) { return $cli ? in_array($cliKey, $args, true) : !empty($_GET[$getKey]); };
$dry = $opt('--dry', 'dry');
$update = $opt('--update', 'update');
$noExtras = $opt('--no-extras', 'noextras');
$noFiles = $opt('--no-files', 'nofiles');
$noTune = $opt('--no-tune', 'notune');
$noCc = $opt('--no-clientconfig', 'nocc');
$static = $opt('--static', 'static');
$force = $opt('--force', 'force');
$dir = __DIR__;
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { echo "Запускайте fc-install.php, а не этот файл.\n"; exit(2); }
$stamp = date('Ymd-His');

function page($title, $body, $color = '#2A1A14') {
    return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . htmlspecialchars($title) . '</title>'
        . '<body style="font:15px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;background:#FFF6EC;color:' . $color . ';padding:24px;max-width:980px;margin:0 auto">'
        . '<h1 style="font-size:22px;line-height:1.3">' . htmlspecialchars($title) . '</h1>' . $body;
}
function stop($msg, $code = 2) {   // ошибка до начала изменений
    global $cli;
    if ($cli) { fwrite(STDERR, "ОСТАНОВЛЕНО: $msg\n"); exit($code); }
    http_response_code(500);
    exit(page('Установка остановлена', '<p style="color:#b3261e">' . nl2br(htmlspecialchars($msg)) . '</p><p>База и файлы не изменялись.</p>'));
}

// --- 1. окружение: PHP и MODX 3
if (PHP_VERSION_ID < 70400) stop('Нужен PHP 7.4 или новее (для MODX 3 — 8.1+). Сейчас: ' . PHP_VERSION . '. Поменяйте версию PHP в панели хостинга.');
foreach (['json', 'mbstring'] as $ext) if (!extension_loaded($ext)) stop("Не включено расширение PHP «{$ext}». Включите его в настройках PHP хостинга.");

$cfg = null;
foreach ($args as $a) if (strpos($a, '--root=') === 0) $cfg = rtrim(substr($a, 7), '/\\') . '/config.core.php';
for ($d = $dir, $i = 0; !$cfg && $i < 8; $d = dirname($d), $i++) { if (is_file($d . '/config.core.php')) $cfg = $d . '/config.core.php'; }
if (!$cfg || !is_file($cfg)) stop("Не найден config.core.php — похоже, MODX здесь не установлен.\nПоложите папку установщика в корень сайта с MODX (рядом с index.php) или укажите --root=путь_к_корню_сайта.");
require_once $cfg;
if (!defined('MODX_CORE_PATH')) stop('config.core.php не задаёт MODX_CORE_PATH — это не файл MODX.');
if (!is_file(MODX_CORE_PATH . 'vendor/autoload.php')) stop('Не найден core/vendor/autoload.php. Похоже, это MODX 2.x — установщик рассчитан на MODX 3.');
require_once MODX_CORE_PATH . 'vendor/autoload.php';
if (!class_exists('MODX\Revolution\modX')) stop('Нет класса MODX\Revolution\modX. Установщик рассчитан на MODX 3.');

use MODX\Revolution\modX;
use MODX\Revolution\modCategory;
use MODX\Revolution\modChunk;
use MODX\Revolution\modTemplate;
use MODX\Revolution\modSnippet;
use MODX\Revolution\modTemplateVar;
use MODX\Revolution\modTemplateVarTemplate;
use MODX\Revolution\modSystemSetting;
use MODX\Revolution\modPlugin;
use MODX\Revolution\modPluginEvent;
use MODX\Revolution\modResource;
use MODX\Revolution\modDocument;
use MODX\Revolution\modContentType;
use MODX\Revolution\Transport\modTransportProvider;
use MODX\Revolution\Transport\modTransportPackage;

if ($cli && !isset($_SESSION)) $_SESSION = [];   // поставщикам дополнений MODX нужна сессия, в консоли её нет
try {
    $modx = new modX();
    $modx->initialize($cli ? 'web' : 'mgr');
} catch (Throwable $e) {
    stop('MODX не запустился: ' . $e->getMessage());
}
if (!$cli && !($modx->user && $modx->user->isAuthenticated('mgr') && $modx->user->get('sudo'))) {
    http_response_code(403);
    exit(page('Нужен вход в админку', '<p>Войдите в админку MODX под администратором: <a href="' . htmlspecialchars(MODX_MANAGER_URL) . '" target="_blank">' . htmlspecialchars(MODX_MANAGER_URL)
        . '</a>, затем обновите эту страницу.</p>'));
}
$ver = method_exists($modx, 'getVersionData') ? $modx->getVersionData() : [];
if ((int)($ver['version'] ?? 3) < 3) stop('Это MODX ' . ($ver['full_version'] ?? '2.x') . '. Установщик рассчитан на MODX 3.');

// --- удалить папку установщика (кнопка в конце отчёта, только из браузера)
if (!$cli && !empty($_GET['remove'])) {
    $removed = true;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { if (!($f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()))) $removed = false; }
    $emptyLeft = false;
    if (!@rmdir($dir)) { if ($removed) $emptyLeft = true; $removed = false; }   // файлы удалены, а папку держит сервер (бывает на Windows)
    if ($emptyLeft) exit(page('Файлы установщика удалены', '<p>Осталась пустая папка ' . htmlspecialchars(basename($dir)) . ' — удалите её файловым менеджером, когда будет удобно. Сайт: <a href="'
        . htmlspecialchars($modx->getOption('site_url')) . '">' . htmlspecialchars($modx->getOption('site_url')) . '</a></p>'));
    exit(page($removed ? 'Папка установщика удалена' : 'Папку удалить не получилось',
        '<p>' . ($removed ? 'Готово. Сайт: <a href="' . htmlspecialchars($modx->getOption('site_url')) . '">' . htmlspecialchars($modx->getOption('site_url')) . '</a>' : 'Удалите папку ' . htmlspecialchars(basename($dir)) . ' файловым менеджером хостинга.') . '</p>'));
}

// --- журнал
$log = [];
function say($kind, $text) { global $log; $log[] = [$kind, $text]; }
$logDir = MODX_CORE_PATH . 'cache/logs/';
$logFile = $logDir . "fc-install-$stamp.log";

// --- 2. описание сайта и файлы установщика
$manifest = <<<'JSON'
{
 "top": "Fastcup",
 "email": "takeawayperm@yandex.ru",
 "chunks": [
  {
   "name": "fc.head",
   "category": "Главная страница",
   "file": "chunks/fc.head.html"
  },
  {
   "name": "fc.jsonld",
   "category": "Главная страница",
   "file": "chunks/fc.jsonld.html"
  },
  {
   "name": "fc.header",
   "category": "Главная страница",
   "file": "chunks/fc.header.html"
  },
  {
   "name": "fc.hero",
   "category": "Главная страница",
   "file": "chunks/fc.hero.html"
  },
  {
   "name": "fc.beans",
   "category": "Главная страница",
   "file": "chunks/fc.beans.html"
  },
  {
   "name": "fc.about",
   "category": "Главная страница",
   "file": "chunks/fc.about.html"
  },
  {
   "name": "fc.how",
   "category": "Главная страница",
   "file": "chunks/fc.how.html"
  },
  {
   "name": "fc.menu",
   "category": "Главная страница",
   "file": "chunks/fc.menu.html"
  },
  {
   "name": "fc.b2b",
   "category": "Главная страница",
   "file": "chunks/fc.b2b.html"
  },
  {
   "name": "fc.faq",
   "category": "Главная страница",
   "file": "chunks/fc.faq.html"
  },
  {
   "name": "fc.contacts",
   "category": "Главная страница",
   "file": "chunks/fc.contacts.html"
  },
  {
   "name": "fc.footer",
   "category": "Главная страница",
   "file": "chunks/fc.footer.html"
  },
  {
   "name": "fc.scripts",
   "category": "Главная страница",
   "file": "chunks/fc.scripts.html"
  },
  {
   "name": "fc.drink",
   "category": "Служебные",
   "file": "chunks/fc.drink.html"
  },
  {
   "name": "fc.emailTpl",
   "category": "Служебные",
   "file": "chunks/fc.emailTpl.html"
  }
 ],
 "templates": [
  {
   "name": "fc-home",
   "file": "templates/fc-home.html",
   "description": "Главная: карта, меню, бизнесу, вопросы, контакты"
  },
  {
   "name": "fc-page",
   "file": "templates/fc-page.html",
   "description": "Текстовая страница: политика конфиденциальности и т. п."
  },
  {
   "name": "fc-section",
   "file": "templates/fc-section.html",
   "description": "Служебные разделы «Меню» и «Точки» (на сайте — переход на главную)"
  },
  {
   "name": "fc-drink",
   "file": "templates/fc-section.html",
   "description": "Напиток в меню: поля описание, картинка, слои стаканчика"
  },
  {
   "name": "fc-point",
   "file": "templates/fc-section.html",
   "description": "Точка на карте: адрес, часы, координаты"
  }
 ],
 "snippets": [
  {
   "name": "fcCount",
   "file": "snippets/fcCount.php",
   "description": "сколько опубликованных страниц в разделе, со словом в нужной форме: «14 напитков», «21 напиток»"
  },
  {
   "name": "fcCup",
   "file": "snippets/fcCup.php",
   "description": "стаканчик напитка в меню"
  },
  {
   "name": "fcFaq",
   "file": "snippets/fcFaq.php",
   "description": "частые вопросы из поля главной страницы (по умолчанию fc_faq)"
  },
  {
   "name": "fcGoHome",
   "file": "snippets/fcGoHome.php",
   "description": "служебные страницы (разделы «Меню» и «Точки», отдельные напитки и точки) нужны только админке:"
  },
  {
   "name": "fcMenuLd",
   "file": "snippets/fcMenuLd.php",
   "description": "напитки из раздела «Меню» (fc_menu_id) для разметки schema.org (MenuItem) в JSON-LD:"
  },
  {
   "name": "fcWriteStatic",
   "file": "snippets/fcWriteStatic.php",
   "description": "пересоздаёт js/points.js и js/config.js в корне сайта из админки MODX"
  }
 ],
 "plugins": [
  {
   "name": "fcStatic",
   "file": "plugins/fcStatic.php",
   "description": "держит js/points.js и js/config.js в соответствии с админкой (их читают карта и приложение /app/)",
   "events": [
    "OnDocFormSave",
    "OnDocFormDelete",
    "OnResourceUndelete",
    "OnResourceSort",
    "OnEmptyTrash",
    "OnSiteRefresh",
    "OnLoadWebDocument"
   ]
  }
 ],
 "tvs": [
  {
   "name": "fc_faq",
   "caption": "Частые вопросы",
   "type": "textarea",
   "templates": [
    "fc-home"
   ],
   "category": "Поля",
   "description": "По одному вопросу в строке: «Вопрос | Ответ». Выводятся в блоке «Частые вопросы» и в разметке для поисковиков",
   "elements": "",
   "default": ""
  },
  {
   "name": "fc_drink_desc",
   "caption": "Описание напитка",
   "type": "text",
   "templates": [
    "fc-drink"
   ],
   "category": "Поля",
   "description": "Одна строка под названием в меню, например «Эспрессо, горячее молоко и плотная пенка»",
   "elements": "",
   "default": ""
  },
  {
   "name": "fc_drink_img",
   "caption": "Картинка напитка (SVG или PNG)",
   "type": "image",
   "templates": [
    "fc-drink"
   ],
   "category": "Поля",
   "description": "Необязательно. Пусто — нарисуем стаканчик по слоям ниже. Готовые картинки всех напитков: images/export/svg и images/export/png",
   "elements": "",
   "default": ""
  },
  {
   "name": "fc_drink_layers",
   "caption": "Слои стаканчика",
   "type": "textarea",
   "templates": [
    "fc-drink"
   ],
   "category": "Поля",
   "description": "Если нет картинки. По одному слою в строке, снизу вверх: «высота цвет», например «26.4 #3B1F14». Высота всего стакана — 76.6",
   "elements": "",
   "default": "26.4 #3B1F14\n25.7 #F1DFC8\n25.7 #FFF9F0"
  },
  {
   "name": "fc_point_address",
   "caption": "Адрес",
   "type": "text",
   "templates": [
    "fc-point"
   ],
   "category": "Поля",
   "description": "Как показывать на сайте: «ул. Ленина, 50, ТЦ „Пример“, 1 этаж»",
   "elements": "",
   "default": ""
  },
  {
   "name": "fc_point_hours",
   "caption": "Часы работы",
   "type": "text",
   "templates": [
    "fc-point"
   ],
   "category": "Поля",
   "description": "Например «Круглосуточно» или «07:00–23:00»",
   "elements": "",
   "default": "Круглосуточно"
  },
  {
   "name": "fc_point_coords",
   "caption": "Координаты",
   "type": "text",
   "templates": [
    "fc-point"
   ],
   "category": "Поля",
   "description": "«широта, долгота», например «58.0119, 56.2452». В Яндекс Картах: правый клик по месту → «Что здесь?» → скопировать числа",
   "elements": "",
   "default": ""
  }
 ],
 "settings": [
  {
   "key": "fc_phone",
   "value": "+7 (966) 790-12-46",
   "name": "Телефон",
   "cc": "Контакты",
   "help": "Как показывать на сайте: +7 (966) 790-12-46"
  },
  {
   "key": "fc_phone_raw",
   "value": "+79667901246",
   "name": "Телефон для ссылки",
   "cc": "Контакты",
   "help": "Только + и цифры: +79667901246"
  },
  {
   "key": "fc_email",
   "value": "takeawayperm@yandex.ru",
   "name": "Почта",
   "cc": "Контакты",
   "help": "Показывается на сайте. Сменили — запустите установщик ещё раз, чтобы заявки шли на новую почту"
  },
  {
   "key": "fc_max_url",
   "value": "https://max.ru/u/f9LHodD0cOLNgyFpx2TMMpZm8dQrDDkFBR8pqF8eruHmCrOysKUcZy9P4CU",
   "name": "Ссылка на MAX",
   "cc": "Контакты",
   "help": "Полная ссылка https://max.ru/…"
  },
  {
   "key": "fc_telegram_url",
   "value": "https://t.me/fastcup_perm",
   "name": "Ссылка на Telegram",
   "cc": "Контакты",
   "help": "Полная ссылка https://t.me/…"
  },
  {
   "key": "fc_price_200",
   "value": "100",
   "name": "Цена 200 мл, ₽",
   "cc": "Цены и карта",
   "help": "Только число: 100"
  },
  {
   "key": "fc_price_300",
   "value": "150",
   "name": "Цена 300 мл, ₽",
   "cc": "Цены и карта",
   "help": "Только число: 150"
  },
  {
   "key": "fc_map_center",
   "value": "58.0105, 56.2294",
   "name": "Центр карты",
   "cc": "Цены и карта",
   "help": "«широта, долгота» — если точек нет: 58.0105, 56.2294"
  },
  {
   "key": "fc_menu_id",
   "value": "",
   "name": "ID раздела «Меню»",
   "cc": "",
   "help": "Заполняет установщик"
  },
  {
   "key": "fc_points_id",
   "value": "",
   "name": "ID раздела «Точки»",
   "cc": "",
   "help": "Заполняет установщик"
  },
  {
   "key": "fc_policy_id",
   "value": "",
   "name": "ID страницы политики",
   "cc": "",
   "help": "Заполняет установщик"
  }
 ],
 "cc_groups": {
  "Контакты": "Телефон, почта, MAX и Telegram — на сайте, в форме и в приложении",
  "Цены и карта": "Цены на напитки 200 и 300 мл и центр карты"
 },
 "managed_files": [
  "js/points.js",
  "js/config.js"
 ],
 "id_settings": {
  "fc_menu_id": "menu",
  "fc_points_id": "tochki",
  "fc_policy_id": "politika"
 }
}
JSON;
$M = json_decode($manifest, true);
if (!is_array($M)) stop('Описание сайта внутри установщика повреждено (' . json_last_error_msg() . '). Загрузите fc-install.inc.php заново.');

$problems = [];   // не пройдено — ничего не меняем
$warnings = [];   // можно продолжать

foreach (['chunks', 'templates', 'snippets', 'plugins'] as $group) foreach ($M[$group] as $e) {
    $p = $dir . '/' . $e['file'];
    if (!is_file($p)) $problems[] = "нет файла установщика: {$e['file']} — загрузите папку целиком";
    elseif (filesize($p) === 0) $problems[] = "файл установщика пустой (оборвалась загрузка?): {$e['file']}";
}
$R = json_decode((string)@file_get_contents($dir . '/resources.json'), true);
if (!is_array($R)) $problems[] = 'нет или повреждён resources.json — загрузите папку установщика заново';
$siteDir = $dir . '/site';
$hasSite = is_dir($siteDir);
if (!$hasSite && !$noFiles) $warnings[] = 'рядом нет папки site/ с файлами сайта — стили, скрипты и картинки не скопируются (загрузите архив целиком или скопируйте файлы вручную)';

// --- 3. права на запись: журнал, резервная копия, js/
$backupDir = MODX_CORE_PATH . "cache/fc-install-backup/$stamp/";
if (!$dry) {
    foreach ([$logDir, dirname(rtrim($backupDir, '/'))] as $d) {
        if (!is_dir($d)) @mkdir($d, 0755, true);
        if (!is_dir($d) || !is_writable($d)) $problems[] = 'нет прав на запись в ' . str_replace(MODX_CORE_PATH, 'core/', $d) . ' — проверьте права на папку core/cache/';
    }
    if (!$noFiles && !is_writable(MODX_BASE_PATH)) $problems[] = 'нет прав на запись в корень сайта — файлы сайта не скопировать';
}

// --- 4. конфликты имён: элемент с тем же именем в чужой категории — не наш, без --force не перезаписываем
$ourCats = [];
$top = $modx->getObject(modCategory::class, ['category' => $M['top'], 'parent' => 0]);
if ($top) {
    $ourCats[] = (int)$top->get('id');
    foreach ($modx->getIterator(modCategory::class, ['parent' => $top->get('id')]) as $c) $ourCats[] = (int)$c->get('id');
}
$conflicts = [];
$checkName = function ($class, $field, $name, $label) use ($modx, &$conflicts, $ourCats) {
    $o = $modx->getObject($class, [$field => $name]);
    if ($o && (int)$o->get('category') !== 0 && !in_array((int)$o->get('category'), $ourCats, true)) $conflicts[] = "$label «{$name}»";
};
foreach ($M['chunks'] as $e) $checkName(modChunk::class, 'name', $e['name'], 'чанк');
foreach ($M['templates'] as $e) $checkName(modTemplate::class, 'templatename', $e['name'], 'шаблон');
foreach ($M['snippets'] as $e) $checkName(modSnippet::class, 'name', $e['name'], 'сниппет');
foreach ($M['plugins'] as $e) $checkName(modPlugin::class, 'name', $e['name'], 'плагин');
foreach ($M['tvs'] as $e) $checkName(modTemplateVar::class, 'name', $e['name'], 'поле (TV)');
if ($conflicts) {
    $msg = 'в MODX уже есть элементы с такими же именами в чужих категориях: ' . implode(', ', $conflicts);
    if ($force) $warnings[] = $msg . ' — перезаписываю (--force), старые версии будут в резервной копии';
    else $problems[] = $msg . '. Переименуйте их или запустите с --force (старые версии сохранятся в резервную копию)';
}

// --- резервная копия и сохранение
$backedUp = 0;
function backup($type, $name, $data) {
    global $dry, $backupDir, $backedUp;
    if ($dry) return;
    $safe = preg_replace('~[^\w.\-]+~u', '_', $name);
    $d = $backupDir . $type . '/';
    if (!is_dir($d) && !@mkdir($d, 0755, true)) { say('error', "не удалось создать папку резервной копии $d"); return; }
    $ext = is_string($data) ? ($type === 'snippets' || $type === 'plugins' ? '.php' : ($type === 'files' ? '' : '.html')) : '.json';
    $text = is_string($data) ? ($type === 'snippets' || $type === 'plugins' ? "<?php\n" . $data : $data) : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (@file_put_contents($d . $safe . $ext, $text) === false) { say('error', "не удалось сохранить резервную копию: $type/$name"); return; }
    $backedUp++;
}
function saved($o, $what) {   // save() с проверкой результата
    global $dry;
    if ($dry) return true;
    try {
        if ($o->save()) return true;
        say('error', "MODX не сохранил $what (проверьте журнал ошибок MODX)");
    } catch (Throwable $e) {
        say('error', "ошибка при сохранении $what: " . $e->getMessage());
    }
    return false;
}
function setting($key, $value, $area = 'Fastcup') {   // системная настройка: создать или поменять значение
    global $modx;
    $s = $modx->getObject(modSystemSetting::class, $key);
    $old = $s ? (string)$s->get('value') : null;
    if ($old === (string)$value) return false;
    if (!$s) { $s = $modx->newObject(modSystemSetting::class); $s->fromArray(['key' => $key, 'xtype' => 'textfield', 'namespace' => 'core', 'area' => $area], '', true); }
    else backup('settings', $key, $s->toArray());
    $s->set('value', (string)$value);
    return saved($s, "настройку $key") ? ($old === null ? 'new' : 'upd') : false;
}

// --- 5. дополнения: ставим недостающие из modx.com и modstore.pro
$snippetExists = function ($name) use ($modx) { return (bool)$modx->getObject(modSnippet::class, ['name' => $name]); };
$extras = [   // имя пакета → [поставщик, признак установки]
    'pdoTools'     => ['https://rest.modx.com/extras/', function () use ($snippetExists) { return $snippetExists('pdoResources'); }],
    'FormIt'       => ['https://rest.modx.com/extras/', function () use ($snippetExists) { return $snippetExists('FormIt'); }],
    'ClientConfig' => ['https://rest.modx.com/extras/', function () { return is_dir(MODX_CORE_PATH . 'components/clientconfig/'); }],
    'SendIt'       => ['https://modstore.pro/extras/', function () { return is_dir(MODX_CORE_PATH . 'components/sendit/'); }],
];
$missing = [];
foreach ($extras as $name => [$url, $has]) if (!$has()) $missing[] = $name;
if ($missing && $noExtras) foreach ($missing as $name) $warnings[] = "не установлено дополнение $name (--no-extras) — установите его в «Пакеты → Установщик»";

foreach ($warnings as $w) say('warn', $w);
if ($problems) {
    foreach ($problems as $p) say('error', $p);
    if (!$dry) { report(false); stop("Проверки не пройдены, база и файлы не изменялись:\n• " . implode("\n• ", $problems)); }
}

function provider($name, $url) {
    global $modx, $dry;
    $p = $modx->getObject(modTransportProvider::class, ['service_url' => $url]);
    if ($p || $dry) return $p;
    $p = $modx->newObject(modTransportProvider::class);
    $p->fromArray(['name' => $name, 'service_url' => $url, 'description' => '', 'username' => '', 'api_key' => '']);
    if (!saved($p, "поставщика $name")) return null;
    say('new', "поставщик дополнений $name");
    return $p;
}
function bestSignature($prov, $name) {   // самая новая стабильная версия пакета у поставщика
    $list = [];
    $v = $prov->latest(strtolower($name));
    if (is_array($v)) foreach ($v as $n) if (strcasecmp($n['package_name'] ?? '', $name) === 0) $list[] = $n['signature'];
    if (!$list) {   // modstore.pro не фильтрует версии по имени — ищем поиском
        $f = $prov->find(['query' => $name, 'limit' => 20]);
        if (is_array($f) && isset($f[1])) foreach ($f[1] as $n) if (strcasecmp($n['name'] ?? '', $name) === 0) $list[] = $n['signature'];
    }
    $stable = array_values(array_filter($list, function ($s) { return preg_match('~-pl\d*$~', $s); }));
    if ($stable) $list = $stable;
    usort($list, function ($a, $b) { return version_compare(preg_replace('~^[^-]+-~', '', $b), preg_replace('~^[^-]+-~', '', $a)); });
    return $list[0] ?? null;
}
if ($missing && !$noExtras) {
    foreach ($missing as $name) {
        [$url] = $extras[$name];
        if ($dry) { say('new', "дополнение $name — скачаю и установлю (" . parse_url($url, PHP_URL_HOST) . ')'); continue; }
        try {
            $prov = provider(parse_url($url, PHP_URL_HOST) === 'modstore.pro' ? 'modstore.pro' : 'modx.com', $url);
            if (!$prov) { say('error', "дополнение $name: не создался поставщик"); continue; }
            $sig = bestSignature($prov, $name);
            if (!$sig) { say('error', "дополнение $name: не нашёл у " . $prov->get('name') . ' — нет связи с сайтом поставщика? Установите вручную: «Пакеты → Установщик»'); continue; }
            $pkg = $modx->getObject(modTransportPackage::class, ['signature' => $sig]) ?: $prov->transfer($sig);
            if (!$pkg) { say('error', "дополнение $name: не скачался пакет $sig"); continue; }
            if (!$pkg->get('installed') && !$pkg->install()) { say('error', "дополнение $name: ошибка установки $sig — смотрите журнал ошибок MODX"); continue; }
            say('new', "установлено дополнение $name ($sig)");
        } catch (Throwable $e) { say('error', "дополнение $name: " . $e->getMessage()); }
    }
    $modx->cacheManager->refresh();
}
if (!$snippetExists('pdoResources')) say($dry && in_array('pdoTools', $missing, true) ? 'skip' : 'warn', 'pdoTools пока не установлен — без него меню и карта сайта не выводятся');

// ClientConfig: контакты и цены — в его группы, остальное — системные настройки
$cc = false;
if (!$noCc && is_dir(MODX_CORE_PATH . 'components/clientconfig/')) {
    try {
        $modx->addPackage('clientconfig', MODX_CORE_PATH . 'components/clientconfig/model/');
        $cc = (bool)$modx->loadClass('cgSetting') && (bool)$modx->loadClass('cgGroup');
    } catch (Throwable $e) { $cc = false; }
    if (!$cc) say('warn', 'ClientConfig найден, но его модель не загрузилась — контакты запишу в системные настройки fc_*');
}

// --- категории и элементы
$catIds = [];
function category($name, $parent = 0) {
    global $modx, $dry, $catIds;
    $key = $parent . '/' . $name;
    if (isset($catIds[$key])) return $catIds[$key];
    $c = $modx->getObject(modCategory::class, ['category' => $name, 'parent' => $parent]);
    if (!$c) {
        say('new', "категория «{$name}»");
        if ($dry) return $catIds[$key] = 0;
        $c = $modx->newObject(modCategory::class);
        $c->fromArray(['category' => $name, 'parent' => $parent]);
        if (!saved($c, "категорию «{$name}»")) return $catIds[$key] = 0;
    }
    return $catIds[$key] = (int)$c->get('id');
}
$norm = function ($p) { $r = realpath($p); return rtrim(str_replace('\\', '/', $r ?: $p), '/') . '/'; };
$base = $norm(MODX_BASE_PATH);
$here = $norm($dir);
$inside = DIRECTORY_SEPARATOR === '\\' ? stripos($here, $base) === 0 : strpos($here, $base) === 0;
if ($inside) { $staticSource = (int)$modx->getOption('default_media_source', null, 1); $staticPrefix = substr($here, strlen($base)); }
else { $staticSource = 0; $staticPrefix = $here; }
$typeDir = [modChunk::class => 'chunks', modTemplate::class => 'templates', modSnippet::class => 'snippets', modPlugin::class => 'plugins'];
function element($class, $nameField, $name, $content, $catId, $desc, $file) {
    global $modx, $static, $staticSource, $staticPrefix, $typeDir;
    try {
        if (trim((string)$content) === '') { say('error', "$name: файл $file пустой или не читается — элемент не создан"); return null; }
        $o = $modx->getObject($class, [$nameField => $name]);
        $isNew = !$o;
        if ($isNew) $o = $modx->newObject($class);
        else {
            if ((string)$o->getContent() === (string)$content && (int)$o->get('category') === (int)$catId && (bool)$o->get('static') === (bool)$static) { say('skip', "без изменений: $name"); return $o; }
            backup($typeDir[$class], $name, (string)$o->getContent());
        }
        $data = [$nameField => $name, 'description' => $desc, 'category' => $catId];
        $data += $static ? ['static' => 1, 'source' => $staticSource, 'static_file' => $staticPrefix . $file] : ['static' => 0, 'static_file' => ''];
        $o->fromArray($data);
        $o->setContent($content);
        if (saved($o, $name)) say($isNew ? 'new' : 'upd', ($isNew ? 'создан' : 'обновлён') . ": $name");
        return $o;
    } catch (Throwable $e) {
        say('error', "$name: " . $e->getMessage());
        return null;
    }
}
function body($rel) { global $dir; return rtrim((string)@file_get_contents($dir . '/' . $rel), "\n"); }
function code($rel) { return preg_replace('~^\s*<\?php\s*~', '', body($rel)); }

try {
    // --- 6. файлы сайта: site/ → корень (только изменившиеся; перезаписанные — в резервную копию)
    if ($hasSite && !$noFiles) {
        $copied = 0; $same = 0; $failed = 0;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($siteDir) + 1));
            $target = MODX_BASE_PATH . $rel;
            if (in_array($rel, $M['managed_files'], true) && is_file($target)) { $same++; continue; }   // их ведёт MODX (fcWriteStatic)
            if (is_file($target) && filesize($target) === $f->getSize() && md5_file($target) === md5_file($f->getPathname())) { $same++; continue; }
            if ($dry) { $copied++; continue; }
            if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) { $failed++; say('error', "не создать папку для $rel"); continue; }
            if (is_file($target) && filesize($target) < 2 * 1024 * 1024) backup('files', str_replace('/', '__', $rel), (string)file_get_contents($target));
            if (@copy($f->getPathname(), $target)) $copied++; else { $failed++; say('error', "не скопировался файл $rel"); }
        }
        say($copied ? 'upd' : 'skip', ($dry ? 'скопирую' : 'скопировано') . " файлов сайта: $copied, без изменений: $same" . ($failed ? ", ошибок: $failed" : ''));
    }

    $top = category($M['top']);
    $sub = function ($name) use ($top) { return category($name, $top); };
    if ($static) say('upd', 'статичные элементы читают код из папки: ' . ($staticSource ? '/' : '') . $staticPrefix);

    // --- 7. элементы
    foreach ($M['chunks'] as $c) element(modChunk::class, 'name', $c['name'], body($c['file']), $sub($c['category']), $c['description'] ?? '', $c['file']);
    $tplIds = [];
    foreach ($M['templates'] as $t) {
        $o = element(modTemplate::class, 'templatename', $t['name'], body($t['file']), $sub('Шаблоны'), $t['description'], $t['file']);
        $tplIds[$t['name']] = ($o && !$dry) ? (int)$o->get('id') : 0;
    }
    foreach ($M['snippets'] as $s) element(modSnippet::class, 'name', $s['name'], code($s['file']), $sub('Сниппеты'), $s['description'], $s['file']);
    foreach ($M['plugins'] as $p) {
        $o = element(modPlugin::class, 'name', $p['name'], code($p['file']), $sub('Плагины'), $p['description'], $p['file']);
        if ($dry) { say('new', "события плагина {$p['name']}: " . implode(', ', $p['events'])); continue; }
        if (!$o || !$o->get('id')) continue;
        foreach ($p['events'] as $ev) {
            $link = ['pluginid' => $o->get('id'), 'event' => $ev];
            if ($modx->getObject(modPluginEvent::class, $link)) continue;
            $e = $modx->newObject(modPluginEvent::class);
            $e->fromArray($link + ['priority' => 0, 'propertyset' => 0], '', true);
            if (saved($e, "событие $ev плагина {$p['name']}")) say('new', "плагин {$p['name']} подписан на событие $ev");
        }
    }

    // --- 8. поля (TV) и привязка к шаблонам
    foreach ($M['tvs'] as $i => $t) {
        try {
            $o = $modx->getObject(modTemplateVar::class, ['name' => $t['name']]);
            $isNew = !$o;
            if ($isNew) $o = $modx->newObject(modTemplateVar::class);
            else backup('tvs', $t['name'], $o->toArray());
            $o->fromArray(['name' => $t['name'], 'caption' => $t['caption'], 'description' => $t['description'], 'type' => $t['type'],
                           'elements' => $t['elements'], 'default_text' => $t['default'], 'display' => 'default', 'rank' => $i,
                           'category' => $sub($t['category'])]);
            if (!empty($t['input_properties'])) $o->set('input_properties', $t['input_properties']);
            if (!saved($o, "поле {$t['name']}")) continue;
            say($isNew ? 'new' : 'skip', ($isNew ? 'создано' : 'обновлено') . " поле (TV) «{$t['caption']}» {$t['name']} → " . implode(', ', $t['templates']));
            if ($dry) continue;
            foreach ($t['templates'] as $tn) {
                if (empty($tplIds[$tn])) { say('error', "поле {$t['name']}: шаблон $tn не создан — не привязано"); continue; }
                $link = ['tmplvarid' => $o->get('id'), 'templateid' => $tplIds[$tn]];
                if ($modx->getObject(modTemplateVarTemplate::class, $link)) continue;
                $l = $modx->newObject(modTemplateVarTemplate::class);
                $l->fromArray($link + ['rank' => $i], '', true);
                saved($l, "привязку {$t['name']} к $tn");
            }
        } catch (Throwable $e) { say('error', "поле {$t['name']}: " . $e->getMessage()); }
    }

    // --- 9. настройки: контакты и цены — ClientConfig (только недостающие), остальное — системные
    $ccIds = [];
    if ($cc) foreach ($M['cc_groups'] as $label => $about) {
        try {
            $g = $modx->getObject('cgGroup', ['label' => $label]);
            if ($g) { $ccIds[$label] = (int)$g->get('id'); continue; }
            say('new', "ClientConfig: группа «{$label}»");
            if ($dry) { $ccIds[$label] = 0; continue; }
            $g = $modx->newObject('cgGroup');
            $g->fromArray(['label' => $label, 'description' => $about, 'sortorder' => count($ccIds)]);
            if (saved($g, "группу ClientConfig «{$label}»")) $ccIds[$label] = (int)$g->get('id');
        } catch (Throwable $e) { say('error', "ClientConfig, группа «{$label}»: " . $e->getMessage() . ' — её поля пишу в системные настройки'); }
    }
    $inCc = function ($s) use (&$ccIds) { return !empty($s['cc']) && isset($ccIds[$s['cc']]); };
    $ccSort = [];
    foreach ($M['settings'] as $s) {
        if (!$inCc($s)) continue;
        $grp = $s['cc']; $ccSort[$grp] = isset($ccSort[$grp]) ? $ccSort[$grp] + 1 : 0;
        try {
            if ($modx->getObject('cgSetting', ['key' => $s['key']])) { say('skip', "ClientConfig: {$s['name']} ({$s['key']}) уже есть — не трогаю"); continue; }
            $value = $s['value'];
            $sys = $modx->getObject(modSystemSetting::class, $s['key']);
            if ($sys && (string)$sys->get('value') !== '') $value = (string)$sys->get('value');
            $o = $modx->newObject('cgSetting');
            $o->fromArray(['key' => $s['key'], 'label' => $s['name'], 'description' => $s['help'], 'xtype' => 'textfield',
                           'value' => $value, 'default' => '', 'group' => $ccIds[$grp], 'sortorder' => $ccSort[$grp]]);
            if (saved($o, "поле ClientConfig {$s['key']}")) say('new', "ClientConfig «{$grp}»: {$s['name']} = «{$value}»");
        } catch (Throwable $e) { say('error', "ClientConfig {$s['key']}: " . $e->getMessage()); }
    }
    foreach ($M['settings'] as $s) {
        if ($inCc($s)) continue;
        if ($modx->getObject(modSystemSetting::class, $s['key'])) { say('skip', "настройка {$s['key']} уже есть — не трогаю"); continue; }
        $o = $modx->newObject(modSystemSetting::class);
        $o->fromArray(['key' => $s['key'], 'value' => $s['value'], 'xtype' => 'textfield', 'namespace' => 'core', 'area' => 'Fastcup'], '', true);
        if (saved($o, "настройку {$s['key']}")) say('new', "настройка {$s['key']} = «{$s['value']}»");
        $modx->setOption($s['key'], $s['value']);
    }
    // значения из ClientConfig — сразу в этот запуск (их ждут сниппеты ниже)
    if ($cc && !$dry) foreach ($modx->getIterator('cgSetting') as $o) $modx->setOption($o->get('key'), $o->get('value'));

    // --- 10. настройки MODX: человекопонятные адреса без .html, кэш, .htaccess (--no-tune — не трогать)
    if (!$noTune) {
        foreach (['friendly_urls' => '1', 'use_alias_path' => '0', 'container_suffix' => '', 'cache_disabled' => '0', 'cache_resource' => '1',
                  'automatic_template_assignment' => 'sibling', 'publish_default' => '1', 'site_name' => 'Fastcup'] as $k => $v) {
            if ($dry) { $cur = $modx->getObject(modSystemSetting::class, $k); if (!$cur || (string)$cur->get('value') !== $v) say('upd', "настройка $k = «{$v}»"); continue; }
            if ($r = setting($k, $v, 'Fastcup')) say($r, "настройка $k = «{$v}»");
            $modx->setOption($k, $v);   // сразу в этот запуск: адреса страниц ниже считаются по этим настройкам
        }
        $html = $modx->getObject(modContentType::class, ['mime_type' => 'text/html']);
        if ($html && $html->get('file_extensions') !== '') {
            say('upd', 'тип содержимого HTML: адреса страниц без «.html»');
            if (!$dry) { backup('settings', 'content_type_html', $html->toArray()); $html->set('file_extensions', ''); saved($html, 'тип содержимого HTML'); }
        }
        $ht = MODX_BASE_PATH . '.htaccess';
        if (!is_file($ht) && is_file(MODX_BASE_PATH . 'ht.access')) {
            say('new', '.htaccess из ht.access MODX (человекопонятные адреса)');
            if (!$dry && !@copy(MODX_BASE_PATH . 'ht.access', $ht)) say('error', 'не удалось создать .htaccess — переименуйте ht.access в .htaccess в корне сайта вручную');
        } elseif (is_file($ht) && strpos((string)file_get_contents($ht), 'RewriteRule') === false) {
            say('warn', 'в .htaccess нет правил MODX — адреса страниц могут не открываться; возьмите правила из ht.access');
        }
    }

    // --- 11. страницы
    $ids = []; $menu = [];
    $ctype = function ($mime) use ($modx) {
        if (!$mime) return null;
        $c = $modx->getObject(modContentType::class, ['mime_type' => $mime]);
        if (!$c) say('warn', "тип содержимого $mime не найден — оставляю HTML");
        return $c ? (int)$c->get('id') : null;
    };
    foreach ($R as $r) {
        try {
            $parentId = $r['parent'] === '' ? 0 : ($ids[$r['parent']] ?? null);
            if ($parentId === null) { say('error', "страница /{$r['alias']}: не создан раздел /{$r['parent']} — пропускаю"); continue; }
            $menu[$parentId] = ($menu[$parentId] ?? -1) + 1;
            $home = !empty($r['home']);
            $o = $home ? $modx->getObject(modResource::class, (int)$modx->getOption('site_start', null, 1))
                       : $modx->getObject(modResource::class, ['alias' => $r['alias'], 'parent' => $parentId, 'context_key' => 'web']);
            $isNew = !$o;
            // главная — всегда наша, если на ней ещё не наш шаблон (свежий MODX: страница «Home»)
            $ours = !$isNew && $home && (int)$o->get('template') === (int)($tplIds[$r['template']] ?? -1);
            // always — служебные страницы (robots.txt, sitemap.xml): всегда как в установщике
            if (!$isNew && !$update && empty($r['always']) && (!$home || $ours || $dry && !$tplIds)) {
                $ids[$r['alias']] = (int)$o->get('id');
                say('skip', "страница «{$o->get('pagetitle')}» уже есть — не трогаю (перезаписать: --update)");
                continue;
            }
            if (!$isNew) backup('resources', $r['alias'], $o->toArray() + ['tvs' => array_map(function ($n) use ($o) { return $o->getTVValue($n); }, array_combine(array_keys($r['tvs']), array_keys($r['tvs'])))]);
            if ($isNew) $o = $modx->newObject(modDocument::class);
            $data = $r['fields'] + [
                'parent' => $parentId, 'template' => $r['template'] ? (int)($tplIds[$r['template']] ?? 0) : 0,
                'published' => $r['published'] ?? 1, 'hidemenu' => $r['hidemenu'] ?? 0, 'searchable' => $r['searchable'] ?? 1,
                'isfolder' => $r['isfolder'] ?? 0, 'menuindex' => $menu[$parentId], 'context_key' => 'web',
                'richtext' => $r['richtext'] ?? 0, 'class_key' => modDocument::class, 'cacheable' => 1,
            ];
            if (!$home || $isNew) $data['alias'] = $r['alias'];
            if ($isNew && $data['published']) $data['publishedon'] = time();
            if (($ct = $ctype($r['content_type'] ?? '')) !== null) $data['content_type'] = $ct;
            $o->fromArray($data);
            $title = $r['fields']['pagetitle'] ?? $r['alias'];
            if (!saved($o, "страницу /{$r['alias']}")) continue;
            say($isNew ? 'new' : 'upd', ($isNew ? 'создана' : 'обновлена') . " страница «{$title}»" . ($r['tvs'] ? ' · полей: ' . count($r['tvs']) : ''));
            if ($dry) { $ids[$r['alias']] = 0; continue; }
            $ids[$r['alias']] = (int)$o->get('id');
            foreach ($r['tvs'] as $tvName => $value) if (!$o->setTVValue($tvName, $value)) say('error', "страница /{$r['alias']}: не записалось поле $tvName");
        } catch (Throwable $e) { say('error', "страница /{$r['alias']}: " . $e->getMessage()); }
    }

    // --- 12. ID разделов в настройки (их читают сниппеты и чанки)
    foreach ($M['id_settings'] as $key => $alias) {
        if (empty($ids[$alias])) continue;
        if ($r = setting($key, $ids[$alias])) say($r, "настройка $key = ID страницы /$alias");
        $modx->setOption($key, $ids[$alias]);
    }

    // --- 13. пресет формы SendIt: свой файл (стандартный перезаписывается при обновлении SendIt), заявки — на fc_email
    if (is_dir(MODX_CORE_PATH . 'components/sendit/') || $dry) {
        $target = MODX_CORE_PATH . 'components/sendit/presets/fastcup.inc.php';
        $email = (string)$modx->getOption('fc_email', null, $M['email']);
        // отправитель — ящик на домене сайта: письмо «от» чужой почты (yandex.ru, gmail.com) с сервера хостинга почтовики считают спамом
        $host = preg_replace('~^www\.~', '', (string)parse_url((string)$modx->getOption('site_url'), PHP_URL_HOST));
        $from = (string)$modx->getOption('fc_email_from', null, '');
        if ($from === '') $from = strpos($host, '.') !== false ? 'noreply@' . $host : (string)$modx->getOption('emailsender', null, '');
        $preset = str_replace(['%%EMAIL_TO%%', '%%EMAIL_FROM%%'], [addslashes($email), addslashes($from)], body('sendit/fastcup.inc.php'));
        if ($dry) say('new', "пресет формы SendIt: заявки на $email, отправитель $from");
        else {
            if (!is_dir(dirname($target))) @mkdir(dirname($target), 0755, true);
            if (is_file($target) && file_get_contents($target) === $preset) say('skip', 'пресет формы SendIt без изменений');
            else {
                if (is_file($target)) backup('sendit', 'fastcup.inc.php', (string)file_get_contents($target));
                if (@file_put_contents($target, $preset) === false) say('error', 'не удалось записать пресет SendIt в core/components/sendit/presets/ — нет прав?');
                else say('upd', "пресет формы SendIt: заявки на $email, отправитель $from");
            }
            if ($r = setting('si_path_to_presets', 'components/sendit/presets/fastcup.inc.php', 'SendIt')) say($r, 'настройка si_path_to_presets → пресет Fastcup');
        }
    }

    // --- 14. js/points.js и js/config.js из админки
    if ($dry) say('upd', 'js/points.js и js/config.js — соберу из страниц «Точки» и настроек');
    elseif ($snippetExists('fcWriteStatic')) say('upd', 'файлы карты: ' . $modx->runSnippet('fcWriteStatic', ['what' => 'all']));
} catch (Throwable $e) {
    say('error', 'установка прервана: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . '). Уже сделанное сохранено, резервная копия — ' . str_replace(MODX_CORE_PATH, 'core/', $backupDir));
}

// адреса всех страниц заново (страницы, сохранённые до включения человекопонятных адресов, остались бы без адреса)
if (!$dry && $modx->getOption('friendly_urls')) {
    try { modResource::refreshURIs($modx); say('upd', 'адреса страниц пересчитаны'); } catch (Throwable $e) { say('warn', 'адреса страниц не пересчитались: ' . $e->getMessage() . ' — очистите кэш в админке'); }
}
if (!$dry) { try { $modx->cacheManager->refresh(); } catch (Throwable $e) { say('warn', 'кэш не очистился: очистите вручную (Управление → Очистить кэш)'); } }

report(true);

// --- отчёт: экран + файл журнала
function report($finished) {
    global $log, $dry, $cli, $logFile, $backedUp, $backupDir, $modx, $static, $inside;
    $count = ['new' => 0, 'upd' => 0, 'skip' => 0, 'warn' => 0, 'error' => 0];
    foreach ($log as $l) $count[$l[0]]++;
    $title = ($dry ? 'Пробный прогон (ничего не менялось). ' : ($finished ? 'Готово. ' : 'Остановлено. '))
           . "Создано: {$count['new']}, обновлено: {$count['upd']}, без изменений: {$count['skip']}, предупреждений: {$count['warn']}, ошибок: {$count['error']}";
    if (!$dry && $backedUp) say('upd', "резервная копия перезаписанного ($backedUp шт.): " . str_replace(MODX_CORE_PATH, 'core/', $backupDir));
    if (!$dry) {
        $text = $title . "\n" . implode("\n", array_map(function ($l) { return "[{$l[0]}] {$l[1]}"; }, $log)) . "\n";
        if (@file_put_contents($logFile, $text) !== false) say('skip', 'журнал: ' . str_replace(MODX_CORE_PATH, 'core/', $logFile));
    }
    if (!$finished) return;
    if ($cli) {
        echo $title, "\n";
        foreach ($log as $l) echo "[{$l[0]}] {$l[1]}\n";
        exit($count['error'] ? 1 : 0);
    }
    $colors = ['new' => '#1e7d45', 'upd' => '#1f5fbf', 'skip' => '#8a7a70', 'warn' => '#a35b00', 'error' => '#b3261e'];
    $names = ['new' => 'новое', 'upd' => 'обновлено', 'skip' => 'без изменений', 'warn' => 'внимание', 'error' => 'ошибка'];
    $btn = function ($href, $text, $main = false) {
        return '<a href="' . $href . '" style="display:inline-block;margin:0 8px 8px 0;padding:10px 18px;border-radius:999px;text-decoration:none;font-weight:600;'
            . ($main ? 'background:#E0674F;color:#fff' : 'background:#fff;color:#2A1A14;box-shadow:inset 0 0 0 1.5px #E7D3C0') . '">' . $text . '</a>';
    };
    $body = '';
    if ($dry && !$count['error']) $body .= '<p>Всё верно? ' . $btn('?', 'Установить', true) . $btn('?update=1', 'Установить и перезаписать страницы') . '</p>';
    elseif ($dry) $body .= '<p style="color:#b3261e">Исправьте ошибки (красным) и запустите пробный прогон снова: ' . $btn('?dry=1', 'Проверить ещё раз') . '</p>';
    else {
        $site = htmlspecialchars($modx->getOption('site_url'));
        $body .= '<p>' . ($count['error'] ? 'Есть ошибки — смотрите красные строки и журнал. ' : 'Сайт установлен. ') . $btn($site, 'Открыть сайт', true)
            . $btn(htmlspecialchars(MODX_MANAGER_URL), 'Открыть админку') . '</p>'
            . '<p>Дальше: в админке проверьте «Точки» (сейчас там примеры адресов), реквизиты в «Политике конфиденциальности» и контакты в «Конфигурация» (ClientConfig).</p>';
        if ($inside && !$static && !$count['error']) $body .= '<p>Папка установщика больше не нужна: ' . $btn('?remove=1', 'Удалить папку установщика') . '</p>';
    }
    $list = '<ul style="list-style:none;padding:0">';
    foreach ($log as $l) $list .= '<li style="padding:2px 0"><b style="color:' . $colors[$l[0]] . '">' . $names[$l[0]] . '</b> ' . htmlspecialchars($l[1]) . '</li>';
    echo page($title, $body . $list . '</ul>');
}
