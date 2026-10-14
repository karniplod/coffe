/* ================================================================
   Настройки сайта Fastcup — меняйте только этот файл и points.js
   ================================================================ */
window.FASTCUP_CONFIG = {
  // Центр карты, если в points.js нет ни одной точки (Пермь)
  mapCenter: [58.0105, 56.2294],

  // Контакты
  phone: "+7 (966) 790-12-46",       // как показывать
  phoneHref: "+79667901246",          // для ссылки tel:
  telegram: "https://t.me/fastcup_perm",
  max: "https://max.ru/fastcup_perm",
  email: "takeawayperm@yandex.ru",

  // Куда отправлять заявки из формы (POST JSON).
  // Пусто — заявка откроется письмом на email выше.
  // Варианты: свой обработчик (PHP/Node), Яндекс Cloud Function → Telegram-бот, Formspree.
  formEndpoint: ""
};
