<?php

namespace Tests\Feature\Observability;

use App\Services\Observability\ChannelLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Que un fallo al ESCRIBIR el log no pueda tumbar lo que se estaba haciendo.
 *
 * Esto se encontró en producción con Meta todavía apagado: el cron del
 * scheduler corría como root y creaba `channel-<fecha>.log` en propiedad de
 * root, así que php-fpm no podía abrirlo en modo append. Monolog lanzaba, la
 * excepción subía por WebhookMetaController y el webhook contestaba **500** —
 * incluso en el camino que RECHAZA una firma inválida, que ya había decidido
 * bien—. Con Meta enviando de verdad, un 500 sostenido hace que Meta reintente
 * y termine dando de baja la suscripción: se pierde el canal entero por no
 * poder escribir una línea de texto.
 */
class ChannelLogNeverBreaksTheRequestTest extends TestCase
{
    use RefreshDatabase;

    /** Logger que falla siempre, como el fichero que no se puede abrir. */
    private function brokenLogger(): LoggerInterface
    {
        return new class implements LoggerInterface
        {
            public function emergency($message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }

            public function alert($message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }

            public function critical($message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }

            public function error($message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }

            public function warning($message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }

            public function notice($message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }

            public function info($message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }

            public function debug($message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }

            public function log($level, $message, array $context = []): void
            {
                throw new RuntimeException('The stream or file could not be opened in append mode: Permission denied');
            }
        };
    }

    public function test_a_channel_that_cannot_be_written_does_not_throw(): void
    {
        Log::shouldReceive('channel')->andReturn($this->brokenLogger());
        Log::shouldReceive('getFacadeRoot')->andReturn($this->brokenLogger());

        // Ni la línea que se pierde ni el fallback que también falla pueden
        // convertirse en una excepción que suba al controlador.
        ChannelLog::warning('meta.webhook.rejected', ['reason' => 'invalid_signature']);
        ChannelLog::info('meta.webhook.received', ['body_bytes' => 49]);
        ChannelLog::error('meta.send.failed', ['status' => 500]);

        $this->assertTrue(true, 'ChannelLog propagó una excepción de escritura');
    }

    public function test_it_falls_back_to_the_default_logger_when_the_channel_fails(): void
    {
        $fallback = Log::spy();
        Log::shouldReceive('channel')->andReturn($this->brokenLogger());
        Log::shouldReceive('getFacadeRoot')->andReturn($fallback);

        ChannelLog::warning('meta.webhook.rejected', ['reason' => 'invalid_signature']);

        $fallback->shouldHaveReceived('warning')->once();
    }

    public function test_timed_still_reports_the_original_failure_not_the_logging_one(): void
    {
        Log::shouldReceive('channel')->andReturn($this->brokenLogger());
        Log::shouldReceive('getFacadeRoot')->andReturn($this->brokenLogger());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('el trabajo de verdad falló');

        ChannelLog::timed('meta.send', [], function (): void {
            throw new RuntimeException('el trabajo de verdad falló');
        });
    }

    /**
     * El canal dedicado se configura con permiso de grupo porque lo escriben
     * php-fpm, los workers y el scheduler. Sin esto, el primero que crea el
     * fichero del día deja fuera a los demás.
     */
    public function test_the_channel_log_is_group_writable(): void
    {
        $this->assertSame(
            0664,
            config('logging.channels.channel.permission'),
            'El canal de WhatsApp debe crear sus ficheros con permiso de grupo.',
        );
    }
}
