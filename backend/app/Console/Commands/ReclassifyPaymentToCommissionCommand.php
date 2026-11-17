<?php

namespace App\Console\Commands;

use App\Services\Payments\PaymentReclassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Reclasifica un cobro de mostrador que en realidad era el piso de un
 * entrenador: lo anula por la vía oficial y registra el piso (acuerdo, cobro
 * del mes y el abono que lo salda) EN EL TURNO Y LA FECHA en que entró el
 * dinero. La lógica, sus garantías y sus invariantes están en
 * {@see PaymentReclassifier}; el contrato, en docs/ops/RECLASIFICACION-DE-COBROS.md.
 *
 *   php artisan payments:reclassify-to-commission --payment=20209 --member=862 \
 *       --trainer-from-member=7516 --actor=1 --reason="…"              (ensayo)
 *   … --apply                                                          (confirma)
 *
 * ENSAYO POR DEFECTO: hace todo dentro de una transacción y la deshace, así que
 * el informe es lo que de verdad pasaría. Solo `--apply` confirma, y solo si
 * todas las invariantes se cumplen. Volver a ejecutarlo sobre un cobro ya
 * reclasificado informa «ya aplicada» y no escribe nada.
 *
 * Salida: 0 ensayo correcto, aplicada o ya aplicada · 1 opciones inválidas o
 * precondición no cumplida · 2 invariante roto · 3 error inesperado. En los
 * tres últimos no queda nada escrito.
 */
class ReclassifyPaymentToCommissionCommand extends Command
{
    protected $signature = 'payments:reclassify-to-commission
        {--payment= : Id del cobro a reclasificar (payments.id)}
        {--member= : Ficha de socio (members.id) de la clienta del acuerdo; tiene que ser la titular del cobro}
        {--trainer= : Id del entrenador (trainers.id), si ya existe}
        {--trainer-from-member= : Ficha de socio (members.id) del entrenador: se reutiliza el entrenador con ese documento o se le da de alta}
        {--actor= : Administrador (admins.id) que firma la corrección}
        {--received-by= : Administrador que recibió el dinero (por defecto, quien registró el cobro)}
        {--starts-on= : Inicio del acuerdo, Y-m-d (por defecto, el día de negocio en que entró el dinero)}
        {--cycle-days=30 : Días que cubre cada piso}
        {--payer=member : Quién asume el piso: member | trainer}
        {--blocks-app=0 : 1 si la deuda vencida le retira la app a la socia}
        {--reason= : Motivo de la corrección (10-500 caracteres)}
        {--archive-plan : Archiva el plan del cobro si nadie lo tiene vigente y no le cambia el acceso a nadie}
        {--apply : Confirma los cambios. Sin esta opción todo termina en ROLLBACK}
        {--json : Imprime el informe como JSON}';

    protected $description = 'Reclasifica un cobro de mostrador como piso de entrenador, en su turno y su fecha originales (ensayo por defecto)';

    public function handle(PaymentReclassifier $reclasificador): int
    {
        $entrada = [
            'payment' => $this->option('payment'),
            'member' => $this->option('member'),
            'trainer' => $this->option('trainer'),
            'trainer-from-member' => $this->option('trainer-from-member'),
            'actor' => $this->option('actor'),
            'received-by' => $this->option('received-by'),
            'starts-on' => $this->option('starts-on'),
            'cycle-days' => $this->option('cycle-days'),
            'payer' => $this->option('payer'),
            'blocks-app' => (string) $this->option('blocks-app'),
            'reason' => is_string($this->option('reason')) ? trim($this->option('reason')) : $this->option('reason'),
        ];

        // En el borde, antes de tocar la base: formato, rangos y combinaciones.
        $validador = Validator::make($entrada, [
            'payment' => ['required', 'integer', 'min:1'],
            'member' => ['required', 'integer', 'min:1'],
            'trainer' => ['nullable', 'integer', 'min:1', 'required_without:trainer-from-member', 'prohibits:trainer-from-member'],
            'trainer-from-member' => ['nullable', 'integer', 'min:1'],
            'actor' => ['required', 'integer', 'min:1'],
            'received-by' => ['nullable', 'integer', 'min:1'],
            'starts-on' => ['nullable', 'date_format:Y-m-d'],
            'cycle-days' => ['required', 'integer', 'min:1', 'max:366'],
            'payer' => ['required', 'in:member,trainer'],
            'blocks-app' => ['required', 'in:0,1'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ], [
            // En español y diciendo la opción: es lo que lee quien lo ejecuta.
            'required' => 'Falta --:attribute.',
            'integer' => '--:attribute tiene que ser un id numérico.',
            'min' => '--:attribute tiene que ser al menos :min.',
            'max' => '--:attribute no puede pasar de :max.',
            'reason.min' => 'El motivo (--reason) necesita al menos :min caracteres.',
            'reason.max' => 'El motivo (--reason) no puede pasar de :max caracteres.',
            'starts-on.date_format' => '--starts-on tiene que ser una fecha AAAA-MM-DD.',
            'payer.in' => '--payer es member o trainer.',
            'blocks-app.in' => '--blocks-app es 0 o 1.',
            'trainer.required_without' => 'Indica --trainer o --trainer-from-member.',
            'trainer.prohibits' => 'Indica --trainer o --trainer-from-member, no los dos.',
        ]);

        if ($validador->fails()) {
            return $this->invalido($validador->errors()->all());
        }

        $informe = $reclasificador->run([
            'payment_id' => (int) $entrada['payment'],
            'member_id' => (int) $entrada['member'],
            'trainer_id' => filled($entrada['trainer']) ? (int) $entrada['trainer'] : null,
            'trainer_from_member_id' => filled($entrada['trainer-from-member']) ? (int) $entrada['trainer-from-member'] : null,
            'actor_id' => (int) $entrada['actor'],
            'received_by' => filled($entrada['received-by']) ? (int) $entrada['received-by'] : null,
            'starts_on' => filled($entrada['starts-on']) ? (string) $entrada['starts-on'] : null,
            'cycle_days' => (int) $entrada['cycle-days'],
            'payer' => (string) $entrada['payer'],
            'blocks_app' => $entrada['blocks-app'] === '1',
            'reason' => (string) $entrada['reason'],
            'archive_plan' => (bool) $this->option('archive-plan'),
        ], (bool) $this->option('apply'));

        if ($this->option('json')) {
            $this->line(json_encode($informe, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->pintar($informe);
        }

        return (int) $informe['exit_code'];
    }

    /** @param  list<string>  $errores */
    private function invalido(array $errores): int
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'procedure' => PaymentReclassifier::procedure(),
                'status' => PaymentReclassifier::STATUS_REJECTED,
                'exit_code' => PaymentReclassifier::EXIT_REJECTED,
                'problem' => ['code' => 'invalid_options', 'message' => implode(' ', $errores)],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($errores as $error) {
                $this->error($error);
            }
            $this->line('Nada quedó escrito.');
        }

        return PaymentReclassifier::EXIT_REJECTED;
    }

    /**
     * El informe para una persona. Sin datos personales: ids, importes,
     * estados, fechas y totales.
     *
     * @param  array<string, mixed>  $r
     */
    private function pintar(array $r): void
    {
        $modo = $r['mode'] === 'apply' ? 'APLICAR (COMMIT si todo cuadra)' : 'ENSAYO (ROLLBACK)';
        $this->line("RECLASIFICACIÓN DE COBRO · {$r['procedure']} · {$modo}");
        $this->line("Cobro #{$r['payment_id']} → piso de entrenador");
        $resultado = "Resultado: {$r['status']} (salida {$r['exit_code']}). {$r['message']}";
        match ($r['status']) {
            PaymentReclassifier::STATUS_APPLIED, PaymentReclassifier::STATUS_DRY_RUN, PaymentReclassifier::STATUS_ALREADY => $this->info($resultado),
            default => $this->error($resultado),
        };
        if ($r['problem'] !== null) {
            $this->line("Motivo [{$r['problem']['code']}]: {$r['problem']['message']}");
        }
        if ($r['before'] === null) {
            return;
        }

        $antes = $r['before'];
        $despues = $r['after'] ?? $antes;
        $this->newLine();
        $this->line("Turno #{$antes['shift']['id']} ({$antes['shift']['status']}, cerrado {$antes['shift']['closed_at']}) · día económico {$antes['day']['date']} (Bogotá)");

        $filas = [];
        foreach ($antes['shift']['frozen'] as $columna => $congelado) {
            $vivo = [
                'sales_total' => 'gross_total', 'cash_sales_total' => 'cash_total', 'transfer_total' => 'transfer_total',
                'card_total' => 'card_total', 'wompi_total' => 'wompi_total', 'other_total' => 'other_total',
                'operations_count' => 'operations_count', 'expected_amount' => 'expected_cash',
            ][$columna];
            $filas[] = [$columna, $congelado ?? '—', $antes['shift']['live'][$vivo], $despues['shift']['live'][$vivo]];
        }
        $filas[] = ['informe: consistency.matches', '—', $antes['shift']['consistency']['matches'] ? 'sí' : 'no', $despues['shift']['consistency']['matches'] ? 'sí' : 'no'];
        $this->table(['Turno', 'Congelado', 'En vivo antes', 'En vivo después'], $filas);

        $dia = [
            ['Recaudado', $antes['day']['collected'], $despues['day']['collected']],
            ['Operaciones', $antes['day']['count'], $despues['day']['count']],
        ];
        foreach ($antes['day']['by_source'] as $fuente => $importe) {
            $dia[] = ['Fuente: '.$fuente, $importe, $despues['day']['by_source'][$fuente] ?? 0];
        }
        foreach ($antes['day']['by_method'] as $medio => $importe) {
            $dia[] = ['Medio: '.$medio, $importe, $despues['day']['by_method'][$medio] ?? 0];
        }
        $dia[] = ['Lo que no entró (anulados)', $antes['day']['not_collected']['cancelled_payments']['amount'], $despues['day']['not_collected']['cancelled_payments']['amount']];
        $dia[] = ['Reclasificado (sí entró)', $antes['day']['not_collected']['reclassified_payments']['amount'], $despues['day']['not_collected']['reclassified_payments']['amount']];
        $this->table(['Día económico', 'Antes', 'Después'], $dia);

        if ($m = $r['membership']) {
            $this->line(sprintf(
                'Vigencia de la socia: %s → %s (%s días menos; la vista previa decía %s y %s días).',
                $m['end_before'] ?? '—', $m['end_after'] ?? '—', $m['days_removed'] ?? '—',
                $m['preview_end_after'] ?? '—', $m['preview_days_removed'] ?? '—',
            ));
        }
        if ($k = $r['created']) {
            $this->line(sprintf(
                'Entrenador #%s (%s) · acuerdo #%s · cobro del mes #%s (%s, cubre %s → %s) · deuda #%s · abono #%s en el turno #%s · recibo original %s (sin recibo nuevo).',
                $k['trainer_id'] ?? '—', ($k['trainer_created'] ?? false) ? 'alta nueva, sin roles' : 'ya existía',
                $k['agreement_id'] ?? '—', $k['charge_id'] ?? '—', $k['charge_period'] ?? '—', $k['covers_from'] ?? '—', $k['covers_to'] ?? '—',
                $k['receivable_id'] ?? '—', $k['receivable_payment_id'] ?? '—', $antes['shift']['id'], $k['original_receipt_number'] ?? 'ninguno',
            ));
        }
        if ($f = $r['invoice']) {
            $this->line("Factura #{$f['id']}: {$f['status_before']} → ".($f['status_after'] ?? '—').'.');
        }
        if ($a = $r['plan_archive']) {
            $this->line("Plan #{$a['plan_id']}: ".($a['archived'] ? 'archivado' : 'NO archivado').($a['reason'] ? " — {$a['reason']}" : '.'));
        }

        $this->table(['Invariante', 'Resultado'], array_map(
            fn (array $i) => [$i['check'], $i['ok'] ? 'OK' : 'FALLA: esperado '.json_encode($i['expected'], JSON_UNESCAPED_UNICODE).' · real '.json_encode($i['actual'], JSON_UNESCAPED_UNICODE)],
            $r['invariants'],
        ));
        $this->line('Escrituras ('.($r['status'] === PaymentReclassifier::STATUS_APPLIED ? 'confirmadas' : 'deshechas').'): '
            .implode(', ', array_map(fn ($firma, $n) => "{$n}× {$firma}", array_keys($r['writes']), $r['writes'])).'.');
        foreach ($r['unexpected_writes'] as $inesperada) {
            $this->error('  · '.$inesperada);
        }
    }
}
