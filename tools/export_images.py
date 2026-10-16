"""
Выгружает графику сайта отдельными файлами: images/export/svg/… и images/export/png/… (PNG — с прозрачным фоном, в 4 раза крупнее).

  drinks/   стаканчики всех напитков из меню (как на сайте) — для поля «Картинка напитка» в MODX или макетов
  icons/    иконки шагов «Как это работает», сервиса, контактов, метка на карте, стаканчик из блока «Свежемолотый кофе»
  logo/     логотип Fastcup в векторе (обведён из images/logo4.jpg): надпись и надпись с «кофе с собой», тёмный и светлый
  photos/   (только PNG) фотографии сайта — из сжатых копий images/web; в SVG фото не переводятся

Запуск: python tools/export_images.py   (нужны numpy, potracer, playwright; рендерит установленный Chrome)
"""
import html
import io
import os
import re
import subprocess
import sys

import numpy as np
import potrace

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, "images", "export")
page = io.open(os.path.join(ROOT, "index.html"), encoding="utf-8").read()
FOAM, INK, APRICOT = "#FFF6EC", "#2A1A14", "#F6A65A"
SUNSET = ('<linearGradient id="sun" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#F8B867"/><stop offset=".45" stop-color="#EF8458"/>'
          '<stop offset=".78" stop-color="#C9505A"/><stop offset="1" stop-color="#6B2E47"/></linearGradient>')
svgs = {}   # "папка/имя" → (svg, ширина, высота)


def add(name, svg, w, h):
    svgs[name] = (svg, w, h)


def slug(name):
    t = dict(zip("абвгдеёжзийклмнопрстуфхцчшщъыьэюя", ["a", "b", "v", "g", "d", "e", "e", "zh", "z", "i", "y", "k", "l", "m", "n", "o", "p", "r", "s",
                                                     "t", "u", "f", "h", "ts", "ch", "sh", "sch", "", "y", "", "e", "yu", "ya"]))
    return re.sub(r"[^a-z0-9]+", "-", "".join(t.get(c, c) for c in name.lower())).strip("-")


# --- стаканчики напитков: те же слои, что на сайте
for i, li in enumerate(re.findall(r'<li class="drink">(.*?)</li>', page, re.S)):
    name = html.unescape(re.search(r'<h3 class="drink__name">(.*?)</h3>', li).group(1))
    rects = "".join(re.findall(r'<rect x="0" y="[\d.]+" width="80" height="[\d.]+" fill="#[0-9A-Fa-f]+"/>', li))
    add("drinks/" + slug(name),
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 100" width="80" height="100"><title>%s</title>'
        '<defs><clipPath id="c"><path d="M11 20h58l-6.5 76h-45z"/></clipPath></defs><g clip-path="url(#c)">%s<path d="M11 20h58l-1 9h-56z" fill="#fff" opacity=".12"/></g>'
        '<path d="M11 20h58l-6.5 76h-45z" fill="none" stroke="%s" stroke-opacity=".85" stroke-width="2.4" stroke-linejoin="round"/></svg>' % (html.escape(name), rects, FOAM),
        80, 100)

# --- шаги «Как это работает»: белые линии на закатной плитке, как на сайте
for n, inner in enumerate(re.findall(r'<svg class="step__icon" viewBox="0 0 64 64" aria-hidden="true">(.*?)</svg>', page, re.S), 1):
    inner = inner.replace('class="fill"', 'fill="#fff"').replace(' class="wave"', "")
    add("icons/step-%d" % n,
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 104 104" width="104" height="104"><defs>%s</defs><rect width="104" height="104" rx="32" fill="url(#sun)"/>'
        '<g transform="translate(24 24) scale(.875)" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">%s</g></svg>' % (SUNSET, inner.strip()),
        104, 104)

# --- сервис и контакты: абрикосовые линии
service = re.search(r'<ul class="service">(.*?)</ul>', page, re.S).group(1)
for name, inner in zip(["zagruzka", "chistka", "remont"], re.findall(r'<svg viewBox="0 0 24 24" aria-hidden="true">(.*?)</svg>', service)):
    add("icons/service-" + name, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><g fill="none" stroke="%s" stroke-width="1.8" '
        'stroke-linecap="round" stroke-linejoin="round">%s</g></svg>' % (APRICOT, inner), 24, 24)
channels = re.search(r'<ul class="channels">(.*?)</ul>', page, re.S).group(1)
for name, inner in zip(["phone", "telegram", "max", "email"], re.findall(r'<svg viewBox="0 0 24 24" aria-hidden="true">(.*?)</svg>', channels)):
    add("icons/contact-" + name, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><g fill="none" stroke="%s" stroke-width="1.8" '
        'stroke-linecap="round" stroke-linejoin="round">%s</g></svg>' % (APRICOT, inner), 24, 24)

# --- метка на карте (из js/omap.js)
pin = "".join(re.findall(r"'([^']*)'", re.search(r"FastcupMap\.PIN =\s*(.*?);\n", io.open(os.path.join(ROOT, "js", "omap.js"), encoding="utf-8").read(), re.S).group(1)))
add("icons/map-pin", pin.replace('<svg class="fc-pin" viewBox="0 0 46 56" aria-hidden="true">', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 46 56" width="46" height="56">'), 46, 56)


# --- логотип: обводим контуры (potrace) — настоящий вектор
def pgm(path, width, crop=None):
    args = ["magick", path, "-colorspace", "gray"] + (["-fill", "white", "-draw", "rectangle %d,%d %d,%d" % crop] if crop else []) + \
           ["-trim", "+repage", "-resize", "%dx" % width, "-depth", "8", "pgm:-"]
    raw = subprocess.run(args, capture_output=True, check=True).stdout
    m = re.match(rb"P5\s+(\d+)\s+(\d+)\s+(\d+)\s", raw)
    w, h = int(m.group(1)), int(m.group(2))
    return np.frombuffer(raw[m.end():], dtype=np.uint8).reshape(h, w)


def trace(img):
    bm = potrace.Bitmap(img > 140)   # светлое — фон, тёмное — буквы
    d = []
    for curve in bm.trace(turdsize=4, alphamax=1.0, opticurve=True, opttolerance=0.2):
        s = curve.start_point
        d.append("M%.1f %.1f" % (s.x, s.y))
        for seg in curve.segments:
            if seg.is_corner:
                d.append("L%.1f %.1fL%.1f %.1f" % (seg.c.x, seg.c.y, seg.end_point.x, seg.end_point.y))
            else:
                d.append("C%.1f %.1f %.1f %.1f %.1f %.1f" % (seg.c1.x, seg.c1.y, seg.c2.x, seg.c2.y, seg.end_point.x, seg.end_point.y))
        d.append("Z")
    return "".join(d), img.shape[1], img.shape[0]


src = os.path.join(ROOT, "images", "logo4.jpg")
logo_full = trace(pgm(src, 1400))
logo_word = trace(pgm(src, 1400, crop=(1250, 700, 2954, 946)))   # без «кофе с собой» (подпись — справа внизу)
for kind, (d, w, h) in (("full", logo_full), ("word", logo_word)):
    for tone, color in (("dark", INK), ("light", FOAM)):
        add("logo/logo-%s-%s" % (kind, tone), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d"><title>Fastcup</title>'
            '<path fill="%s" fill-rule="evenodd" d="%s"/></svg>' % (w, h, w, h, color, d), w, h)

# --- стаканчик из блока «Свежемолотый кофе»: логотип на рукаве — вектором вместо картинки
cup = re.search(r'(<svg class="cup3d".*?</svg>)', page, re.S).group(1)
d, w, h = logo_word
k = min(96 / w, 43 / h)
cup = re.sub(r'<image class="cup3d__logo"[^>]*/>', '<path transform="translate(%.2f %.2f) scale(%.4f)" fill="%s" fill-rule="evenodd" d="%s"/>'
             % (52 + (96 - w * k) / 2, 126 + (43 - h * k) / 2, k, FOAM, d), cup)
cup = re.sub(r'<image class="cup3d__sub"[^>]*/>', "", cup)
cup = cup.replace('<svg class="cup3d" viewBox="0 0 200 260" role="img" aria-label="Стаканчик Fastcup">',
                  '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 260" width="200" height="260"><title>Стаканчик Fastcup</title>')
cup = re.sub(r'<g class="cup3d__steam"[^>]*>.*?</g>', "", cup, flags=re.S)
add("icons/cup-fastcup", cup, 200, 260)

# ---------------------------------------------------------------- запись SVG и PNG
for name, (svg, w, h) in svgs.items():
    path = os.path.join(OUT, "svg", name + ".svg")
    os.makedirs(os.path.dirname(path), exist_ok=True)
    io.open(path, "w", encoding="utf-8", newline="\n").write(svg + "\n")

from playwright.sync_api import sync_playwright

SCALE = 4
with sync_playwright() as pw:
    try:
        br = pw.chromium.launch(channel="chrome")
    except Exception:
        br = pw.chromium.launch()
    pg = br.new_page(device_scale_factor=SCALE)
    for name, (svg, w, h) in svgs.items():
        pg.set_viewport_size({"width": w, "height": h})
        pg.set_content('<html><body style="margin:0;background:transparent">%s</body></html>' % svg.replace("<svg ", '<svg style="display:block" ', 1))
        path = os.path.join(OUT, "png", name + ".png")
        os.makedirs(os.path.dirname(path), exist_ok=True)
        pg.screenshot(path=path, omit_background=True, clip={"x": 0, "y": 0, "width": w, "height": h})
    br.close()

# фото — только PNG (из сжатых копий сайта; исходники в images/ не трогаем)
PHOTOS = ["banner-cup.webp", "cup-logo.webp", "vending.webp", "vending-interior.jpg", "splash.webp", "beans.webp", "bonus.webp"]
os.makedirs(os.path.join(OUT, "png", "photos"), exist_ok=True)
for f in PHOTOS:
    subprocess.run(["magick", os.path.join(ROOT, "images", "web", f), "-strip", "-define", "png:compression-level=9",
                    os.path.join(OUT, "png", "photos", os.path.splitext(f)[0] + ".png")], check=True)

size = sum(os.path.getsize(os.path.join(b, f)) for b, _, fs in os.walk(OUT) for f in fs)
print("Выгружено: SVG %d, PNG %d (+ фото %d) — images/export, %.1f МБ" % (len(svgs), len(svgs), len(PHOTOS), size / 1048576))
