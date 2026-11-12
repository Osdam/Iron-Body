<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Commercial\CommercialVocabulary;
use App\Services\Outreach\Core\OutreachCatalog;
use PHPUnit\Framework\TestCase;

/**
 * I-5 de la auditoría de cierre B (11-11-2026): `available` de los eventos
 * del vocabulario dice la VERDAD sobre quién los registra en
 * `commercial_events`. Se comprueba leyendo el código, sin base de datos:
 *
 *  1. La única puerta de escritura es `CommercialEventRecorder`. Ningún otro
 *     sitio de `app/` crea filas de `CommercialEvent` ni inserta en
 *     `commercial_events`.
 *  2. Quién usa esa puerta es una lista CERRADA: los observadores de
 *     `app/Observers/Commercial` y `commercial:scan`. Un emisor nuevo en otro
 *     sitio rompe aquí y obliga a mirar el catálogo.
 *  3. Un evento del vocabulario está disponible si y solo si alguno de esos
 *     emisores nombra su constante `EV_*` en el código, sin contar los
 *     comentarios. Dar un emisor a uno de
 *     {@see OutreachCatalog::EVENTS_WITHOUT_EMITTER} exige abrirlo allí y subir
 *     la versión del catálogo; quitarle el último a uno disponible, cerrarlo.
 *
 * Q1-2 (11-11-2026): `MarketingAiActionCommercialObserver` entra en la lista
 * y le da emisor a `complaint_created` (catálogo `outreach-catalog/2026-11-11.2`).
 */
class OutreachCatalogEmittersTest extends TestCase
{
    /** Los únicos ficheros de `app/` que pueden registrar un evento comercial (rutas relativas al backend). */
    private const EMITTERS = [
        'app/Console/Commands/ScanCommercialFacts.php',
        'app/Observers/Commercial/AttendanceCommercialObserver.php',
        'app/Observers/Commercial/ElectronicInvoiceCommercialObserver.php',
        'app/Observers/Commercial/MarketingAiActionCommercialObserver.php',
        'app/Observers/Commercial/MarketingAppointmentCommercialObserver.php',
        'app/Observers/Commercial/MarketingConversationCommercialObserver.php',
        'app/Observers/Commercial/MarketingLeadCommercialObserver.php',
        'app/Observers/Commercial/MemberCommercialObserver.php',
        'app/Observers/Commercial/MembershipCommercialObserver.php',
        'app/Observers/Commercial/PaymentTransactionCommercialObserver.php',
    ];

    private const RECORDER = 'app/Services/Commercial/CommercialEventRecorder.php';

    private static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return array<string, string> ruta relativa → código sin comentarios */
    private static function appCode(): array
    {
        $codigo = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::root().'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $texto = '';
            foreach (token_get_all((string) file_get_contents($f->getPathname())) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $texto .= is_array($t) ? $t[1] : $t;
            }
            $codigo[substr($f->getPathname(), strlen(self::root()) + 1)] = $texto;
        }
        ksort($codigo);

        return $codigo;
    }

    public function test_la_unica_puerta_de_commercial_events_es_el_registrador_y_sus_emisores_son_una_lista_cerrada(): void
    {
        $codigo = self::appCode();
        $escriben = array_keys(array_filter($codigo, static fn (string $c): bool => preg_match('/CommercialEvent::(create|insert|forceCreate|upsert|firstOrCreate|updateOrCreate|updateOrInsert)\b|new\s+CommercialEvent\b|table\(\s*[\'"]commercial_events[\'"]\s*\)\s*->\s*(insert|upsert|updateOrInsert|insertOrIgnore|insertGetId)\b/', $c) === 1));
        $this->assertSame([self::RECORDER], $escriben, 'solo CommercialEventRecorder escribe commercial_events');

        $usan = array_keys(array_filter($codigo, static fn (string $c): bool => str_contains($c, 'CommercialEventRecorder')));
        $usan = array_values(array_diff($usan, [self::RECORDER]));
        $this->assertSame(self::EMITTERS, $usan, 'un emisor nuevo de eventos comerciales: revisa `available` en OutreachCatalog (I-5)');
    }

    public function test_un_evento_del_vocabulario_esta_disponible_si_y_solo_si_alguien_lo_emite(): void
    {
        $codigo = self::appCode();
        $emitidos = [];
        foreach (self::EMITTERS as $ruta) {
            preg_match_all('/\b(?:V|CommercialVocabulary)::(EV_[A-Z_]+)\b/', $codigo[$ruta], $m);
            foreach ($m[1] as $constante) {
                $emitidos[constant(CommercialVocabulary::class.'::'.$constante)] = true;
            }
        }
        ksort($emitidos);
        $this->assertSame(22, count($emitidos), 'los 21 eventos con emisor de la auditoría B (I-5) y complaint_created (Q1-2)');
        $this->assertArrayHasKey('complaint_created', $emitidos, 'Q1-2: lo emite MarketingAiActionCommercialObserver');

        foreach (OutreachCatalog::CRM_EVENTS as $clave => $finalidades) {
            $this->assertSame(isset($emitidos[$clave]), OutreachCatalog::event($clave)['available'], "{$clave}: available tiene que decir si alguien lo emite");
        }
        $this->assertSame(array_values(array_diff(array_keys(OutreachCatalog::CRM_EVENTS), array_keys($emitidos))), OutreachCatalog::EVENTS_WITHOUT_EMITTER);
        $this->assertEqualsCanonicalizing(CommercialVocabulary::EVENTS, array_keys(OutreachCatalog::CRM_EVENTS), 'el catálogo cubre el vocabulario entero');
    }
}
