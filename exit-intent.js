(function () {
  "use strict";

  var modalShown = false;

  // mouseout на document + relatedTarget === null
  // relatedTarget === null означает, что курсор вышел за пределы окна браузера
  document.addEventListener("mouseout", function (e) {
    var to = e.relatedTarget || e.toElement; // e.toElement — IE fallback
    if (to) return;          // курсор внутри окна — игнорируем
    if (e.clientY > 10) return; // не верхняя граница — игнорируем

    if (modalShown) return;
    modalShown = true;

    var overlay = document.createElement("div");
    overlay.style.cssText =
      "position:fixed;inset:0;z-index:999999;" +
      "background:rgba(0,0,0,.55);display:flex;" +
      "align-items:center;justify-content:center;" +
      "font-family:system-ui,sans-serif";

    var box = document.createElement("div");
    box.style.cssText =
      "background:#fff;border-radius:12px;padding:36px 40px;" +
      "max-width:420px;width:90%;text-align:center;" +
      "box-shadow:0 20px 60px rgba(0,0,0,.3)";

    box.innerHTML =
      '<p style="margin:0 0 8px;font-size:22px">&#128466;</p>' +
      '<h2 style="margin:0 0 12px;font-size:20px;color:#111">Подождите!</h2>' +
      '<p style="margin:0 0 24px;color:#555;line-height:1.5">' +
        "Вы собираетесь покинуть страницу.<br>Уверены?" +
      "</p>" +
      '<div style="display:flex;gap:12px;justify-content:center">' +
        '<button id="exit-stay" style="padding:10px 24px;border:none;border-radius:8px;background:#2563eb;color:#fff;font-size:15px;cursor:pointer">Остаться</button>' +
        '<button id="exit-leave" style="padding:10px 24px;border:none;border-radius:8px;background:#f3f4f6;color:#374151;font-size:15px;cursor:pointer">Уйти</button>' +
      "</div>";

    overlay.appendChild(box);
    document.body.appendChild(overlay);

    document.getElementById("exit-stay").onclick = function () {
      overlay.remove();
      modalShown = false;
    };

    document.getElementById("exit-leave").onclick = function () {
      overlay.remove();
      alert("Выход разрешён.");
    };
  });

})();
