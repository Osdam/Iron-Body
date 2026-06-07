<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="robots" content="noindex,nofollow">
    <title>Iron Body · Pago seguro</title>
    <style>
        :root { --gold:#E8B923; --dark:#0E0F12; }
        * { box-sizing:border-box; }
        body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
               background:#0E0F12; color:#fff; display:flex; min-height:100vh; align-items:center; justify-content:center; }
        .box { text-align:center; padding:28px 22px; max-width:360px; }
        .ring { width:54px; height:54px; margin:0 auto 18px; border:3px solid rgba(232,185,35,.25);
                border-top-color:var(--gold); border-radius:50%; animation:spin 1s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
        h1 { font-size:18px; font-weight:700; margin:0 0 8px; }
        p { font-size:13.5px; line-height:1.5; color:#c9ccd2; margin:0 0 18px; }
        .btn { display:inline-block; background:var(--gold); color:var(--dark); font-weight:700;
               text-decoration:none; padding:13px 22px; border-radius:12px; font-size:14px; border:0; cursor:pointer; }
        .muted { color:#8b8f98; font-size:11.5px; margin-top:16px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="ring" id="ring"></div>
        <h1 id="title">Abriendo el pago seguro…</h1>
        <p id="msg">Continúa en ePayco para completar el pago. No cierres esta ventana hasta finalizar.</p>
        <button class="btn" id="openBtn" style="display:none" onclick="openCheckout()">Continuar al pago</button>
        <div class="muted">Pago protegido por ePayco · Iron Body</div>
    </div>

    {{-- checkout-v2.js oficial de ePayco (Smart Checkout v2). NUNCA se incluyen
         llaves privadas/P_KEY: el cobro va atado al sessionId acuñado en backend. --}}
    <script src="{{ $checkoutJs }}"></script>
    <script>
        // Datos mínimos no sensibles: sessionId (de un solo uso), modo test y la
        // URL de retorno (la app detecta esta navegación para consultar estado).
        var SESSION_ID = @json($sessionId);
        var TEST_MODE  = @json($test);
        var RESPONSE_URL = @json($responseUrl);
        var opened = false;

        function showManual() {
            document.getElementById('ring').style.display = 'none';
            document.getElementById('title').innerText = 'Toca para continuar';
            document.getElementById('openBtn').style.display = 'inline-block';
        }

        function openCheckout() {
            try {
                if (typeof ePayco === 'undefined' || !ePayco.checkout) { showManual(); return; }
                var handler = ePayco.checkout.configure({
                    sessionId: SESSION_ID,
                    external: 'false',
                    test: TEST_MODE
                });
                opened = true;
                if (handler && typeof handler.openNew === 'function') {
                    handler.openNew();
                } else if (handler && typeof handler.open === 'function') {
                    handler.open();
                } else if (typeof ePayco.checkout.open === 'function') {
                    ePayco.checkout.open({ sessionId: SESSION_ID });
                }
            } catch (e) {
                showManual();
            }
        }

        // Auto-abrir cuando el script cargue. Si no aparece, mostrar botón manual.
        window.addEventListener('load', function () {
            setTimeout(function () {
                openCheckout();
                setTimeout(function () { if (!opened) showManual(); }, 1500);
            }, 300);
        });
    </script>
</body>
</html>
