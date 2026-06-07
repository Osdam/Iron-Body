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

    {{-- checkout-v2.js oficial. NUNCA se incluyen llaves privadas/P_KEY. En modo
         fallback se usa la LLAVE PÚBLICA (no es secreta, está diseñada para el
         cliente). El cobro queda atado a la referencia/confirmation del backend. --}}
    <script src="{{ $checkoutJs }}"></script>
    <script>
        var SESSION_ID = @json($sessionId);
        var PUBLIC_KEY = @json($publicKey);
        var TEST_MODE  = @json($test);
        var DATA = {
            reference: @json($reference),
            amount: @json($amount),
            currency: @json($currency),
            description: @json($description),
            memberId: @json($memberId),
            planId: @json($planId),
            method: @json($method),
            response: @json($responseUrl),
            confirmation: @json($confirmationUrl),
            billing: @json($billing)
        };
        var opened = false;

        function showManual() {
            document.getElementById('ring').style.display = 'none';
            document.getElementById('title').innerText = 'Toca para continuar';
            document.getElementById('openBtn').style.display = 'inline-block';
        }

        function openCheckout() {
            try {
                if (typeof ePayco === 'undefined' || !ePayco.checkout) { showManual(); return; }
                var handler;
                if (SESSION_ID) {
                    // Modo Smart Checkout Session v2.
                    handler = ePayco.checkout.configure({
                        sessionId: SESSION_ID,
                        external: 'false', // ONPAGE: imprescindible para WebView
                        test: TEST_MODE
                    });
                    opened = true;
                    if (handler && typeof handler.open === 'function') handler.open();
                    else if (typeof ePayco.checkout.open === 'function') ePayco.checkout.open({ sessionId: SESSION_ID });
                    else if (handler && typeof handler.openNew === 'function') handler.openNew();
                    return;
                }
                // Modo fallback con LLAVE PÚBLICA + datos del backend.
                handler = ePayco.checkout.configure({ key: PUBLIC_KEY, test: TEST_MODE });
                opened = true;
                handler.open({
                    external: 'false',
                    name: 'Iron Body',
                    description: DATA.description,
                    invoice: DATA.reference,
                    currency: DATA.currency,
                    amount: DATA.amount,
                    tax_base: '0',
                    tax: '0',
                    country: 'co',
                    lang: 'es',
                    response: DATA.response,
                    confirmation: DATA.confirmation,
                    methodconfirmation: 'POST',
                    // Trazabilidad (el webhook ubica la tx por extra1=reference).
                    extra1: DATA.reference,
                    extra2: DATA.memberId,
                    extra3: DATA.planId,
                    extra4: DATA.method,
                    email_billing: (DATA.billing && DATA.billing.email) || '',
                    name_billing: (DATA.billing && DATA.billing.name) || '',
                    type_doc_billing: (DATA.billing && DATA.billing.doc_type) || 'CC',
                    number_doc_billing: (DATA.billing && DATA.billing.doc_number) || '',
                    mobilephone_billing: (DATA.billing && DATA.billing.phone) || ''
                });
            } catch (e) {
                showManual();
            }
        }

        window.addEventListener('load', function () {
            setTimeout(function () {
                openCheckout();
                setTimeout(function () { if (!opened) showManual(); }, 1500);
            }, 300);
        });
    </script>
</body>
</html>
