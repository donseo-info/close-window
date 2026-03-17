/* ═══════════════════════════════════════════════════════
   POPUP B — Бесплатный подарок за контакт
   Дизайн: чистый белый, зелёный акцент, «щедрость»
═══════════════════════════════════════════════════════ */
(function () {
  "use strict";

  var _onSubmitCb = null;
  var _onCloseCb  = null;

  function injectStyles() {
    if (document.getElementById("pb-styles")) return;
    var s = document.createElement("style");
    s.id = "pb-styles";
    s.textContent = [
      "#pb-overlay{",
        "position:fixed;inset:0;z-index:99999;",
        "background:rgba(0,0,0,.48);",
        "display:flex;align-items:center;justify-content:center;",
        "padding:16px;box-sizing:border-box;",
        "opacity:0;transition:opacity .25s;",
      "}",
      "#pb-overlay.pb-on{opacity:1;}",

      "#pb-box{",
        "background:#fff;border-radius:12px;",
        "width:100%;max-width:440px;",
        "position:relative;overflow:hidden;",
        "transform:scale(.94);",
        "transition:transform .32s cubic-bezier(.22,1,.36,1);",
        "box-shadow:0 12px 50px rgba(0,0,0,.16);",
      "}",
      "#pb-overlay.pb-on #pb-box{transform:scale(1);}",

      /* подарочная лента сверху */
      ".pb-ribbon{",
        "height:5px;",
        "background:linear-gradient(90deg,#1db954,#00c853,#1db954);",
      "}",

      ".pb-inner{padding:24px 28px 28px;}",
      "@media(max-width:380px){.pb-inner{padding:18px 18px 22px;}}",

      /* иконка + заголовок */
      ".pb-icon-row{",
        "display:flex;align-items:center;gap:14px;margin-bottom:16px;",
      "}",
      ".pb-icon-wrap{",
        "width:60px;height:60px;flex-shrink:0;",
        "background:#e8f8ef;border-radius:12px;",
        "display:flex;align-items:center;justify-content:center;",
        "font-size:28px;",
      "}",
      ".pb-title-col h2{",
        "font-family:system-ui,'Segoe UI',sans-serif;font-weight:900;",
        "font-size:clamp(18px,4.5vw,22px);",
        "color:#111;margin:0 0 3px;line-height:1.2;",
      "}",
      ".pb-title-col p{",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:13px;",
        "color:#888;margin:0;font-weight:600;",
      "}",

      /* карточка подарка */
      ".pb-gift-card{",
        "background:#f7fdf9;border:1.5px solid #c6f0d5;",
        "border-radius:10px;padding:14px 16px;",
        "display:flex;align-items:center;gap:12px;",
        "margin-bottom:18px;",
      "}",
      ".pb-gift-thumb{",
        "width:44px;height:56px;flex-shrink:0;",
        "background:linear-gradient(145deg,#1db954,#00c853);",
        "border-radius:5px;",
        "display:flex;align-items:center;justify-content:center;",
        "font-size:22px;",
        "box-shadow:2px 2px 8px rgba(29,185,84,.25);",
      "}",
      ".pb-gift-info{}",
      ".pb-gift-name{",
        "font-family:system-ui,'Segoe UI',sans-serif;font-weight:800;",
        "font-size:14px;color:#111;display:block;margin-bottom:2px;",
      "}",
      ".pb-gift-desc{",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;color:#888;",
      "}",
      ".pb-free{",
        "margin-left:auto;flex-shrink:0;",
        "background:#1db954;color:#fff;",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:11px;font-weight:800;",
        "padding:3px 9px;border-radius:20px;letter-spacing:.04em;",
      "}",

      /* форма */
      ".pb-label{",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:13px;font-weight:700;",
        "color:#444;display:block;margin-bottom:6px;",
      "}",
      ".pb-phone{",
        "width:100%;box-sizing:border-box;",
        "border:2px solid #e0e0e0;border-radius:8px;",
        "padding:11px 14px;",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;color:#111;",
        "outline:none;transition:border-color .2s;",
      "}",
      ".pb-phone::placeholder{color:#ccc;}",
      ".pb-phone:focus{border-color:#1db954;}",

      ".pb-msg-label{",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;font-weight:600;",
        "color:#aaa;margin:12px 0 7px;display:block;",
      "}",
      ".pb-messengers{display:flex;gap:7px;}",

      ".pb-m{",
        "flex:1;display:flex;align-items:center;justify-content:center;gap:5px;",
        "padding:9px 0;border-radius:7px;",
        "border:2px solid #ebebeb;background:#fafafa;",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;font-weight:700;",
        "color:#666;cursor:pointer;transition:all .18s;",
      "}",
      ".pb-m.tg:hover,.pb-m.tg.sel{background:#0088cc;border-color:#0088cc;color:#fff;}",
      ".pb-m.wa:hover,.pb-m.wa.sel{background:#25d366;border-color:#25d366;color:#fff;}",
      ".pb-m.mx:hover,.pb-m.mx.sel{background:#6c3fff;border-color:#6c3fff;color:#fff;}",

      ".pb-btn{",
        "width:100%;margin-top:13px;",
        "background:#1db954;border:none;border-radius:8px;",
        "padding:13px;",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;font-weight:800;",
        "color:#fff;cursor:pointer;",
        "transition:background .18s,transform .15s;",
      "}",
      ".pb-btn:hover{background:#17a348;transform:translateY(-1px);}",
      ".pb-btn:active{transform:translateY(0);}",

      ".pb-fine{",
        "font-family:system-ui,'Segoe UI',sans-serif;font-size:11px;",
        "color:#ccc;text-align:center;margin-top:9px;",
      "}",

      /* закрыть */
      ".pb-close{",
        "position:absolute;top:8px;right:8px;",
        "background:rgba(0,0,0,.06);border:none;cursor:pointer;",
        "color:#999;font-size:16px;",
        "width:34px;height:34px;border-radius:50%;",
        "display:flex;align-items:center;justify-content:center;",
        "transition:all .15s;z-index:10;",
      "}",
      ".pb-close:hover{background:rgba(0,0,0,.12);color:#333;}",

      /* success */
      ".pb-ok{text-align:center;padding:28px 16px;}",
      ".pb-ok-ico{font-size:46px;display:block;margin-bottom:10px;}",
      ".pb-ok h3{font-family:system-ui,'Segoe UI',sans-serif;font-weight:900;font-size:20px;color:#1db954;margin:0 0 6px;}",
      ".pb-ok p{font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;color:#888;margin:0;}",
    ].join("");
    document.head.appendChild(s);
  }

  var ICONS = {
    tg: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L8.327 13.4l-2.96-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.82.16z"/></svg>',
    wa: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>',
    mx: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 2c4.418 0 8 3.582 8 8s-3.582 8-8 8a7.96 7.96 0 01-4.076-1.115L6 19.5l.615-1.924A7.96 7.96 0 014 12c0-4.418 3.582-8 8-8zm-3 5v2h6V9H9zm0 4v2h4v-2H9z"/></svg>',
  };

  function buildDOM() {
    var ov = document.createElement("div");
    ov.id = "pb-overlay";
    ov.innerHTML = [
      '<div id="pb-box">',
        '<div class="pb-ribbon"></div>',
        '<button class="pb-close" id="pb-x">✕</button>',
        '<div class="pb-inner">',
          '<div class="pb-icon-row">',
            '<div class="pb-icon-wrap">🎁</div>',
            '<div class="pb-title-col">',
              '<h2>Не уходите с пустыми руками!</h2>',
              '<p>Бесплатный подарок для вас</p>',
            '</div>',
          '</div>',
          '<div class="pb-gift-card">',
            '<div class="pb-gift-thumb">📖</div>',
            '<div class="pb-gift-info">',
              '<span class="pb-gift-name">«Как за 7 дней улучшить результат»</span>',
              '<span class="pb-gift-desc">PDF-гайд · 24 страницы</span>',
            '</div>',
            '<span class="pb-free">FREE</span>',
          '</div>',
          '<div id="pb-form-area">',
            '<form id="pb-form" novalidate>',
              '<label class="pb-label">Ваш номер телефона</label>',
              '<input class="pb-phone" type="tel" id="pb-tel" placeholder="+7 (___) ___-__-__" autocomplete="tel"/>',
              '<span class="pb-msg-label">Куда прислать?</span>',
              '<div class="pb-messengers">',
                '<button type="button" class="pb-m tg" data-m="tg">' + ICONS.tg + 'Telegram</button>',
                '<button type="button" class="pb-m wa" data-m="wa">' + ICONS.wa + 'WhatsApp</button>',
                '<button type="button" class="pb-m mx" data-m="mx">' + ICONS.mx + 'Max</button>',
              '</div>',
              '<button class="pb-btn" type="submit">📬 Получить гайд бесплатно</button>',
            '</form>',
            '<p class="pb-fine">🔒 Без спама. Отписаться в один клик.</p>',
          '</div>',
        '</div>',
      '</div>',
    ].join("");
    document.body.appendChild(ov);

    var activeM = null;
    ov.querySelectorAll(".pb-m").forEach(function(btn){
      btn.addEventListener("click", function(){
        ov.querySelectorAll(".pb-m").forEach(function(b){ b.classList.remove("sel"); });
        btn.classList.add("sel");
        activeM = btn.dataset.m;
      });
    });

    ov.addEventListener("click", function(e){ if(e.target===ov) hide(); });
    document.getElementById("pb-x").addEventListener("click", hide);
    document.addEventListener("keydown", function esc(e){
      if(e.key==="Escape"){ hide(); document.removeEventListener("keydown",esc); }
    });
    document.getElementById("pb-form").addEventListener("submit", function(e){
      e.preventDefault();
      var tel = document.getElementById("pb-tel").value.trim();
      if (!tel) { document.getElementById("pb-tel").focus(); return; }
      if (_onSubmitCb) _onSubmitCb({ variant:"B", phone: tel, messenger: activeM });
      document.getElementById("pb-form-area").innerHTML = [
        '<div class="pb-ok">',
          '<span class="pb-ok-ico">📬</span>',
          '<h3>Гайд уже летит к вам!</h3>',
          '<p>Менеджер напишет вам в ближайшие 5 минут.</p>',
        '</div>',
      ].join("");
      setTimeout(hide, 3000);
    });
  }

  function show() {
    injectStyles();
    if (!document.getElementById("pb-overlay")) buildDOM();
    var el = document.getElementById("pb-overlay");
    el.style.display = "flex";
    requestAnimationFrame(function(){
      requestAnimationFrame(function(){ el.classList.add("pb-on"); });
    });
  }

  function hide() {
    var el = document.getElementById("pb-overlay");
    if (!el) return;
    el.classList.remove("pb-on");
    if (_onCloseCb) _onCloseCb({ variant:"B" });
    setTimeout(function(){ if(el.parentNode) el.parentNode.removeChild(el); }, 280);
  }

  window.PopupB = {
    show: show, hide: hide,
    onSubmit: function(cb){ _onSubmitCb=cb; },
    onClose:  function(cb){ _onCloseCb=cb; },
  };
})();
