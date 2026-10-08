(function () {
    "use strict";
    document.querySelectorAll("[data-velfypix-widget]").forEach(function (widget) {
        if (widget.dataset.enhanced) return;
        var dialog = document.createElement("dialog");
        if (typeof dialog.showModal !== "function") return;
        var card = widget.querySelector("[data-velfypix]");
        var trigger = widget.querySelector("summary");
        if (!card || !trigger) return;
        widget.dataset.enhanced = "true";
        dialog.className = "velfypix-dialog";
        dialog.id = card.querySelector("textarea").id + "-dialog";
        dialog.setAttribute("aria-labelledby", card.querySelector("h3").id);
        var close = document.createElement("button");
        close.type = "button";
        close.className = "velfypix-dialog-close";
        close.setAttribute("aria-label", "Fechar pagamento PIX");
        close.textContent = "×";
        close.autofocus = true;
        dialog.appendChild(close);
        dialog.appendChild(card);
        document.body.appendChild(dialog);
        trigger.setAttribute("aria-haspopup", "dialog");
        trigger.setAttribute("aria-controls", dialog.id);
        trigger.setAttribute("aria-expanded", "false");
        trigger.addEventListener("click", function (event) {
            event.preventDefault();
            if (dialog.open) return;
            dialog.showModal();
            trigger.setAttribute("aria-expanded", "true");
        });
        close.addEventListener("click", function () { dialog.close(); });
        dialog.addEventListener("click", function (event) {
            if (event.target !== dialog) return;
            var bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
        });
        dialog.addEventListener("close", function () {
            trigger.setAttribute("aria-expanded", "false");
            trigger.focus();
        });
    });
    document.querySelectorAll("[data-velfypix]").forEach(function (card) {
        if (card.dataset.initialized) return;
        card.dataset.initialized = "true";
        var code = card.querySelector("textarea");
        var qr = card.querySelector("[data-velfypix-qr]");
        var copy = card.querySelector("[data-velfypix-copy]");
        var feedback = card.querySelector("[data-velfypix-feedback]");
        try {
            new QRCode(qr, { text: code.value, width: 216, height: 216, correctLevel: QRCode.CorrectLevel.M });
            // QRCode.js adds a title containing the full payment code.
            qr.removeAttribute("title");
        } catch (error) {
            qr.textContent = "Use o código copia e cola abaixo.";
            qr.classList.add("velfypix-qr-failure");
        }
        copy.addEventListener("click", async function () {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(code.value);
                } else {
                    code.focus();
                    code.select();
                    if (!document.execCommand("copy")) throw new Error("copy");
                    code.setSelectionRange(0, 0);
                }
                feedback.textContent = "Código copiado. Cole no app do seu banco.";
            } catch (error) {
                code.focus();
                code.select();
                feedback.textContent = "Selecione o código e copie manualmente.";
            }
        });
        var time = card.querySelector("time");
        if (time) {
            var expiration = new Date(time.dateTime);
            time.textContent = expiration.toLocaleString("pt-BR");
            var interval;
            var checkExpiration = function () {
                if (Date.now() < expiration.getTime()) return;
                card.dataset.expired = "true";
                copy.disabled = true;
                code.disabled = true;
                card.querySelector(".velfypix-status").textContent = "PIX vencido";
                feedback.textContent = "Contate o financeiro para emitir outra cobrança.";
                clearInterval(interval);
            };
            interval = setInterval(checkExpiration, 1000);
            checkExpiration();
        }
    });
})();
