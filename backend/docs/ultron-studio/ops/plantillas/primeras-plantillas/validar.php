<?php

/*
 * Guion de comprobación (fuera del repositorio). Arranca la aplicación del
 * árbol indicado en el entorno de pruebas (SQLite en memoria, caché en
 * array, sin red) y pasa cada plantilla por las MISMAS clases que la ruta
 * `POST strategies/templates` y el envío a Meta:
 *   1. las reglas del controlador (UltronStudioStrategyTemplateController::store);
 *   2. StudioMessageTemplates::content()  (lo que llama create());
 *   3. StudioMessageTemplates::metaWarnings() y metaPayload();
 *   4. TemplateRequestGuard::payload()   (el cuerpo EXACTO que sale hacia Graph);
 *   5. la composición de W9 al despachar: marcadores con valores realistas,
 *      OutreachPublishedStrategies::restrictionViolations() y OutreachTemplateMessage.
 * No escribe en ninguna base, no crea filas, no llama a Meta.
 *
 * Uso: php validar.php /ruta/al/backend
 */

$backend = $argv[1] ?? null;
if (! is_string($backend) || ! is_file($backend.'/bootstrap/app.php')) {
    fwrite(STDERR, "Uso: php validar.php /ruta/al/backend\n");
    exit(2);
}

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
    'BROADCAST_CONNECTION' => 'null', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false',
] as $k => $v) {
    putenv("{$k}={$v}");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

require $backend.'/vendor/autoload.php';
$app = require $backend.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Marketing\OutreachTemplateMessage;
use App\Services\Meta\Templates\TemplateRequestGuard;
use App\Services\Outreach\Core\CanonicalJson;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Facts\WhatsappRecipient;
use App\Services\Outreach\Facts\WhatsappSignals;
use App\Services\Outreach\Run\OutreachPublishedStrategies;
use App\Services\Outreach\Run\TriggerPrefilters;
use App\Services\UltronStudio\StudioMessageTemplates as T;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

Http::preventStrayRequests();

$plantillas = require __DIR__.'/plantillas.php';

echo '== Entorno: APP_ENV='.app()->environment().' · DB='.config('database.default').' · url_hosts='.json_encode(T::urlHosts()).' · phone_button='.json_encode(T::phoneButtonEnabled()).' · legal.brand='.json_encode(config('legal.brand'))."\n";
echo '== Catálogo: '.OutreachCatalog::VERSION.' · W9 resuelve: '.implode(', ', WhatsappRecipient::RESOLVABLE)."\n\n";

$evento = static function (string $clave): ?array {
    return OutreachCatalog::event($clave);
};

foreach ($plantillas as $id => $p) {
    echo "────────────────────────────────────────────────────────────\n";
    echo "[{$id}] key={$p['key']} · channel=whatsapp · purpose={$p['purpose']} · disparador=".($p['trigger'] ?? '—')."\n";

    // 0. El disparador: ¿existe, es disparable, con esa finalidad y con emisor/prefiltro?
    if (isset($p['trigger'])) {
        $e = $evento($p['trigger']);
        echo '  disparador: '.($e === null ? 'NO EXISTE' : sprintf('class=%s subject=%s triggerable=%s available=%s purposes=%s prefiltro=%s',
            $e['class'], $e['subject'], json_encode($e['triggerable']), json_encode($e['available']), json_encode($e['purposes']),
            json_encode(TriggerPrefilters::hasPrefilter($p['trigger']))))."\n";
    }

    // 1. Las reglas del controlador (las de la petición, sin la sesión ni la persona).
    $peticion = Validator::make(
        ['clientRequestId' => '0f9b7a3e-6c1d-4e2a-9b8f-1a2b3c4d5e6f', 'key' => $p['key'], 'channel' => 'whatsapp', 'purpose' => $p['purpose'], 'content' => $p['content']],
        [
            'clientRequestId' => ['required', 'uuid'],
            'key' => ['required', 'string', 'max:48', 'regex:'.T::KEY_PATTERN],
            'channel' => ['required', 'string', Rule::in(T::CHANNELS)],
            'purpose' => ['required', 'string', Rule::in(OutreachCatalog::PURPOSES)],
            'content' => ['required', 'array'],
        ],
    );
    echo '  1) petición (reglas del controlador): '.($peticion->fails() ? 'FALLA '.json_encode($peticion->errors()->toArray(), JSON_UNESCAPED_UNICODE) : 'ok')."\n";

    // 2. content(): lo que llama create().
    $r = T::content('whatsapp', $p['purpose'], $p['content']);
    if ($r['canonical'] === null) {
        echo '  2) content(): RECHAZADA · findings='.json_encode($r['errors'], JSON_UNESCAPED_UNICODE)."\n";
        $ex = T::invalid($r['errors']);
        echo '     → HTTP '.$ex->status.' '.$ex->errorCode.' · errors='.json_encode($ex->payload["errors"] ?? null, JSON_UNESCAPED_UNICODE)."\n";
        if (isset($p['content']['bodyPreview']) && is_string($p['content']['bodyPreview'])) {
            echo '     restrictionViolations(cuerpo)='.json_encode(T::restrictionViolations($p['content']['bodyPreview']))."\n";
        }

        continue;
    }
    $c = $r['canonical'];
    $cuerpo = $c['bodyPreview'];
    echo '  2) content(): VÁLIDA · contentHash='.$r['hash'].' · bytes='.strlen($r['json']).'/'.T::MAX_CONTENT_BYTES."\n";
    echo '     cuerpo: '.mb_strlen($cuerpo).'/'.T::BODY_MAX.' caracteres · variables='.json_encode($c['variables']).' · restricciones='.json_encode(T::violationsOf($c))."\n";
    $menciona = preg_match('/\bBAJA\b/u', $cuerpo) === 1 && in_array('baja', WhatsappSignals::OPT_OUT_PHRASES, true);
    echo '     línea de baja en el TEXTO (comprobación de este guion, no del validador): '.($menciona ? 'sí («BAJA» es frase segura de WhatsappSignals)' : 'NO')."\n";

    // 3. Avisos de Meta (no bloquean) y la carga.
    echo '  3) metaWarnings='.json_encode(T::metaWarnings($c)).' · missingMetaExamples='.json_encode(T::missingMetaExamples($c)).' · hasComponents='.json_encode(T::hasComponents($c))."\n";
    $payload = T::metaPayload($c);

    // 4. El cuerpo EXACTO que saldría (el cliente de Graph llama a TemplateRequestGuard::payload()).
    $json = TemplateRequestGuard::payload($payload);
    echo '  4) TemplateRequestGuard::payload(): ok · '.strlen($json).' bytes · request_hash='.CanonicalJson::hash($payload)."\n";
    echo '     POST /{WABA}/message_templates '.$json."\n";

    // 5. W9 al despachar: valores realistas → texto compuesto → R-xx otra vez → OutreachTemplateMessage.
    foreach ($p['w9Values'] ?? [] as $n => $valores) {
        $parametros = array_map(static fn (string $m): string => $valores[$m], $c['variables']);
        $texto = (string) preg_replace_callback('/\{\{(\d+)\}\}/u', static fn (array $m): string => $parametros[(int) $m[1] - 1] ?? $m[0], $cuerpo);
        $viol = OutreachPublishedStrategies::restrictionViolations($texto);
        try {
            new OutreachTemplateMessage($c['metaName'], $c['language'], $c['category'], $r['hash'], $parametros, $texto);
            $msg = 'ok';
        } catch (InvalidArgumentException $e) {
            $msg = 'template_parameter_invalid: '.$e->getMessage();
        }
        echo "  5.{$n}) W9 compone con ".json_encode($parametros, JSON_UNESCAPED_UNICODE).' → R-xx='.json_encode($viol).' · OutreachTemplateMessage='.$msg."\n";
        if ($n === 1 || str_starts_with($id, "ANTES")) {
            echo '      «'.str_replace("\n", '⏎', $texto)."»\n";
        }
    }
}

echo "────────────────────────────────────────────────────────────\n";
echo 'dateText(2026-12-15)='.json_encode(WhatsappRecipient::dateText('2026-12-15'), JSON_UNESCAPED_UNICODE).' · dateText(2027-01-05)='.json_encode(WhatsappRecipient::dateText('2027-01-05'), JSON_UNESCAPED_UNICODE)
    .' · firstName("MARÍA JOSÉ PÉREZ")='.json_encode(WhatsappRecipient::firstName('MARÍA JOSÉ PÉREZ'), JSON_UNESCAPED_UNICODE)
    .' · firstName("💪 Juan")='.json_encode(WhatsappRecipient::firstName('💪 Juan'))."\n";
echo 'isOptOutText("BAJA")='.json_encode(WhatsappSignals::isOptOutText('BAJA')).' · isOptOutText("Baja, gracias")='.json_encode(WhatsappSignals::isOptOutText('Baja, gracias'))
    .' · isOptOutText("No me interesa")='.json_encode(WhatsappSignals::isOptOutText('No me interesa')).' · isPossibleOptOutText("quiero la baja")='.json_encode(WhatsappSignals::isPossibleOptOutText('quiero la baja'))."\n";
