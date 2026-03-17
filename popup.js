/**
 * popup.js — автономный exit-intent скрипт
 *
 * Подключение на любой сайт:
 * ─────────────────────────────────────────────────────
 * Вариант A — тег напрямую:
 *   <script src="https://your-domain.ru/popup.js"
 *           data-gate="https://your-domain.ru/close-window/api/gate.php"
 *           data-counter="12345678">  ← необязательно: ID счётчика Яндекс.Метрики
 *   </script>
 *
 * Вариант B — асинхронный лоадер (рекомендуется):
 *   <script>
 *   (function(w,d,s,u,g,c){
 *     w._EI={gate:g,counter:c};
 *     var el=d.createElement(s);el.async=1;el.src=u;
 *     d.head.appendChild(el);
 *   })(window,document,'script',
 *     'https://your-domain.ru/close-window/popup.js',
 *     'https://your-domain.ru/close-window/api/gate.php',
 *     '12345678');   // ID счётчика, можно убрать
 *   </script>
 * ─────────────────────────────────────────────────────
 *
 * Поведение:
 *  - Срабатывает 1 раз на сессию (sessionStorage)
 *  - Курсор уходит за верхний край окна → случайный попап A/B/C
 *  - Если Яндекс.Метрика доступна → шлёт gate «open»
 *  - Если Метрика заблокирована  → «open» не шлём, «lead» шлём с has_ym=0
 */
(function () {
  'use strict';

  /* ── 1. Получаем атрибуты: gate URL, базовый URL, counter ── */
  var scriptEl = document.currentScript || (function () {
    var all = document.querySelectorAll('script[data-gate]');
    return all[all.length - 1] || null;
  })();

  var gateUrl = (scriptEl && scriptEl.dataset.gate)
    || (window._EI && window._EI.gate)
    || '';

  var counterId = (scriptEl && scriptEl.dataset.counter)
    || (window._EI && window._EI.counter)
    || '';

  /* базовый URL — директория, где лежит popup.js.
     document.currentScript === null у async-скриптов, ищем по src. */
  var baseUrl = (function () {
    if (scriptEl && scriptEl.src) {
      return scriptEl.src.replace(/\/[^\/\?#]+(\?[^#]*)?(#.*)?$/, '');
    }
    /* async fallback: ищем тег с popup.js в src */
    var all = document.scripts;
    for (var i = all.length - 1; i >= 0; i--) {
      if (all[i].src && all[i].src.indexOf('popup.js') !== -1) {
        return all[i].src.replace(/\/[^\/\?#]+(\?[^#]*)?(#.*)?$/, '');
      }
    }
    return '';
  }());

  /* ── 2. Один показ на браузерную сессию ── */
  var SS_KEY = '_ei_shown';
  var _alreadyShown = false;
  try { _alreadyShown = !!sessionStorage.getItem(SS_KEY); } catch (e) {}

  /* ── 3. Получение Яндекс ClientID (аналогично ct.js) ── */
  function getYmClientId(cb) {
    /* a) localStorage */
    try {
      var lsVal = localStorage.getItem('_ym_uid');
      if (lsVal) {
        var m = lsVal.replace(/"/g, '').match(/\d+/);
        if (m) { cb(m[0]); return; }
      }
    } catch (e) {}

    /* b) cookie */
    var cm = document.cookie.match(/_ym_uid=(\d+)/);
    if (cm) { cb(cm[1]); return; }

    /* c) ym API с таймаутом 1.5с */
    if (counterId && window.ym) {
      var done = false;
      var t = setTimeout(function () { if (!done) { done = true; cb(''); } }, 1500);
      try {
        ym(counterId, 'getClientID', function (id) {
          if (!done) { done = true; clearTimeout(t); cb(id || ''); }
        });
        return;
      } catch (e) {}
    }

    cb('');
  }

  /* ── 4. Динамическая загрузка скрипта попапа ── */
  function loadPopupScript(variant, cb) {
    var globalName = 'Popup' + variant;
    if (window[globalName]) { cb(window[globalName]); return; }

    var s = document.createElement('script');
    s.src = baseUrl + '/popups/popup-' + variant.toLowerCase() + '.js?v=2';
    s.onload  = function () { cb(window[globalName] || null); };
    s.onerror = function () { cb(null); };
    document.head.appendChild(s);
  }

  /* ── 5. Отправка события на гейт ── */
  function sendEvent(data) {
    if (!gateUrl) return;
    data.url      = window.location.href;
    data.referrer = document.referrer || '';
    try {
      fetch(gateUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(data).toString(),
        keepalive: true
      }).catch(function () {});
    } catch (e) {
      /* fetch недоступен — fallback на Image beacon */
      var params = new URLSearchParams(data).toString();
      new Image().src = gateUrl + '?' + params;
    }
  }

  /* ── 6. Основная логика показа ── */
  var VARIANTS = ['A', 'B', 'C'];

  function showVariant(variant, skipSession) {
    /* Блокируем повторный показ в этой сессии (если не ручной вызов) */
    if (!skipSession) {
      try { sessionStorage.setItem(SS_KEY, '1'); } catch (e) {}
    }

    getYmClientId(function (ymId) {
      var hasYm = ymId ? 1 : 0;

      loadPopupScript(variant, function (popup) {
        if (!popup) return;

        popup.onSubmit(function (data) {
          sendEvent({
            action:       'lead',
            variant:      variant,
            phone:        data.phone     || '',
            messenger:    data.messenger || '',
            ym_client_id: ymId,
            has_ym:       hasYm
          });
        });

        /* Уведомление об открытии — только если ym есть */
        if (hasYm) {
          sendEvent({
            action:       'open',
            variant:      variant,
            ym_client_id: ymId,
            has_ym:       1
          });
        }

        popup.show();
      });
    });
  }

  function triggerRandom() {
    var v = VARIANTS[Math.floor(Math.random() * VARIANTS.length)];
    showVariant(v, false);
  }

  /* ── 7. Exit-intent триггер: курсор уходит за верхний край ── */
  var fired = false;
  document.addEventListener('mouseout', function (e) {
    if (fired || _alreadyShown) return;
    var to = e.relatedTarget || e.toElement;
    if (to) return;
    if (e.clientY > 10) return;
    fired = true;
    triggerRandom();
  });

  /* ── 8. Публичное API (для тестовых страниц) ── */
  window.EI = {
    /** Показать конкретный вариант вручную (не считается в сессию) */
    show: function (variant) {
      var v = variant ? variant.toUpperCase() : VARIANTS[Math.floor(Math.random() * VARIANTS.length)];
      showVariant(v, true /* skipSession */);
    },
    /** Сбросить флаг сессии (для повторного тестирования) */
    reset: function () {
      try { sessionStorage.removeItem(SS_KEY); } catch (e) {}
      fired = false;
      _alreadyShown = false;
    }
  };

})();
