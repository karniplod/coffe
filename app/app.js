/* ================================================================
   Приложение Fastcup: где я + пеший маршрут до ближайшего автомата.
   Карта — js/omap.js, точки — js/points.js, маршрут считает route-worker.js
   по графу пеших дорог data/walk-graph.bin (tools/build_routes.py). Без интернета — sw.js.

   «Я здесь» — метка на карте. Её ставит GPS, а если геопозиции нет (запрещена, не определилась,
   вы не в Перми) — она стоит в центре города. Метку всегда можно перетащить (или нажать на карту
   без GPS): маршрут пересчитывается на ходу. «Отмена» возвращает метку назад.

   Дополнительно (включаются в js/config.js → features, по умолчанию выключены):
   promos — акции и цены точек (поля promo, price200, price300 в js/points.js), openNow — «Открыто/Закрыто» по часам.
   ================================================================ */
(function () {
  "use strict";
  var POINTS = window.FASTCUP_POINTS || [];
  var MAP = window.FASTCUP_MAP;
  var CFG = window.FASTCUP_CONFIG || {};
  var CENTER = CFG.mapCenter || [58.0105, 56.2294]; // центр города (js/config.js)
  var WALK_M_PER_MIN = 80;        // ≈ 4,8 км/ч
  var REROUTE_M = 15;             // по GPS пересчитываем маршрут, когда прошли больше
  var KEEP_CHOICE_M = 500;        // выбранная вручную точка держится, пока не ушли дальше — потом снова ближайшая
  var GPS_WAIT_MS = 8000;         // столько ждём GPS, потом ставим метку в центр города
  var POOR_GPS_M = 100;           // точность хуже — подсказываем, что метку можно поправить
  var FEAT = CFG.features || {};
  var PRICES = CFG.prices || {};

  /* Открыто ли сейчас по строке часов: «Круглосуточно», «07:00–23:00», «05:00–00:00» (через полночь).
     true / false; null — часы не разобрать (тогда ничего не показываем) */
  function openNow(hours, d) {
    if (!hours) return null;
    if (/кругл|24\s*\/\s*7|24\s*ч/i.test(hours)) return true;
    var m = /(\d{1,2})[:.](\d{2})\s*[–—-]\s*(\d{1,2})[:.](\d{2})/.exec(hours);
    if (!m) return null;
    d = d || new Date();
    var now = d.getHours() * 60 + d.getMinutes(), from = +m[1] * 60 + +m[2], to = +m[3] * 60 + +m[4];
    if (to === from) return true;
    return from < to ? now >= from && now < to : now >= from || now < to;
  }
  function priceOf(p, ml) { return +(p["price" + ml] || PRICES[ml] || 0); }
  function priceText(p) {
    var a = priceOf(p, 200), b = priceOf(p, 300);
    return [a ? "200 мл — " + a + " ₽" : "", b ? "300 мл — " + b + " ₽" : ""].filter(Boolean).join(" · ");
  }
  function cheaper(p) { // дешевле обычного: своя цена ниже цены по умолчанию
    return (p.price200 && PRICES["200"] && +p.price200 < +PRICES["200"]) || (p.price300 && PRICES["300"] && +p.price300 < +PRICES["300"]);
  }

  function $(id) { return document.getElementById(id); }
  var sheet = $("sheet"), statusEl = $("status"), card = $("card"), locateBtn = $("locate");
  var undoBtn = $("undo"), autoBtn = $("auto"), reloadBtn = $("reload"), help = $("help");
  var listEl = $("list"), itemsEl = $("items"), listBtn = $("listBtn");
  var onlyPromo = false, cheapFirst = false;

  var me = null, meMarker = null;
  var mode = null;                // "gps" — метку ставит GPS, "manual" — поставлена вручную (или центр города)
  var autoCenter = false;         // метка стоит в центре города сама, посетитель её не трогал
  var gpsFix = null;              // последнее, что сообщил GPS: { c, acc }
  var history = [];               // для «Отмены»: прежние положения метки
  var dragging = false, follow = false, firstShown = false, lastFrom = null;
  var results = null, sel = -1;   // результаты маршрута (по возрастанию расстояния) и выбранная точка
  var chosenAt = null;            // где был посетитель, когда сам выбрал точку
  var ready = false, routeId = 0, routeFailed = false, inflight = false, pending = false;
  var pins = [];

  function status(text) { statusEl.textContent = text || ""; }
  function fmtDist(m) { return m < 1000 ? Math.max(10, Math.round(m / 10) * 10) + " м" : (m / 1000).toFixed(1).replace(".", ",") + " км"; }
  function distance(a, b) { // метры по прямой
    var R = 6371000, r = Math.PI / 180, dLat = (b[0] - a[0]) * r, dLon = (b[1] - a[1]) * r;
    var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a[0] * r) * Math.cos(b[0] * r) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return 2 * R * Math.asin(Math.sqrt(h));
  }
  function inMap(c) { return c[0] > MAP.south && c[0] < MAP.north && c[1] > MAP.west && c[1] < MAP.east; }
  function failed(text) { status(text); reloadBtn.hidden = false; }

  if (!MAP || !window.FastcupMap) { failed("Не удалось загрузить карту."); return; }

  /* ---------- карта ---------- */
  function wide() { return window.innerWidth >= 700; }
  var omap = FastcupMap($("map"), {
    data: MAP, base: "../", oneFinger: true, wheel: true, maxScale: 8,
    // свободная от шапки и карточки часть экрана — туда помещаем маршрут
    // сверху — шапка и высота выбранной метки (≈ 70 px над точкой), снизу — карточка и точка «Я здесь» с подписью
    margin: function () {
      var top = document.querySelector(".bar").getBoundingClientRect().bottom + 84;
      return wide() ? [top, 90, 30, 420] : [top, 90, sheet.offsetHeight + 30, 40];   // справа — кнопки масштаба
    },
    minFitScale: 0.08,   // можно отдалить, чтобы вы и далёкая точка поместились целиком
    onUserMove: function () { setFollow(false); collapse(true); },   // двигаете или увеличиваете карту — панель уходит вниз
    onTap: function (c) { if (mode !== "gps") moveByHand(c); }, // без GPS метку можно и просто поставить нажатием
    onError: function () { failed("Карта не загрузилась. Проверьте интернет и нажмите «Перезагрузить»."); }
  });

  POINTS.forEach(function (p, i) {
    var b = document.createElement("button");
    b.type = "button";
    b.className = "omap__pin";
    b.title = p.title;
    b.setAttribute("aria-label", p.title + ", " + p.address + (FEAT.promos && p.promo ? ". Акция: " + p.promo : ""));
    b.innerHTML = FastcupMap.PIN;
    if (FEAT.promos && (p.promo || cheaper(p))) b.classList.add("has-promo");   // значок «%» на метке
    b.addEventListener("click", function () { if (results && find(i)) { sel = i; chosenAt = me; show(true); } });
    pins.push(b);
  });
  omap.fit(POINTS.length ? POINTS.map(function (p) { return p.coords; }) : [CENTER], false);
  POINTS.forEach(function (p, i) { omap.addMarker(pins[i], p.coords); });

  // кнопка «где я» стоит над карточкой: следим за её высотой
  function placeLocate() { document.documentElement.style.setProperty("--sheet-h", sheet.offsetHeight + "px"); }
  if (window.ResizeObserver) new ResizeObserver(placeLocate).observe(sheet);
  placeLocate();

  /* ---------- панель снизу сворачивается: двигаете карту — она уходит вниз, остаются название и расстояние ----------
     Развернуть — тап по панели или свайп вверх; свернуть — «ручка» или свайп вниз. Выбрали точку — панель разворачивается сама. */
  var handle = $("handle"), collapsed = false;
  function collapse(on) {
    if (on && card.hidden && listEl.hidden) return;   // сворачивать нечего (карточки ещё нет)
    if (collapsed === on) return;
    collapsed = on;
    if (on && !listEl.hidden) { listEl.hidden = true; card.hidden = !results; }   // открытый список сворачивается в карточку
    sheet.classList.toggle("is-collapsed", on);
    handle.setAttribute("aria-expanded", String(!on));
    handle.setAttribute("aria-label", on ? "Развернуть панель" : "Свернуть панель");
  }
  handle.addEventListener("click", function (e) { e.stopPropagation(); collapse(!collapsed); });
  sheet.addEventListener("click", function (e) { if (collapsed && !e.target.closest("a")) { e.preventDefault(); e.stopPropagation(); collapse(false); } }, true);
  var swipe = null;
  sheet.addEventListener("touchstart", function (e) {
    var list = e.target.closest(".list__items");
    swipe = list && list.scrollTop > 0 ? null : { y: e.touches[0].clientY };   // прокрутку списка не путаем со свайпом
  }, { passive: true });
  sheet.addEventListener("touchend", function (e) {
    if (!swipe) return;
    var dy = e.changedTouches[0].clientY - swipe.y;
    swipe = null;
    if (dy > 40) collapse(true); else if (dy < -40) collapse(false);
  });

  /* ---------- маршрут в фоне ---------- */
  var worker = new Worker("route-worker.js");
  worker.onmessage = function (e) {
    var msg = e.data;
    if (msg.type === "ready") { ready = true; requestRoute(); }
    else if (msg.type === "error") {
      routeFailed = true;
      reloadBtn.hidden = false;
      requestRoute();
    } else if (msg.type === "route") {
      inflight = false;
      if (msg.id === routeId) { window.__routeMs = msg.ms; onRoute(msg.results); }
      if (pending) { pending = false; requestRoute(); } // метку двигали, пока считали
    }
  };
  worker.postMessage({ type: "init", url: "data/walk-graph.bin", mpu: omap.metersPerUnit });

  /* Посчитать маршрут от метки. Пока предыдущий расчёт не закончен — запомнить и посчитать после */
  function requestRoute() {
    if (!me || !POINTS.length) return;
    if (routeFailed) { // граф не загрузился — хотя бы ближайшие по прямой
      onRoute(POINTS.map(function (p, i) { return { i: i, meters: distance(me, p.coords), straight: true }; }));
      return;
    }
    if (!ready) { if (!statusEl.textContent) status("Готовим пешие маршруты…"); return; }
    if (inflight) { pending = true; return; }
    inflight = true;
    routeId++;
    worker.postMessage({
      type: "route", id: routeId, from: omap.toXY(me),
      targets: POINTS.map(function (p) { return omap.toXY(p.coords); })
    });
  }

  function onRoute(list) {
    results = list.filter(function (r) { return r.meters != null; }).sort(function (a, b) { return a.meters - b.meters; });
    if (!results.length) { status("Отсюда не нашли пешего пути до точек. Перетащите метку «Я здесь» на ближайшую улицу."); return; }
    if (chosenAt && me && distance(chosenAt, me) > KEEP_CHOICE_M) chosenAt = null;
    if (!chosenAt || !find(sel)) sel = results[0].i;
    if (routeFailed) status("Пешие дороги не загрузились — показываем расстояние по прямой.");
    else if (statusEl.textContent === "Готовим пешие маршруты…") explain(lastReason);
    show(!firstShown && !dragging);
    if (!dragging) firstShown = true;
  }
  function find(i) { for (var k = 0; results && k < results.length; k++) if (results[k].i === i) return results[k]; return null; }

  /* Показать выбранную точку: карточка, линия маршрута, подсветка метки. fit — подвинуть карту к маршруту */
  function show(fit) {
    var r = find(sel); if (!r) return;
    if (fit) collapse(false);   // выбрали точку — показываем карточку целиком
    var p = POINTS[r.i];
    card.hidden = !listEl.hidden;   // открыт список точек — карточку не показываем поверх
    $("kicker").textContent = r.i === results[0].i ? "Ближайший кофе" : "Ещё одна точка";
    $("title").textContent = p.title;
    $("addr").textContent = p.address + (p.hours ? " · " + p.hours : "");
    $("dist").textContent = fmtDist(r.meters);
    $("time").textContent = r.straight ? "по прямой" : "≈ " + Math.max(1, Math.round(r.meters / WALK_M_PER_MIN)) + " мин пешком";
    $("next").hidden = results.length < 2;
    var open = FEAT.openNow ? openNow(p.hours) : null;
    $("open").hidden = open === null;
    $("open").textContent = open ? "Открыто" : "Закрыто";
    $("open").classList.toggle("is-closed", open === false);
    $("promo").hidden = !(FEAT.promos && p.promo);
    $("promo").textContent = p.promo || "";
    $("price").hidden = !(FEAT.promos && priceText(p));
    $("price").textContent = FEAT.promos ? priceText(p) : "";
    $("ext").href = "https://yandex.ru/maps/?rtext=" + me.join(",") + "~" + p.coords.join(",") + "&rtt=pd";
    listBtn.hidden = POINTS.length < 2;
    if (!listEl.hidden) renderList();
    omap.setRoute(r.points || [omap.toXY(me), omap.toXY(p.coords)]);
    pins.forEach(function (b, k) { b.firstChild.classList.toggle("is-selected", k === r.i); });
    if (fit) fitRoute(r);
  }
  function fitRoute(r) {
    var pts = r.points ? r.points.map(function (q) { return omap.fromXY(q[0], q[1]); }) : [me, POINTS[r.i].coords];
    setFollow(false);
    omap.fit(pts, false, true);
  }

  $("show").addEventListener("click", function () { var r = find(sel); if (r) fitRoute(r); });
  $("next").addEventListener("click", function () {
    if (!results) return;
    var k = results.indexOf(find(sel));
    sel = results[(k + 1) % results.length].i;
    chosenAt = me;
    show(true);
  });

  /* ---------- все точки списком: по расстоянию (пешком, если маршруты готовы), с фильтрами акций ---------- */
  function renderList() {
    var rows = POINTS.map(function (p, i) {
      var r = find(i);
      return { i: i, p: p, m: r ? r.meters : (me ? distance(me, p.coords) : 0), walk: r && !r.straight };
    });
    if (FEAT.promos && onlyPromo) rows = rows.filter(function (x) { return x.p.promo || cheaper(x.p); });
    rows.sort(function (a, b) {
      if (FEAT.promos && cheapFirst) {
        var d = (priceOf(a.p, 300) || priceOf(a.p, 200)) - (priceOf(b.p, 300) || priceOf(b.p, 200));
        if (d) return d;
      }
      return a.m - b.m;
    });
    itemsEl.innerHTML = "";
    if (!rows.length) { itemsEl.innerHTML = '<li class="list__empty">Сейчас акций нет.</li>'; return; }
    rows.forEach(function (x) {
      var li = document.createElement("li"), b = document.createElement("button");
      b.type = "button";
      b.className = "item" + (x.i === sel ? " is-selected" : "");
      var open = FEAT.openNow ? openNow(x.p.hours) : null;
      b.innerHTML = '<span class="item__main"><span class="item__title"></span><span class="item__addr"></span>'
        + (FEAT.promos && x.p.promo ? '<span class="item__promo"></span>' : "") + "</span>"
        + '<span class="item__side"><b class="item__dist"></b>'
        + (open !== null ? '<span class="badge' + (open ? "" : " is-closed") + '">' + (open ? "Открыто" : "Закрыто") + "</span>" : "") + "</span>";
      b.querySelector(".item__title").textContent = x.p.title;
      b.querySelector(".item__addr").textContent = x.p.address + (x.p.hours ? " · " + x.p.hours : "");
      if (FEAT.promos && x.p.promo) b.querySelector(".item__promo").textContent = x.p.promo;
      b.querySelector(".item__dist").textContent = me ? fmtDist(x.m) + (x.walk ? "" : " ↗") : "";
      b.addEventListener("click", function () {
        if (!results || !find(x.i)) return;
        sel = x.i; chosenAt = me;
        listEl.hidden = true; card.hidden = false;
        show(true);
      });
      li.appendChild(b);
      itemsEl.appendChild(li);
    });
  }
  listBtn.addEventListener("click", function () { collapse(false); card.hidden = true; listEl.hidden = false; renderList(); });
  $("listClose").addEventListener("click", function () { listEl.hidden = true; card.hidden = !results; });
  $("filters").hidden = !FEAT.promos;
  function chip(id, set) {
    $(id).addEventListener("click", function () { var on = this.getAttribute("aria-pressed") !== "true"; this.setAttribute("aria-pressed", String(on)); set(on); renderList(); });
  }
  chip("onlyPromo", function (v) { onlyPromo = v; });
  chip("cheap", function (v) { cheapFirst = v; });

  /* ---------- метка «Я здесь» ---------- */
  function ensureMarker(c) {
    if (meMarker) return;
    var dot = document.createElement("div");
    dot.className = "omap__me me";
    dot.title = "Я здесь — перетащите туда, где вы";
    dot.innerHTML = '<span class="me__hit"></span><span class="me__label">Я здесь</span><div class="fc-me"></div>';
    meMarker = omap.addMarker(dot, c, {
      draggable: true,
      onDrag: function (p) {
        if (!dragging) { // начали тащить: запомнить, откуда — для «Отмены»
          dragging = true;
          remember();
          setMode("manual");
          status("Отпустите метку там, где вы.");
        }
        me = p;
        requestRoute(); // маршрут на ходу
      },
      onDragEnd: function (p) {
        dragging = false;
        me = p;
        autoCenter = false;
        requestRoute();
        explain();
      }
    });
  }

  /* Поставить метку: how = "gps" (с точностью acc) или "manual" */
  function placeMe(c, how, acc) {
    ensureMarker(c);
    me = c;
    setMode(how);
    omap.moveMarker(meMarker, c);
    omap.setAccuracy(how === "gps" ? c : null, acc || 0);
    if (follow && how === "gps") omap.fit([c], true, true);
    if (how !== "gps" || !lastFrom || distance(lastFrom, c) > REROUTE_M) { lastFrom = c; requestRoute(); }
  }

  function setMode(m) {
    mode = m;
    if (meMarker) meMarker.el.classList.toggle("is-manual", m === "manual");
    if (m === "manual") { omap.setAccuracy(null, 0); setFollow(false); }
    if (m === "gps") help.hidden = true;
    updateTools();
  }

  function remember() {
    if (!me) return;
    history.push({ c: me, mode: mode, acc: gpsFix && mode === "gps" ? gpsFix.acc : 0, autoCenter: autoCenter });
    if (history.length > 30) history.shift();
    updateTools();
  }

  function moveByHand(c) {
    remember();
    autoCenter = false;
    placeMe(c, "manual");
    explain();
  }

  function updateTools() {
    undoBtn.hidden = !history.length;
    autoBtn.hidden = mode === "gps";
  }

  /* Подсказка в карточке: что сейчас происходит с меткой */
  function explain(reason) {
    if (mode === "gps") {
      status(gpsFix && gpsFix.acc > POOR_GPS_M
        ? "GPS неточен (±" + Math.round(gpsFix.acc) + " м). Если точка не там — перетащите её."
        : "");
      return;
    }
    var why = {
      denied: "Геопозиция выключена. ",
      nogeo: "Телефон не сообщает, где вы. ",
      fail: "Не удалось определить, где вы. ",
      wait: "Геопозиция пока не определилась. ",
      outside: "Вы сейчас не в Перми. "
    }[reason] || "";
    status(autoCenter
      ? why + "Перетащите метку «Я здесь» туда, где вы, — маршрут построится от неё."
      : "Метка «Я здесь» стоит там, куда вы её поставили. Перетащите, чтобы уточнить.");
  }

  /* Без геопозиции: метка в центре города, дальше — перетаскивание */
  var lastReason = "";
  function goManual(reason) {
    lastReason = reason;
    if (!me) {
      autoCenter = true;
      placeMe(CENTER, "manual");
      omap.fit([CENTER], false, true);
    } else if (mode !== "manual") setMode("manual");
    explain(reason);
  }

  undoBtn.addEventListener("click", function () {
    var h = history.pop();
    if (!h) return;
    autoCenter = h.autoCenter;
    placeMe(h.c, h.mode === "gps" ? "gps" : "manual", h.acc);
    explain(lastReason);
    updateTools();
  });

  /* ---------- GPS ---------- */
  var watchId = null, gpsTimer = 0, forceGps = false;
  function onPos(pos) {
    clearTimeout(gpsTimer);
    var c = [pos.coords.latitude, pos.coords.longitude];
    gpsFix = { c: c, acc: pos.coords.accuracy, t: Date.now() };
    if (!inMap(c)) { forceGps = false; if (mode !== "manual" || autoCenter) goManual("outside"); return; }
    if (mode === "manual" && !autoCenter && !forceGps) return; // поставили вручную — GPS метку не двигает
    useGps(forceGps);
  }
  /* поставить метку по GPS; fit — показать место на карте (после «Определить автоматически») */
  function useGps(fit) {
    if (mode !== "gps") remember();
    forceGps = false;
    autoCenter = false;
    placeMe(gpsFix.c, "gps", gpsFix.acc);
    if (fit) omap.fit([gpsFix.c], false, true);
    explain();
  }
  function onErr(err) {
    if (err.code === 1) stopGps();
    if (forceGps) { forceGps = false; if (err.code === 1) help.hidden = false; explain(err.code === 1 ? "denied" : "fail"); return; }
    if (mode === "gps") return; // временная ошибка — оставляем последнее место
    goManual(err.code === 1 ? "denied" : "fail");
  }
  function startGps() {
    if (!("geolocation" in navigator)) { goManual("nogeo"); return; }
    if (watchId !== null) return;
    if (!me) status("Определяем, где вы…");
    watchId = navigator.geolocation.watchPosition(onPos, onErr, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
    clearTimeout(gpsTimer);
    gpsTimer = setTimeout(function () { if (!me) goManual("wait"); }, GPS_WAIT_MS);
  }
  function stopGps() { if (watchId !== null) navigator.geolocation.clearWatch(watchId); watchId = null; }

  /* «Определить автоматически»: вернуть метку на GPS (если геопозиция запрещена — подсказать, как разрешить) */
  autoBtn.addEventListener("click", function () {
    if (!("geolocation" in navigator)) { status("Телефон не сообщает, где вы — геопозиция недоступна."); return; }
    function ask() {
      if (gpsFix && Date.now() - gpsFix.t < 30000 && inMap(gpsFix.c)) { useGps(true); return; } // GPS недавно сообщал место
      status("Определяем, где вы…");
      forceGps = true;  // следующее место от GPS — поставить метку туда
      stopGps();
      startGps();
    }
    // если браузер уже запретил геопозицию, повторный запрос ничего не покажет — сразу подсказка
    if (navigator.permissions && navigator.permissions.query) {
      navigator.permissions.query({ name: "geolocation" }).then(function (p) {
        if (p.state === "denied") { help.hidden = false; explain("denied"); } else ask();
      }, ask);
    } else ask();
  });

  reloadBtn.addEventListener("click", function () { location.reload(); });

  function setFollow(on) { follow = on; locateBtn.classList.toggle("is-follow", on); }
  locateBtn.addEventListener("click", function () {
    if (!me) { startGps(); return; }
    setFollow(mode === "gps");
    omap.fit([me], false, true);
  });

  if (!POINTS.length) status("Точек на карте пока нет.");
  else startGps();

  /* ---------- установка на телефон ----------
     Android/Chrome: системное окно установки (beforeinstallprompt). iPhone: подсказка «Поделиться → На экран Домой».
     Кнопка в шапке — всегда, пока не установлено; карточка в низу — один раз, через 20 секунд работы (не мешает с порога). */
  var installBtn = $("install"), tip = $("installTip"), installCard = $("installCard"), deferred = null;
  var standalone = window.matchMedia("(display-mode: standalone)").matches || navigator.standalone;
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
                set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  function canInstall() { return !standalone && (deferred || ios); }
  function offerLater() {
    if (store.get("fc-install-later") || !canInstall()) return;
    setTimeout(function () { if (canInstall() && listEl.hidden) installCard.hidden = false; }, 20000);
  }
  function install() {
    installCard.hidden = true;
    if (deferred) {
      deferred.prompt();
      deferred.userChoice.then(function (c) { deferred = null; if (c.outcome === "accepted") installBtn.hidden = true; });
    } else tip.hidden = false;
  }
  window.addEventListener("beforeinstallprompt", function (e) { e.preventDefault(); deferred = e; installBtn.hidden = false; offerLater(); });
  window.addEventListener("appinstalled", function () { installBtn.hidden = true; tip.hidden = true; installCard.hidden = true; standalone = true; });
  if (ios && !standalone) { installBtn.hidden = false; offerLater(); }
  installBtn.addEventListener("click", install);
  $("installNow").addEventListener("click", install);
  $("installLater").addEventListener("click", function () { installCard.hidden = true; store.set("fc-install-later", "1"); });
  $("tipClose").addEventListener("click", function () { tip.hidden = true; });

  // iPhone: щипок по шапке или карточке увеличивал бы всю страницу — в приложении масштабируется только карта
  document.addEventListener("gesturestart", function (e) { e.preventDefault(); }, { passive: false });

  /* Офлайн: приложение готово без интернета сразу после первой загрузки, картинки карты докачиваются в фоне */
  if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register("sw.js");
    navigator.serviceWorker.ready.then(function (reg) { if (reg.active) reg.active.postMessage({ type: "cache-map" }); });
  }
})();
