<?php

namespace App\Services\Marketing\Attribution;

use App\Models\MarketingLeadAttribution;
use App\Models\MarketingTag;
use App\Models\Plan;
use App\Services\Marketing\MarketingConversationTagService;
use App\Services\Observability\ChannelLog;
use Throwable;

/**
 * Construye el contexto de adquisición y lo contrasta con el catálogo vigente.
 *
 * Es el ÚNICO sitio donde se arma ese contexto. El prompt del agente, el motor
 * comercial y la analítica lo piden aquí; si cada uno lo montara por su cuenta
 * acabarían discrepando sobre de dónde vino una persona, y esa discrepancia
 * termina en una decisión de presupuesto tomada con el dato equivocado.
 *
 * La parte que no es obvia es el contraste. Un anuncio sigue publicado en Meta
 * semanas después de que el plan que promocionaba subiera de precio o
 * desapareciera del catálogo. Sin comprobarlo, el agente hablaría de una oferta
 * que ya no existe y el gimnasio quedaría o mintiendo o regalando dinero. Con
 * comprobarlo, el agente sabe que hay diferencia antes de escribir la primera
 * frase, y el equipo se entera de que hay una pauta que corregir.
 */
class AttributionContextService
{
    /** Resultado ya calculado en esta petición, por lead. */
    private array $memo = [];

    public function __construct(private readonly MarketingConversationTagService $tags) {}

    /**
     * El contexto de adquisición de un lead.
     *
     * Memorizado por petición: lo piden el prompt, el motor y el panel, y
     * consultarlo tres veces sería trabajo repetido para un dato que no puede
     * cambiar a mitad de petición.
     */
    public function forLead(?int $leadId): AttributionContext
    {
        if ($leadId === null) {
            return AttributionContext::absent();
        }

        if (isset($this->memo[$leadId])) {
            return $this->memo[$leadId];
        }

        try {
            $attribution = MarketingLeadAttribution::query()
                ->where('marketing_lead_id', $leadId)
                ->first();
        } catch (Throwable $e) {
            // Sin atribución se atiende igual. Que falte el contexto de dónde
            // vino alguien no puede impedir contestarle.
            ChannelLog::warning('attribution.context_unavailable', [
                'lead_id' => $leadId,
                'error_class' => class_basename($e),
            ]);

            return $this->memo[$leadId] = AttributionContext::absent();
        }

        if ($attribution === null) {
            return $this->memo[$leadId] = AttributionContext::absent();
        }

        return $this->memo[$leadId] = AttributionContext::fromModel(
            $attribution,
            $this->reconcile($attribution),
        );
    }

    /**
     * Contrasta lo que prometía el anuncio con lo que hay hoy en el catálogo.
     *
     * **No escribe nada.** Es una función de lectura y tiene que seguir
     * siéndolo: la construye el prompt de cada mensaje entrante, y la primera
     * versión levantaba la alerta aquí dentro, así que cada lectura del
     * contexto acababa escribiendo una etiqueta. Medido, eran 6 consultas por
     * llamada en vez de 3, y sobre todo era un camino de lectura con efectos.
     * La alerta se levanta cuando la atribución cambia, que es el momento en
     * que la noticia es nueva.
     *
     * El orden importa: primero se mira si el producto anunciado sigue
     * existiendo, y solo si existe tiene sentido comparar precios.
     */
    public function reconcile(MarketingLeadAttribution $attribution): OfferConsistency
    {
        $plan = $this->advertisedPlan($attribution);
        $product = $attribution->advertised_product;

        // Nada concreto anunciado: no hay promesa que contrastar. Es el caso
        // normal, porque el referral de WhatsApp rara vez identifica el plan.
        if ($plan === null && $attribution->advertised_plan_id === null && $product === null) {
            return OfferConsistency::notAdvertised();
        }

        if ($plan === null || ! $plan->active) {
            return OfferConsistency::planUnavailable($product);
        }

        $advertisedPrice = $this->priceInAdText($attribution);
        $currentPrice = (float) $plan->price;

        if ($advertisedPrice !== null && ! $this->sameMoney($advertisedPrice, $currentPrice)) {
            return OfferConsistency::priceChanged(
                $product ?? $plan->name,
                $advertisedPrice,
                $currentPrice,
                $plan->id,
            );
        }

        return OfferConsistency::matches($product ?? $plan->name, $currentPrice, $plan->id);
    }

    private function advertisedPlan(MarketingLeadAttribution $attribution): ?Plan
    {
        if ($attribution->advertised_plan_id === null) {
            return null;
        }

        try {
            return Plan::find($attribution->advertised_plan_id);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * El precio que aparecía en el texto del anuncio, si aparecía alguno.
     *
     * Esto lee TEXTO NO CONFIABLE, y por eso lo único que se extrae es un
     * número: nada de este texto se interpreta como instrucción ni se le
     * repite al cliente. Se busca el primer importe con pinta de peso
     * colombiano —seis cifras o más, con o sin separadores— porque por debajo
     * de eso lo que hay son duraciones, porcentajes y números de teléfono.
     */
    public function priceInAdText(MarketingLeadAttribution $attribution): ?float
    {
        $text = trim((string) $attribution->headline.' '.(string) $attribution->body);

        if ($text === '') {
            return null;
        }

        // Números de 6 o más dígitos, admitiendo . o , como separador de miles.
        if (preg_match('/(\d{1,3}(?:[.,]\d{3}){1,3}|\d{6,9})/u', $text, $match) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $match[1]) ?? '';

        if ($digits === '' || strlen($digits) < 5) {
            return null;
        }

        return (float) $digits;
    }

    /**
     * Dos precios son «el mismo» con mil pesos de margen.
     *
     * Un anuncio que dice «desde 89.900» y un catálogo en 90.000 no es una
     * incoherencia que merezca alertar a nadie; es redondeo publicitario.
     * Alertar por eso llenaría la bandeja de avisos que se acaban ignorando, y
     * un aviso que se ignora es peor que ninguno.
     */
    private function sameMoney(float $a, float $b): bool
    {
        return abs($a - $b) <= 1000.0;
    }

    /**
     * Comprueba la coherencia y, si falla, deja constancia.
     *
     * Se llama desde el registro de la atribución: es cuando el hecho es nuevo
     * y cuando tiene sentido avisar. Llamarlo en cada lectura llenaría el log
     * de la misma advertencia una vez por mensaje.
     */
    public function reviewAndAlert(MarketingLeadAttribution $attribution): OfferConsistency
    {
        $consistency = $this->reconcile($attribution);

        if ($consistency->needsAttention()) {
            $this->raiseAlert($attribution, $consistency);
        }

        return $consistency;
    }

    /**
     * Deja constancia de que hay una pauta desincronizada.
     *
     * Se marca la CONVERSACIÓN con una etiqueta del sistema, no un log y ya:
     * quien atiende tiene que verlo en la bandeja en el momento, y quien lleva
     * la pauta tiene que poder listar cuántas conversaciones llegaron con una
     * oferta que ya no existe. Un aviso que solo vive en un archivo de log no
     * lo lee nadie a tiempo.
     *
     * Nunca lanza: esto es un aviso, y un aviso que rompe la atención al
     * cliente ha dejado de ser un aviso para ser una avería.
     */
    private function raiseAlert(MarketingLeadAttribution $attribution, OfferConsistency $consistency): void
    {
        ChannelLog::warning('attribution.offer_inconsistent', array_merge(
            ['lead_id' => $attribution->marketing_lead_id, 'ad_id' => $attribution->ad_id],
            $consistency->toEvidence(),
        ));

        $conversationId = $attribution->marketing_conversation_id;

        if ($conversationId === null) {
            return;
        }

        try {
            $conversation = \App\Models\MarketingConversation::find($conversationId);

            if ($conversation === null) {
                return;
            }

            $this->tags->attachSystem(
                $conversation,
                'pauta-desactualizada',
                MarketingTag::KIND_SYSTEM,
                array_merge(['fact' => 'advertised_offer_no_longer_valid'], $consistency->toEvidence()),
            );
        } catch (Throwable $e) {
            ChannelLog::warning('attribution.alert_failed', [
                'lead_id' => $attribution->marketing_lead_id,
                'error_class' => class_basename($e),
            ]);
        }
    }
}
