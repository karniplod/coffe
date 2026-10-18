"""
Страницы документов для сайта без MODX: politika.html, soglasie.html, cookie.html в корне сайта.

Тексты — те же, что уходят в MODX: tools/modx/content/{politika,soglasie,cookie}.html (в них теги MODX [[++fc_…]]).
Здесь теги заменяются значениями из js/config.js (реквизиты, почта для обращений), ссылки — на соседние .html.
Подвал берётся из index.html — как на главной.

Запуск: python tools/build_legal.py (его же вызывает tools/modx_build.py). Поменяли тексты или реквизиты — запустите снова.
"""
import html
import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, "tools", "modx", "content")

PAGES = {
    "politika": ("Политика обработки персональных данных", "Как Fastcup обрабатывает персональные данные посетителей сайта и приложения: какие данные, зачем, сроки, ваши права."),
    "soglasie": ("Согласие на обработку персональных данных", "Текст согласия на обработку персональных данных, которое вы даёте, отправляя заявку на сайте Fastcup."),
    "cookie": ("Политика cookie", "Какие cookie использует сайт Fastcup, зачем, сколько хранятся и как изменить своё решение. Аналитика — только с вашего согласия."),
}
LINKS = {"fc_policy_id": "politika.html", "fc_agree_id": "soglasie.html", "fc_cookie_id": "cookie.html"}


def read(rel):
    return io.open(os.path.join(ROOT, rel), encoding="utf-8").read()


def config():
    js = read("js/config.js")
    get = lambda k: (re.search(r'%s:\s*"([^"]*)"' % k, js) or [None, ""])[1]
    return {"fc_legal_name": get("name"), "fc_inn": get("inn"), "fc_ogrnip": get("ogrnip"), "fc_pd_email": get("pdEmail"),
            "fc_email": get("email"), "fc_phone": get("phone")}


def build():
    cfg = config()
    if not cfg["fc_inn"]:
        sys.exit("В js/config.js нет реквизитов (legal: { name, inn, ogrnip, pdEmail })")
    index = read("index.html")
    footer = re.search(r'(<footer class="footer".*?</footer>)', index, re.S).group(1)
    made = []
    for alias, (title, desc) in PAGES.items():
        body = io.open(os.path.join(SRC, alias + ".html"), encoding="utf-8").read().strip()
        body = re.sub(r"\[\[~\[\[\+\+(\w+)\]\]\]\]", lambda m: LINKS[m.group(1)], body)
        body = re.sub(r"\[\[\+\+(\w+)\]\]", lambda m: html.escape(cfg[m.group(1)]), body)
        if "[[" in body:
            sys.exit("%s: остался тег MODX — добавьте его в build_legal.py" % alias)
        page = f"""<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <!-- Страница создана tools/build_legal.py из tools/modx/content/{alias}.html — правьте там -->
  <title>{html.escape(title)} — Fastcup</title>
  <meta name="description" content="{html.escape(desc)}">
  <meta name="robots" content="index, follow">
  <meta name="theme-color" content="#1E120E">
  <link rel="icon" href="images/web/favicon.png">
  <link rel="preload" href="fonts/inter-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="css/fonts.css">
  <link rel="stylesheet" href="css/style.css">
</head>
<body class="doc-page">

<a class="skip" href="#main">К содержимому</a>
<header class="doc-head">
  <a class="doc-head__brand" href="index.html" aria-label="Fastcup — на главную"><img src="images/web/logo-word-dark.png" alt="Fastcup" width="480" height="223"></a>
  <a class="doc-head__back" href="index.html">← На главную</a>
</header>

<main class="doc" id="main">
  <h1>{html.escape(title)}</h1>
{body}
</main>

{footer}

<script src="js/config.js"></script>
<script src="js/cookies.js" defer data-fc-cookies data-policy="cookie.html"></script>
</body>
</html>
"""
        io.open(os.path.join(ROOT, alias + ".html"), "w", encoding="utf-8", newline="\n").write(page)
        made.append(alias + ".html")
    return made


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    print("Страницы документов:", ", ".join(build()))
