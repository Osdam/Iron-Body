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
        .box { text-align:center; padding:28px 22px; max-width:360px; width:100%; }
        .ring { width:54px; height:54px; margin:0 auto 18px; border:3px solid rgba(232,185,35,.25);
                border-top-color:var(--gold); border-radius:50%; animation:spin 1s linear infinite; }
        @keyframes spin { to { transform:rotate(360deg); } }
        h1 { font-size:18px; font-weight:700; margin:0 0 8px; }
        p { font-size:13.5px; line-height:1.5; color:#c9ccd2; margin:0 0 18px; }
        .btn { display:inline-block; background:var(--gold); color:var(--dark); font-weight:700;
               text-decoration:none; padding:14px 26px; border-radius:12px; font-size:15px; border:0;
               cursor:pointer; -webkit-tap-highlight-color:rgba(232,185,35,.3); transition:opacity .2s; }
        .btn[disabled] { opacity:.45; cursor:default; }
        .err { color:#ff8b8b; font-size:13px; margin:14px 0 0; min-height:18px; }
        .muted { color:#8b8f98; font-size:11.5px; margin-top:16px; }
        /* Contenedor onpage por si la SDK lo necesita para renderizar embebido. */
        #epayco-checkout-container { margin-top:14px; }
    </style>
</head>
<body>
    <div class="box">
        <div class="ring" id="ring"></div>
        <h1 id="title">Preparando el pago seguro…</h1>
        <p id="msg">Estamos cargando ePayco. En un momento podrás continuar.</p>
        {{-- El botón nace DESHABILITADO; se habilita solo cuando checkout-v2.js
             cargó y window.ePayco.checkout existe (ver enableButton()). --}}
        <button class="btn" id="openBtn" type="button" disabled>Continuar al pago</button>
        <div class="err" id="err"></div>
        <div id="epayco-checkout-container"></div>
        <div class="muted">Pago protegido por ePayco · Iron Body</div>
    </div>

    {{-- checkout-v2.js NO se incluye con <script src> aquí: se inyecta por JS para
         capturar onload/onerror/timeout de forma fiable (BLOQUE 1). NUNCA viajan
         llaves privadas/P_KEY. En fallback se usa la LLAVE PÚBLICA (no es secreta,
         está diseñada para el cliente); el cobro queda atado a reference/confirmation
         del backend, que es la única fuente de verdad. --}}
    <script>
        var CHECKOUT_JS = @json($checkoutJs);
        var SESSION_ID  = @json($sessionId);
        var PUBLIC_KEY  = @json($publicKey);
        var TEST_MODE   = @json($test);
        var METHODS_DISABLE = @json($methodsDisable);
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

        var scriptLoaded = false;   // checkout-v2.js cargó OK
        var ready        = false;   // ePayco.checkout disponible + botón habilitado
        var opening      = false;   // hay un open() en curso (evita doble tap)
        var openedOnce   = false;   // ya se intentó abrir al menos una vez

        var btn   = document.getElementById('openBtn');
        var elErr = document.getElementById('err');

        /* Log seguro: consola + canal Flutter (IronPayBridge) si existe. Nunca
           imprime llaves ni datos sensibles, solo el nombre del evento + extras. */
        function bridgeLog(event, extra) {
            try { console.log('bridge:' + event, extra ? JSON.stringify(extra) : ''); } catch (e) {}
            try {
                if (window.IronPayBridge && typeof window.IronPayBridge.postMessage === 'function') {
                    var payload = { event: event, reference: DATA.reference };
                    if (extra) { for (var k in extra) { if (extra.hasOwnProperty(k)) payload[k] = extra[k]; } }
                    window.IronPayBridge.postMessage(JSON.stringify(payload));
                }
            } catch (e) {}
        }

        function setError(text) {
            if (elErr) elErr.innerText = text || '';
        }

        function showWaiting(title, msg) {
            document.getElementById('ring').style.display = '';
            document.getElementById('title').innerText = title;
            document.getElementById('msg').innerText = msg;
        }

        function showButton(title, msg) {
            document.getElementById('ring').style.display = 'none';
            document.getElementById('title').innerText = title;
            document.getElementById('msg').innerText = msg;
        }

        function enableButton() {
            ready = true;
            btn.disabled = false;
            showButton('Toca para continuar', 'Pulsa el botón para abrir el pago seguro de ePayco.');
            bridgeLog('button_enabled', { mode: SESSION_ID ? 'session' : 'public_key' });
        }

        /* Comprueba si la SDK ya está usable. Devuelve true si quedó lista. */
        function checkReady() {
            if (ready) return true;
            if (scriptLoaded && typeof ePayco !== 'undefined' && ePayco && ePayco.checkout) {
                var hasCreds = !!SESSION_ID || !!PUBLIC_KEY;
                if (hasCreds) { enableButton(); return true; }
            }
            return false;
        }

        /* Construye el handler de ePayco (configure) según el modo disponible. */
        function buildHandler() {
            if (SESSION_ID) {
                // Smart Checkout Session v2: methodsDisable ya viaja en la sesión;
                // se reenvía por compatibilidad de versiones del JS. external:false
                // => ONPAGE (iframe embebido), imprescindible para WKWebView/WebView.
                bridgeLog('checkout_configure_start', { mode: 'session' });
                var h = ePayco.checkout.configure({
                    sessionId: SESSION_ID,
                    external: 'false',
                    test: TEST_MODE,
                    methodsDisable: METHODS_DISABLE
                });
                bridgeLog('checkout_configure_ok', { mode: 'session' });
                return h;
            }
            // Fallback con LLAVE PÚBLICA + datos del backend (monto autoritativo).
            bridgeLog('checkout_configure_start', { mode: 'public_key' });
            var hp = ePayco.checkout.configure({ key: PUBLIC_KEY, test: TEST_MODE });
            bridgeLog('checkout_configure_ok', { mode: 'public_key' });
            return hp;
        }

        /* Abre el checkout. SIEMPRE ONPAGE (external:'false'); nunca abre pestaña
           ni ventana nueva (WKWebView no las muestra). Debe ejecutarse dentro
           del gesto real del usuario (onclick del botón). */
        function startCheckout() {
            if (opening) return;
            if (!checkReady()) {
                setError('Aún cargando ePayco. Espera un momento e inténtalo de nuevo.');
                bridgeLog('open_not_ready', { scriptLoaded: scriptLoaded });
                return;
            }
            opening = true;
            openedOnce = true;
            setError('');
            btn.disabled = true;
            btn.innerText = 'Abriendo…';

            try {
                var handler = buildHandler();
                if (!handler || typeof handler.open !== 'function') {
                    throw new Error('handler.open no disponible');
                }

                bridgeLog('checkout_open_start', { mode: SESSION_ID ? 'session' : 'public_key' });

                if (SESSION_ID) {
                    handler.open();
                } else {
                    handler.open({
                        external: 'false',          // ONPAGE: nunca pestaña nueva
                        methodsDisable: METHODS_DISABLE,
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
                        // Trazabilidad: el webhook ubica la tx por extra1=reference.
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
                }

                bridgeLog('checkout_open_ok', { mode: SESSION_ID ? 'session' : 'public_key' });

                // El iframe ePayco ya está tomando la pantalla. Si por alguna razón
                // el usuario lo cierra, dejamos el botón listo para reintentar.
                setTimeout(function () {
                    opening = false;
                    btn.disabled = false;
                    btn.innerText = 'Continuar al pago';
                }, 4000);
            } catch (e) {
                opening = false;
                btn.disabled = false;
                btn.innerText = 'Continuar al pago';
                setError('No pudimos abrir el checkout. Intenta nuevamente.');
                bridgeLog('checkout_open_error', { message: (e && e.message) ? String(e.message) : 'unknown' });
            }
        }

        btn.addEventListener('click', function () {
            bridgeLog('button_clicked');
            startCheckout();
        });

        /* Carga checkout-v2.js dinámicamente para capturar onload/onerror/timeout. */
        function loadCheckoutScript() {
            var s = document.createElement('script');
            s.src = CHECKOUT_JS;
            s.async = true;
            var settled = false;

            var timer = setTimeout(function () {
                if (settled) return;
                settled = true;
                bridgeLog('script_timeout');
                showButton('No pudimos cargar el pago',
                           'Revisa tu conexión y toca para reintentar.');
                setError('No se pudo cargar ePayco. Toca para reintentar.');
                btn.disabled = false; // permitir reintento manual
                btn.innerText = 'Reintentar';
                btn.onclick = function () { location.reload(); };
            }, 12000);

            s.onload = function () {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                scriptLoaded = true;
                bridgeLog('script_loaded');
                // Algunas versiones exponen ePayco con un microtardío.
                var tries = 0;
                (function poll() {
                    if (checkReady()) {
                        // Intento automático único (ayuda en Android). Si no toma,
                        // el botón habilitado queda como camino fiable de respaldo.
                        if (!openedOnce) { /* esperar gesto del usuario */ }
                        return;
                    }
                    if (++tries < 20) { setTimeout(poll, 150); return; }
                    bridgeLog('epayco_undefined_after_load');
                    showButton('Casi listo',
                               'No pudimos inicializar ePayco. Toca para reintentar.');
                    setError('ePayco no respondió. Toca para reintentar.');
                    btn.disabled = false;
                    btn.innerText = 'Reintentar';
                    btn.onclick = function () { location.reload(); };
                })();
            };

            s.onerror = function () {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                bridgeLog('script_error');
                showButton('No pudimos cargar el pago',
                           'Revisa tu conexión y toca para reintentar.');
                setError('No se pudo cargar ePayco. Toca para reintentar.');
                btn.disabled = false;
                btn.innerText = 'Reintentar';
                btn.onclick = function () { location.reload(); };
            };

            document.head.appendChild(s);
        }

        bridgeLog('page_ready', { mode: SESSION_ID ? 'session' : (PUBLIC_KEY ? 'public_key' : 'none'), test: TEST_MODE });
        loadCheckoutScript();
    </script>
</body>
</html>
