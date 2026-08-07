<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Datos sintéticos para medir el Inbox al volumen que va a tener.
 *
 * Medir con las veintidós conversaciones que hay hoy no dice nada: cualquier
 * consulta va rápida sobre una tabla que cabe en memoria, y los problemas que
 * de verdad importan —un N+1, un índice que no se usa, una búsqueda que hace
 * escaneo secuencial— solo aparecen cuando hay volumen. Este comando crea el
 * gimnasio dentro de un año: miles de conversaciones, cientos de miles de
 * mensajes y unas cuantas conversaciones muy largas, que son las que rompen
 * la paginación y el scroll.
 *
 * NO se ejecuta en producción. La comprobación es doble —entorno y bandera—
 * porque escribir cientos de miles de filas falsas en la base real sería
 * exactamente el tipo de daño que no se deshace con un rollback.
 */
class SeedInboxBenchmarkData extends Command
{
    protected $signature = 'marketing:bench-seed
        {--conversations=5000 : Conversaciones a crear}
        {--messages=400000 : Mensajes repartidos entre ellas}
        {--long=5 : Conversaciones muy largas (5.000 mensajes cada una)}
        {--fresh : Borra los datos de prueba anteriores antes de sembrar}';

    protected $description = 'Siembra datos sintéticos para medir el rendimiento del Inbox (nunca en producción).';

    /** Marca en los datos falsos: permite borrarlos sin tocar nada real. */
    private const MARK = 'bench';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Este comando no se ejecuta en producción.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->purge();
        }

        $conversations = max(1, (int) $this->option('conversations'));
        $messages = max(0, (int) $this->option('messages'));
        $long = max(0, (int) $this->option('long'));

        $this->info("Sembrando {$conversations} conversaciones…");
        $leadIds = $this->seedLeads($conversations);
        $conversationIds = $this->seedConversations($leadIds);

        $this->info("Sembrando {$messages} mensajes repartidos…");
        $this->seedMessages($conversationIds, $messages);

        if ($long > 0) {
            $this->info("Sembrando {$long} conversaciones largas de 5.000 mensajes…");
            $this->seedLongConversations(array_slice($conversationIds, 0, $long));
        }

        $this->seedTags($conversationIds);

        $this->newLine();
        $this->info(sprintf(
            'Listo. Total: %d conversaciones · %d mensajes.',
            DB::table('marketing_conversations')->count(),
            DB::table('marketing_messages')->count(),
        ));

        return self::SUCCESS;
    }

    private function purge(): void
    {
        $this->warn('Borrando datos de prueba anteriores…');

        $leadIds = DB::table('marketing_leads')->where('source', self::MARK)->pluck('id');

        if ($leadIds->isEmpty()) {
            return;
        }

        $conversationIds = DB::table('marketing_conversations')
            ->whereIn('lead_id', $leadIds)->pluck('id');

        DB::table('marketing_messages')->whereIn('conversation_id', $conversationIds)->delete();
        DB::table('marketing_conversation_tags')->whereIn('conversation_id', $conversationIds)->delete();
        DB::table('marketing_conversations')->whereIn('id', $conversationIds)->delete();
        DB::table('marketing_leads')->whereIn('id', $leadIds)->delete();
    }

    /** @return array<int,int> */
    private function seedLeads(int $count): array
    {
        $now = now();
        $ids = [];

        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            $rows = [];

            foreach ($chunk as $i) {
                $rows[] = [
                    'channel' => 'whatsapp',
                    'source' => self::MARK,
                    'name' => $this->name($i),
                    /*
                     * Con FORMA de movil colombiano valido, y a proposito.
                     *
                     * El primer intento uso numeros que empezaban por 9 para
                     * que no pudieran confundirse con los de una persona. El
                     * efecto fue que el despachador los rechazaba por
                     * `lead_without_phone` y NINGUN envio llegaba a
                     * registrarse: el escenario de envio medía un camino que
                     * no existe. Un dato de prueba tiene que recorrer el mismo
                     * codigo que el real o no mide nada.
                     *
                     * Que sean inofensivos lo garantiza otra cosa, no el
                     * formato: esto no corre en produccion, y META_ENABLED
                     * esta apagado, asi que nunca sale un mensaje a la red.
                     */
                    'phone' => '3'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
                    'status' => 'new',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('marketing_leads')->insert($rows);
        }

        return DB::table('marketing_leads')->where('source', self::MARK)->pluck('id')->all();
    }

    /**
     * @param  array<int,int>  $leadIds
     * @return array<int,int>
     */
    private function seedConversations(array $leadIds): array
    {
        $now = now();

        foreach (array_chunk($leadIds, 1000) as $chunk) {
            $rows = [];

            foreach ($chunk as $i => $leadId) {
                // Reparto realista: la mayoría abiertas, unas cuantas con la IA
                // pausada, algunas pendientes de revisión y varias sin leer.
                $rows[] = [
                    'lead_id' => $leadId,
                    'channel' => 'whatsapp',
                    'status' => $i % 7 === 0 ? 'closed' : 'open',
                    'ai_enabled' => $i % 5 !== 0,
                    'human_takeover' => $i % 5 === 0,
                    'unread_count' => $i % 3 === 0 ? ($i % 9) + 1 : 0,
                    'staff_review_pending' => $i % 11 === 0,
                    'staff_review_reason' => $i % 11 === 0 ? 'revision automatica' : null,
                    'assigned_to_admin_id' => null,
                    'last_message_at' => $now->copy()->subMinutes($i % 100000),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('marketing_conversations')->insert($rows);
        }

        return DB::table('marketing_conversations')
            ->whereIn('lead_id', $leadIds)->pluck('id')->all();
    }

    /** @param array<int,int> $conversationIds */
    private function seedMessages(array $conversationIds, int $total): void
    {
        if ($conversationIds === [] || $total === 0) {
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $count = count($conversationIds);
        $now = now();
        $rows = [];
        $written = 0;

        for ($i = 0; $i < $total; $i++) {
            $conversationId = $conversationIds[$i % $count];

            $rows[] = $this->messageRow($conversationId, $i, $now->copy()->subMinutes($total - $i));

            if (count($rows) >= 2000) {
                DB::table('marketing_messages')->insert($rows);
                $written += count($rows);
                $bar->advance(count($rows));
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('marketing_messages')->insert($rows);
            $bar->advance(count($rows));
        }

        $bar->finish();
        $this->newLine();
        unset($written);
    }

    /**
     * Conversaciones muy largas: las que rompen la paginación y el scroll.
     *
     * @param  array<int,int>  $conversationIds
     */
    private function seedLongConversations(array $conversationIds): void
    {
        $now = now();

        foreach ($conversationIds as $conversationId) {
            $rows = [];

            for ($i = 0; $i < 5000; $i++) {
                $rows[] = $this->messageRow($conversationId, $i, $now->copy()->subMinutes(5000 - $i));

                if (count($rows) >= 2000) {
                    DB::table('marketing_messages')->insert($rows);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                DB::table('marketing_messages')->insert($rows);
            }
        }
    }

    /** @return array<string,mixed> */
    private function messageRow(int $conversationId, int $i, Carbon $at): array
    {
        $inbound = $i % 2 === 0;

        return [
            'conversation_id' => $conversationId,
            'direction' => $inbound ? 'inbound' : 'outbound',
            'sender_type' => $inbound ? 'lead' : ($i % 4 === 1 ? 'ai' : 'human'),
            'body' => $this->body($i),
            'status' => $inbound ? null : 'sent',
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /** @param array<int,int> $conversationIds */
    private function seedTags(array $conversationIds): void
    {
        $now = now();
        $slugs = ['meta-ads', 'organico', 'alta-intencion', 'requiere-revision', 'facturacion', 'soporte'];
        $rows = [];

        foreach ($conversationIds as $i => $conversationId) {
            // Dos de cada tres conversaciones llevan etiqueta: suficiente para
            // que el filtro por etiqueta tenga trabajo real que hacer.
            //
            // El reparto usa numeros PRIMOS ENTRE SI (3 y 6 no lo son). Con
            // `i % 6` sobre un conjunto que ya excluye `i % 3 === 0`, la
            // primera etiqueta no salia nunca y el filtro medía sobre cero
            // filas: un escenario que parecia instantaneo porque no hacia nada.
            if ($i % 3 === 0) {
                continue;
            }

            $rows[] = [
                'conversation_id' => $conversationId,
                'tag' => $slugs[intdiv($i, 3) % count($slugs)],
                'assigned_kind' => $i % 2 === 0 ? 'source' : 'manual',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 2000) {
                DB::table('marketing_conversation_tags')->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            DB::table('marketing_conversation_tags')->insert($rows);
        }
    }

    private function name(int $i): string
    {
        $first = ['Ana', 'Carlos', 'Diana', 'Esteban', 'Fabiola', 'Gustavo', 'Helena', 'Ivan', 'Julia', 'Kevin'];
        $last = ['Ramirez', 'Torres', 'Gomez', 'Perez', 'Cardenas', 'Moreno', 'Silva', 'Rojas'];

        return $first[$i % count($first)].' '.$last[$i % count($last)];
    }

    private function body(int $i): string
    {
        $samples = [
            'Hola, quiero saber los precios de las membresias',
            'Buenas, cual es el horario de la sede',
            'Me interesa el plan trimestral, tienen promocion',
            'Gracias, lo voy a pensar y te escribo',
            'Puedo pagar con Nequi o solo tarjeta',
            'Necesito la factura electronica a nombre de mi empresa',
            'Ya hice el pago, adjunto el comprobante',
            'Quiero agendar una clase de prueba esta semana',
        ];

        return $samples[$i % count($samples)].' #'.$i;
    }
}
