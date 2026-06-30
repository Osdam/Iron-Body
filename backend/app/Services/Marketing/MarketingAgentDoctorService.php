<?php

namespace App\Services\Marketing;

use App\Models\Plan;
use App\Services\Meta\MetaDoctorService;

/**
 * Diagnóstico INTEGRAL del agente comercial de WhatsApp (sin exponer secretos).
 * Agrega: cerebro OpenAI, Meta/WhatsApp, base de conocimiento, plan mensual,
 * preparación de Wompi (producción o pendiente) y el estado de auto-ejecución.
 *
 * Cada chequeo reporta ok/estado + una pista accionable. NUNCA imprime tokens,
 * llaves ni montos libres. Pensado para el comando marketing:agent-doctor.
 */
class MarketingAgentDoctorService
{
    public function __construct(
        private readonly MarketingAiDoctorService $aiDoctor,
        private readonly MetaDoctorService $metaDoctor,
        private readonly MarketingKnowledgeBaseService $knowledge,
        private readonly SalesPaymentReadinessService $payment,
    ) {
    }

    /** @return array<string,mixed> reporte saneado. */
    public function report(): array
    {
        $checks = [
            'openai'        => $this->openAiCheck(),
            'meta'          => $this->metaCheck(),
            'knowledge'     => $this->knowledgeCheck(),
            'monthly_plan'  => $this->monthlyPlanCheck(),
            'wompi_payment' => $this->wompiCheck(),
            'auto_execute'  => $this->autoExecuteCheck(),
        ];

        // El agente está "listo para operar en vivo" cuando lo esencial está ok.
        // Wompi en sandbox/pending NO bloquea: el agente escala el pago a un humano.
        $blocking = ['meta', 'knowledge', 'monthly_plan'];
        $ready = collect($blocking)->every(fn ($k) => $checks[$k]['ok'] === true);

        return [
            'ready'   => $ready,
            'checks'  => $checks,
            'safety'  => $this->safetyGuarantees($checks),
            'summary' => $this->summaryLine($checks, $ready),
        ];
    }

    /**
     * Garantías de seguridad que el agente cumple SIEMPRE (no dependen de flags):
     * nunca activa membresías, nunca marca pagos aprobados y nunca entrega un link
     * Wompi sandbox como si fuera real.
     *
     * @return array<int, string>
     */
    private function safetyGuarantees(array $checks): array
    {
        $wompi = $checks['wompi_payment'];

        return [
            $wompi['sandbox_links_blocked']
                ? 'No se enviarán links Wompi sandbox como si fueran reales (Wompi NO_PRODUCTIVO → un asesor comparte el medio de pago).'
                : 'Wompi productivo: los links entregados son reales.',
            'El agente NUNCA activa membresías ni marca pagos como aprobados (eso es exclusivo del webhook Wompi aprobado).',
            'Casos sensibles (humano, lesión, queja, pago fallido, factura) se marcan needs_human y detienen la automatización.',
        ];
    }

    private function openAiCheck(): array
    {
        $r = $this->aiDoctor->report();
        $ready = (bool) ($r['openai_ready'] ?? false);

        return [
            'ok'        => $ready,
            'status'    => $ready ? 'openai' : 'fake',
            'detail'    => 'Responder efectivo: '.($r['effective_responder'] ?? 'fake'),
            'hint'      => $ready
                ? 'OpenAI listo (Laravel valida y ejecuta).'
                : 'Cerebro determinista (fake) activo. Configura OPENAI_API_KEY + MARKETING_SALES_AI_DRIVER=openai para usar OpenAI.',
        ];
    }

    private function metaCheck(): array
    {
        $r = $this->metaDoctor->report();
        $live = (bool) ($r['live_send_allowed'] ?? false);

        return [
            'ok'     => $live,
            'status' => $r['send_mode'] ?? 'dry_run',
            'detail' => 'Envío WhatsApp: '.($live ? 'real' : 'dry_run (no entrega)'),
            'hint'   => $live
                ? 'Meta/WhatsApp configurado para envío real.'
                : 'Meta en dry_run: completa META_ENABLED + credenciales para enviar en vivo.',
        ];
    }

    private function knowledgeCheck(): array
    {
        $s = $this->knowledge->summary();
        $active = (int) ($s['active_items'] ?? 0);
        $missing = (array) ($s['missing_recommended'] ?? []);

        return [
            'ok'     => $active > 0,
            'status' => $active.' items activos',
            'detail' => 'Categorías recomendadas faltantes: '.($missing === [] ? 'ninguna' : implode(', ', $missing)),
            'hint'   => $active > 0
                ? 'Base de conocimiento activa.'
                : 'Sin conocimiento activo. Corre: php artisan marketing:knowledge-seed.',
        ];
    }

    private function monthlyPlanCheck(): array
    {
        $plan = $this->findMonthlyPlan();

        return [
            'ok'     => $plan !== null,
            'status' => $plan !== null ? 'encontrado' : 'no encontrado',
            'detail' => $plan !== null ? 'Plan mensual: '.$plan->name : 'No hay un plan mensual activo.',
            'hint'   => $plan !== null
                ? 'Plan mensual disponible (fuente de precio para el agente).'
                : 'Crea/activa un plan mensual (≈30 días) para que el agente pueda cotizar y cobrar.',
        ];
    }

    private function wompiCheck(): array
    {
        $r          = $this->payment->report();
        $state      = (string) $r['state'];
        $productive = $state === SalesPaymentReadinessService::STATE_PRODUCTION_READY;

        // Etiqueta explícita: sandbox / sin configurar = NO_PRODUCTIVO.
        $label = match ($state) {
            SalesPaymentReadinessService::STATE_PRODUCTION_READY => 'PRODUCTIVO',
            SalesPaymentReadinessService::STATE_SANDBOX_PENDING  => 'NO_PRODUCTIVO (sandbox)',
            default                                              => 'NO_PRODUCTIVO (sin config)',
        };

        return [
            'ok'            => $productive,
            'status'        => $label,
            'productive'    => $productive,
            'sandbox'       => $r['env'] === 'sandbox',
            // Garantía de seguridad: salvo productivo, NUNCA se entrega link real.
            'sandbox_links_blocked' => ! $productive,
            'detail'        => 'Wompi env: '.$r['env'].' · checkout '.($r['checkout_configured'] ? 'configurado' : 'incompleto')
                .' · links sandbox como reales: '.($productive ? 'N/A (productivo)' : 'BLOQUEADOS'),
            'hint'          => match ($state) {
                SalesPaymentReadinessService::STATE_PRODUCTION_READY => 'Wompi productivo: el agente puede entregar links reales.',
                SalesPaymentReadinessService::STATE_SANDBOX_PENDING  => 'Wompi en SANDBOX (NO_PRODUCTIVO): el agente NO entrega links sandbox como reales; un asesor comparte el medio de pago.',
                default                                              => 'Wompi NO_PRODUCTIVO sin configurar: completa WOMPI_PUBLIC_KEY + WOMPI_INTEGRITY_SECRET + checkout. Mientras tanto, no se entregan links.',
            },
        ];
    }

    private function autoExecuteCheck(): array
    {
        $agentEnabled = (bool) config('marketing.agent_enabled', false);
        $autoAnalyze  = (bool) config('marketing.inbound.auto_analyze', true);
        $autoExecute  = (bool) config('marketing.inbound.auto_execute', false);
        $effective    = $agentEnabled && $autoExecute;

        return [
            'ok'     => true, // informativo: ambos modos son válidos y seguros.
            'status' => $effective ? 'on' : 'off',
            'detail' => 'MARKETING_AGENT_ENABLED='.($agentEnabled ? 'true' : 'false')
                .' · INBOUND_AUTO_ANALYZE='.($autoAnalyze ? 'true' : 'false')
                .' · INBOUND_AUTO_EXECUTE='.($autoExecute ? 'true' : 'false'),
            'hint'   => $effective
                ? 'Auto-ejecución de herramientas seguras ACTIVA (requiere agent_enabled + auto_execute).'
                : 'Auto-ejecución OFF (modo seguro): el agente analiza/propone pero no ejecuta solo.',
        ];
    }

    /** Plan mensual activo (resolución compartida con el flujo de precio). */
    private function findMonthlyPlan(): ?Plan
    {
        return $this->knowledge->defaultMonthlyPlan();
    }

    private function summaryLine(array $checks, bool $ready): string
    {
        $ok = collect($checks)->filter(fn ($c) => $c['ok'] === true)->count();
        $total = count($checks);

        return ($ready ? 'Agente listo para operar' : 'Agente con pendientes')
            ." ({$ok}/{$total} chequeos en verde).";
    }
}
