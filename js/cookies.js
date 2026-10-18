/* Согласие на cookie (152-ФЗ) и Яндекс Метрика — только после согласия. Основа — плагин сайта вебстудия59.рф (assets/js/cookies.js).
   Подключение (после js/config.js): <script src="js/cookies.js" defer data-fc-cookies data-policy="cookie.html"></script>
     data-policy   ссылка на «Политику cookie»
   Номер счётчика Метрики и версия политики — в js/config.js: metrikaId (пусто — Метрика не подключается), cookieVersion
   (поменяли состав cookie — поменяйте дату, баннер спросит согласие заново). В MODX — «Конфигурация» → «Аналитика».
   Выбор хранится в cookie fc_consent 12 месяцев. Кнопка или ссылка [data-cookie-settings] (в подвале) открывает настройки снова.
   Событие для своих скриптов: document.addEventListener('fc:consent', e => e.detail.analytics).
*/
(function () {
  var me = document.querySelector('[data-fc-cookies]') || document.currentScript;
  var CFG = window.FASTCUP_CONFIG || {};
  var policy = me && me.dataset.policy || '';
  var KEY = 'fc_consent', DAYS = 365, YM = String(CFG.metrikaId || '').replace(/\D/g, ''), VER = CFG.cookieVersion || '1';
  var box, opts, analyticsBox, saveBtn, cfgBtn, back;

  /* работают ли cookie на самом деле: на file:// и при запрете в браузере запись молча не проходит — тогда выбор в localStorage */
  function cookiesWork() {
    try { document.cookie = 'fc_ck=1; path=/; SameSite=Lax'; var on = /(?:^|;\s*)fc_ck=1/.test(document.cookie); document.cookie = 'fc_ck=; max-age=0; path=/'; return on; } catch (e) { return false; }
  }
  var useLS = !cookiesWork();
  function ok(c) { return c && c.v === VER && Date.now() - c.t < DAYS * 864e5 ? c : null; }
  function read() {
    var c = null;
    if (useLS) { try { c = JSON.parse(localStorage.getItem(KEY) || 'null'); } catch (e) {} }
    else { var m = document.cookie.match(/(?:^|;\s*)fc_consent=([^;]*)/); try { if (m) c = JSON.parse(decodeURIComponent(m[1])); } catch (e) {} }
    return ok(c);
  }
  function write(analytics) {
    var c = { v: VER, a: analytics ? 1 : 0, t: Date.now() }, j = JSON.stringify(c);
    document.cookie = KEY + '=' + encodeURIComponent(j) + '; max-age=' + DAYS * 86400 + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    if (useLS) { try { localStorage.setItem(KEY, j); } catch (e) {} }
    return c;
  }

  /* Метрика — стандартный код счётчика, только после согласия */
  var ymOn = false;
  function metrika() {
    if (ymOn || !YM) return; ymOn = true;
    (function (m, e, t, r, i, k, a) { m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); }; m[i].l = 1 * new Date();
      k = e.createElement(t); a = e.getElementsByTagName(t)[0]; k.async = 1; k.src = r; a.parentNode.insertBefore(k, a); })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
    window.ym(+YM, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true, webvisor: false });
  }
  /* отозвали согласие: стираем cookie Метрики на своём домене и перезагружаем, чтобы счётчик выгрузился */
  function forget() {
    var host = location.hostname, doms = ['', host, '.' + host, '.' + host.split('.').slice(-2).join('.')];
    document.cookie.split(';').forEach(function (p) {
      var n = p.split('=')[0].trim(); if (!/^_ym/.test(n)) return;
      doms.forEach(function (d) { document.cookie = n + '=; max-age=0; path=/' + (d ? '; domain=' + d : ''); });
    });
  }
  function apply(c, fromUser) {
    if (c.a) metrika();
    document.dispatchEvent(new CustomEvent('fc:consent', { detail: { analytics: !!c.a } }));
    if (fromUser && !c.a && ymOn) { forget(); location.reload(); }
  }

  function build() {
    box = document.createElement('section');
    box.className = 'ck'; box.setAttribute('aria-labelledby', 'ck-t'); box.hidden = true;
    box.innerHTML = '<div class="ck__in">'
      + '<h2 class="ck__t" id="ck-t">Cookie на сайте</h2>'
      + '<p class="ck__x">Необходимые cookie нужны для работы сайта и заявок. Аналитические (Яндекс Метрика, статистика посещений) включим только с вашего согласия.'
      + (policy ? ' <a href="' + policy + '">Политика cookie</a>' : '') + '</p>'
      + '<div class="ck__opts" id="ck-opts" hidden>'
      + '<label class="ck__o"><input type="checkbox" checked disabled><span><b>Необходимые</b><small>Работа сайта, отправка заявки, запоминание этого выбора. Всегда включены.</small></span></label>'
      + '<label class="ck__o"><input type="checkbox" name="ck-analytics"><span><b>Аналитические</b><small>Яндекс Метрика: посещаемость, источники переходов, клики по кнопкам.</small></span></label>'
      + '</div>'
      + '<div class="ck__acts">'
      + '<button class="ck__b ck__b--main" type="button" data-ck="all">Принять все</button>'
      + '<button class="ck__b" type="button" data-ck="need">Только необходимые</button>'
      + '<button class="ck__b ck__b--main" type="button" data-ck="save" hidden>Сохранить выбор</button>'
      + '<button class="ck__l" type="button" data-ck="cfg" aria-expanded="false" aria-controls="ck-opts">Настроить</button>'
      + '</div></div>';
    document.body.appendChild(box);
    opts = box.querySelector('.ck__opts'); analyticsBox = box.querySelector('[name="ck-analytics"]');
    saveBtn = box.querySelector('[data-ck="save"]'); cfgBtn = box.querySelector('[data-ck="cfg"]');
    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-ck]'); if (!b) return;
      var k = b.dataset.ck;
      if (k === 'cfg') { settings(opts.hidden); return; }
      choose(k === 'all' ? true : k === 'need' ? false : analyticsBox.checked);
    });
  }
  function settings(on) {
    opts.hidden = !on; saveBtn.hidden = !on; cfgBtn.hidden = on; cfgBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) analyticsBox.focus();
  }
  /* высота баннера — в --ck-h: кнопка «Наверх» встаёт над ним */
  var ro;
  function height() {
    var set = function () { document.documentElement.style.setProperty('--ck-h', box.hidden ? '0px' : box.offsetHeight + 'px'); };
    set(); if (!ro && window.ResizeObserver) { ro = new ResizeObserver(set); ro.observe(box); }
  }
  function show(withSettings) {
    if (!box) build();
    var c = read(); analyticsBox.checked = !!(c && c.a);
    box.hidden = false; document.documentElement.classList.add('ck-on');
    height();
    settings(!!withSettings);
  }
  function hide() {
    box.hidden = true; document.documentElement.classList.remove('ck-on');
    document.documentElement.style.setProperty('--ck-h', '0px');
    if (back && document.contains(back)) back.focus(); back = null;
  }
  function choose(analytics) { var c = write(analytics); hide(); apply(c, true); }

  function init() {
    var c = read();
    if (c) apply(c, false); else show(false);
    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-cookie-settings]'); if (!t) return;
      e.preventDefault(); back = t; show(true);
    });
  }
  window.FCConsent = { get: read, open: function () { show(true); } };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
