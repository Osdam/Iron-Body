<?php

namespace Database\Seeders;

use App\Models\MarketingKnowledgeItem;
use Illuminate\Database\Seeder;

/**
 * Conocimiento comercial base de Iron Body (Fase 3.5). IDEMPOTENTE: upsert por
 * `key` (no duplica; actualiza el contenido base si la key existe). Contenido
 * CONSERVADOR: no inventa dirección, horarios exactos ni promociones. Editable
 * luego vía el endpoint interno o el CRM.
 */
class MarketingKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->items() as $item) {
            MarketingKnowledgeItem::updateOrCreate(
                ['key' => $item['key']],
                [
                    'category'  => $item['category'],
                    'title'     => $item['title'] ?? null,
                    'content'   => $item['content'],
                    'priority'  => $item['priority'] ?? 100,
                    'is_active' => $item['is_active'] ?? true,
                    'source'    => 'seeder',
                ],
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function items(): array
    {
        return [
            // ── business_identity ────────────────────────────────────────────
            ['key' => 'identity.brand', 'category' => 'business_identity', 'priority' => 10,
             'title' => 'Quiénes somos',
             'content' => 'Iron Body Neiva es un centro de acondicionamiento físico en Neiva.'],
            ['key' => 'identity.role', 'category' => 'business_identity', 'priority' => 20,
             'title' => 'Rol del asesor',
             'content' => 'El asesor orienta, resuelve dudas comerciales, recomienda planes y facilita links de pago seguros. No reemplaza al equipo humano en casos sensibles.'],

            // ── tone ─────────────────────────────────────────────────────────
            ['key' => 'tone.style', 'category' => 'tone', 'priority' => 10,
             'title' => 'Tono',
             'content' => 'Responde como un asesor comercial humano de Iron Body: mensajes cortos para WhatsApp, cálido y claro, sin lenguaje robótico. Haz una pregunta útil cuando falte información y cierra con link solo cuando haya intención clara.'],

            // ── payment_policy ───────────────────────────────────────────────
            ['key' => 'payment.wompi', 'category' => 'payment_policy', 'priority' => 10,
             'title' => 'Pagos',
             'content' => 'Los pagos por link se procesan por Wompi. La membresía solo queda registrada cuando Wompi confirma el pago en el sistema.'],
            ['key' => 'payment.no_proof', 'category' => 'payment_policy', 'priority' => 20,
             'title' => 'Comprobantes',
             'content' => 'No se aceptan capturas, mensajes ni promesas como confirmación de pago. Si el usuario dice que ya pagó pero no aparece confirmado, se escala a una persona del equipo.'],

            // ── membership_policy ────────────────────────────────────────────
            ['key' => 'membership.activation', 'category' => 'membership_policy', 'priority' => 10,
             'title' => 'Activación',
             'content' => 'La activación de la membresía depende de la confirmación del pago en el sistema. El asesor no puede activar membresías manualmente.'],

            // ── invoice_policy ───────────────────────────────────────────────
            ['key' => 'invoice.request', 'category' => 'invoice_policy', 'priority' => 10,
             'title' => 'Factura',
             'content' => 'Si el cliente solicita factura electrónica, pedir o confirmar el correo de facturación. El asesor no promete emisión inmediata ni modifica datos fiscales; los casos fiscales sensibles se escalan.'],

            // ── restrictions ─────────────────────────────────────────────────
            ['key' => 'restrictions.core', 'category' => 'restrictions', 'priority' => 10,
             'title' => 'Restricciones',
             'content' => 'No inventar precios ni promociones. No prometer resultados. No diagnosticar casos médicos ni dar rutinas a lesionados. No modificar pagos, facturas ni membresías. No enviar mensajes si do_not_contact=true.'],

            // ── objections ───────────────────────────────────────────────────
            ['key' => 'objection.price', 'category' => 'objections', 'priority' => 10,
             'title' => 'Precio alto',
             'content' => 'Validar la objeción y volver al valor: estructura, seriedad y acompañamiento del entrenamiento. Preguntar si la idea es empezar este mes o solo está mirando opciones.'],
            ['key' => 'objection.looking', 'category' => 'objections', 'priority' => 20,
             'title' => 'Solo estoy mirando',
             'content' => 'Ofrecer orientación y preguntar el objetivo (bajar grasa, ganar músculo, condición o volver a entrenar).'],
            ['key' => 'objection.time', 'category' => 'objections', 'priority' => 30,
             'title' => 'No tengo tiempo',
             'content' => 'Preguntar disponibilidad real y orientar a empezar con algo realista.'],
            ['key' => 'objection.think', 'category' => 'objections', 'priority' => 40,
             'title' => 'Quiero pensarlo',
             'content' => 'Ofrecer resolver una duda concreta y programar un seguimiento suave.'],

            // ── faq ──────────────────────────────────────────────────────────
            ['key' => 'faq.price', 'category' => 'faq', 'priority' => 10,
             'title' => 'Preguntan por precio',
             'content' => 'Orientar con los planes activos del sistema (active_plans). No inventar valores.'],
            ['key' => 'faq.link', 'category' => 'faq', 'priority' => 20,
             'title' => 'Preguntan por el link',
             'content' => 'Enviar el link seguro de pago si hay un plan claro (tool payment_link_send).'],
            ['key' => 'faq.injury', 'category' => 'faq', 'priority' => 30,
             'title' => 'Mencionan una lesión',
             'content' => 'Escalar a una persona del equipo. No diagnosticar ni recomendar rutinas.'],
            ['key' => 'faq.already_paid', 'category' => 'faq', 'priority' => 40,
             'title' => 'Dicen que ya pagaron',
             'content' => 'Escalar a una persona del equipo. La confirmación es del sistema, no del mensaje.'],

            // ── human_escalation ─────────────────────────────────────────────
            ['key' => 'escalation.rules', 'category' => 'human_escalation', 'priority' => 10,
             'title' => 'Cuándo escalar',
             'content' => 'Escalar casos médicos, reclamos, devoluciones, facturación sensible, disputas de pago, clientes molestos o cuando el cliente pida hablar con una persona.'],

            // ── location ─────────────────────────────────────────────────────
            // CONSERVADOR: no inventa dirección exacta; una persona la confirma.
            ['key' => 'location.city', 'category' => 'location', 'priority' => 10,
             'title' => 'Ubicación',
             'content' => 'Iron Body Neiva está en Cl. 24 Sur #33-53, Neiva, Huila. Para llegar más fácil, una persona del equipo puede orientarte; el asesor no inventa otras direcciones.'],

            // ── gym_info (información general del gimnasio) ───────────────────
            ['key' => 'gym.benefits', 'category' => 'gym_info', 'priority' => 10,
             'title' => 'Beneficios',
             'content' => 'En Iron Body entrenas en un lugar serio, con estructura y acompañamiento para avanzar con seguridad hacia tu objetivo (bajar grasa, ganar masa, condición o retomar).'],
            ['key' => 'gym.accompaniment', 'category' => 'gym_info', 'priority' => 20,
             'title' => 'Acompañamiento',
             'content' => 'El equipo acompaña al cliente desde el primer día: orientación en la técnica, en la rutina y en la constancia. No se promete resultados garantizados.'],
            ['key' => 'gym.environment', 'category' => 'gym_info', 'priority' => 30,
             'title' => 'Ambiente',
             'content' => 'El ambiente es respetuoso y motivador, pensado para que cualquier persona se sienta cómoda entrenando, sin importar su nivel.'],
            ['key' => 'gym.beginners', 'category' => 'gym_info', 'priority' => 40,
             'title' => 'Principiantes',
             'content' => 'Quien nunca ha entrenado es bienvenido. Se empieza desde cero, sin pena y a su ritmo, con acompañamiento para que aprenda los movimientos con seguridad.'],
            ['key' => 'gym.includes', 'category' => 'gym_info', 'priority' => 50,
             'title' => 'Qué incluye',
             'content' => 'Lo que incluye cada plan (clases, acceso, beneficios) sale de los planes activos del sistema (active_plans). El asesor no inventa beneficios que no estén ahí.'],
            ['key' => 'gym.how_to_start', 'category' => 'gym_info', 'priority' => 60,
             'title' => 'Cómo empezar',
             'content' => 'Para empezar: se define el objetivo, se elige un plan activo y se realiza el pago seguro (link Wompi cuando esté disponible); la membresía queda activa al confirmarse el pago en el sistema.'],
        ];
    }
}
