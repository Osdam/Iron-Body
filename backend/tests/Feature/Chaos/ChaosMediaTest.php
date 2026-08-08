<?php

namespace Tests\Feature\Chaos;

use App\Models\Incident;
use App\Models\MarketingMessage;
use App\Models\MarketingMessageAttachment;
use App\Services\IronGuard\ChannelHealthDetector;
use App\Services\Meta\MetaMediaService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * F6.14 – F6.18 · El archivo llega y el disco no colabora.
 *
 * La regla que ordena toda esta familia: **un adjunto solo puede decir
 * `stored` si sus bytes están de verdad en el disco.** Suena obvio hasta que
 * se mira el orden de las operaciones —escribir y después marcar— y se
 * pregunta qué pasa si la escritura no funciona y nadie mira el resultado.
 *
 * Un adjunto marcado como guardado que no existe es peor que uno fallido: el
 * fallido se reintenta y se ve en el panel; el falso positivo se descubre
 * meses después, cuando alguien abre la conversación buscando el comprobante
 * de pago que el cliente juró haber mandado.
 */
class ChaosMediaTest extends ChaosTestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Credenciales COMPLETAS y descarga encendida.
         *
         * No es un detalle de montaje: sin `app_secret`, `isConfigured()` es
         * falso y el servicio se salta la descarga entera. La prueba pasaría en
         * verde sin haber ejercitado una sola línea del camino que dice probar,
         * que es la peor clase de prueba que existe.
         *
         * El canal sigue sin poder ENVIAR nada: lo que se enciende aquí es la
         * capacidad de traerse un archivo, y toda la red está falseada.
         */
        config()->set('marketing.media.download_enabled', true);
        config()->set('marketing.media.max_attempts', 3);
        config()->set('meta.enabled', true);
        config()->set('meta.access_token', 'chaos-token');
        config()->set('meta.app_secret', 'chaos-app-secret');
        config()->set('meta.graph_base', 'https://graph.facebook.com');
        config()->set('meta.graph_version', 'v21.0');
        config()->set('meta.whatsapp_phone_number_id', 'PNID-CHAOS');
    }

    private function attachment(string $declaredMime = 'image/jpeg', string $kind = 'image'): MarketingMessageAttachment
    {
        $conversation = \App\Models\MarketingConversation::create([
            'lead_id' => \App\Models\MarketingLead::create([
                'channel' => 'whatsapp', 'meta_user_id' => '573001112233',
                'phone' => '573001112233', 'name' => 'Prospecto Chaos',
            ])->id,
            'channel' => 'whatsapp', 'status' => 'open',
        ]);

        $message = MarketingMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'sender_type' => 'lead',
            'body' => 'Te mando el comprobante',
            'status' => 'received',
        ]);

        return MarketingMessageAttachment::create([
            'message_id' => $message->id,
            'direction' => 'inbound',
            'kind' => $kind,
            'media_id' => 'MEDIA-CHAOS-1',
            'declared_mime_type' => $declaredMime,
            'status' => MarketingMessageAttachment::STATUS_PENDING,
        ]);
    }

    /** Meta entrega la URL y después los bytes que se le pidan. */
    private function fakeMetaMedia(string $binary): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'url' => 'https://lookaside.fbcdn.net/whatsapp/chaos-1',
                'mime_type' => 'image/jpeg',
            ], 200),
            'lookaside.fbcdn.net/*' => Http::response($binary, 200),
        ]);
    }

    // ── F6.14 ───────────────────────────────────────────────────────────

    /**
     * F6.14 — El archivo llega y el disco no está.
     *
     * El binario se descargó bien; lo que falla es escribirlo. La tentación es
     * seguir adelante porque «casi todo salió bien», y es exactamente lo que no
     * se puede hacer: sin bytes en disco no hay adjunto, y decir que lo hay es
     * mentir en la única pantalla donde alguien iría a comprobarlo.
     */
    public function test_f614_disco_caido_no_deja_el_adjunto_como_guardado(): void
    {
        $attachment = $this->attachment();
        $this->fakeMetaMedia(self::JPEG);

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);   // el disco dice que no
        $disk->shouldReceive('exists')->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);

        app(MetaMediaService::class)->download($attachment);

        $attachment->refresh();

        $this->assertNotSame(MarketingMessageAttachment::STATUS_STORED, $attachment->status, sprintf(
            'El adjunto quedó como «%s» con el disco caído. En el inbox aparecería '
            .'un archivo que no existe, y nadie sabría que se perdió.',
            $attachment->status,
        ));
        $this->assertNotEmpty($attachment->failure_reason, 'No quedó dicho por qué no se pudo guardar.');
    }

    /**
     * F6.14b — Y la metadata para investigarlo sobrevive.
     *
     * Aunque el archivo se pierda, tiene que quedar constancia de que llegó
     * algo, de quién y cuándo: es lo que permite pedirle al cliente que lo
     * reenvíe en lugar de no saber que existió.
     */
    public function test_f614b_el_fallo_de_disco_conserva_la_pista_del_adjunto(): void
    {
        $attachment = $this->attachment();
        $this->fakeMetaMedia(self::JPEG);

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->andReturn($disk);

        app(MetaMediaService::class)->download($attachment);

        $attachment->refresh();

        $this->assertSame('MEDIA-CHAOS-1', $attachment->media_id);
        $this->assertSame('image/jpeg', $attachment->declared_mime_type);
        $this->assertNotNull($attachment->message_id);
        $this->assertGreaterThanOrEqual(1, (int) $attachment->attempts);

        // Y el mensaje que lo traía sigue en el inbox: el archivo se perdió, la
        // conversación no.
        $this->assertNotNull(MarketingMessage::find($attachment->message_id));
    }

    // ── F6.15 ───────────────────────────────────────────────────────────

    /**
     * F6.15 — No queda sitio en el disco.
     *
     * ENOSPC llega como excepción, no como `false`. Tiene que fallar antes de
     * dejar nada a medias, y tiene que ser clasificable: un disco lleno es un
     * problema de infraestructura, no del archivo, y quien mire el panel
     * necesita distinguirlos para saber a quién llamar.
     */
    public function test_f615_disco_lleno_falla_antes_de_corromper_y_es_clasificable(): void
    {
        $attachment = $this->attachment();
        $this->fakeMetaMedia(self::JPEG);

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andThrow(
            new \RuntimeException('fwrite(): write of 20 bytes failed with errno=28 No space left on device'),
        );
        Storage::shouldReceive('disk')->andReturn($disk);

        try {
            app(MetaMediaService::class)->download($attachment);
        } catch (\Throwable $e) {
            // Que escape es aceptable: el job lo reintenta. Lo que no vale es
            // que el adjunto quede dicho como guardado.
            $this->assertStringContainsString('No space left', $e->getMessage());
        }

        $attachment->refresh();
        $this->assertNotSame(MarketingMessageAttachment::STATUS_STORED, $attachment->status);
        $this->assertNull($attachment->path, 'Se registró una ruta de un archivo que nunca se escribió.');
        $this->assertNull($attachment->sha256);
    }

    /** F6.15b — IRON GUARD sabe decir «el disco no admite escrituras». */
    public function test_f615b_iron_guard_clasifica_el_disco_inservible(): void
    {
        \Mockery::close();

        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->andThrow(new \RuntimeException('errno=28 No space left on device'));
        $disk->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->andReturn($disk);

        app(ChannelHealthDetector::class)->scan();

        $incident = Incident::where('kind', 'disk_unavailable')->first();

        $this->assertNotNull($incident, 'Un disco que no admite escrituras no levantó ningún incidente.');
        $this->assertSame(Incident::SEVERITY_CRITICAL, $incident->severity);
        $this->assertSame('storage', $incident->source);
        $this->assertNoSecretsLeaked($incident);
    }

    // ── F6.16 ───────────────────────────────────────────────────────────

    /**
     * F6.16 — Los bytes no son lo que dicen ser.
     *
     * Un archivo corrupto o vacío no se guarda «por si acaso»: se rechaza con
     * el motivo, porque guardarlo solo traslada el problema a quien lo abra.
     */
    public function test_f616_archivo_corrupto_se_rechaza_con_motivo(): void
    {
        $attachment = $this->attachment();
        $this->fakeMetaMedia('esto no es una imagen, son letras');

        $ok = app(MetaMediaService::class)->download($attachment);

        $this->assertFalse($ok);
        $attachment->refresh();

        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->status);
        // Sirve cualquiera de las dos puertas —tipo no permitido o incoherencia
        // con lo declarado—: lo que importa es que ninguna lo deja pasar.
        $this->assertMatchesRegularExpression(
            '/disallowed_mime|mime_mismatch/',
            (string) $attachment->failure_reason,
        );
        $this->assertNull($attachment->path);
    }

    /** F6.16b — Un cuerpo vacío tampoco pasa por bueno. */
    public function test_f616b_cuerpo_vacio_no_se_guarda(): void
    {
        $attachment = $this->attachment();
        $this->fakeMetaMedia('');

        $this->assertFalse(app(MetaMediaService::class)->download($attachment));

        $attachment->refresh();
        $this->assertNotSame(MarketingMessageAttachment::STATUS_STORED, $attachment->status);
        $this->assertSame('empty_body', $attachment->failure_reason);
    }

    // ── F6.17 ───────────────────────────────────────────────────────────

    /**
     * F6.17 — Un ejecutable con nombre de foto.
     *
     * Lo que Meta declara es lo que dijo el cliente, y el cliente puede mentir.
     * La única fuente fiable son los primeros bytes del fichero, y por eso el
     * servicio los lee en vez de creerse la cabecera.
     */
    public function test_f617_mime_falsificado_se_detecta_por_los_bytes(): void
    {
        $attachment = $this->attachment(declaredMime: 'image/jpeg');

        // Cabecera de ejecutable ELF con nombre y mime de imagen.
        $this->fakeMetaMedia("\x7FELF\x02\x01\x01\x00".str_repeat("\x00", 64));

        $this->assertFalse(app(MetaMediaService::class)->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->status);
        $this->assertNull($attachment->path, 'Un ejecutable disfrazado llegó a escribirse en disco.');
        $this->assertNotSame('image/jpeg', $attachment->detected_mime_type);
    }

    /**
     * F6.17b — Y un PDF que dice ser JPEG se rechaza por incoherencia.
     *
     * Aquí los bytes SÍ son de un tipo permitido: lo que no cuadra es con lo
     * declarado. Un pdf que se presenta como foto no es un descuido de codec.
     */
    public function test_f617b_incoherencia_entre_declarado_y_real_se_rechaza(): void
    {
        config()->set('marketing.media.allowed_mime_types', ['image/jpeg', 'application/pdf']);

        $attachment = $this->attachment(declaredMime: 'image/jpeg', kind: 'image');
        $this->fakeMetaMedia("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");

        $this->assertFalse(app(MetaMediaService::class)->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->status);
        $this->assertSame('mime_mismatch', $attachment->failure_reason);
    }

    /**
     * F6.17c — Un adjunto rechazado NO se reintenta.
     *
     * Reintentar un archivo prohibido solo repite el rechazo y gasta cuota. La
     * decisión es definitiva por diseño.
     */
    public function test_f617c_lo_rechazado_no_se_reintenta(): void
    {
        $attachment = $this->attachment();
        $attachment->forceFill(['status' => MarketingMessageAttachment::STATUS_REJECTED])->save();

        Http::fake(['*' => Http::response([], 500)]);

        (new \App\Jobs\DownloadWhatsappMedia($attachment->id))->handle(app(MetaMediaService::class));

        Http::assertNothingSent();
        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->fresh()->status);
    }

    // ── F6.18 ───────────────────────────────────────────────────────────

    /**
     * F6.18 — La URL de Meta ya caducó.
     *
     * Las URLs de media viven minutos. Si el job corre tarde —porque la cola
     * estaba llena, porque el worker se cayó— Meta responde que no hay nada.
     * Eso es reintentable un número acotado de veces, no para siempre.
     */
    public function test_f618_url_expirada_deja_el_adjunto_reintentable_y_acotado(): void
    {
        $attachment = $this->attachment();

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Media not found']], 404),
        ]);

        $this->assertFalse(app(MetaMediaService::class)->download($attachment));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_FAILED, $attachment->status);
        $this->assertSame('media_url_unavailable', $attachment->failure_reason);

        // Segundo y tercer intento: al agotar, deja de reintentarse.
        app(MetaMediaService::class)->download($attachment);
        app(MetaMediaService::class)->download($attachment->fresh());

        $this->assertSame(MarketingMessageAttachment::STATUS_REJECTED, $attachment->fresh()->status,
            'La descarga de una URL caducada se reintentaría para siempre.');
        $this->assertSame(3, (int) $attachment->fresh()->attempts);
    }

    /**
     * F6.18b — Y si Meta vuelve a tiempo, el archivo se guarda una sola vez.
     *
     * La mitad que suele faltar: comprobar que tras el fallo la recuperación
     * ocurre y no deja dos copias ni dos filas.
     */
    public function test_f618b_recuperacion_tras_url_caducada_guarda_una_sola_vez(): void
    {
        $attachment = $this->attachment();

        /*
         * Un solo stub que cambia de humor, en vez de dos stubs encadenados.
         * `Http::fake()` acumula y gana el primero que empareja, así que volver
         * a declarar `graph.facebook.com` no sustituye al 404: lo deja detrás,
         * inalcanzable, y la «recuperación» nunca ocurriría.
         */
        $metaCaida = true;

        Http::fake([
            'graph.facebook.com/*' => function () use (&$metaCaida) {
                return $metaCaida
                    ? Http::response(['error' => ['message' => 'Media not found']], 404)
                    : Http::response(['url' => 'https://lookaside.fbcdn.net/whatsapp/chaos-1'], 200);
            },
            'lookaside.fbcdn.net/*' => Http::response(self::JPEG, 200),
        ]);

        app(MetaMediaService::class)->download($attachment);
        $this->assertSame(MarketingMessageAttachment::STATUS_FAILED, $attachment->fresh()->status);

        // Meta se recupera.
        $metaCaida = false;
        $this->assertTrue(app(MetaMediaService::class)->download($attachment->fresh()));

        $attachment->refresh();
        $this->assertSame(MarketingMessageAttachment::STATUS_STORED, $attachment->status);
        $this->assertNotNull($attachment->path);
        Storage::disk('whatsapp')->assertExists($attachment->path);

        // Y volver a llamar no descarga otra vez ni escribe otro archivo.
        $rutaOriginal = $attachment->path;
        $this->assertTrue(app(MetaMediaService::class)->download($attachment->fresh()));
        $this->assertSame($rutaOriginal, $attachment->fresh()->path);
        $this->assertSame(1, MarketingMessageAttachment::where('message_id', $attachment->message_id)->count());
    }
}
