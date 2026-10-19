(function () {
  "use strict";
  var CFG = window.FASTCUP_CONFIG || {};
  var POINTS = window.FASTCUP_POINTS || [];
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var isTouch = window.matchMedia("(pointer: coarse)").matches;

  /* ---------- контакты из config.js ---------- */
  document.querySelectorAll(".js-phone").forEach(function (a) {
    a.href = "tel:" + CFG.phoneHref;
    var span = a.querySelector("span");
    (span || a).textContent = CFG.phone;
  });
  document.querySelectorAll(".js-tg").forEach(function (a) { a.href = CFG.telegram; });
  document.querySelectorAll(".js-max").forEach(function (a) { a.href = CFG.max; });
  document.querySelectorAll(".js-email").forEach(function (a) {
    a.href = "mailto:" + CFG.email;
    var span = a.querySelector("span");
    (span || a).textContent = CFG.email;
  });
  var y = document.getElementById("year"); if (y) y.textContent = new Date().getFullYear();
  var L = CFG.legal || {};   // реквизиты в подвале — из config.js
  document.querySelectorAll(".js-legal").forEach(function (el) {
    if (L.inn) el.textContent = (L.name || "").replace("Индивидуальный предприниматель", "ИП") + " · ИНН " + L.inn + (L.ogrnip ? " · ОГРНИП " + L.ogrnip : "");
  });

  /* ---------- мобильное меню ---------- */
  var burger = document.querySelector(".burger");
  var mnav = document.getElementById("mnav");
  function setMenu(open) {
    burger.setAttribute("aria-expanded", String(open));
    burger.setAttribute("aria-label", open ? "Закрыть меню" : "Открыть меню");
    mnav.hidden = !open;
  }
  burger.addEventListener("click", function () { setMenu(mnav.hidden); });
  mnav.addEventListener("click", function (e) { if (e.target.tagName === "A") setMenu(false); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !mnav.hidden) { setMenu(false); burger.focus(); } });

  /* ---------- выбор темы заявки по кнопке ---------- */
  document.querySelectorAll("[data-topic]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var r = document.querySelector('input[name="topic"][value="' + btn.dataset.topic + '"]');
      if (r) r.checked = true;
    });
  });

  /* ---------- подсветка меню: «пилюля» едет за курсором ----------
     Меню разделено логотипом на две половины (.nav слева и справа), у каждой своя «пилюля». */
  var navs = Array.prototype.slice.call(document.querySelectorAll(".nav"));
  var navLinks = [];
  navs.forEach(function (nav) { navLinks = navLinks.concat(Array.prototype.slice.call(nav.querySelectorAll("a"))); });
  var activeLink = null;
  // показать «пилюлю» под пунктом a (в его половине меню), в другой половине — убрать; null — убрать везде
  function movePill(a) {
    navs.forEach(function (nav) {
      if (!a || !nav.contains(a)) { nav.classList.remove("has-pill"); return; }
      var pill = nav.querySelector(".nav__pill");
      pill.style.width = a.offsetWidth + "px";
      pill.style.transform = "translateX(" + a.offsetLeft + "px)";
      nav.classList.add("has-pill");
    });
  }
  navLinks.forEach(function (a) {
    a.addEventListener("mouseenter", function () { movePill(a); });
    a.addEventListener("focus", function () { movePill(a); });
  });
  function restPill() { movePill(activeLink); }
  function navHovered() { return navs.some(function (nav) { return nav.matches(":hover"); }); }
  navs.forEach(function (nav) { nav.addEventListener("mouseleave", restPill); });

  /* какой раздел сейчас на экране: первый экран — логотип (он же ссылка наверх), дальше — пункты меню */
  var brand = document.querySelector(".brand");
  var spyTargets = [{ a: brand, el: document.querySelector(".hero") }].concat(navLinks.map(function (a) {
    return { a: a, el: document.getElementById(a.dataset.spy) };
  })).filter(function (t) { return t.a && t.el; });
  /* Шапка: прилипает и поджимается при прокрутке */
  var topbar = document.querySelector(".topbar");
  var barCompact = null;
  // шапка всегда одного цвета (тёмная): при прокрутке внимание на блоках, а не на смене её цвета; меняется только высота
  function updateBar(y) {
    if (!topbar) return;
    var compact = y > 40;
    if (compact !== barCompact) { barCompact = compact; topbar.classList.toggle("is-compact", compact); }
  }

  var totop = document.querySelector(".totop");
  var ring = totop && totop.querySelector(".totop__progress");
  var ticking = false;
  function onScroll() {
    ticking = false;
    var y = window.scrollY, vh = window.innerHeight, cur = null;
    updateBar(y);
    spyTargets.forEach(function (t) { if (t.el.getBoundingClientRect().top <= vh * 0.4) cur = t.a; });
    if (cur !== activeLink) {
      if (activeLink) activeLink.classList.remove("is-active");
      activeLink = cur;
      if (activeLink) activeLink.classList.add("is-active");
      if (!navHovered()) movePill(activeLink);
    }
    if (totop) {
      totop.classList.toggle("is-visible", y > vh * 0.8);
      var max = document.documentElement.scrollHeight - vh;
      ring.style.strokeDashoffset = String(157.08 * (1 - Math.min(1, y / (max || 1))));
    }
  }

  /* Стрелка «наверх» — сразу в самый верх */
  if (totop) totop.addEventListener("click", function (e) {
    e.preventDefault();
    window.scrollTo({ top: 0, behavior: reduceMotion ? "auto" : "smooth" });
  });
  window.addEventListener("scroll", function () { if (!ticking) { ticking = true; requestAnimationFrame(onScroll); } }, { passive: true });
  window.addEventListener("resize", function () { restPill(); onScroll(); });
  // шапка поджалась или расправилась — у пунктов другие отступы, «пилюлю» и середину шапки меряем заново
  if (topbar) topbar.addEventListener("transitionend", function (e) { if (e.target === topbar && e.propertyName === "padding-left") restPill(); });
  onScroll();

  /* =========================================================
     КАРТА: своя офлайн-карта Перми (движок — js/omap.js) + метки автоматов поверх.
     Двигается мышкой, на телефоне — как в приложении: один палец двигает, два — масштаб; не дальше границ города.
     ========================================================= */
  var nearestLabel = document.getElementById("nearestLabel");
  var nearestRoute = document.getElementById("nearestRoute");

  function plural(n, one, few, many) {
    var m10 = n % 10, m100 = n % 100;
    if (m10 === 1 && m100 !== 11) return one;
    if (m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14)) return few;
    return many;
  }
  function distance(a, b) { // метры, формула гаверсинусов
    var R = 6371000, toRad = Math.PI / 180;
    var dLat = (b[0] - a[0]) * toRad, dLon = (b[1] - a[1]) * toRad;
    var h = Math.sin(dLat / 2) ** 2 + Math.cos(a[0] * toRad) * Math.cos(b[0] * toRad) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
  }
  function fmtDist(m) { return m < 1000 ? Math.round(m / 10) * 10 + " м" : (m / 1000).toFixed(1).replace(".", ",") + " км"; }
  function routeUrl(from, to) {
    return "https://yandex.ru/maps/?rtext=" + (from ? from.join(",") : "") + "~" + to.join(",") + "&rtt=pd";
  }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }

  var count = POINTS.length;
  var nearestNext = document.getElementById("nearestNext");
  var nearestBack = document.getElementById("nearestBack");
  var me = null;          // координаты посетителя, если он разрешил геолокацию
  var order = POINTS.map(function (_, i) { return i; }); // порядок точек: ближайшие первыми
  var selected = -1;      // какая точка выбрана сейчас
  var mapEl = document.getElementById("map");
  var omap = null;         // карта (js/omap.js)
  var pins = [];

  nearestLabel.textContent = count + " " + plural(count, "автомат", "автомата", "автоматов") + " в Перми · определяем, где вы";

  /* Выбрать точку: обновить карточку, ссылку маршрута и подсветить метку на карте */
  function selectPoint(i, pan) {
    var p = POINTS[i]; if (!p) return;
    selected = i;
    var dist = me ? distance(me, p.coords) : null;
    var isNearest = me && i === order[0];
    var head = me
      ? (isNearest ? "Ближайший Fastcup" : "Fastcup") + " в <b>" + fmtDist(dist) + "</b> по прямой"
      : "<b>" + esc(p.title) + "</b>";
    nearestLabel.innerHTML = head + "<br>" + esc(p.address) + (p.hours ? " · " + esc(p.hours) : "");
    // Маршрут: от вас (если знаем где вы) до ВЫБРАННОЙ точки, пешком.
    // Без геолокации Яндекс Карты сами подставят ваше местоположение как начало маршрута.
    nearestRoute.href = routeUrl(me, p.coords);
    nearestRoute.hidden = false;
    nearestNext.hidden = count < 2;
    nearestBack.hidden = !me || isNearest;   // выбрана не ближайшая — можно вернуться к ближайшей
    pins.forEach(function (b, k) { b.firstChild.classList.toggle("is-selected", k === i); });
    // показать посетителя и точку вместе; без геолокации — просто подвинуть карту к точке
    if (omap && pan) omap.fit(me ? [me, p.coords] : [p.coords], !me, true);
  }
  nearestNext.addEventListener("click", function () {
    var pos = order.indexOf(selected);
    selectPoint(order[(pos + 1) % order.length], true);
  });
  nearestBack.addEventListener("click", function () { selectPoint(order[0], true); });


  /* Свободная часть карты [сверху, справа, снизу, слева] — карта ставит точки в её середину.
     Видимая часть — между шапкой и панелью с текстом. Метка рисуется над своей точкой (выбранная — ≈ 70 px вверх),
     поэтому сверху отступ больше на высоту метки: тогда в центре оказывается сама метка, а не её острие. */
  var PIN_H = 84;   // выбранная метка увеличена в 1,25 раза (≈ 70 px) + зазор до шапки
  var ME_PAD = 22;  // снизу: точка «вы здесь» (≈ 22 px с ободком) и острие метки не должны уходить под панель
  function panelMargin() {
    var panel = document.querySelector(".hero__panel"), bar = document.querySelector(".topbar");
    var map = mapEl.getBoundingClientRect(), p = panel.getBoundingClientRect();
    var top = Math.max(0, bar.getBoundingClientRect().bottom - map.top) + PIN_H;
    if (window.innerWidth <= 960) {  // панель под картой и заходит на неё снизу (см. @media 960px в style.css)
      var bottom = Math.max(10, map.bottom - p.top) + ME_PAD;
      return [top, 84, bottom, 84];   // справа — кнопки масштаба (метка шириной ≈ 58 px их не задевает); слева столько же — точка по центру
    }
    var cupRight = window.innerWidth > 1080 ? 300 : 90;     // справа место под карточку стаканчика (если видна) и кнопки масштаба
    return [top, cupRight, ME_PAD, p.right - map.left + 40];
  }

  function initMap() {
    omap = FastcupMap(mapEl, { data: window.FASTCUP_MAP, base: CFG.assetsBase || "", oneFinger: true, margin: panelMargin, onError: mapFallback, maxScale: 6, maxFitScale: 2, minFitScale: .08 });   // .08 — можно отдалить, чтобы далёкие точки поместились целиком
    // подсказка, как пользоваться картой — один раз: исчезает при первом касании карты или через 9 секунд
    var seen = false;
    try { seen = !!localStorage.getItem("fc-map-hint"); } catch (e) {}
    if (!seen) {
      var mh = document.createElement("p");
      mh.className = "map-hint";
      mh.textContent = isTouch ? "Палец — двигать · два пальца — масштаб · метка — адрес"
                               : "Тяните карту мышью · двойной щелчок — ближе · метка — адрес";
      mapEl.appendChild(mh);
      try { localStorage.setItem("fc-map-hint", "1"); } catch (e) {}   // показали — больше не показываем
      var hideMh = function () { mh.hidden = true; };
      mapEl.addEventListener("pointerdown", hideMh, { once: true });
      setTimeout(hideMh, 9000);
    }
    // iPhone: щипок по карте масштабирует карту, а не всю страницу
    mapEl.addEventListener("gesturestart", function (e) { e.preventDefault(); }, { passive: false });
    // кнопка «Все точки» под «+» и «−»: вернуть карту к виду со всеми автоматами
    var zoomBox = mapEl.querySelector(".omap__zoom");
    if (zoomBox && count > 1) {
      var allBtn = document.createElement("button");
      allBtn.type = "button";
      allBtn.className = "omap__all";
      allBtn.title = "Показать все точки";
      allBtn.setAttribute("aria-label", "Показать все точки на карте");
      allBtn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>';
      allBtn.addEventListener("click", function () { omap.fit(POINTS.map(function (p) { return p.coords; }), false, true); });
      zoomBox.appendChild(allBtn);
    }
    POINTS.forEach(function (p, i) {
      var b = document.createElement("button");
      b.type = "button";
      b.className = "omap__pin";
      b.title = p.title;
      b.setAttribute("aria-label", p.title + ", " + p.address);
      b.innerHTML = FastcupMap.PIN;
      // клик по метке = выбрать эту точку в карточке слева
      b.addEventListener("click", function () { selectPoint(i, false); });
      pins.push(b);
    });
    omap.fit(count ? POINTS.map(function (p) { return p.coords; }) : [CFG.mapCenter || [58.0105, 56.2294]], false);
    POINTS.forEach(function (p, i) { omap.addMarker(pins[i], p.coords); });

    function noGeo(msg) {
      if (count) selectPoint(0, false);
      nearestLabel.innerHTML = msg + "<br>" + nearestLabel.innerHTML;
    }
    if (!("geolocation" in navigator)) { noGeo("Нажмите на метку, чтобы выбрать точку"); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
      me = [pos.coords.latitude, pos.coords.longitude];
      // сортируем точки по расстоянию от посетителя
      order.sort(function (a, b) { return distance(me, POINTS[a].coords) - distance(me, POINTS[b].coords); });
      if (!count) return;
      if (distance(me, POINTS[order[0]].coords) > 50000) { // человек не в Перми
        me = null;
        noGeo("Вы далеко от Перми, но наши автоматы ждут вас здесь");
        return;
      }
      var dot = document.createElement("div");
      dot.className = "omap__me";
      dot.title = "Вы здесь";
      dot.innerHTML = '<div class="fc-me"></div>';
      omap.addMarker(dot, me);
      selectPoint(order[0], false);
      // первый вид: вы и три ближайших автомата — видно и ближайший, и соседние
      omap.fit([me].concat(order.slice(0, 3).map(function (k) { return POINTS[k].coords; })), false, true);
    }, function () {
      noGeo("Разрешите геопозицию, и мы найдём ближайший автомат");
    }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
  }

  function mapFallback() {
    if (omap) { omap.destroy(); omap = null; }
    var fb = document.getElementById("mapFallback");
    fb.innerHTML = '<p>Карта не загрузилась.<br>Наши точки:</p>';
    var ul = document.createElement("ul");
    ul.style.cssText = "list-style:none;padding:0;margin:16px 0 0;display:grid;gap:8px;font-size:15px;text-align:center";
    POINTS.forEach(function (p) {
      var li = document.createElement("li");
      li.innerHTML = '<a href="' + routeUrl(null, p.coords) + '" target="_blank" rel="noopener">' + esc(p.title) + "</a>";
      ul.appendChild(li);
    });
    fb.firstChild.appendChild(ul);
    if (count) selectPoint(0, false);
  }

  if (!window.FASTCUP_MAP || !window.FastcupMap) mapFallback();
  else initMap();

  /* =========================================================
     СТАКАНЧИК: при наведении за ним «взрывается» кофе
     ========================================================= */
  var section = document.getElementById("beans");
  var stage = document.getElementById("beansStage");
  var canvas = document.getElementById("beansCanvas");
  var hint = section && section.querySelector(".beans__hint");
  if (hint && isTouch) hint.textContent = "Коснитесь стаканчика";
  function setOpen(v) { stage.classList.toggle("is-open", v); }

  if (stage && (reduceMotion || !window.FASTCUP_BEANS)) {
    // без анимации: просто показываем зёрна и логотип
    section.classList.add("no-particles");
    stage.addEventListener("mouseenter", function () { setOpen(true); });
    stage.addEventListener("mouseleave", function () { setOpen(false); });
    stage.addEventListener("click", function () { setOpen(!stage.classList.contains("is-open")); });
  } else if (stage && canvas) {
    var ctx = canvas.getContext("2d");
    var img = new Image();
    var parts = [], W = 0, H = 0, dpr = 1, imgData, buf32, running = false, visible = false, open = false;
    var mouse = { x: -9999, y: -9999, r: 80, active: false };
    var cx = 0, cy = 0;

    function build() {
      var rect = canvas.getBoundingClientRect();
      dpr = Math.min(window.devicePixelRatio || 1, 2);
      W = Math.round(rect.width * dpr); H = Math.round(rect.height * dpr);
      canvas.width = W; canvas.height = H;
      cx = W / 2; cy = H / 2;
      var inner = W / 1.24;
      var scale = Math.min(inner / img.width, (H / 1.24) / img.height);
      var iw = img.width * scale, ih = img.height * scale;
      var tmp = document.createElement("canvas"); tmp.width = W; tmp.height = H;
      var t = tmp.getContext("2d"); t.drawImage(img, (W - iw) / 2, (H - ih) / 2, iw, ih);
      var data = t.getImageData(0, 0, W, H).data;
      var step = Math.max(2, Math.round(3 * dpr));
      parts = [];
      for (var yy = 0; yy < H; yy += step) {
        for (var xx = 0; xx < W; xx += step) {
          var i = (yy * W + xx) * 4;
          if (data[i + 3] > 120) {
            // «спрятанная» позиция — внутри стаканчика
            var a = Math.random() * Math.PI * 2, rr = Math.sqrt(Math.random()) * W * 0.09;
            var sx = cx + Math.cos(a) * rr, sy = cy + Math.sin(a) * rr * 1.4;
            parts.push({ hx: xx, hy: yy, sx: sx, sy: sy, x: open ? xx : sx, y: open ? yy : sy, vx: 0, vy: 0,
              k: 0.035 + Math.random() * 0.03,
              c: (255 << 24) | (data[i + 2] << 16) | (data[i + 1] << 8) | data[i] });
          }
        }
      }
      parts.size = step;
      imgData = ctx.createImageData(W, H);
      buf32 = new Uint32Array(imgData.data.buffer);
      mouse.r = W * 0.09;
      draw();
    }

    function draw() {
      buf32.fill(0);
      var s = parts.size;
      for (var k = 0; k < parts.length; k++) {
        var p = parts[k], px = p.x | 0, py = p.y | 0;
        if (px < 0 || py < 0 || px >= W - s || py >= H - s) continue;
        for (var dy = 0; dy < s; dy++) {
          var row = (py + dy) * W + px;
          for (var dx = 0; dx < s; dx++) buf32[row + dx] = p.c;
        }
      }
      ctx.putImageData(imgData, 0, 0);
    }

    function tick() {
      var moving = false, r = mouse.r, r2 = r * r;
      for (var k = 0; k < parts.length; k++) {
        var p = parts[k];
        var tx = open ? p.hx : p.sx, ty = open ? p.hy : p.sy;
        if (open && mouse.active) {
          var dx = p.x - mouse.x, dy = p.y - mouse.y, d2 = dx * dx + dy * dy;
          if (d2 < r2 && d2 > 0.01) { var d = Math.sqrt(d2), f = (1 - d / r) * 6; p.vx += (dx / d) * f; p.vy += (dy / d) * f; }
        }
        p.vx += (tx - p.x) * p.k; p.vy += (ty - p.y) * p.k;
        p.vx *= 0.86; p.vy *= 0.86;
        p.x += p.vx; p.y += p.vy;
        if (!moving && (Math.abs(p.vx) > 0.04 || Math.abs(p.vy) > 0.04 || Math.abs(p.x - tx) > 0.6)) moving = true;
      }
      draw();
      if (moving && visible) requestAnimationFrame(tick);
      else { running = false; if (open && !moving) stage.classList.add("is-settled"); }
    }
    function kick() { if (!running && visible && parts.length) { running = true; stage.classList.remove("is-settled"); requestAnimationFrame(tick); } }

    function explode() {
      if (open) return;
      open = true; setOpen(true);
      // стартовый «выстрел» наружу от центра
      for (var k = 0; k < parts.length; k++) {
        var p = parts[k], dx = p.hx - cx, dy = p.hy - cy, d = Math.sqrt(dx * dx + dy * dy) || 1;
        var f = 10 + Math.random() * 14;
        p.vx += (dx / d) * f; p.vy += (dy / d) * f - 4;
      }
      kick();
    }
    function collapse() { if (!open) return; open = false; setOpen(false); mouse.active = false; kick(); }

    function setMouse(e) {
      var rect = canvas.getBoundingClientRect();
      mouse.x = (e.clientX - rect.left) * dpr; mouse.y = (e.clientY - rect.top) * dpr;
      mouse.active = true; if (open) kick();
    }
    var cupEl = stage.querySelector(".cup3d");
    if (!isTouch) {
      cupEl.addEventListener("mouseenter", explode);           // наведение на стаканчик
      stage.addEventListener("pointermove", setMouse);         // зёрна уворачиваются от курсора
      stage.addEventListener("mouseleave", collapse);          // ушли — кофе вернулся в стакан
    }
    stage.addEventListener("click", function () { open ? collapse() : explode(); });
    stage.setAttribute("role", "button");
    stage.setAttribute("tabindex", "0");
    stage.setAttribute("aria-label", "Открыть стаканчик Fastcup");
    stage.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open ? collapse() : explode(); } });

    new IntersectionObserver(function (en) { visible = en[0].isIntersecting; if (visible) kick(); else if (open && isTouch) collapse(); }).observe(stage);
    var rt; window.addEventListener("resize", function () { clearTimeout(rt); rt = setTimeout(build, 200); });

    img.onload = function () {
      try { build(); }
      catch (err) { section.classList.add("no-particles"); parts = []; }
    };
    img.src = window.FASTCUP_BEANS;
  }

  /* =========================================================
     ФОРМА ЗАЯВКИ
     ========================================================= */
  var form = document.getElementById("leadForm");
  var status = document.getElementById("formStatus");
  var siBtn = form.querySelector("button[type=submit]"), siLabel = siBtn.textContent, siTimer = 0;
  function siDone() { delete form.dataset.busy; siBtn.disabled = false; siBtn.textContent = siLabel; clearTimeout(siTimer); }
  document.addEventListener("si:send:finish", siDone);   // SendIt ответил (принял или вернул ошибки) — кнопка снова работает

  // Маска телефона: +7 (912) 345-67-89. Начали с 8 или 9 — всё равно получится +7
  var phoneEl = form.elements["phone"];
  function phoneDigits(v) {
    var d = v.replace(/\D/g, "");
    if (d.charAt(0) === "8") d = "7" + d.slice(1);
    else if (d && d.charAt(0) !== "7") d = "7" + d;
    return d.slice(0, 11);
  }
  function phoneFormat(d) {
    if (!d) return "";
    var r = "+7", p = [[1, 4, " (", ")"], [4, 7, " ", ""], [7, 9, "-", ""], [9, 11, "-", ""]];
    for (var i = 0; i < p.length && d.length > p[i][0]; i++) {
      var part = d.slice(p[i][0], p[i][1]);
      r += p[i][2] + part + (part.length === p[i][1] - p[i][0] ? p[i][3] : "");
    }
    return r;
  }
  var phonePrev = "";
  phoneEl.addEventListener("input", function (e) {
    var v = phoneEl.value, caret = phoneEl.selectionStart == null ? v.length : phoneEl.selectionStart;
    var before = phoneDigits(v.slice(0, caret)).length;   // сколько цифр до курсора (с учётом +7) — чтобы курсор не прыгал в конец
    var d = phoneDigits(v);
    // стёрли скобку, пробел или дефис — цифры те же; стираем и цифру перед ним, иначе знак вернётся обратно
    if (e.inputType && e.inputType.indexOf("delete") === 0 && d === phonePrev && before > 0) {
      d = d.slice(0, before - 1) + d.slice(before); before--;
    }
    var out = phoneFormat(d);
    phoneEl.value = out; phonePrev = d;
    var pos = out.length;
    if (before < d.length) for (var i = 0, n = 0; i < out.length; i++) if (/\d/.test(out[i]) && ++n > before && i > 1) { pos = i; break; }
    try { phoneEl.setSelectionRange(pos, pos); } catch (err) {}
  });
  phoneEl.addEventListener("blur", function () { if (phoneDigits(phoneEl.value).length <= 1) { phoneEl.value = ""; phonePrev = ""; } });

  // Проверка полей до отправки: подсветка и подсказка под каждым полем.
  // На MODX это важно вдвойне: пустую форму без ввода антиспам SendIt отклоняет раньше проверки полей («Проверка не пройдена»)
  function fieldError(name, text) {
    var input = form.elements[name], box = form.querySelector('[data-si-error="' + name + '"]');
    input.setAttribute("aria-invalid", String(!!text));
    if (box) box.textContent = text || "";
  }
  ["name", "phone", "agree"].forEach(function (n) {
    form.elements[n].addEventListener(n === "agree" ? "change" : "input", function () {
      if (form.elements[n].getAttribute("aria-invalid") === "true") fieldError(n, "");
    });
  });
  function checkFields() {
    var el = form.elements, bad = [];
    var name = el["name"].value.trim(), digits = el["phone"].value.replace(/\D/g, "");
    fieldError("name", name ? "" : "Укажите имя, чтобы мы знали, как к вам обращаться"); if (!name) bad.push(el["name"]);
    var phoneErr = !digits ? "Укажите телефон, чтобы мы могли перезвонить" : digits.length < 11 ? "Проверьте телефон: нужно 10 цифр после +7" : "";
    fieldError("phone", phoneErr); if (phoneErr) bad.push(el["phone"]);
    fieldError("agree", el["agree"].checked ? "" : "Отметьте согласие на обработку персональных данных"); if (!el["agree"].checked) bad.push(el["agree"]);
    if (bad.length) bad[0].focus();
    return !bad.length;
  }

  form.addEventListener("submit", function (e) {
    if (!checkFields()) {   // не отправляем, пока поля не заполнены (SendIt слушает отправку на document — до него не дойдёт)
      e.preventDefault(); e.stopPropagation();
      status.className = "form__status"; status.textContent = "";
      return;
    }
    if (form.hasAttribute("data-si-form")) {  // сайт на MODX: форму отправляет SendIt (modx/), он же проверяет поля
      // SendIt перед отправкой решает задачу защиты от ботов (до 10 с на слабом телефоне). Второе нажатие за это время
      // ушло бы второй заявкой и получило «Проверка не пройдена» — поэтому, пока идёт отправка, кнопка не работает
      if (form.dataset.busy) { e.preventDefault(); e.stopPropagation(); return; }
      form.dataset.busy = "1";
      siBtn.disabled = true; siBtn.textContent = "Отправляем…";
      clearTimeout(siTimer); siTimer = setTimeout(siDone, 40000);   // на случай, если ответ так и не пришёл
      return;
    }
    e.preventDefault();
    var el = form.elements;
    var name = el["name"].value.trim(), phone = el["phone"].value.trim();
    var data = { name: name, phone: phone, topic: el["topic"].value, message: el["message"].value.trim(), page: location.href };
    var btn = form.querySelector("button[type=submit]");

    if (!CFG.formEndpoint) {
      var body = "Имя: " + data.name + "\nТелефон: " + data.phone + "\nТема: " + data.topic + "\n\n" + data.message;
      location.href = "mailto:" + CFG.email + "?subject=" + encodeURIComponent("Заявка с сайта: " + data.topic) + "&body=" + encodeURIComponent(body);
      status.className = "form__status is-ok";
      status.textContent = "Открыли письмо с заявкой. Отправьте его, и мы свяжемся с вами";
      return;
    }
    btn.disabled = true; btn.textContent = "Отправляем…";
    fetch(CFG.formEndpoint, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) })
      .then(function (r) { if (!r.ok) throw new Error(); })
      .then(function () {
        form.reset();
        status.className = "form__status is-ok";
        status.textContent = "Заявка отправлена. Перезвоним в течение рабочего дня";
      })
      .catch(function () {
        status.className = "form__status is-err";
        status.textContent = "Не получилось отправить. Позвоните нам или напишите в Telegram";
      })
      .finally(function () { btn.disabled = false; btn.textContent = "Отправить заявку"; });
  });
})();
