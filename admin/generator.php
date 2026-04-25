<?php
/**
 * generator.php — генерация JS-файлов попапов из конфига
 * Вызывается из admin/index.php при сохранении настроек
 */

/* ── Escape строки для вставки в JS single-quoted string ── */
function jsq(string $s): string {
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace("'",  "\\'",  $s);
    $s = str_replace(["\r\n", "\n", "\r"], ['\\n', '\\n', '\\n'], $s);
    $s = str_replace('</', '<\\/', $s);
    return $s;
}

/* ── Затемнить hex-цвет ── */
function darkenHex(string $hex, float $f = 0.80): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return sprintf('#%02x%02x%02x',
        max(0, (int)(hexdec(substr($hex, 0, 2)) * $f)),
        max(0, (int)(hexdec(substr($hex, 2, 2)) * $f)),
        max(0, (int)(hexdec(substr($hex, 4, 2)) * $f))
    );
}

/* ── Дефолтные конфиги ── */
function popupDefaults(string $v): array {
    $d = [
        'A' => [
            'enabled'  => 1,
            'color'    => '#e02020',
            'badge'    => '🔥 Только сегодня',
            'headline' => 'Уже уходите?<br>Подождите — скидка&nbsp;−10%!',
            'subtext'  => 'Оставьте номер и получите промокод прямо сейчас',
            'btn'      => 'Получить скидку −10%',
            'ok_title' => 'Промокод отправлен!',
            'ok_text'  => 'Менеджер напишет вам в течение 5 минут.',
            'timer'    => 180,
        ],
        'B' => [
            'enabled'   => 1,
            'color'     => '#1db954',
            'headline'  => 'Не уходите с пустыми руками!',
            'subtext'   => 'Бесплатный подарок для вас',
            'gift_name' => '«Как за 7 дней улучшить результат»',
            'gift_desc' => 'PDF-гайд · 24 страницы',
            'btn'       => '📬 Получить гайд бесплатно',
            'ok_title'  => 'Гайд уже летит к вам!',
            'ok_text'   => 'Менеджер напишет вам в ближайшие 5 минут.',
        ],
        'C' => [
            'enabled'  => 1,
            'color'    => '#2563eb',
            'label'    => '🤔 Подождите секунду',
            'headline' => 'Уже почти всё готово…<br>Остался всего 1 шаг!',
            'check1'   => 'Цели определены',
            'check2'   => 'Тарифный план выбран',
            'check3'   => 'Бюджет согласован',
            'check4'   => 'Условия изучены',
            'check5'   => 'Контакт для связи с менеджером',
            'btn'      => '✔ Завершить оформление',
            'ok_title' => 'Отлично! Вы на финише.',
            'ok_text'  => 'Менеджер свяжется с вами в течение 15 минут.',
        ],
    ];
    return $d[$v] ?? [];
}

/* ── SVG иконки мессенджеров ── */
function svgTg(int $sz = 16): string {
    return '<svg width="'.$sz.'" height="'.$sz.'" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm5.894 8.221-1.97 9.28c-.145.658-.537.818-1.084.508l-3-2.21-1.447 1.394c-.16.16-.295.295-.605.295l.213-3.053 5.56-5.023c.242-.213-.054-.333-.373-.12L8.327 13.4l-2.96-.924c-.643-.204-.657-.643.136-.953l11.57-4.461c.537-.194 1.006.131.82.16z"/></svg>';
}
function svgWa(int $sz = 16): string {
    return '<svg width="'.$sz.'" height="'.$sz.'" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
}
function svgMx(int $sz = 16): string {
    return '<svg width="'.$sz.'" height="'.$sz.'" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12c0 1.89.525 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2zm0 2c4.418 0 8 3.582 8 8s-3.582 8-8 8a7.96 7.96 0 01-4.076-1.115L6 19.5l.615-1.924A7.96 7.96 0 014 12c0-4.418 3.582-8 8-8zm-3 5v2h6V9H9zm0 4v2h4v-2H9z"/></svg>';
}

/* ════════════════════════════════════════════════════════
   POPUP A — Скидка + таймер
════════════════════════════════════════════════════════ */
function generatePopupA(array $c): string {
    $col   = $c['color'];
    $colD  = darkenHex($col);
    $timer = (int)($c['timer'] ?? 180);

    $css = "#pa-overlay{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;opacity:0;transition:opacity .25s}"
         . "#pa-overlay.pa-on{opacity:1}"
         . "#pa-box{background:#fff;border-radius:8px;width:100%;max-width:460px;overflow:hidden;position:relative;transform:translateY(16px);transition:transform .3s cubic-bezier(.22,1,.36,1);box-shadow:0 8px 40px rgba(0,0,0,.18)}"
         . "#pa-overlay.pa-on #pa-box{transform:translateY(0)}"
         . ".pa-head{background:{$col};padding:22px 28px 18px;text-align:center;position:relative}"
         . ".pa-badge{display:inline-block;background:rgba(255,255,255,.18);color:#fff;font-family:system-ui,'Segoe UI',sans-serif;font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;padding:3px 10px;border-radius:20px;margin-bottom:8px}"
         . ".pa-head h2{font-family:system-ui,'Segoe UI',sans-serif;font-weight:800;font-size:clamp(20px,5vw,26px);color:#fff;margin:0 0 4px;line-height:1.15}"
         . ".pa-head p{font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;color:rgba(255,255,255,.85);margin:0}"
         . ".pa-timer{display:flex;justify-content:center;gap:6px;margin-top:14px}"
         . ".pa-t-block{background:rgba(0,0,0,.2);border-radius:5px;padding:6px 10px;min-width:46px;text-align:center}"
         . ".pa-t-num{display:block;font-family:system-ui,'Segoe UI',sans-serif;font-size:22px;font-weight:800;color:#fff;line-height:1}"
         . ".pa-t-lbl{display:block;font-family:system-ui,'Segoe UI',sans-serif;font-size:9px;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.06em;margin-top:2px}"
         . ".pa-t-sep{font-family:system-ui,'Segoe UI',sans-serif;font-size:22px;font-weight:800;color:rgba(255,255,255,.5);align-self:flex-start;padding-top:6px}"
         . ".pa-body{padding:22px 28px 26px}"
         . ".pa-phone-label{font-family:system-ui,'Segoe UI',sans-serif;font-size:13px;font-weight:600;color:#333;display:block;margin-bottom:6px}"
         . ".pa-phone{width:100%;box-sizing:border-box;border:2px solid #e5e5e5;border-radius:6px;padding:11px 14px;font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;color:#111;outline:none;transition:border-color .2s}"
         . ".pa-phone::placeholder{color:#bbb}"
         . ".pa-phone:focus{border-color:{$col}}"
         . ".pa-msg-label{font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;color:#888;margin:14px 0 8px;display:block}"
         . ".pa-messengers{display:flex;gap:8px}"
         . ".pa-m-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 0;border-radius:6px;border:2px solid transparent;font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;font-weight:600;cursor:pointer;transition:all .18s}"
         . ".pa-m-btn.tg{background:#e8f4fd;border-color:#e8f4fd;color:#0088cc}"
         . ".pa-m-btn.tg:hover,.pa-m-btn.tg.active{background:#0088cc;border-color:#0088cc;color:#fff}"
         . ".pa-m-btn.wa{background:#e8f8ef;border-color:#e8f8ef;color:#25d366}"
         . ".pa-m-btn.wa:hover,.pa-m-btn.wa.active{background:#25d366;border-color:#25d366;color:#fff}"
         . ".pa-m-btn.mx{background:#f0eeff;border-color:#f0eeff;color:#6c3fff}"
         . ".pa-m-btn.mx:hover,.pa-m-btn.mx.active{background:#6c3fff;border-color:#6c3fff;color:#fff}"
         . ".pa-submit{width:100%;margin-top:14px;background:{$col};border:none;border-radius:6px;padding:13px;font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;font-weight:700;color:#fff;cursor:pointer;letter-spacing:.02em;transition:background .18s,transform .15s}"
         . ".pa-submit:hover{background:{$colD};transform:translateY(-1px)}"
         . ".pa-submit:active{transform:translateY(0)}"
         . ".pa-agree{font-family:system-ui,'Segoe UI',sans-serif;font-size:11px;color:#bbb;text-align:center;margin-top:10px}"
         . ".pa-close{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.2);border:none;cursor:pointer;color:#fff;font-size:16px;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:background .15s;z-index:10;flex-shrink:0}"
         . ".pa-close:hover{background:rgba(255,255,255,.35)}"
         . ".pa-success{text-align:center;padding:32px 20px}"
         . ".pa-success-ico{font-size:48px;display:block;margin-bottom:12px}"
         . ".pa-success h3{font-family:system-ui,'Segoe UI',sans-serif;font-size:20px;font-weight:800;color:{$col};margin:0 0 8px}"
         . ".pa-success p{font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;color:#666;margin:0}";

    $dom = '<div id="pa-box">'
         . '<div class="pa-head">'
         . '<button class="pa-close" id="pa-x">✕</button>'
         . '<div class="pa-badge">' . $c['badge'] . '</div>'
         . '<h2>' . $c['headline'] . '</h2>'
         . '<p>' . $c['subtext'] . '</p>'
         . '<div class="pa-timer">'
         . '<div class="pa-t-block"><span class="pa-t-num" id="pa-min">03</span><span class="pa-t-lbl">мин</span></div>'
         . '<span class="pa-t-sep">:</span>'
         . '<div class="pa-t-block"><span class="pa-t-num" id="pa-sec">00</span><span class="pa-t-lbl">сек</span></div>'
         . '</div></div>'
         . '<div class="pa-body"><div id="pa-form-area">'
         . '<label class="pa-phone-label">Ваш номер телефона</label>'
         . '<form id="pa-form" novalidate>'
         . '<input class="pa-phone" type="tel" id="pa-tel" placeholder="+7 (___) ___-__-__" autocomplete="tel"/>'
         . '<span class="pa-msg-label">Куда удобно написать?</span>'
         . '<div class="pa-messengers">'
         . '<button type="button" class="pa-m-btn tg" data-m="tg">' . svgTg() . 'Telegram</button>'
         . '<button type="button" class="pa-m-btn wa" data-m="wa">' . svgWa() . 'WhatsApp</button>'
         . '<button type="button" class="pa-m-btn mx" data-m="mx">' . svgMx() . 'Max</button>'
         . '</div>'
         . '<button class="pa-submit" type="submit">' . $c['btn'] . '</button>'
         . '<input id="pa-hp" type="email" name="email" tabindex="-1" autocomplete="off" style="position:absolute!important;left:-9999px!important;opacity:0!important;height:0!important;pointer-events:none!important;" aria-hidden="true">'
         . '</form>'
         . '<p class="pa-agree">Нажимая кнопку, вы соглашаетесь с политикой конфиденциальности</p>'
         . '</div></div></div>';

    $ok = '<div class="pa-success">'
        . '<span class="pa-success-ico">🎉</span>'
        . '<h3>' . $c['ok_title'] . '</h3>'
        . '<p>' . $c['ok_text'] . '</p>'
        . '</div>';

    $cssQ = jsq($css);
    $domQ = jsq($dom);
    $okQ  = jsq($ok);

    return "(function(){'use strict';"
         . "var _t=null,_onSub=null,_onCls=null;"
         . "function injectStyles(){if(document.getElementById('pa-styles'))return;"
         . "var s=document.createElement('style');s.id='pa-styles';"
         . "s.textContent='{$cssQ}';document.head.appendChild(s);}"
         . "function startTimer(){var total={$timer};"
         . "function tick(){var m=document.getElementById('pa-min'),s=document.getElementById('pa-sec');"
         . "if(!m){clearInterval(_t);return;}"
         . "m.textContent=String(Math.floor(total/60)).padStart(2,'0');"
         . "s.textContent=String(total%60).padStart(2,'0');"
         . "if(total--<=0)clearInterval(_t);}tick();_t=setInterval(tick,1000);}"
         . "function buildDOM(){var ov=document.createElement('div');ov.id='pa-overlay';"
         . "ov.innerHTML='{$domQ}';document.body.appendChild(ov);"
         . "var aM=null;"
         . "ov.querySelectorAll('.pa-m-btn').forEach(function(b){b.addEventListener('click',function(){"
         . "ov.querySelectorAll('.pa-m-btn').forEach(function(x){x.classList.remove('active');});"
         . "b.classList.add('active');aM=b.dataset.m;});});"
         . "ov.addEventListener('click',function(e){if(e.target===ov)hide();});"
         . "document.getElementById('pa-x').addEventListener('click',hide);"
         . "document.addEventListener('keydown',function esc(e){if(e.key==='Escape'){hide();document.removeEventListener('keydown',esc);}});"
         . "document.getElementById('pa-form').addEventListener('submit',function(e){"
         . "e.preventDefault();var tel=document.getElementById('pa-tel').value.trim();"
         . "if(!tel){document.getElementById('pa-tel').focus();return;}"
         . "var hp=(document.getElementById('pa-hp')||{}).value||'';"
         . "if(_onSub)_onSub({variant:'A',phone:tel,messenger:aM,email:hp});"
         . "document.getElementById('pa-form-area').innerHTML='{$okQ}';"
         . "setTimeout(hide,3000);});}"
         . "function show(){injectStyles();if(!document.getElementById('pa-overlay'))buildDOM();"
         . "var el=document.getElementById('pa-overlay');el.style.display='flex';"
         . "requestAnimationFrame(function(){requestAnimationFrame(function(){el.classList.add('pa-on');});});startTimer();}"
         . "function hide(){var el=document.getElementById('pa-overlay');if(!el)return;"
         . "el.classList.remove('pa-on');clearInterval(_t);"
         . "if(_onCls)_onCls({variant:'A'});"
         . "setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el);},280);}"
         . "window.PopupA={show:show,hide:hide,"
         . "onSubmit:function(cb){_onSub=cb;},"
         . "onClose:function(cb){_onCls=cb;}};"
         . "})();";
}

/* ════════════════════════════════════════════════════════
   POPUP B — Подарок
════════════════════════════════════════════════════════ */
function generatePopupB(array $c): string {
    $col  = $c['color'];
    $colD = darkenHex($col);

    $css = "#pb-overlay{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.48);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;opacity:0;transition:opacity .25s}"
         . "#pb-overlay.pb-on{opacity:1}"
         . "#pb-box{background:#fff;border-radius:12px;width:100%;max-width:440px;position:relative;overflow:hidden;transform:scale(.94);transition:transform .32s cubic-bezier(.22,1,.36,1);box-shadow:0 12px 50px rgba(0,0,0,.16)}"
         . "#pb-overlay.pb-on #pb-box{transform:scale(1)}"
         . ".pb-ribbon{height:5px;background:linear-gradient(90deg,{$col},{$colD},{$col})}"
         . ".pb-inner{padding:24px 28px 28px}"
         . "@media(max-width:380px){.pb-inner{padding:18px 18px 22px}}"
         . ".pb-icon-row{display:flex;align-items:center;gap:14px;margin-bottom:16px}"
         . ".pb-icon-wrap{width:60px;height:60px;flex-shrink:0;background:#e8f8ef;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:28px}"
         . ".pb-title-col h2{font-family:system-ui,'Segoe UI',sans-serif;font-weight:900;font-size:clamp(18px,4.5vw,22px);color:#111;margin:0 0 3px;line-height:1.2}"
         . ".pb-title-col p{font-family:system-ui,'Segoe UI',sans-serif;font-size:13px;color:#888;margin:0;font-weight:600}"
         . ".pb-gift-card{background:#f7fdf9;border:1.5px solid #c6f0d5;border-radius:10px;padding:14px 16px;display:flex;align-items:center;gap:12px;margin-bottom:18px}"
         . ".pb-gift-thumb{width:44px;height:56px;flex-shrink:0;background:linear-gradient(145deg,{$col},{$colD});border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:2px 2px 8px rgba(0,0,0,.15)}"
         . ".pb-gift-name{font-family:system-ui,'Segoe UI',sans-serif;font-weight:800;font-size:14px;color:#111;display:block;margin-bottom:2px}"
         . ".pb-gift-desc{font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;color:#888}"
         . ".pb-free{margin-left:auto;flex-shrink:0;background:{$col};color:#fff;font-family:system-ui,'Segoe UI',sans-serif;font-size:11px;font-weight:800;padding:3px 9px;border-radius:20px;letter-spacing:.04em}"
         . ".pb-label{font-family:system-ui,'Segoe UI',sans-serif;font-size:13px;font-weight:700;color:#444;display:block;margin-bottom:6px}"
         . ".pb-phone{width:100%;box-sizing:border-box;border:2px solid #e0e0e0;border-radius:8px;padding:11px 14px;font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;color:#111;outline:none;transition:border-color .2s}"
         . ".pb-phone::placeholder{color:#ccc}"
         . ".pb-phone:focus{border-color:{$col}}"
         . ".pb-msg-label{font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;font-weight:600;color:#aaa;margin:12px 0 7px;display:block}"
         . ".pb-messengers{display:flex;gap:7px}"
         . ".pb-m{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:9px 0;border-radius:7px;border:2px solid #ebebeb;background:#fafafa;font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;font-weight:700;color:#666;cursor:pointer;transition:all .18s}"
         . ".pb-m.tg:hover,.pb-m.tg.sel{background:#0088cc;border-color:#0088cc;color:#fff}"
         . ".pb-m.wa:hover,.pb-m.wa.sel{background:#25d366;border-color:#25d366;color:#fff}"
         . ".pb-m.mx:hover,.pb-m.mx.sel{background:#6c3fff;border-color:#6c3fff;color:#fff}"
         . ".pb-btn{width:100%;margin-top:13px;background:{$col};border:none;border-radius:8px;padding:13px;font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;font-weight:800;color:#fff;cursor:pointer;transition:background .18s,transform .15s}"
         . ".pb-btn:hover{background:{$colD};transform:translateY(-1px)}"
         . ".pb-btn:active{transform:translateY(0)}"
         . ".pb-fine{font-family:system-ui,'Segoe UI',sans-serif;font-size:11px;color:#ccc;text-align:center;margin-top:9px}"
         . ".pb-close{position:absolute;top:8px;right:8px;background:rgba(0,0,0,.06);border:none;cursor:pointer;color:#999;font-size:16px;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:all .15s;z-index:10}"
         . ".pb-close:hover{background:rgba(0,0,0,.12);color:#333}"
         . ".pb-ok{text-align:center;padding:28px 16px}"
         . ".pb-ok-ico{font-size:46px;display:block;margin-bottom:10px}"
         . ".pb-ok h3{font-family:system-ui,'Segoe UI',sans-serif;font-weight:900;font-size:20px;color:{$col};margin:0 0 6px}"
         . ".pb-ok p{font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;color:#888;margin:0}";

    $dom = '<div id="pb-box">'
         . '<div class="pb-ribbon"></div>'
         . '<button class="pb-close" id="pb-x">✕</button>'
         . '<div class="pb-inner">'
         . '<div class="pb-icon-row">'
         . '<div class="pb-icon-wrap">🎁</div>'
         . '<div class="pb-title-col"><h2>' . $c['headline'] . '</h2><p>' . $c['subtext'] . '</p></div>'
         . '</div>'
         . '<div class="pb-gift-card">'
         . '<div class="pb-gift-thumb">📖</div>'
         . '<div class="pb-gift-info">'
         . '<span class="pb-gift-name">' . $c['gift_name'] . '</span>'
         . '<span class="pb-gift-desc">' . $c['gift_desc'] . '</span>'
         . '</div>'
         . '<span class="pb-free">FREE</span>'
         . '</div>'
         . '<div id="pb-form-area">'
         . '<form id="pb-form" novalidate>'
         . '<label class="pb-label">Ваш номер телефона</label>'
         . '<input class="pb-phone" type="tel" id="pb-tel" placeholder="+7 (___) ___-__-__" autocomplete="tel"/>'
         . '<span class="pb-msg-label">Куда прислать?</span>'
         . '<div class="pb-messengers">'
         . '<button type="button" class="pb-m tg" data-m="tg">' . svgTg(14) . 'Telegram</button>'
         . '<button type="button" class="pb-m wa" data-m="wa">' . svgWa(14) . 'WhatsApp</button>'
         . '<button type="button" class="pb-m mx" data-m="mx">' . svgMx(14) . 'Max</button>'
         . '</div>'
         . '<button class="pb-btn" type="submit">' . $c['btn'] . '</button>'
         . '<input id="pb-hp" type="email" name="email" tabindex="-1" autocomplete="off" style="position:absolute!important;left:-9999px!important;opacity:0!important;height:0!important;pointer-events:none!important;" aria-hidden="true">'
         . '</form>'
         . '<p class="pb-fine">🔒 Без спама. Отписаться в один клик.</p>'
         . '</div></div></div>';

    $ok = '<div class="pb-ok">'
        . '<span class="pb-ok-ico">📤</span>'
        . '<h3>' . $c['ok_title'] . '</h3>'
        . '<p>' . $c['ok_text'] . '</p>'
        . '</div>';

    $cssQ = jsq($css);
    $domQ = jsq($dom);
    $okQ  = jsq($ok);

    return "(function(){'use strict';"
         . "var _onSub=null,_onCls=null;"
         . "function injectStyles(){if(document.getElementById('pb-styles'))return;"
         . "var s=document.createElement('style');s.id='pb-styles';"
         . "s.textContent='{$cssQ}';document.head.appendChild(s);}"
         . "function buildDOM(){var ov=document.createElement('div');ov.id='pb-overlay';"
         . "ov.innerHTML='{$domQ}';document.body.appendChild(ov);"
         . "var aM=null;"
         . "ov.querySelectorAll('.pb-m').forEach(function(b){b.addEventListener('click',function(){"
         . "ov.querySelectorAll('.pb-m').forEach(function(x){x.classList.remove('sel');});"
         . "b.classList.add('sel');aM=b.dataset.m;});});"
         . "ov.addEventListener('click',function(e){if(e.target===ov)hide();});"
         . "document.getElementById('pb-x').addEventListener('click',hide);"
         . "document.addEventListener('keydown',function esc(e){if(e.key==='Escape'){hide();document.removeEventListener('keydown',esc);}});"
         . "document.getElementById('pb-form').addEventListener('submit',function(e){"
         . "e.preventDefault();var tel=document.getElementById('pb-tel').value.trim();"
         . "if(!tel){document.getElementById('pb-tel').focus();return;}"
         . "var hp=(document.getElementById('pb-hp')||{}).value||'';"
         . "if(_onSub)_onSub({variant:'B',phone:tel,messenger:aM,email:hp});"
         . "document.getElementById('pb-form-area').innerHTML='{$okQ}';"
         . "setTimeout(hide,3000);});}"
         . "function show(){injectStyles();if(!document.getElementById('pb-overlay'))buildDOM();"
         . "var el=document.getElementById('pb-overlay');el.style.display='flex';"
         . "requestAnimationFrame(function(){requestAnimationFrame(function(){el.classList.add('pb-on');});});}"
         . "function hide(){var el=document.getElementById('pb-overlay');if(!el)return;"
         . "el.classList.remove('pb-on');"
         . "if(_onCls)_onCls({variant:'B'});"
         . "setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el);},280);}"
         . "window.PopupB={show:show,hide:hide,"
         . "onSubmit:function(cb){_onSub=cb;},"
         . "onClose:function(cb){_onCls=cb;}};"
         . "})();";
}

/* ════════════════════════════════════════════════════════
   POPUP C — Прогресс / Зейгарник
════════════════════════════════════════════════════════ */
function generatePopupC(array $c): string {
    $col  = $c['color'];
    $colD = darkenHex($col, 0.75);

    $checks_done = [];
    for ($i = 1; $i <= 4; $i++) {
        $checks_done[] = '<li class="done"><span class="pc-chk done">✓</span>' . $c['check'.$i] . '</li>';
    }
    $checksDoneHtml = implode('', $checks_done);
    $checkPendHtml  = '<li><span class="pc-chk pend">○</span>' . $c['check5'] . '</li>';

    $css = "#pc-overlay{position:fixed;inset:0;z-index:99999;background:rgba(10,20,40,.55);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box;opacity:0;transition:opacity .25s}"
         . "#pc-overlay.pc-on{opacity:1}"
         . "#pc-box{background:#fff;border-radius:10px;width:100%;max-width:460px;position:relative;overflow:hidden;transform:translateY(20px);transition:transform .32s cubic-bezier(.22,1,.36,1);box-shadow:0 12px 50px rgba(10,20,80,.18)}"
         . "#pc-overlay.pc-on #pc-box{transform:translateY(0)}"
         . ".pc-head{background:linear-gradient(135deg,{$colD},{$col});padding:20px 28px 18px;position:relative;overflow:hidden}"
         . ".pc-head::after{content:'';position:absolute;width:180px;height:180px;border-radius:50%;background:rgba(255,255,255,.06);top:-60px;right:-50px;pointer-events:none}"
         . ".pc-head-label{font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;font-weight:500;color:rgba(255,255,255,.7);letter-spacing:.08em;text-transform:uppercase;display:block;margin-bottom:5px}"
         . ".pc-head h2{font-family:system-ui,'Segoe UI',sans-serif;font-weight:700;font-size:clamp(18px,4.5vw,23px);color:#fff;margin:0 0 14px;line-height:1.2}"
         . ".pc-progress-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;color:rgba(255,255,255,.65)}"
         . ".pc-pct{font-weight:700;color:#fff;font-size:14px}"
         . ".pc-track{height:7px;background:rgba(255,255,255,.2);border-radius:4px;overflow:hidden}"
         . ".pc-fill{height:100%;width:0;background:#fff;border-radius:4px;transition:width 1s cubic-bezier(.22,1,.36,1)}"
         . ".pc-steps-row{display:flex;gap:4px;margin-top:10px}"
         . ".pc-step{height:3px;flex:1;border-radius:2px;background:rgba(255,255,255,.25)}"
         . ".pc-step.done{background:rgba(255,255,255,.8)}"
         . ".pc-step.cur{background:#fff}"
         . ".pc-body{padding:20px 28px 26px}"
         . "@media(max-width:380px){.pc-body{padding:16px 18px 20px}}"
         . ".pc-list{list-style:none;margin:0 0 18px;padding:0;display:flex;flex-direction:column;gap:7px}"
         . ".pc-list li{display:flex;align-items:center;gap:9px;font-family:system-ui,'Segoe UI',sans-serif;font-size:13px;color:#bbb}"
         . ".pc-list li.done{color:#333}"
         . ".pc-chk{width:18px;height:18px;flex-shrink:0;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:9px}"
         . ".pc-chk.done{background:{$col};color:#fff}"
         . ".pc-chk.pend{background:#f0f0f0;color:#ccc;font-size:11px}"
         . ".pc-label{font-family:system-ui,'Segoe UI',sans-serif;font-size:13px;font-weight:500;color:#333;display:block;margin-bottom:6px}"
         . ".pc-phone{width:100%;box-sizing:border-box;border:1.5px solid #dde2ee;border-radius:7px;padding:11px 14px;font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;color:#111;outline:none;transition:border-color .2s;background:#f9fbff}"
         . ".pc-phone::placeholder{color:#bbb}"
         . ".pc-phone:focus{border-color:{$col};background:#fff}"
         . ".pc-msg-label{font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;color:#aaa;margin:12px 0 7px;display:block}"
         . ".pc-messengers{display:flex;gap:7px}"
         . ".pc-m{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:9px 0;border-radius:7px;border:1.5px solid #e5e5e5;background:#fafafa;font-family:system-ui,'Segoe UI',sans-serif;font-size:12px;font-weight:500;color:#666;cursor:pointer;transition:all .18s}"
         . ".pc-m.tg:hover,.pc-m.tg.sel{background:#0088cc;border-color:#0088cc;color:#fff}"
         . ".pc-m.wa:hover,.pc-m.wa.sel{background:#25d366;border-color:#25d366;color:#fff}"
         . ".pc-m.mx:hover,.pc-m.mx.sel{background:#6c3fff;border-color:#6c3fff;color:#fff}"
         . ".pc-btn{width:100%;margin-top:13px;background:{$col};border:none;border-radius:7px;padding:13px;font-family:system-ui,'Segoe UI',sans-serif;font-size:15px;font-weight:700;color:#fff;cursor:pointer;transition:background .18s,transform .15s;display:flex;align-items:center;justify-content:center;gap:7px}"
         . ".pc-btn:hover{background:{$colD};transform:translateY(-1px)}"
         . ".pc-btn:active{transform:translateY(0)}"
         . ".pc-fine{font-family:system-ui,'Segoe UI',sans-serif;font-size:11px;color:#ccc;text-align:center;margin-top:9px}"
         . ".pc-close{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.15);border:none;cursor:pointer;color:#fff;font-size:15px;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;transition:background .15s;z-index:10}"
         . ".pc-close:hover{background:rgba(255,255,255,.3)}"
         . ".pc-ok{text-align:center;padding:28px 16px}"
         . ".pc-ok-ico{font-size:46px;display:block;margin-bottom:10px}"
         . ".pc-ok h3{font-family:system-ui,'Segoe UI',sans-serif;font-size:21px;font-weight:700;color:{$col};margin:0 0 6px}"
         . ".pc-ok p{font-family:system-ui,'Segoe UI',sans-serif;font-size:14px;color:#888;margin:0}";

    $dom = '<div id="pc-box">'
         . '<div class="pc-head">'
         . '<button class="pc-close" id="pc-x">✕</button>'
         . '<span class="pc-head-label">' . $c['label'] . '</span>'
         . '<h2>' . $c['headline'] . '</h2>'
         . '<div class="pc-progress-row"><span>Ваш прогресс</span><span class="pc-pct">80%</span></div>'
         . '<div class="pc-track"><div class="pc-fill" id="pc-fill"></div></div>'
         . '<div class="pc-steps-row">'
         . '<div class="pc-step done"></div><div class="pc-step done"></div>'
         . '<div class="pc-step done"></div><div class="pc-step done"></div>'
         . '<div class="pc-step cur"></div>'
         . '</div></div>'
         . '<div class="pc-body">'
         . '<ul class="pc-list">' . $checksDoneHtml . $checkPendHtml . '</ul>'
         . '<div id="pc-form-area">'
         . '<form id="pc-form" novalidate>'
         . '<label class="pc-label">Номер телефона</label>'
         . '<input class="pc-phone" type="tel" id="pc-tel" placeholder="+7 (___) ___-__-__" autocomplete="tel"/>'
         . '<span class="pc-msg-label">Предпочтительный мессенджер</span>'
         . '<div class="pc-messengers">'
         . '<button type="button" class="pc-m tg" data-m="tg">' . svgTg(14) . 'Telegram</button>'
         . '<button type="button" class="pc-m wa" data-m="wa">' . svgWa(14) . 'WhatsApp</button>'
         . '<button type="button" class="pc-m mx" data-m="mx">' . svgMx(14) . 'Max</button>'
         . '</div>'
         . '<button class="pc-btn" type="submit">' . $c['btn'] . '</button>'
         . '<input id="pc-hp" type="email" name="email" tabindex="-1" autocomplete="off" style="position:absolute!important;left:-9999px!important;opacity:0!important;height:0!important;pointer-events:none!important;" aria-hidden="true">'
         . '</form>'
         . '<p class="pc-fine">Менеджер свяжется с вами в течение 15 минут</p>'
         . '</div></div></div>';

    $ok = '<div class="pc-ok">'
        . '<span class="pc-ok-ico">🚀</span>'
        . '<h3>' . $c['ok_title'] . '</h3>'
        . '<p>' . $c['ok_text'] . '</p>'
        . '</div>';

    $cssQ = jsq($css);
    $domQ = jsq($dom);
    $okQ  = jsq($ok);

    return "(function(){'use strict';"
         . "var _onSub=null,_onCls=null;"
         . "function injectStyles(){if(document.getElementById('pc-styles'))return;"
         . "var s=document.createElement('style');s.id='pc-styles';"
         . "s.textContent='{$cssQ}';document.head.appendChild(s);}"
         . "function buildDOM(){var ov=document.createElement('div');ov.id='pc-overlay';"
         . "ov.innerHTML='{$domQ}';document.body.appendChild(ov);"
         . "var aM=null;"
         . "ov.querySelectorAll('.pc-m').forEach(function(b){b.addEventListener('click',function(){"
         . "ov.querySelectorAll('.pc-m').forEach(function(x){x.classList.remove('sel');});"
         . "b.classList.add('sel');aM=b.dataset.m;});});"
         . "ov.addEventListener('click',function(e){if(e.target===ov)hide();});"
         . "document.getElementById('pc-x').addEventListener('click',hide);"
         . "document.addEventListener('keydown',function esc(e){if(e.key==='Escape'){hide();document.removeEventListener('keydown',esc);}});"
         . "document.getElementById('pc-form').addEventListener('submit',function(e){"
         . "e.preventDefault();var tel=document.getElementById('pc-tel').value.trim();"
         . "if(!tel){document.getElementById('pc-tel').focus();return;}"
         . "var hp=(document.getElementById('pc-hp')||{}).value||'';"
         . "if(_onSub)_onSub({variant:'C',phone:tel,messenger:aM,email:hp});"
         . "document.getElementById('pc-form-area').innerHTML='{$okQ}';"
         . "setTimeout(hide,3000);});}"
         . "function show(){injectStyles();if(!document.getElementById('pc-overlay'))buildDOM();"
         . "var el=document.getElementById('pc-overlay');el.style.display='flex';"
         . "requestAnimationFrame(function(){requestAnimationFrame(function(){"
         . "el.classList.add('pc-on');"
         . "setTimeout(function(){var f=document.getElementById('pc-fill');if(f)f.style.width='80%';},250);"
         . "});});}"
         . "function hide(){var el=document.getElementById('pc-overlay');if(!el)return;"
         . "el.classList.remove('pc-on');"
         . "if(_onCls)_onCls({variant:'C'});"
         . "setTimeout(function(){if(el.parentNode)el.parentNode.removeChild(el);},280);}"
         . "window.PopupC={show:show,hide:hide,"
         . "onSubmit:function(cb){_onSub=cb;},"
         . "onClose:function(cb){_onCls=cb;}};"
         . "})();";
}

/* ── Обновить список вариантов в popup.js и popup.min.js ── */
function updateLoaderVariants(array $enabled): ?string {
    $root = dirname(__DIR__);

    /* popup.js: var VARIANTS = ['A', 'B', 'C']; */
    $jsFile = $root . '/popup.js';
    if (file_exists($jsFile) && is_writable($jsFile)) {
        $arr  = count($enabled) ? "'" . implode("', '", $enabled) . "'" : '';
        $repl = "var VARIANTS = [{$arr}];";
        $src  = file_get_contents($jsFile);
        $new  = preg_replace("/var VARIANTS\s*=\s*\[.*?\];/", $repl, $src);
        if ($new !== null && $new !== $src) file_put_contents($jsFile, $new);
    }

    /* popup.min.js: var m=["A","B","C"] (терser переименовал VARIANTS → m) */
    $minFile = $root . '/popup.min.js';
    if (!file_exists($minFile)) return 'popup.min.js не найден';
    if (!is_writable($minFile)) return 'popup.min.js недоступен для записи';
    $arr  = count($enabled) ? '"' . implode('","', $enabled) . '"' : '';
    $repl = 'var m=[' . $arr . ']';
    $src  = file_get_contents($minFile);
    $new  = preg_replace('/var m=\["[A-C]"(?:,"[A-C]")*\]/', $repl, $src);
    if ($new === null || $new === $src) {
        /* Запасной вариант: ищем пустой массив тоже */
        $new = preg_replace('/var m=\[[^\]]*\](?=,f=)/', $repl, $src);
    }
    if ($new && $new !== $src) file_put_contents($minFile, $new);
    return null;
}

/* ── Записать .js и .min.js (контент одинаковый — уже компактный) ── */
function buildAndSave(string $variant, array $c): ?string {
    $fn  = 'generatePopup' . $variant;
    $js  = $fn($c);
    $dir = dirname(__DIR__) . '/popups/';
    $v   = strtolower($variant);
    if (!is_writable($dir)) return 'Директория ' . $dir . ' недоступна для записи';
    file_put_contents($dir . 'popup-' . $v . '.js',     $js);
    file_put_contents($dir . 'popup-' . $v . '.min.js', $js);
    return null; // null = успех
}
