<?php

namespace App\Services\Marketing\Ultron;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\SalesAgentDecisionSchema;
use App\Services\Marketing\SalesAgentOrchestratorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LA FICHA PERMANENTE DEL LEAD: lo que vale de una conversación a la siguiente.
 *
 * La memoria de conversación (`marketing_conversations.memory`) es estado
 * TEMPORAL: la pregunta abierta, la oferta viva, el objetivo entre manos.
 * Muere con la conversación, y está bien que muera. Lo que no puede morir es
 * lo comercial que la persona ya contó: qué busca, si ha entrenado, cuándo
 * puede venir, qué prefiere, y qué pasó —pidió precio, rechazó un plan,
 * solicitó una visita—. Eso no vivía en ningún sitio que ULTRON leyera:
 * `marketing_leads.objective` sólo lo escribía el agente clásico (con un
 * código, y sólo si estaba vacío), y una conversación nueva volvía a
 * preguntar el objetivo a quien lo dijo la semana pasada.
 *
 * Aquí se escribe en `marketing_leads.metadata[ultron_profile]` (columna que
 * ya existe, sin migración) y se lee desde el perfil de cliente. Se aprende
 * de forma DETERMINISTA y sólo de dos fuentes: lo que la persona escribió y
 * lo que de verdad pasó (los deltas de la memoria de conversación: el precio
 * que salió, la visita que se registró). Nunca de la etiqueta del modelo: un
 * «no me interesa por ahora» etiquetado como pago dejaba en la ficha
 * «mostró intención de pagar». Cada episodio se guarda con su significado:
 * «Solicitó una visita de cortesía para el miércoles 24 de septiembre a las
 * 14:00», no «courtesy_request».
 *
 * La ficha es PERMANENTE y vuelve al modelo en cada conversación, así que se
 * aprende con cuidado: sólo lo que la persona dice de SÍ MISMA, afirmado
 * (no negado ni preguntado), y nunca lo sensible —la inseguridad con su
 * cuerpo o el miedo a empezar no se guardan: se atienden en el turno—.
 *
 * Lo que NO se copia a la conversación nueva, a propósito: ningún estado
 * temporal. Un «hola» de un lead antiguo se recibe; lo que se sabe de él
 * sirve para no volver a preguntarlo, no para dar por hecho qué quiere hoy.
 */
final class LeadProfileService
{
    public const KEY = 'ultron_profile';

    public const MAX_EPISODIOS = 12;

    /**
     * Los códigos que escribe el agente clásico en `marketing_leads.objective`
     * ({@see SalesAgentOrchestratorService}). Son de
     * máquina, no del equipo: se leen con su nombre y la ficha los puede
     * actualizar cuando la persona dice otra cosa.
     */
    private const CODIGOS_DEL_AGENTE_CLASICO = [
        'fat_loss' => 'bajar grasa',
        'muscle_gain' => 'ganar masa muscular',
        'conditioning' => 'mejorar la condición física',
        'return' => 'retomar el entrenamiento',
        'health' => 'salud',
        'discipline' => 'disciplina',
    ];

    /**
     * El objetivo dicho con palabras, sólo cuando la persona habla de lo que
     * QUIERE («quiero bajar de peso», «busco ganar masa», «me interesa la
     * recomposición»). Sin ese contexto, «volumen», «definir» o «resistencia»
     * aparecen en frases que no hablan de su cuerpo («¿tienen máquinas de
     * resistencia?») y la ficha es permanente: un objetivo inventado dura meses.
     */
    private const CONTEXTO_DE_OBJETIVO = '/\b(quiero|quisiera|busco|buscando|me gustaria|necesito|deseo|pienso|mi meta|mi objetivo|me interesa|me interesaria|para|trato de|intento|lograr|estoy tratando)\b/u';

    /**
     * Alguien que no es la persona: «para mi mamá que quiere bajar de peso»,
     * «es para mi sobrina», «mi señora quiere empezar», «somos pareja, ella
     * es principiante» o «él es avanzado» no son su objetivo ni su nivel. El
     * texto llega normalizado («señora» es «senora», «él» es «el»: «el» como
     * artículo nunca va delante de un verbo). Los diminutivos van uno a uno a
     * propósito: un «\w+it[oa]» genérico también atraparía «mi cita» y «mi
     * visita».
     */
    private const OTRA_PERSONA = '/\b(mis?|para mis?|de mis?) (mama|mami|mamit[oa]|papa|papi|papit[oa]|hij[oa]s?|hijit[oa]s?|espos[oa]s?|novi[oa]s?|herman[oa]s?|hermanit[oa]s?|amig[oa]s?|pareja|mujer|marido|senora|senor|abuel[oa]s?|ti[oa]s?|prim[oa]s?|suegr[oa]s?|sobrin[oa]s?|sobrinit[oa]s?|cunad[oa]s?|niet[oa]s?|nin[oa]s?|prometid[oa]|companer[oa]s?|vecin[oa]s?|jef[ea]|parcer[oa]s?|parce)\b|\bpara (el|ella|ellos|ellas|alguien)\b|\b(el|ella|ellos|ellas) (quiere|quieren|es|esta|son|estan|tiene|tienen|nunca|ya|puede|pueden|necesita|necesitan)\b|\bsomos\b/u';

    /**
     * «Rebajar» sólo es bajar de peso con un objeto del cuerpo («rebajar
     * unos kilos») o cuando lo quiere ella, sin más («quiero rebajar»):
     * «¿me pueden rebajar el precio?» o «busco que me puedan rebajar la
     * mensualidad» piden un descuento. Y «masa muscular» a secas no es
     * querer ganarla: «¿hacen valoración de grasa y masa muscular?» o
     * «quiero saber mi masa muscular» piden una medida.
     */
    private const OBJETIVO_EN_TEXTO = [
        'recomposición corporal' => '/\b(recomposicion|recomponer)\b/u',
        'bajar grasa' => '/\b(bajar (de )?(peso|grasa|barriga|talla|kilos)|perder (peso|grasa|kilos|barriga)|perdida de grasa|adelgazar|rebajar (de )?(la |unos |unas |algunos |un poco de )?(peso|kilos|kilitos|talla|tallas|barriga|grasa|medidas)|(quiero|quisiera|busco|necesito|deseo|me gustaria|para) rebajar(?= y\b|$)|quemar (grasa|calorias)|quitar(me)? (la )?barriga)\b/u',
        'ganar masa muscular' => '/\b(ganar (masa|musculo|volumen|peso)|aumentar (mi |la )?(masa|musculo|peso)|subir de peso|(subir|mas|hacer|desarrollar|construir) (de )?masa muscular|hipertrofia|ponerme (fuerte|grande|mamado))\b/u',
        'tonificar' => '/\b(tonific\w*|definir (el cuerpo|musculo|musculos|abdomen)|definicion muscular)\b/u',
        'mejorar la condición física' => '/\b(mejorar (mi |la )?(condicion fisica|resistencia)|estar en forma|ponerme en forma)\b/u',
    ];

    /** «No soy (del todo) principiante»: dicho así, manda sobre la palabra «principiante» que lleva dentro. */
    private const NO_SOY_PRINCIPIANTE = '/\bno soy (del todo |tan |para nada |nada )?(principiante|novat[oa])\b/u';

    /**
     * Principiante, dicho sin rodeos. «No he entrenado desde hace un año» y
     * «mi primera vez fue hace 5 años» NO lo son: hablan de alguien que ya
     * entrenó. Tampoco la primera vez AQUÍ: «sería mi primera vez en este
     * gym», «es mi primera vez por acá» o «primera vez que entro a su
     * página» hablan del gimnasio o del chat, no del ejercicio, y es lo que
     * contesta quien ya entrenó a «¿sería tu primera vez entrenando?».
     */
    private const PRINCIPIANTE = '/\b(principiante|novat[oa]|primera vez(?! (fue|que (fui|entrene|escribo|hablo|contacto|pregunto|consulto|pregunte|escribi|hable|entro|veo|visito|vengo|les|te|le|los|las|lo|la)|por (aca|aqui)|aqui|aca|en (este|esta|ese|esa|el|la|su) (gym|gimnasio|sede|lugar|sitio|pagina)|en iron ?body|en neiva|con ustedes)\b)|nunca he (entrenado|ido a un gimnasio|ido al gimnasio|pisado un gimnasio|hecho ejercicio)|empezando de cero|no he entrenado(?! (desde|en (mucho|un buen|harto) tiempo|hace))|desde cero)\b/u';

    /** «Soy nuevo» puede ser nuevo en el gimnasio, en el barrio o en la ciudad, y no en el ejercicio: sólo cuenta si nada dice lo contrario. */
    private const PRINCIPIANTE_DEBIL = '/\bsoy nuev[oa](?! (en (este|esta|ese|esa|el|la|su) (gym|gimnasio|lugar|sitio|sede|barrio|ciudad|sector|zona)|aqui|aca|por (aca|aqui)|en neiva)\b)\b/u';

    /**
     * Experiencia, dicha con el ENTRENAMIENTO como objeto. «Hace 2 meses les
     * escribí» o «quiero retomar mis estudios» no son experiencia; «hace 2
     * meses que no entreno» o «quiero retomar el gym», sí.
     */
    private const CON_EXPERIENCIA = '/\b(intermedi[oa]|ya (he )?entren(e|ado)|entrenaba|antes (iba al (gym|gimnasio)|entrenaba)|tengo experiencia|vengo de otro (gym|gimnasio)|llevo .{0,15}entrenando|volver a entrenar|volver al (gimnasio|gym)|retomar (el |la |mis |los |las )?(entreno|entrenamiento|entrenar|gym|gimnasio|rutina|ejercicio|pesas)|hace \d+ (anos|meses|ano) (que )?(no )?(entreno|entrenaba|voy al (gym|gimnasio))|deje de entrenar|deje el (gym|gimnasio)|no he entrenado (desde|en (mucho|un buen|harto) tiempo|hace))\b/u';

    /**
     * Avanzado, dicho de SÍ MISMA: «soy avanzado», «me considero avanzada»,
     * «compito», «llevo años entrenando». «Me da miedo que sea muy avanzado»
     * o «la rutina de ustedes es avanzada» hablan del entrenamiento, no de
     * ella. Sin «competencia»: «¿qué los diferencia de la competencia?» no es
     * un atleta.
     */
    private const AVANZADO = '/\b((soy|me considero|estoy|mi nivel es) (muy |bastante )?avanzad[oa]|(tengo|manejo|estoy en) (un )?nivel avanzado|compit(o|iendo)|soy (un |una )?(competidor(a)?|atleta)|anos (de|en el) gym|anos entrenando)\b/u';

    /**
     * La franja, con su artículo o en plural: «en las noches», «por la
     * tarde», «las mañanas», «al mediodía». «Mañana» a secas es un DÍA:
     * «puedo ir mañana a las 5» no dice que le queden bien las mañanas.
     */
    public const FRANJA = '/\b(?:(?:en|por|de|a) (?:la|las) |las )(mananas?|tardes?|noches?|madrugadas?)\b|\b(?:al|a) (mediodia)\b|\b(mananas|tardes|noches)\b/u';

    public const DIAS = '/\b(toda la semana|todos los dias|de lunes a (viernes|sabado|domingo)|entre semana|fines? de semana|los (lunes|martes|miercoles|jueves|viernes|sabados?|domingos?)|(\d|dos|tres|cuatro|cinco|seis) dias)\b/u';

    /**
     * La persona hablando de SU tiempo: «puedo», «me queda», «solo en las
     * noches», «quiero entrenar 6 días». Sin esto, «buenas tardes» era
     * disponibilidad por las tardes, «¿tienen clases en la tarde?» también, y
     * «¿a qué hora abren los sábados?» la dejaba libre los sábados.
     */
    private const DISPONIBILIDAD_PROPIA = '/\b(puedo|podria|pueda|me queda|me quedan|me sirve|me sirven|me conviene|me acomoda|tengo (tiempo|libre|disponible)|estoy (libre|disponible)|quiero (entrenar|ir|venir)|quisiera (entrenar|ir|venir)|me gustaria (entrenar|ir|venir)|voy a (entrenar|ir|venir)|pienso (entrenar|ir|venir)|iria|entrenaria|vendria|entreno|prefiero|despues del trabajo)\b/u';

    /**
     * Lo que NO es la disponibilidad de la persona aunque nombre una franja:
     * el horario del gimnasio, las clases, pagar, llevar a alguien, o la
     * visita de un día concreto («me queda bien pasar mañana en la tarde a la
     * visita» es un plan para mañana, no su costumbre).
     */
    private const NO_ES_SU_TIEMPO = '/\b(abren|abre|cierran|cierra|atienden|horario|clases?|plan|planes|zona|maquinas|entrenador|entrenadora|profesor|profesora|instructor|instructora|promo|promocion|sede|recepcion|parqueadero|pagar|pago|llevar a|visita|cita|cortesia|conocer)\b/u';

    /** Lo que ocupa a la persona o lo que NO puede («me queda imposible», «jamás puedo»): ese trozo no dice cuándo puede. */
    private const OCUPADO = '/\b(trabajo|estudio|salgo|entro|estoy ocupad[oa]|me toca|tengo clase|no|nunca|tampoco|ni|imposible|jamas)\b/u';

    /**
     * La excepción: «puedo cualquier día menos los lunes», «todas las noches
     * excepto los viernes». Lo que va detrás es justo lo que NO puede; leído
     * como disponibilidad, la ficha guardaba para siempre el día excluido.
     */
    private const EXCEPCION = '/(?:^|\s)(?:menos|excepto|salvo)\s/u';

    /** «Menos» que no exceptúa nada: «por lo menos 3 días», «al menos en las noches», «a las 7 menos cuarto». */
    private const MENOS_QUE_NO_EXCEPTUA = '/\b(por lo menos|al menos|a lo menos|mas o menos|menos (cuarto|cinco|diez|veinte|veinticinco))\b/u';

    /** Lo que nombra la excepción: si nombra días, los días de delante ya no están completos; si nombra una franja, la franja tampoco. */
    private const NOMBRA_DIAS = '/\b(lunes|martes|miercoles|jueves|viernes|sabados?|domingos?|fines? de semana|entre semana)\b/u';

    private const NOMBRA_FRANJA = '/\b(mananas?|tardes?|noches?|madrugadas?|mediodia)\b/u';

    private const SALUDO_DE_FRANJA = '/\b(buen(as|os) (noches|tardes|dias)|buen dia)\b/u';

    private const PREFERENCIAS = [
        'quiere conocer las instalaciones antes de decidir' => '/\b(conocer (las )?instalaciones|conocer el (gimnasio|gym|lugar)|ver (el gimnasio|las instalaciones)|visitar(los|las)?|dia de cortesia|pasar a conocer)\b/u',
        'le interesan las clases grupales' => '/\b(clases? (grupal|grupales|funcional|funcionales|dirigida|dirigidas)|las clases|que clases)\b/u',
        'busca acompañamiento de entrenador' => '/\b(entrenador personal|entrenadora personal|con entrenador|que me guien|acompanamiento)\b/u',
        'está comparando gimnasios' => '/\b(buscando (opciones de )?gimnasios|comparando|otros gimnasios|cambiar de gimnasio|evaluando)\b/u',
    ];

    /**
     * Las objeciones que se guardan: las comerciales. La inseguridad con el
     * cuerpo y el miedo a empezar se atienden en el turno, pero no se
     * persisten: volverían al modelo en cada conversación, meses después,
     * para hablar de algo que la persona no sacó.
     *
     * El precio, dicho como queja del precio: «cara», «muy alto» o
     * «presupuesto» sueltos también son «tratamientos para la cara», «soy muy
     * alto» y «tengo presupuesto para el plan anual».
     */
    private const OBJECION_EN_TEXTO = [
        'el precio' => '/\b(^car[oa]s?$|(esta|estan|es|son|muy|tan|que|algo|poco|bastante|demasiado|super|me parece|me parecio|se me hace|lo veo) car[oa]s?|costos[oa]|no me alcanza|mucha plata|(precio|valor|costo)s? (esta |estan |es |son |me parece )?(muy |algo |un poco |bastante |demasiado )?alt[oa]s?|se sale de mi presupuesto|fuera de mi presupuesto|no tengo (el )?presupuesto|no tengo (plata|dinero|con que)|esta pesado)\b/u',
        'el tiempo' => '/\b(no tengo tiempo|poco tiempo|no me da el tiempo|ando (muy )?ocupad[oa]|mucho trabajo)\b/u',
    ];

    /**
     * Querer inscribirse o pagar, dicho por la persona. «Me quedo con…» sólo
     * cuenta atado a un plan: «me quedo con el gimnasio donde estoy», «con el
     * que tengo» o «con el mío» dicen lo contrario, que se queda donde está.
     */
    private const QUIERE_PAGAR = '/\b((quiero|me quiero|voy a|vamos a)\s+(inscribirme|inscribir|matricularme|pagar|empezar ya|arrancar ya|tomar el plan)|como (pago|me inscribo|hago para pagar)|mandame el (link|enlace)|pasame el (link|enlace) de pago|me quedo con (ese|este)(?! (gym|gimnasio|lugar|sitio|que tengo|donde)\b)( plan)?|me quedo con el (plan|mensual|trimestral|semestral|anual))\b/u';

    /** Quedó pendiente de DECIDIR sólo si lo dijo: «lo voy a pensar», «déjame pensarlo». */
    private const PENDIENTE_DE_DECIDIR = '/\b(lo (voy a |tengo que )?pens(are|ar|o)|lo pienso|dejame pensarlo|tengo que pensarlo|voy a pensarlo)\b/u';

    /** Que responderá luego, sin decir que lo piensa: «ya pagué, luego te confirmo», «el jueves voy, después te aviso la hora». */
    private const RESPONDERA_DESPUES = '/\b(despues|luego) te (digo|aviso|escribo|confirmo)\b/u';

    /**
     * La disponibilidad que la persona dijo de SÍ MISMA («solo puedo en las
     * noches», «de lunes a viernes me queda bien», «quiero entrenar 6 días»),
     * o null. Cláusula a cláusula: no cuenta una cláusula negada («no puedo en
     * las mañanas»), preguntada («¿puedo ir los sábados?»), sobre el gimnasio o
     * sobre un día concreto, ni lo que la ocupa («trabajo en las mañanas») o
     * exceptúa («cualquier día menos los lunes»). Los saludos de franja se
     * descartan antes de mirar.
     */
    public static function disponibilidadEn(string $textoNormalizado): ?string
    {
        $t = (string) preg_replace(self::SALUDO_DE_FRANJA, ' ', $textoNormalizado);
        $franja = null;
        $dias = null;
        foreach (Clausulas::de($t, false) as $c) {
            if ($c['pregunta']) {
                continue;
            }
            /*
             * Trozo a trozo por «y»/«pero»: «en la tarde trabajo y en la noche
             * puedo» dice lo que la ocupa en un trozo y cuándo puede en el
             * otro; «solo puedo en las noches y quiero conocer las
             * instalaciones» habla de la visita en el segundo. Se descartan
             * los trozos que la ocupan o niegan, los del gimnasio o de la
             * visita, y los de un día concreto («puedo ir mañana en la tarde»
             * es un plan de ese día, no su costumbre). La persona tiene que
             * decir que PUEDE en alguno de los que quedan.
             *
             * De cada trozo vale sólo lo que va DELANTE de una excepción: en
             * «puedo cualquier día menos los lunes» lo de detrás es justo lo
             * que no puede, y también lo que la sigue sin volver a decir que
             * puede («menos los viernes y los sábados»). Si la excepción
             * nombra días, los días de delante ya no están completos («todos
             * los días menos el domingo» no es «todos los días»); si nombra
             * una franja, la franja tampoco.
             */
            $trozos = [];
            $exceptuando = false;
            foreach (preg_split('/\s+(?:y|e|pero)\s+/u', (string) preg_replace(self::MENOS_QUE_NO_EXCEPTUA, ' ', $c['texto'])) ?: [] as $x) {
                if ($exceptuando && preg_match(self::DISPONIBILIDAD_PROPIA, $x) !== 1) {
                    continue;
                }
                $partes = preg_split(self::EXCEPCION, $x, 2) ?: [$x];
                $exceptuando = count($partes) === 2;
                $trozos[] = [$partes[0], $partes[1] ?? ''];
            }
            $trozos = array_values(array_filter(
                $trozos,
                fn (array $x) => preg_match(self::OCUPADO, $x[0]) !== 1
                    && preg_match(self::NO_ES_SU_TIEMPO, $x[0]) !== 1
                    && CourtesyAuthority::fechaDesdeTexto($x[0]) === null,
            ));
            $puede = array_filter($trozos, fn (array $x) => preg_match(self::DISPONIBILIDAD_PROPIA, $x[0]) === 1);
            if ($puede === []) {
                continue;
            }
            foreach ($trozos as [$trozo, $excepcion]) {
                if ($franja === null && preg_match(self::NOMBRA_FRANJA, $excepcion) !== 1 && preg_match(self::FRANJA, $trozo, $f) === 1) {
                    $franja = ($f[1] ?? '') !== '' ? $f[1] : ((($f[2] ?? '') !== '') ? $f[2] : ($f[3] ?? null));
                }
                if ($dias === null && preg_match(self::NOMBRA_DIAS, $excepcion) !== 1 && preg_match(self::DIAS, $trozo, $d) === 1) {
                    $dias = $d[1];
                }
            }
        }
        if ($franja === null && $dias === null) {
            return null;
        }

        return implode(', ', array_filter([$franja, $dias]));
    }

    /**
     * La ficha, siempre con la misma forma aunque esté vacía.
     *
     * @return array{objective:?string, experience_level:?string, availability:?string, preferences:string[], episodes:array<int,array{kind:string,meaning:string,at:string}>, updated_at:?string}
     */
    public function profileOf(?MarketingLead $lead): array
    {
        $vacia = ['objective' => null, 'experience_level' => null, 'availability' => null, 'preferences' => [], 'episodes' => [], 'updated_at' => null];
        if ($lead === null) {
            return $vacia;
        }
        $meta = is_array($lead->metadata) ? $lead->metadata : [];
        $p = is_array($meta[self::KEY] ?? null) ? $meta[self::KEY] : [];

        /*
         * El objetivo tiene DOS dueños posibles y se respetan los dos: si la
         * columna la escribió una persona desde el CRM, manda la columna; si la
         * escribió una máquina (ULTRON, cuyo último valor guarda
         * `objective_synced`, o el agente clásico, con su código), manda lo
         * último que la persona dijo. Sin distinguirlos, quien pasaba de
         * «bajar grasa» a «ganar masa» se quedaba en «bajar grasa» para
         * siempre, porque la columna ya no estaba vacía.
         */
        $columna = trim((string) ($lead->objective ?? ''));
        $aprendido = is_string($p['objective'] ?? null) && $p['objective'] !== '' ? $p['objective'] : null;
        $objetivo = self::columnaEsDeMaquina($columna, $p)
            ? ($aprendido ?? self::CODIGOS_DEL_AGENTE_CLASICO[$columna] ?? ($columna !== '' ? $columna : null))
            : $columna;

        return [
            'objective' => $objetivo,
            'experience_level' => is_string($p['experience_level'] ?? null) ? $p['experience_level'] : null,
            'availability' => is_string($p['availability'] ?? null) ? $p['availability'] : null,
            'preferences' => array_values(array_filter((array) ($p['preferences'] ?? []), 'is_string')),
            'episodes' => array_values(array_filter((array) ($p['episodes'] ?? []), fn ($e) => is_array($e) && isset($e['meaning'], $e['at']))),
            'updated_at' => is_string($p['updated_at'] ?? null) ? $p['updated_at'] : null,
        ];
    }

    /**
     * ¿La columna del objetivo la escribió una máquina (o está vacía)? Entonces sigue a la persona.
     *
     * @param  array<string,mixed>  $ficha
     */
    private static function columnaEsDeMaquina(string $columna, array $ficha): bool
    {
        return $columna === ''
            || $columna === ($ficha['objective_synced'] ?? null)
            || array_key_exists($columna, self::CODIGOS_DEL_AGENTE_CLASICO);
    }

    /**
     * Lo que este turno enseñó del lead, aprendido del texto de la persona y
     * de lo que cambió en la memoria de conversación. Idempotente: repetir el
     * mismo turno no duplica episodios. La intención sólo se guarda en el
     * registro; no enseña nada (en la rendición, además, es la de la
     * propuesta que se tumbó).
     */
    public function absorb(
        MarketingConversation $conversation,
        MarketingMessage $inbound,
        string $intent,
        ?ConversationMemory $antes,
        ConversationMemory $despues,
    ): void {
        $leadId = (int) ($conversation->lead_id ?? 0);
        if ($leadId === 0 || $conversation->lead === null) {
            return;
        }

        try {
            /*
             * Leer, aprender y escribir con la fila BLOQUEADA y recién leída.
             * Protege en un sentido: esta escritura no pisa lo que otros
             * guardaron en `metadata` (el reclamo de pagos, el perfil del
             * agente clásico). En el otro no: ellos escriben la columna entera
             * sin bloquear, y si guardan una copia vieja justo después pueden
             * revertir la ficha de este turno. Se acepta: la ficha se vuelve a
             * aprender del turno siguiente, y tocar esos escritores es tocar
             * pagos. La transacción, además, deja un punto de retorno propio:
             * en PostgreSQL un fallo aquí no envenena nada de fuera.
             */
            DB::transaction(function () use ($leadId, $conversation, $inbound, $antes, $despues): void {
                $lead = MarketingLead::query()->whereKey($leadId)->lockForUpdate()->first();
                if ($lead === null) {
                    return;
                }
                $ficha = $this->profileOf($lead);
                $texto = SalesAgentDecisionSchema::normalize((string) $inbound->body);
                $at = now()->toIso8601String();

                $ficha = $this->aprenderRasgos($ficha, $texto);
                foreach ($this->episodiosDe($texto, $antes?->toArray() ?? [], $despues->toArray(), $ficha['episodes'] === []) as $ep) {
                    $ficha = $this->anotar($ficha, $ep['kind'], $ep['meaning'], $at, (int) $conversation->id);
                }

                $this->guardar($lead, $ficha, $at);
            });
        } catch (Throwable $e) {
            // La ficha nunca puede tumbar un turno: un hecho que no se
            // aprende hoy se aprende mañana.
            Log::warning('ultron.lead_profile.absorb_failed', ['lead_id' => $leadId, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string,mixed>  $ficha */
    private function aprenderRasgos(array $ficha, string $texto): array
    {
        /*
         * Si el mensaje habla de OTRA persona («es para mi hijo, es
         * principiante», «mi novia quiere bajar de peso»), no se aprende nada
         * de él: lo que diga puede ser de ella, y la ficha es permanente.
         */
        if (preg_match(self::OTRA_PERSONA, $texto) === 1) {
            return $ficha;
        }

        /*
         * Objetivo: lo que dijo con palabras, afirmado y hablando de lo que
         * quiere. Manda el ÚLTIMO que afirmó, porque la corrección va al
         * final: «quiero bajar de peso, mejor dicho ganar masa», «mentiras,
         * quiero ganar masa», «mi objetivo era bajar de peso pero ahora
         * quiero ganar masa»; quedarse con el primero guardaba justo lo que
         * retiró. La cláusula que un «no» suelto retira («quiero bajar de
         * peso? no», «pensándolo bien no») no enseña nada, y la que corrige
         * («mejor dicho ganar masa») no necesita repetir el «quiero» si antes
         * habló de lo que quiere. Una cláusula con negación no se descarta
         * entera: «no quiero bajar de peso sino ganar masa» dice qué quiere.
         */
        $objetivo = null;
        $hablaDeLoQueQuiere = false;
        foreach (Clausulas::de($texto, false) as $c) {
            $conContexto = preg_match(self::CONTEXTO_DE_OBJETIVO, $c['texto']) === 1;
            $corrige = $c['prefiere'] && $hablaDeLoQueQuiere;
            $hablaDeLoQueQuiere = $hablaDeLoQueQuiere || $conContexto;
            if ($c['negada_despues'] || (! $conContexto && ! $corrige)) {
                continue;
            }
            // Dentro de la cláusula también manda el último: «busco bajar de peso mejor dicho ganar masa», sin coma.
            $ultimo = -1;
            foreach (self::OBJETIVO_EN_TEXTO as $nombre => $rx) {
                if (Clausulas::afirma($rx, $c['texto']) && preg_match_all($rx, $c['texto'], $pos, PREG_OFFSET_CAPTURE) > 0
                    && (int) end($pos[0])[1] > $ultimo) {
                    $ultimo = (int) end($pos[0])[1];
                    $objetivo = $nombre;
                }
            }
        }
        if ($objetivo !== null) {
            $ficha['objective'] = $objetivo;
            $ficha['objective_learned'] = true;
        }

        /*
         * Experiencia, sólo de lo que AFIRMA: en «soy principiante, ¿la
         * rutina es avanzada?» lo avanzado es la rutina. Lo avanzado; el «no
         * soy principiante» explícito; el principiante dicho sin rodeos; la
         * experiencia con el entrenamiento como objeto; y «soy nuevo» sólo si
         * nada dice lo contrario. Nada de eso cuenta si viene negado («no soy
         * avanzado», «principiante no soy»). Y si el mismo mensaje la hace
         * principiante y con experiencia a la vez, no se aprende nada: la
         * contradicción no se resuelve adivinando, y el nivel que ya estaba
         * no se pisa con una lectura dudosa. El «no soy principiante»
         * explícito no es contradicción: la palabra que lleva dentro no la
         * afirma.
         */
        $noSoyPrincipiante = preg_match(self::NO_SOY_PRINCIPIANTE, $texto) === 1;
        $principiante = ! $noSoyPrincipiante && Clausulas::afirma(self::PRINCIPIANTE, $texto, false);
        $avanzado = Clausulas::afirma(self::AVANZADO, $texto, false);
        $conExperiencia = Clausulas::afirma(self::CON_EXPERIENCIA, $texto, false);
        $nivel = match (true) {
            $principiante && ($avanzado || $conExperiencia) => null,
            $avanzado => 'advanced',
            $noSoyPrincipiante => 'experienced',
            $principiante => 'beginner',
            $conExperiencia => 'experienced',
            Clausulas::afirma(self::PRINCIPIANTE_DEBIL, $texto, false) => 'beginner',
            default => null,
        };
        if ($nivel !== null) {
            $ficha['experience_level'] = $nivel;
        }

        $disponibilidad = self::disponibilidadEn($texto);
        if ($disponibilidad !== null) {
            $ficha['availability'] = $disponibilidad;
        }

        // Una preferencia se anota cuando se afirma y se RETIRA cuando se
        // niega con claridad: «no me interesan las clases grupales», «las
        // clases grupales no me gustan», «odio las clases», «clases? no».
        foreach (self::PREFERENCIAS as $pref => $rx) {
            if (Clausulas::afirma($rx, $texto)) {
                if (! in_array($pref, $ficha['preferences'], true)) {
                    $ficha['preferences'][] = $pref;
                }
            } elseif (Clausulas::niega($rx, $texto)) {
                $ficha['preferences'] = array_values(array_filter($ficha['preferences'], fn ($p) => $p !== $pref));
            }
        }

        return $ficha;
    }

    /**
     * Los eventos con significado de este turno.
     *
     * @param  array<string,mixed>  $antes
     * @param  array<string,mixed>  $despues
     * @return array<int,array{kind:string,meaning:string}>
     */
    private function episodiosDe(string $texto, array $antes, array $despues, bool $fichaVacia): array
    {
        $eps = [];
        $nombre = fn (int $id): string => (string) (Plan::find($id)?->name ?? 'el plan');

        $preciosAntes = array_map(fn ($p) => (int) ($p['plan_id'] ?? 0), (array) ($antes['prices_delivered'] ?? []));
        foreach ((array) ($despues['prices_delivered'] ?? []) as $p) {
            $id = (int) ($p['plan_id'] ?? 0);
            if ($id > 0 && ! in_array($id, $preciosAntes, true)) {
                $eps[] = ['kind' => 'price_asked', 'meaning' => 'Pidió el precio del '.$nombre($id).'.'];
            }
        }

        $discutidosAntes = array_map('intval', (array) ($antes['plans_discussed'] ?? []));
        $discutidos = array_map('intval', (array) ($despues['plans_discussed'] ?? []));
        $nuevos = array_values(array_diff($discutidos, $discutidosAntes));
        if (count($discutidos) >= 2 && $nuevos !== [] && count($discutidosAntes) >= 1) {
            $eps[] = ['kind' => 'compared', 'meaning' => 'Comparó planes: '.implode(' y ', array_map($nombre, array_slice($discutidos, -2))).'.'];
        }

        $rechazadosAntes = array_map('intval', (array) ($antes['plans_rejected'] ?? []));
        foreach (array_map('intval', (array) ($despues['plans_rejected'] ?? [])) as $id) {
            if (! in_array($id, $rechazadosAntes, true)) {
                $eps[] = ['kind' => 'rejected', 'meaning' => 'Rechazó el '.$nombre($id).'.'];
            }
        }

        // La objeción, por lo que la persona ESCRIBIÓ, y sólo las comerciales.
        foreach (self::OBJECION_EN_TEXTO as $cual => $rx) {
            if (Clausulas::afirma($rx, $texto)) {
                $eps[] = ['kind' => 'objection', 'meaning' => 'Manifestó una objeción: '.$cual.'.'];
            }
        }

        $cortesiaAntes = is_array($antes['courtesy_request'] ?? null) ? $antes['courtesy_request'] : null;
        $cortesia = is_array($despues['courtesy_request'] ?? null) ? $despues['courtesy_request'] : null;
        $estadoAntes = $cortesiaAntes['status'] ?? null;
        if ($cortesia !== null && ($cortesia['status'] ?? null) === 'requested') {
            $cuando = $this->cuandoLegible((string) ($cortesia['scheduled_at'] ?? ''), (string) ($cortesia['date'] ?? ''), (string) ($cortesia['time'] ?? ''));
            if ($estadoAntes !== 'requested') {
                $eps[] = ['kind' => 'visit_requested', 'meaning' => 'Solicitó una visita de cortesía'.($cuando !== '' ? ' para el '.$cuando : '').'; el equipo la confirma.'];
            } elseif (($cortesiaAntes['date'] ?? null) !== ($cortesia['date'] ?? null) || ($cortesiaAntes['time'] ?? null) !== ($cortesia['time'] ?? null)) {
                // Mover la visita también es historia: sin esto, la ficha seguía con el día viejo.
                $eps[] = ['kind' => 'visit_changed', 'meaning' => 'Cambió la visita de cortesía'.($cuando !== '' ? ' para el '.$cuando : '').'; el equipo la confirma.'];
            }
        }
        if ($estadoAntes !== null && $estadoAntes !== 'cancelled' && (($cortesia['status'] ?? null) === 'cancelled')) {
            $eps[] = ['kind' => 'visit_cancelled', 'meaning' => 'Canceló la visita de cortesía.'];
        }

        $compromisoAntes = $antes['commercial_commitment']['plan_id'] ?? null;
        $compromiso = $despues['commercial_commitment']['plan_id'] ?? null;
        if ($compromiso !== null && $compromiso !== $compromisoAntes) {
            $eps[] = ['kind' => 'commitment', 'meaning' => 'Se decidió por el '.$nombre((int) $compromiso).'.'];
        }

        // El interés, también por lo que escribió: la etiqueta del modelo confunde «no me interesa por ahora» con un pago.
        if (Clausulas::afirma(self::QUIERE_PAGAR, $texto)) {
            $eps[] = ['kind' => 'interest', 'meaning' => 'Mostró intención de inscribirse o pagar.'];
        } elseif ($fichaVacia && preg_match('/\b(informacion|info|informes|precios?|planes|horarios?|como funciona)\b/u', $texto) === 1) {
            $eps[] = ['kind' => 'interest', 'meaning' => 'Pidió información del gimnasio por primera vez.'];
        }

        // Pendiente de decidir sólo si dijo que lo pensaría (y no «no lo voy a
        // pensar»). «Ya pagué, luego te confirmo» o «después te aviso la hora»
        // no dicen eso, y se anotan como lo que son. «Más adelante», suelto,
        // no se anota: no dice ni qué ni cuándo.
        if (Clausulas::afirma(self::PENDIENTE_DE_DECIDIR, $texto)) {
            $eps[] = ['kind' => 'pending', 'meaning' => 'Quedó pendiente de decidir; dijo que lo pensaría.'];
        } elseif (Clausulas::afirma(self::RESPONDERA_DESPUES, $texto)) {
            $eps[] = ['kind' => 'pending', 'meaning' => 'Dijo que respondería después.'];
        }

        return $eps;
    }

    private function cuandoLegible(string $scheduledAt, string $fecha, string $hora): string
    {
        // La memoria guarda la hora LOCAL del gimnasio sin desplazamiento
        // («2026-09-23 14:00:00»): leerla como UTC la convertía en las 09:00.
        try {
            $d = $fecha !== '' && $hora !== ''
                ? Carbon::parse($fecha.' '.$hora, BusinessClock::TZ)
                : ($scheduledAt !== '' ? Carbon::parse($scheduledAt, BusinessClock::TZ)->setTimezone(BusinessClock::TZ) : null);
        } catch (Throwable) {
            $d = null;
        }
        if ($d === null) {
            return '';
        }
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $dias[$d->dayOfWeek].' '.$d->day.' de '.$meses[$d->month - 1].' a las '.$d->format('H:i');
    }

    /** @param  array<string,mixed>  $ficha */
    private function anotar(array $ficha, string $kind, string $meaning, string $at, int $conversationId): array
    {
        foreach ($ficha['episodes'] as $e) {
            // El mismo hecho, contado igual, no se anota dos veces.
            if (($e['meaning'] ?? null) === $meaning) {
                return $ficha;
            }
        }
        $ficha['episodes'][] = ['kind' => $kind, 'meaning' => $meaning, 'at' => $at, 'conversation_id' => $conversationId];
        $ficha['episodes'] = array_slice($ficha['episodes'], -self::MAX_EPISODIOS);

        return $ficha;
    }

    /** @param  array<string,mixed>  $ficha */
    private function guardar(MarketingLead $lead, array $ficha, string $at): void
    {
        $meta = is_array($lead->metadata) ? $lead->metadata : [];
        $previa = is_array($meta[self::KEY] ?? null) ? $meta[self::KEY] : [];
        $sincronizado = is_string($previa['objective_synced'] ?? null) ? $previa['objective_synced'] : null;
        $columna = trim((string) ($lead->objective ?? ''));

        // Lo aprendido se guarda en la ficha; lo leído de la columna, no (si
        // no, un objetivo escrito a mano acabaría pareciendo aprendido).
        $objetivoFicha = ($ficha['objective_learned'] ?? false) === true
            ? $ficha['objective']
            : (is_string($previa['objective'] ?? null) ? $previa['objective'] : null);

        // La columna sigue a la persona si la escribió una máquina; si la escribió el equipo, manda.
        $escribeColumna = self::columnaEsDeMaquina($columna, $previa) && $objetivoFicha !== null && $columna !== $objetivoFicha;

        $nueva = [
            'objective' => $objetivoFicha,
            'objective_synced' => $escribeColumna ? $objetivoFicha : $sincronizado,
            'experience_level' => $ficha['experience_level'],
            'availability' => $ficha['availability'],
            'preferences' => $ficha['preferences'],
            'episodes' => $ficha['episodes'],
        ];
        if (array_diff_key($previa, ['updated_at' => 1]) === $nueva && ! $escribeColumna) {
            return;
        }
        $meta[self::KEY] = $nueva + ['updated_at' => $at];
        $lead->metadata = $meta;
        if ($escribeColumna) {
            $lead->objective = $objetivoFicha;
        }
        $lead->save();
    }
}
