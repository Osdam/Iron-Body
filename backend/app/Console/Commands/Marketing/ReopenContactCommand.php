<?php

namespace App\Console\Commands\Marketing;

use App\Models\MarketingConsentEvent;
use App\Models\MarketingLead;
use App\Services\Marketing\ConsentAuthority;
use App\Services\Marketing\MarketingConsentService;
use Illuminate\Console\Command;

/**
 * La vía administrativa para reabrir un contacto cerrado.
 *
 * Existe porque la alternativa era un UPDATE a mano contra producción, y un
 * UPDATE no deja expediente: nadie puede decir después quién reabrió el
 * contacto de alguien que había pedido que no le escribieran, ni por qué.
 *
 * Exige las dos cosas que un UPDATE no exige: una persona que lo firme y un
 * motivo escrito. Sin cualquiera de las dos, no se ejecuta.
 *
 *   php artisan marketing:reopen-contact 42 --actor=7 --reason="Vino al gimnasio
 *       y pidió que le mandáramos el plan; queda constancia en la nota interna."
 *
 * `--dry-run` enseña lo que haría sin tocar nada, que es lo primero que uno
 * quiere cuando el comando afecta al consentimiento de una persona real.
 */
class ReopenContactCommand extends Command
{
    protected $signature = 'marketing:reopen-contact
        {lead : id del lead}
        {--actor= : id del admin que lo autoriza (obligatorio)}
        {--reason= : por qué se reabre (obligatorio)}
        {--dry-run : enseña el efecto sin escribir nada}';

    protected $description = 'Reabre, con acta, el contacto de un lead marcado como do_not_contact.';

    public function handle(MarketingConsentService $consent): int
    {
        $lead = MarketingLead::find((int) $this->argument('lead'));
        if ($lead === null) {
            $this->error('No existe ese lead.');

            return self::FAILURE;
        }

        $actor = $this->option('actor');
        $motivo = trim((string) $this->option('reason'));

        if ($actor === null || $actor === '' || ! ctype_digit((string) $actor)) {
            $this->error('Falta --actor: quién autoriza esto tiene que constar.');

            return self::FAILURE;
        }
        if ($motivo === '') {
            $this->error('Falta --reason: el motivo va al acta y se lee después.');

            return self::FAILURE;
        }

        $this->line('Lead '.$lead->id.' — do_not_contact='.var_export((bool) $lead->do_not_contact, true)
            .', consent_status='.var_export($lead->consent_status, true));

        if (! $lead->do_not_contact) {
            $this->info('Ya estaba abierto. No se toca nada.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY RUN: se reabriría con actor '.$actor.' y quedaría un acta. No se ha escrito nada.');

            return self::SUCCESS;
        }

        $r = $consent->reopen($lead, ConsentAuthority::SOURCE_ADMIN, $motivo, (int) $actor);

        if ($r['status'] !== 'reopened') {
            $this->error('No se reabrió: '.($r['reason'] ?? $r['status']));

            return self::FAILURE;
        }

        $acta = MarketingConsentEvent::find($r['event_id']);
        $this->info('Reabierto. Acta '.$r['event_id'].' — '.$acta?->from_consent_status.' → '.$acta?->to_consent_status);
        $this->line('  actor: '.$actor.'   motivo: '.$motivo);

        return self::SUCCESS;
    }
}
