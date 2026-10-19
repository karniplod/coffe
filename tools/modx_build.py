"""
Собирает пакет для переноса сайта на MODX 3: папку modx/ и архив dist/fastcup-modx.zip для загрузки на хостинг.

Что берёт:
  index.html            — режет на шаблон fc-home и чанки (шапка, блоки, подвал), вынимает тексты:
                          напитки (название, описание, слои стаканчика), частые вопросы, SEO главной
  js/points.js          — точки на карте → страницы раздела «Точки»
  js/config.js          — контакты → настройки (ClientConfig)
  tools/modx/           — то, что пишется руками: установщик, сниппеты, плагин, служебные чанки и шаблоны, пресет формы
Что делает:
  modx/                 — готовый пакет (его же можно положить на сервер без архива, но тогда без файлов сайта)
  dist/fastcup-modx.zip — пакет + папка site/ с файлами сайта (стили, скрипты, картинки, карта, приложение)

Запуск: python tools/modx_build.py   (поменяли сайт — запустите снова и загрузите новый архив; установщик обновит только изменённое)
"""
import html
import io
import json
import os
import re
import shutil
import sys
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "tools", "modx")
OUT = os.path.join(ROOT, "modx")
DIST = os.path.join(ROOT, "dist")
PKG = "fastcup-modx"   # имя папки в архиве (и на сервере)


def read(rel):
    return io.open(os.path.join(ROOT, rel), encoding="utf-8").read()


def write(rel, text):
    path = os.path.join(OUT, rel)
    os.makedirs(os.path.dirname(path), exist_ok=True)
    io.open(path, "w", encoding="utf-8", newline="\n").write(text.rstrip("\n") + "\n")


def one(pattern, text, what, flags=re.S):
    m = re.search(pattern, text, flags)
    if not m:
        sys.exit("Не нашёл в index.html: " + what)
    return m


def must_replace(text, old, new, what, count=1):
    n = text.count(old)
    if n != count:
        sys.exit("Ожидал %d вхождение(й) «%s», нашёл %d — index.html изменился, поправьте tools/modx_build.py" % (count, what, n))
    return text.replace(old, new)


TRANSLIT = dict(zip("абвгдеёжзийклмнопрстуфхцчшщъыьэюя",
                    ["a", "b", "v", "g", "d", "e", "e", "zh", "z", "i", "y", "k", "l", "m", "n", "o", "p", "r", "s", "t", "u", "f", "h", "ts",
                     "ch", "sh", "sch", "", "y", "", "e", "yu", "ya"]))


def alias(name):
    s = "".join(TRANSLIT.get(c, c) for c in name.lower())
    return re.sub(r"[^a-z0-9]+", "-", s).strip("-")


def text_of(fragment):
    return html.unescape(re.sub(r"<[^>]+>", "", fragment)).replace("\xa0", " ").strip()


# ---------------------------------------------------------------- данные из сайта
sys.path.insert(0, os.path.join(ROOT, "tools"))
import build_legal   # страницы документов для сайта без MODX — из тех же текстов
print("Страницы документов:", ", ".join(build_legal.build()))
LEGAL = build_legal.config()

page = read("index.html")
cfg_js = read("js/config.js")
points_js = read("js/points.js")


def cfg(key):
    return one(r'%s:\s*"([^"]*)"' % key, cfg_js, "js/config.js: " + key).group(1)


CONTACTS = {k: cfg(k) for k in ("phone", "phoneHref", "telegram", "max", "email")}
CENTER = one(r"mapCenter:\s*\[([^\]]+)\]", cfg_js, "js/config.js: mapCenter").group(1).strip()
FEATURES = {k: v == "true" for k, v in re.findall(r"(promos|openNow):\s*(true|false)", cfg_js)}

head = one(r"<head>\n(.*?)\n</head>", page, "<head>").group(1)
body = one(r"<body[^>]*>\n(.*?)\n</body>", page, "<body>").group(1)

SEO = {
    "title": text_of(one(r"<title>(.*?)</title>", head, "<title>").group(1)),
    "description": html.unescape(one(r'<meta name="description" content="([^"]*)"', head, "description").group(1)),
    "og_description": html.unescape(one(r'<meta property="og:description" content="([^"]*)"', head, "og:description").group(1)),
}

# напитки: название, описание, слои стаканчика (из svg: прямоугольники сверху вниз → слои снизу вверх)
drinks_ul = one(r'      <ul class="drinks">\n(.*?)\n      </ul>', body, "<ul class=\"drinks\">")
DRINKS = []
for li in re.findall(r'<li class="drink">(.*?)</li>', drinks_ul.group(1), re.S):
    rects = re.findall(r'<rect x="0" y="([\d.]+)" width="80" height="([\d.]+)" fill="(#[0-9A-Fa-f]+)"/>', li)
    layers = []
    for k, (y, h, color) in enumerate(reversed(rects)):   # снизу вверх
        h = float(h) if k == 0 else round(float(h) - 0.6, 1)   # у верхних слоёв +0.6 на перекрытие — убираем
        layers.append("%s %s" % (("%.1f" % h).rstrip("0").rstrip("."), color))
    DRINKS.append({
        "name": text_of(one(r'<h3 class="drink__name">(.*?)</h3>', li, "название напитка").group(1)),
        "desc": text_of(one(r'<p class="drink__desc">(.*?)</p>', li, "описание напитка").group(1)),
        "layers": "\n".join(layers),
    })
if len(DRINKS) < 3:
    sys.exit("Нашёл мало напитков (%d) — проверьте разметку меню" % len(DRINKS))

faq_div = one(r'      <div class="faq__list">\n(.*?)\n      </div>', body, "<div class=\"faq__list\">")
FAQ = [(text_of(q), text_of(a)) for q, a in re.findall(r"<summary>(.*?)</summary>\s*<p>(.*?)</p>", faq_div.group(1), re.S)]

POINTS = []
for blk in re.findall(r"\{([^{}]*coords[^{}]*)\}", points_js):
    get = lambda k: (re.search(r'%s:\s*"([^"]*)"' % k, blk) or [None, ""])[1]
    c = re.search(r"coords:\s*\[([^\]]+)\]", blk)
    if c:
        POINTS.append({"title": get("title"), "address": get("address"), "hours": get("hours"), "coords": c.group(1).strip()})

# ---------------------------------------------------------------- превращения разметки
PRICE200, PRICE300 = "100", "150"


def absolutize(s):
    """Пути к файлам — от корня сайта и в папку assets/ (там в MODX лежат все файлы сайта); страницы бывают не в корне (/politika)."""
    s = re.sub(r'((?:src|href|srcset|poster)=")(?:\./)?(images|css|js|app|fonts)/', r"\1/assets/\2/", s)
    return s


def contacts(s):
    s = s.replace("tel:" + CONTACTS["phoneHref"], "tel:[[++fc_phone_raw]]")
    s = s.replace(CONTACTS["phone"], "[[++fc_phone]]")
    s = s.replace("mailto:" + CONTACTS["email"], "mailto:[[++fc_email]]")
    s = s.replace(CONTACTS["email"], "[[++fc_email]]")
    s = s.replace(CONTACTS["max"], "[[++fc_max_url]]")
    s = s.replace(CONTACTS["telegram"], "[[++fc_telegram_url]]")
    # документы: ссылки — на страницы MODX, реквизиты — из настроек
    s = s.replace('href="politika.html"', 'href="[[~[[++fc_policy_id]]]]"').replace('href="soglasie.html"', 'href="[[~[[++fc_agree_id]]]]"')
    s = s.replace('href="cookie.html"', 'href="[[~[[++fc_cookie_id]]]]"').replace('data-policy="cookie.html"', 'data-policy="[[~[[++fc_cookie_id]]]]"')
    s = s.replace("ИНН " + LEGAL["fc_inn"], "ИНН [[++fc_inn]]").replace("ОГРНИП " + LEGAL["fc_ogrnip"], "ОГРНИП [[++fc_ogrnip]]")
    s = s.replace(LEGAL["fc_legal_name"].replace("Индивидуальный предприниматель", "ИП"), "[[++fc_legal_name:replace=`Индивидуальный предприниматель==ИП`]]")
    return s


def common(s):
    return contacts(absolutize(s))


# --- шапка: ссылка-пропуск, капсула, мобильное меню, стрелка «наверх»
hdr = one(r'(<a class="skip" href="#main">.*?)\n\n<main id="main">', body, "шапка (до <main>)").group(1)
CHUNKS = {"fc.header": common(hdr)}

# --- блоки главной
main = one(r'<main id="main">\n(.*?)\n</main>', body, "<main>").group(1)
blocks = re.split(r"\n(?=  <!--[^\n]*-->\n  <section )", "\n" + main.strip("\n"))
names = {"hero": "fc.hero", "beans": "fc.beans", "about": "fc.about", "how": "fc.how", "menu": "fc.menu", "b2b": "fc.b2b", "faq": "fc.faq", "contacts": "fc.contacts"}
order = []
for b in blocks:
    b = b.strip("\n")
    if not b:
        continue
    cls = one(r'<section class="([\w-]+)"', b, "класс блока").group(1)
    if cls not in names:
        sys.exit("Неизвестный блок в index.html: section.%s — добавьте его в names в tools/modx_build.py" % cls)
    CHUNKS[names[cls]] = common(b)
    order.append(names[cls])

# первый экран: цены под фото стакана — из настроек
CHUNKS["fc.hero"] = must_replace(CHUNKS["fc.hero"], "<figcaption>%s ₽ · 200 мл<br>%s ₽ · 300 мл</figcaption>" % (PRICE200, PRICE300),
                                 "<figcaption>[[++fc_price_200]] ₽ · 200 мл<br>[[++fc_price_300]] ₽ · 300 мл</figcaption>", "подпись с ценами")

# меню: напитки из раздела «Меню», число напитков и ценник — из админки
m = CHUNKS["fc.menu"]
m = re.sub(r'      <ul class="drinks">\n.*?\n      </ul>',
           '      <ul class="drinks">\n[[pdoResources? &parents=`[[++fc_menu_id]]` &depth=`0` &tpl=`fc.drink` &sortby=`menuindex` &sortdir=`ASC` &limit=`0`'
           ' &includeTVs=`fc_drink_desc,fc_drink_img,fc_drink_layers` &tvPrefix=`` &showHidden=`1`]]\n      </ul>', m, count=1, flags=re.S)
m = re.sub(r"<p class=\"section-lead\">\d+ напит\w+ ", '<p class="section-lead">[[fcCount? &parent=`[[++fc_menu_id]]`]] ', m, count=1)
m = must_replace(m, 'aria-label="Любой напиток: 200 миллилитров — %s рублей, 300 миллилитров — %s рублей"' % (PRICE200, PRICE300),
                 'aria-label="Любой напиток: 200 миллилитров — [[++fc_price_200]] рублей, 300 миллилитров — [[++fc_price_300]] рублей"', "ценник: подпись")
m = must_replace(m, '<span class="price-tag__big">%s ₽</span>' % PRICE200, '<span class="price-tag__big">[[++fc_price_200]] ₽</span>', "ценник 200")
m = must_replace(m, '<span class="price-tag__big">%s ₽</span>' % PRICE300, '<span class="price-tag__big">[[++fc_price_300]] ₽</span>', "ценник 300")
CHUNKS["fc.menu"] = m

# частые вопросы — из поля fc_faq главной
CHUNKS["fc.faq"] = re.sub(r'      <div class="faq__list">\n.*?\n      </div>', '      <div class="faq__list">\n[[fcFaq]]\n      </div>',
                          CHUNKS["fc.faq"], count=1, flags=re.S)

# форма: отправляет SendIt (пресет fastcup), ошибки полей — под полями, ссылка на политику
f = CHUNKS["fc.contacts"]
f = must_replace(f, '<form class="form" id="leadForm" novalidate>',
                 '<form class="form" id="leadForm" action="[[~[[*id]]]]" method="post" novalidate data-si-form="fastcup" data-si-preset="fastcup">\n'
                 '        <input type="hidden" name="page" value="[[*pagetitle]] ([[~[[*id]]? &scheme=`full`]])">', "форма")
for field in ("name", "phone"):   # места для ошибок под полями (если их ещё нет в index.html)
    if 'data-si-error="%s"' % field in f:
        continue
    f = re.sub(r'(<input id="f-%s"[^>]*>)' % field, r'\1\n          <p class="form__err" data-si-error="%s" aria-live="polite"></p>' % field, f, count=1)
if 'name="agree"' not in f:
    sys.exit("В форме нет галочки согласия name=\"agree\" — без неё SendIt не примет заявку (152-ФЗ)")
CHUNKS["fc.contacts"] = f

# подвал и скрипты
CHUNKS["fc.footer"] = common(one(r'(<footer class="footer".*?</footer>)', body, "подвал").group(1))
CHUNKS["fc.scripts"] = common(one(r"</footer>\n\n(.*)", body, "скрипты после подвала").group(1).strip("\n"))

# --- <head>: SEO из полей страницы, JSON-LD — отдельным чанком
ld = one(r'(  <script type="application/ld\+json">.*?</script>)', head, "JSON-LD").group(1)
h = head.replace(ld + "\n", "").replace(ld, "")
h = re.sub(r"  <!-- SEO: замените https://example.ru[^\n]*-->\n", "", h)
h = re.sub(r"<title>.*?</title>", "<title>[[*longtitle:default=`[[*pagetitle]]`:htmlent]]</title>", h)
# код подтверждения Яндекс Вебмастера — из «Конфигурации» → «Аналитика»: вписали — метка появилась
h = must_replace(h, '  <!-- Подтверждение прав в Яндекс Вебмастере: вставьте свой код -->\n  <!-- <meta name="yandex-verification" content="ВАШ_КОД"> -->',
                 '  [[++fc_yandex_verification:notempty=`<meta name="yandex-verification" content="[[++fc_yandex_verification]]">`]]', "yandex-verification")
h = re.sub(r'<meta name="description" content="[^"]*">', '<meta name="description" content="[[*description:htmlent]]">', h)
h = re.sub(r'<link rel="canonical" href="[^"]*">', '<link rel="canonical" href="[[~[[*id]]? &scheme=`full`]]">', h)
h = re.sub(r'<meta property="og:title" content="[^"]*">', '<meta property="og:title" content="[[*longtitle:default=`[[*pagetitle]]`:htmlent]]">', h)
h = re.sub(r'<meta property="og:description" content="[^"]*">', '<meta property="og:description" content="[[*introtext:default=`[[*description]]`:htmlent]]">', h)
h = re.sub(r'<meta property="og:url" content="[^"]*">', '<meta property="og:url" content="[[~[[*id]]? &scheme=`full`]]">', h)
h = h.replace("https://example.ru/images/", "[[++site_url]]assets/images/").replace("https://example.ru/", "[[++site_url]]")
CHUNKS["fc.head"] = common(h)

ld = ld.replace("https://example.ru/images/", "[[++site_url]]assets/images/").replace("https://example.ru/", "[[++site_url]]")
ld = must_replace(ld, '"legalName": "%s"' % LEGAL["fc_legal_name"], '"legalName": "[[++fc_legal_name]]"', "legalName")
ld = must_replace(ld, '"taxID": "%s"' % LEGAL["fc_inn"], '"taxID": "[[++fc_inn]]"', "taxID")
ld = ld.replace('"telephone": "%s"' % CONTACTS["phoneHref"], '"telephone": "[[++fc_phone_raw]]"')
ld = must_replace(ld, '"sameAs": ["%s"]' % CONTACTS["telegram"], '"email": "[[++fc_email]]",\n        "sameAs": ["[[++fc_telegram_url]]", "[[++fc_max_url]]"]', "sameAs")
ld = re.sub(r'"priceRange": "[^"]*"', '"priceRange": "[[++fc_price_200]]–[[++fc_price_300]] ₽"', ld)
ld = re.sub(r'("hasMenuItem": \[\n).*?(\n            \])', r"\1              [[fcMenuLd]]\2", ld, count=1, flags=re.S)
ld = re.sub(r'("@type": "FAQPage",\n        "mainEntity": \[\n).*?(\n        \])', r"\1          [[fcFaq? &mode=`ld`]]\2", ld, count=1, flags=re.S)
if "[[fcMenuLd]]" not in ld or "[[fcFaq? &mode=`ld`]]" not in ld:
    sys.exit("JSON-LD: не нашёл меню или FAQ — index.html изменился, поправьте tools/modx_build.py")
CHUNKS["fc.jsonld"] = ld

for k, v in CHUNKS.items():
    if "example.ru" in v:
        sys.exit("В чанке %s остался example.ru" % k)

# --- шаблон главной
tpl_home = ("<!doctype html>\n<html lang=\"ru\">\n<head>\n[[$fc.head]]\n[[$fc.jsonld]]\n</head>\n<body id=\"top\">\n\n[[$fc.header]]\n\n<main id=\"main\">\n\n"
            + "\n\n".join("[[$%s]]" % n for n in order) + "\n\n</main>\n\n[[$fc.footer]]\n\n[[$fc.scripts]]\n</body>\n</html>\n")

# ---------------------------------------------------------------- пакет modx/
if os.path.isdir(OUT):
    shutil.rmtree(OUT)
os.makedirs(OUT)

CAT_PAGE, CAT_SERVICE = "Главная страница", "Служебные"
chunk_list = []
for n in ["fc.head", "fc.jsonld", "fc.header"] + order + ["fc.footer", "fc.scripts"]:
    write("chunks/%s.html" % n, CHUNKS[n])
    chunk_list.append({"name": n, "category": CAT_PAGE, "file": "chunks/%s.html" % n})
for f in sorted(os.listdir(os.path.join(SRC, "chunks"))):
    shutil.copy(os.path.join(SRC, "chunks", f), os.path.join(OUT, "chunks", f))
    chunk_list.append({"name": f[:-5], "category": CAT_SERVICE, "file": "chunks/" + f})

write("templates/fc-home.html", tpl_home)
os.makedirs(os.path.join(OUT, "templates"), exist_ok=True)
for f in ("fc-page.html", "fc-section.html"):
    shutil.copy(os.path.join(SRC, "templates", f), os.path.join(OUT, "templates", f))
templates = [
    {"name": "fc-home", "file": "templates/fc-home.html", "description": "Главная: карта, меню, бизнесу, вопросы, контакты"},
    {"name": "fc-page", "file": "templates/fc-page.html", "description": "Текстовая страница: политика конфиденциальности и т. п."},
    {"name": "fc-section", "file": "templates/fc-section.html", "description": "Служебные разделы «Меню» и «Точки» (на сайте — переход на главную)"},
    {"name": "fc-drink", "file": "templates/fc-section.html", "description": "Напиток в меню: поля описание, картинка, слои стаканчика"},
    {"name": "fc-point", "file": "templates/fc-section.html", "description": "Точка на карте: адрес, часы, координаты"},
]


def doc_of(path):
    m = re.search(r"/\*\*?\s*\n?\s*\*?\s*(\w+)\s+—\s+([^\n]*)", io.open(path, encoding="utf-8").read())
    return m.group(2).strip().rstrip(".") if m else ""


snippets, plugins = [], []
for f in sorted(os.listdir(os.path.join(SRC, "snippets"))):
    os.makedirs(os.path.join(OUT, "snippets"), exist_ok=True)
    shutil.copy(os.path.join(SRC, "snippets", f), os.path.join(OUT, "snippets", f))
    snippets.append({"name": f[:-4], "file": "snippets/" + f, "description": doc_of(os.path.join(SRC, "snippets", f))[:255]})
for f in sorted(os.listdir(os.path.join(SRC, "plugins"))):
    os.makedirs(os.path.join(OUT, "plugins"), exist_ok=True)
    src = os.path.join(SRC, "plugins", f)
    shutil.copy(src, os.path.join(OUT, "plugins", f))
    ev = one(r"@events\s+([^\n]+)", io.open(src, encoding="utf-8").read(), "@events в " + f).group(1)
    plugins.append({"name": f[:-4], "file": "plugins/" + f, "description": doc_of(src)[:255], "events": [e.strip() for e in ev.split(",")]})
os.makedirs(os.path.join(OUT, "sendit"), exist_ok=True)
shutil.copy(os.path.join(SRC, "sendit", "fastcup.inc.php"), os.path.join(OUT, "sendit", "fastcup.inc.php"))

TVS = [
    {"name": "fc_faq", "caption": "Частые вопросы", "type": "textarea", "templates": ["fc-home"], "category": "Поля",
     "description": "По одному вопросу в строке: «Вопрос | Ответ». Выводятся в блоке «Частые вопросы» и в разметке для поисковиков", "elements": "", "default": ""},
    {"name": "fc_drink_desc", "caption": "Описание напитка", "type": "text", "templates": ["fc-drink"], "category": "Поля",
     "description": "Одна строка под названием в меню, например «Эспрессо, горячее молоко и плотная пенка»", "elements": "", "default": ""},
    {"name": "fc_drink_img", "caption": "Картинка напитка (SVG или PNG)", "type": "image", "templates": ["fc-drink"], "category": "Поля",
     "description": "Необязательно. Пусто — нарисуем стаканчик по слоям ниже. Готовые картинки всех напитков: images/export/svg и images/export/png",
     "elements": "", "default": ""},
    {"name": "fc_drink_layers", "caption": "Слои стаканчика", "type": "textarea", "templates": ["fc-drink"], "category": "Поля",
     "description": "Если нет картинки. По одному слою в строке, снизу вверх: «высота цвет», например «26.4 #3B1F14». Высота всего стакана — 76.6",
     "elements": "", "default": "26.4 #3B1F14\n25.7 #F1DFC8\n25.7 #FFF9F0"},
    {"name": "fc_point_address", "caption": "Адрес", "type": "text", "templates": ["fc-point"], "category": "Поля",
     "description": "Как показывать на сайте: «ул. Ленина, 50, ТЦ „Пример“, 1 этаж»", "elements": "", "default": ""},
    {"name": "fc_point_hours", "caption": "Часы работы", "type": "text", "templates": ["fc-point"], "category": "Поля",
     "description": "Например «Круглосуточно» или «07:00–23:00»", "elements": "", "default": "Круглосуточно"},
    {"name": "fc_point_coords", "caption": "Координаты", "type": "text", "templates": ["fc-point"], "category": "Поля",
     "description": "«широта, долгота», например «58.0119, 56.2452». В Яндекс Картах: правый клик по месту → «Что здесь?» → скопировать числа",
     "elements": "", "default": ""},
    {"name": "fc_point_promo", "caption": "Акция в этой точке", "type": "text", "templates": ["fc-point"], "category": "Поля",
     "description": "Необязательно. Видна в приложении, если включены акции («Конфигурация» → «Приложение»). Например «Капучино 300 мл за 120 ₽ до 11:00»",
     "elements": "", "default": ""},
    {"name": "fc_point_price_200", "caption": "Цена 200 мл здесь, ₽", "type": "text", "templates": ["fc-point"], "category": "Поля",
     "description": "Необязательно: только если в этой точке цена не как везде. Только число", "elements": "", "default": ""},
    {"name": "fc_point_price_300", "caption": "Цена 300 мл здесь, ₽", "type": "text", "templates": ["fc-point"], "category": "Поля",
     "description": "Необязательно: только если в этой точке цена не как везде. Только число", "elements": "", "default": ""},
]
SETTINGS = [
    {"key": "fc_phone", "value": CONTACTS["phone"], "name": "Телефон", "cc": "Контакты", "help": "Как показывать на сайте: +7 (966) 790-12-46"},
    {"key": "fc_phone_raw", "value": CONTACTS["phoneHref"], "name": "Телефон для ссылки", "cc": "Контакты", "help": "Только + и цифры: +79667901246"},
    {"key": "fc_email", "value": CONTACTS["email"], "name": "Почта", "cc": "Контакты",
     "help": "Показывается на сайте, на неё же приходят заявки с формы (сразу после сохранения)"},
    {"key": "fc_max_url", "value": CONTACTS["max"], "name": "Ссылка на MAX", "cc": "Контакты", "help": "Полная ссылка https://max.ru/…"},
    {"key": "fc_telegram_url", "value": CONTACTS["telegram"], "name": "Ссылка на Telegram", "cc": "Контакты", "help": "Полная ссылка https://t.me/…"},
    {"key": "fc_price_200", "value": PRICE200, "name": "Цена 200 мл, ₽", "cc": "Цены и карта", "help": "Только число: 100"},
    {"key": "fc_price_300", "value": PRICE300, "name": "Цена 300 мл, ₽", "cc": "Цены и карта", "help": "Только число: 150"},
    {"key": "fc_legal_name", "value": LEGAL["fc_legal_name"], "name": "Оператор персональных данных", "cc": "Реквизиты",
     "help": "Полное наименование: «Индивидуальный предприниматель …». В подвале сокращается до «ИП …»"},
    {"key": "fc_inn", "value": LEGAL["fc_inn"], "name": "ИНН", "cc": "Реквизиты", "help": "Только цифры"},
    {"key": "fc_ogrnip", "value": LEGAL["fc_ogrnip"], "name": "ОГРНИП", "cc": "Реквизиты", "help": "Только цифры"},
    {"key": "fc_pd_email", "value": LEGAL["fc_pd_email"], "name": "Почта для обращений по персональным данным", "cc": "Реквизиты",
     "help": "Указывается в политике и согласии"},
    {"key": "fc_ym_id", "value": "", "name": "Номер счётчика Яндекс Метрики", "cc": "Аналитика",
     "help": "Только цифры. Пусто — Метрика не подключается. Счётчик грузится только после согласия посетителя на аналитические cookie"},
    {"key": "fc_yandex_verification", "value": "", "name": "Код подтверждения Яндекс Вебмастера", "cc": "Аналитика",
     "help": "Только код из метатега, который выдаёт Вебмастер (content=\"…\"). Пусто — метки нет"},
    {"key": "fc_cookie_ver", "value": "2026-10-18", "name": "Версия политики cookie", "cc": "Аналитика",
     "help": "Дата. Поменяли состав cookie (например, подключили Метрику) — поменяйте дату: баннер снова спросит согласие"},
    {"key": "fc_map_center", "value": CENTER, "name": "Центр карты", "cc": "Цены и карта", "help": "«широта, долгота» — если точек нет: 58.0105, 56.2294"},
    {"key": "fc_promos", "value": "1" if FEATURES.get("promos") else "0", "name": "Акции и цены точек в приложении", "cc": "Приложение", "xtype": "xcheckbox",
     "help": "Значок «%» на метке, акция и цена в карточке, фильтры «Только с акциями» и «Сначала дешевле». Акции — в поле точки «Акция в этой точке»"},
    {"key": "fc_open_now", "value": "1" if FEATURES.get("openNow") else "0", "name": "«Открыто / Закрыто» в приложении", "cc": "Приложение", "xtype": "xcheckbox",
     "help": "По часам работы точки: «Круглосуточно» или «07:00–23:00»"},
    {"key": "fc_menu_id", "value": "", "name": "ID раздела «Меню»", "cc": "", "help": "Заполняет установщик"},
    {"key": "fc_points_id", "value": "", "name": "ID раздела «Точки»", "cc": "", "help": "Заполняет установщик"},
    {"key": "fc_policy_id", "value": "", "name": "ID страницы политики", "cc": "", "help": "Заполняет установщик"},
    {"key": "fc_agree_id", "value": "", "name": "ID страницы согласия", "cc": "", "help": "Заполняет установщик"},
    {"key": "fc_cookie_id", "value": "", "name": "ID страницы политики cookie", "cc": "", "help": "Заполняет установщик"},
]

RES = [{
    "alias": "index", "parent": "", "home": True, "template": "fc-home", "richtext": 0,
    "fields": {"pagetitle": "Главная", "longtitle": SEO["title"], "description": SEO["description"], "introtext": SEO["og_description"],
               "menutitle": "Главная", "content": ""},
    "tvs": {"fc_faq": "\n".join("%s | %s" % qa for qa in FAQ)},
}, {
    "alias": "menu", "parent": "", "template": "fc-section", "isfolder": 1, "hidemenu": 1, "searchable": 0,
    "fields": {"pagetitle": "Меню (напитки)", "description": "Напитки на главной. Добавить напиток: правый клик по разделу → «Создать документ». Порядок — перетаскиванием"},
    "tvs": {},
}]
for d in DRINKS:
    RES.append({"alias": alias(d["name"]), "parent": "menu", "template": "fc-drink", "hidemenu": 1, "searchable": 0,
                "fields": {"pagetitle": d["name"]}, "tvs": {"fc_drink_desc": d["desc"], "fc_drink_layers": d["layers"]}})
RES.append({"alias": "tochki", "parent": "", "template": "fc-section", "isfolder": 1, "hidemenu": 1, "searchable": 0,
            "fields": {"pagetitle": "Точки (автоматы на карте)", "description": "Точки на карте сайта и в приложении. Сейчас — ПРИМЕРЫ адресов: замените на свои"},
            "tvs": {}})
for p in POINTS:
    RES.append({"alias": alias(p["title"]), "parent": "tochki", "template": "fc-point", "hidemenu": 1, "searchable": 0,
                "fields": {"pagetitle": p["title"]}, "tvs": {"fc_point_address": p["address"], "fc_point_hours": p["hours"], "fc_point_coords": p["coords"]}})
for a_, (t_, d_) in build_legal.PAGES.items():   # документы: политика, согласие, cookie — тексты из tools/modx/content/
    RES.append({"alias": a_, "parent": "", "template": "fc-page", "hidemenu": 1, "richtext": 1,
                "fields": {"pagetitle": t_, "longtitle": t_ + " — Fastcup", "description": d_,
                           "content": io.open(os.path.join(SRC, "content", a_ + ".html"), encoding="utf-8").read().strip()},
                "tvs": {}})
RES += [{
    # короткий адрес приложения для рекламы и визиток: ваш-домен/app → само приложение в assets/app/
    "alias": "app", "parent": "", "template": "", "class": "weblink", "hidemenu": 1, "searchable": 0, "always": True,
    "fields": {"pagetitle": "Приложение (короткий адрес /app)", "content": "/assets/app/",
               "description": "Перенаправляет на приложение Fastcup. Не удаляйте: адрес /app может быть на визитках и в рекламе"},
    "tvs": {},
}, {
    "alias": "robots", "parent": "", "template": "", "hidemenu": 1, "searchable": 0, "always": True, "content_type": "text/plain",
    "fields": {"pagetitle": "robots.txt", "content": "User-agent: *\nAllow: /\nDisallow: /manager/\nDisallow: /fastcup-modx/\n\nSitemap: [[++site_url]]sitemap.xml"},
    "tvs": {},
}, {
    "alias": "sitemap", "parent": "", "template": "", "hidemenu": 1, "searchable": 0, "always": True, "content_type": "text/xml",
    "fields": {"pagetitle": "sitemap.xml",
               "content": "[[pdoSitemap? &parents=`0,-[[++fc_menu_id]],-[[++fc_points_id]]` &resources=`-[[++fc_menu_id]],-[[++fc_points_id]]` &where=`{ \"searchable\": 1 }` &showHidden=`1`]]"},
    "tvs": {},
}]
write("resources.json", json.dumps(RES, ensure_ascii=False, indent=1))

manifest = {
    "top": "Fastcup", "email": CONTACTS["email"],
    "version": __import__("datetime").datetime.now().strftime("%Y-%m-%d %H:%M"),   # версия пакета — дата сборки, видна в отчёте установщика
    "chunks": chunk_list, "templates": templates, "snippets": snippets, "plugins": plugins, "tvs": TVS, "settings": SETTINGS,
    "cc_groups": {"Контакты": "Телефон, почта, MAX и Telegram — на сайте, в форме и в приложении",
                  "Цены и карта": "Цены на напитки 200 и 300 мл и центр карты",
                  "Реквизиты": "Оператор персональных данных для подвала, политики и согласия (домашний адрес не публикуется)",
                  "Аналитика": "Яндекс Метрика и баннер cookie",
                  "Приложение": "Дополнительные возможности приложения /app/ — по умолчанию выключены"},
    "managed_files": ["assets/js/points.js", "assets/js/config.js"],   # их пересоздаёт MODX: из архива — только если на сервере их ещё нет
    "id_settings": {"fc_menu_id": "menu", "fc_points_id": "tochki", "fc_policy_id": "politika", "fc_agree_id": "soglasie", "fc_cookie_id": "cookie"},
}
tpl = io.open(os.path.join(SRC, "fc-install.inc.php.tpl"), encoding="utf-8").read()
write("fc-install.inc.php", tpl.replace("%%MANIFEST%%", json.dumps(manifest, ensure_ascii=False, indent=1)))
for f in ("fc-install.php", "INSTALL.md"):
    shutil.copy(os.path.join(SRC, f), os.path.join(OUT, f))
shutil.copy(os.path.join(SRC, "htaccess.txt"), os.path.join(OUT, ".htaccess"))   # папка установщика закрыта от браузера, кроме fc-install.php

# ---------------------------------------------------------------- архив: пакет + файлы сайта
SITE = ["css", "js", "app", "fonts", os.path.join("images", "web")]
# Приложение лежит в assets/app/: относительные пути ../js, ../images остаются верными, а ссылки на страницы сайта — от корня
SITE_FIX = {
    "app/index.html": [('href="../politika"', 'href="/politika"'), ('href="../cookie"', 'href="/cookie"'),
                       ('<a class="bar__brand" href="../"', '<a class="bar__brand" href="/"')],
    "js/config.js": [('assetsBase: ""', 'assetsBase: "/assets/"')],
}
os.makedirs(DIST, exist_ok=True)
zpath = os.path.join(DIST, PKG + ".zip")
n_site = 0
with zipfile.ZipFile(zpath, "w", zipfile.ZIP_DEFLATED, compresslevel=6) as z:
    for base, _, files in os.walk(OUT):
        for f in files:
            full = os.path.join(base, f)
            z.write(full, PKG + "/" + os.path.relpath(full, OUT).replace("\\", "/"))
    for d in SITE:
        for base, _, files in os.walk(os.path.join(ROOT, d)):
            if "__pycache__" in base:
                continue
            for f in files:
                full = os.path.join(base, f)
                rel = os.path.relpath(full, ROOT).replace("\\", "/")
                arc = PKG + "/site/assets/" + rel   # в MODX все файлы сайта — в assets/
                if rel in SITE_FIX:   # файлы, где в MODX нужны другие адреса
                    text = io.open(full, encoding="utf-8").read()
                    for a_, b_ in SITE_FIX[rel]:
                        if a_ not in text:
                            sys.exit("%s: не нашёл «%s» — поправьте SITE_FIX в tools/modx_build.py" % (rel, a_))
                        text = text.replace(a_, b_)
                    z.writestr(arc, text)
                else:
                    z.write(full, arc)
                n_site += 1

print("Пакет MODX: modx/ — чанков %d, шаблонов %d, сниппетов %d, плагинов %d, полей %d, страниц %d (напитков %d, точек %d, вопросов %d)"
      % (len(chunk_list), len(templates), len(snippets), len(plugins), len(TVS), len(RES), len(DRINKS), len(POINTS), len(FAQ)))
print("Архив для хостинга: %s — %.1f МБ, файлов сайта %d" % (os.path.relpath(zpath, ROOT), os.path.getsize(zpath) / 1048576, n_site))
