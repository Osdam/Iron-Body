<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;

/**
 * Que la persona sienta que la ayudan, no que la empujan.
 *
 * El Critic juzga el tono con criterio y con contexto; esto es la red de
 * abajo, determinista, y por eso SOLO bloquea lo inequívoco: el reproche en
 * segunda persona, la escasez sin cifra, el «ahora o nunca», el testimonio y
 * el porcentaje que nadie verificó. Todo lo que puede ser un hecho contado
 * bien —«tu plan termina mañana», «quedan 2 cupos en la de las 6», «la promo
 * va hasta el 30», «ya deberías haber recibido el link»— se ANOTA como
 * bandera para que lo juzgue el Critic, nunca se corta: un falso positivo aquí
 * deja a la persona sin respuesta, y eso cuesta un cliente. Nada de esto mira
 * cifras ni hechos: sólo forma y presión.
 */
final class ComposerStyleGuard
{
    public const CODE_PRESSURE = 'machine_reply_pressure';

    /**
     * Pictogramas por rangos de código y no por \p{Extended_Pictographic}: el PCRE de
     * producción (10.39, 2021) no conoce esa propiedad y la regex no compila. Quedan fuera
     * a propósito ©®™ (son prosa) y los indicadores regionales (las banderas van de a dos).
     */
    private const PICTO = '[\x{1F000}-\x{1F1E5}\x{1F200}-\x{1F3FA}\x{1F400}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B05}-\x{2B07}\x{2B1B}\x{2B1C}\x{2B50}\x{2B55}\x{231A}\x{231B}\x{2328}\x{23CF}\x{23E9}-\x{23F3}\x{23F8}-\x{23FA}\x{2194}-\x{2199}\x{21A9}\x{21AA}\x{25AA}\x{25AB}\x{25B6}\x{25C0}\x{25FB}-\x{25FE}\x{2934}\x{2935}\x{3030}\x{303D}\x{3297}\x{3299}\x{203C}\x{2049}\x{2139}\x{24C2}]';

    /** Urgencia y escasez que nadie declaró, dichas como se dicen para empujar. */
    private const URGENCIA_FALSA = [
        '/\b(no lo pienses (mas|tanto)|es ahora o nunca|decide (ya|ahora)( mismo)?\b|aprovecha ya\b)/u',
        '/\bultim[oa]s? (cupos?|lugares?|plazas?|oportunidad)\b/u',
        '/\bquedan (pocos|apenas|poquitos) (cupos?|lugares?|plazas?)\b/u',
        '/\bno te quedes sin (tu |el |un )?(cupo|lugar|plaza)\b/u',
        '/\b(es )?solo por hoy\b/u',
    ];

    /** Culpa y vergüenza corporal: sólo con el marco acusatorio, y nunca negadas. */
    private const CULPA = [
        '/(?<!\bno )(?<!\bnada de )\b(sin excusas|no hay excusas|deja (ya )?las excusas)\b/u',
        '/(?<!\bno )\b(todo (esta|es) (en la|de la|en tu) mente|es (solo )?cuestion de (actitud|voluntad|querer|ganas))\b/u',
        '/\b(estas|eres|te ves|sigues|vas a seguir|quedarte) (muy |tan |mas |asi de )?(gord[oa]s?|obes[oa]s?|flac[oa]s?|barrig[oó]n|panz[oó]n)\b/u',
        '/\b(por|de) (gord[oa]|flac[oa]|vag[oa]|perezos[oa])\b|\bcon ese cuerpo\b/u',
        '/\b(si (de verdad|realmente|en serio) quisieras|si te importara|no te (da|dio) verg[uü]enza|te va a (dar|quedar) pena)\b/u',
        '/\bya deberias (haber (empezado|venido|decidido|arrancado|cambiado)|estar (entrenando|yendo|aqui|en forma))\b/u',
        '/\ba tu edad (ya )?(deberias|tendrias que|no puedes|no deberias)\b/u',
    ];

    /** Testimonios y cifras sociales que nadie verificó. */
    private const TESTIMONIO_INVENTADO = [
        '/\b(mis|nuestr[oa]s|los) (clientes|alumn[oa]s|soci[oa]s|miembros) (dicen|cuentan|logran|bajan|pierden|ganan|renuevan)\b/u',
        '/\bun[a]? (cliente|clienta|soci[oa]|alumn[oa]) (mi[oa]|nuestr[oa])[^.!?]{0,24}?\b(bajo|perdio|gano|logro|consiguio|rebajo) \d+/u',
        '/\b(cientos|miles|millones) de (personas|clientes|socios|alumnos|usuarios)\b/u',
        '/\b\d+ de cada \d+ (clientes|socios|personas|alumnos)\b/u',
        '/\bla mayoria de (nuestros |los )?(clientes|socios|alumnos)\b/u',
        '/\bel \d+\s?% de (nuestros |los )?(clientes|socios|miembros|alumnos|que entran|que empiezan)\b/u',
    ];

    /**
     * DECIRSE EL MEJOR DE LA CIUDAD NO ES UNA OPINIÓN: ES UN DATO QUE NADIE TIENE.
     *
     * Se midió y el silencio era total: dieciocho frases de superioridad y
     * ranking —«el mejor gimnasio de Neiva», «somos el número uno», «líderes
     * en el Huila», «ningún otro gimnasio te da este acompañamiento»— pasaban
     * los nueve cerrojos de salida sin una sola marca. La medición estaba
     * validada con control positivo: garantías, traspasos, descuentos y
     * «últimos cupos» sí bloqueaban con su código.
     *
     * Y es el tipo de afirmación que el modelo NO puede deducir: para saber
     * quién es el mejor de una ciudad hace falta un dato del que Iron Body no
     * dispone. Si el negocio quiere decirlo, lo aprueba por escrito y entonces
     * sale; lo que no puede es nacer de una inferencia del redactor.
     *
     * LA DISCIPLINA DE DOS PIEZAS, igual que en los testimonios: no basta el
     * superlativo, hace falta el ÁMBITO con el que se compara. «Es el mejor
     * PARA TI» es una recomendación y sale —hay un test del repo que lo fija—;
     * «es el mejor DE NEIVA» es un ranking y no sale. La diferencia no es de
     * tono: una habla de encaje y la otra de un mercado.
     */
    /**
     * El mercado o la geografía con la que uno se compara.
     *
     * Vive en una constante porque la usan cuatro patrones distintos y tenerla
     * escrita cuatro veces garantizaba que se desincronizaran: ya pasó con el
     * artículo, que estaba en una lista y no en la otra, y por eso «somos el
     * mejor gimnasio del sector» no se bloqueó nunca.
     *
     * `zona` y `sector` llevan `(?! de )` porque también son el vocabulario
     * interno del gimnasio: la zona de pesas, el sector de espalda. Y `aqui`
     * lleva `(?! a )` porque «de aquí a fin de mes» es una fecha, no un sitio.
     */
    private const ALCANCE = '(de|en|del) (todo el |toda la |todo |toda |el |la )?'
        .'(neiva|ciudad|huila|region|colombia|pais|mercado|barrio|departamento|comuna|aca|'
        .'aqui(?! a )|(zona|sector)(?! de ))\b';

    private const SUPERIORIDAD = [
        /*
         * Superlativo + el mercado o la geografía con la que se compara.
         *
         * La frontera de la izquierda NO es `\b`: «#1» y «nº 1» empiezan por un
         * carácter que no es de palabra, así que `\b` no engancha ahí y «somos
         * el #1 en Neiva» se escapaba entera. Con el lookbehind sí, y sigue sin
         * enganchar dentro de otra palabra.
         */
        '/(?<![a-z0-9])(mejor(es)?|numero uno|#\s?1|n[º°]\s?1|lider(es)?|unic[oa]s?|superior(es)?|insuperable|'
            .'mas (completo|completa|grande|moderno|moderna|avanzado|avanzada|equipado|equipada|capacitad[oa]s?))\b'
            /*
             * SIN COMA EN MEDIO. «Es la mejor hora, en la zona hay menos
             * gente» son dos frases, no un ranking: el superlativo habla de la
             * hora y «la zona» de la sala. Al exigir que vayan en la misma
             * oración se cae ese falso positivo sin tener que sacar `zona` del
             * alcance, que es lo que hace falta para que «somos únicos en la
             * zona» siga muriendo.
             */
            .'[^.!?,;]{0,40}?\b'.self::ALCANCE.'/u',
        /*
         * Y la misma afirmación partida por una coma apositiva: «Somos el
         * mejor gimnasio, de Neiva y de todo el Huila». Prohibir la coma
         * arriba curaba un falso positivo y abría esto, que es exactamente lo
         * que esta familia existe para parar, y no es una evasión exótica: es
         * como escribe un modelo en WhatsApp.
         *
         * Aquí la coma SÍ se admite, y a cambio se exige que el alcance CIERRE
         * la oración —punto, coma, «y»— porque es lo que distingue las tres
         * formas: «de Neiva y…» y «de toda la ciudad.» cierran; «en la zona
         * hay menos gente» sigue con un verbo, y ahí «la zona» es la sala.
         *
         * Las dos hacen falta. Esta sola dejaría escapar «somos el mejor
         * gimnasio de Neiva PARA PRINCIPIANTES», que no cierra la oración.
         */
        '/(?<![a-z0-9])(mejor(es)?|numero uno|#\s?1|n[º°]\s?1|lider(es)?|unic[oa]s?|superior(es)?|insuperable|'
            .'mas (completo|completa|grande|moderno|moderna|avanzado|avanzada|equipado|equipada|capacitad[oa]s?))\b'
            .'[^.!?]{0,40}?\b'.self::ALCANCE.'(?=[.,;!?]|\s+[ye]\b|$)/u',
        // Y la comparación explícita con la competencia, que no necesita geografía.
        '/\b(ningun otro|ninguna otra|nadie mas|no hay otro|no existe otro)\b[^.!?]{0,30}?\b(gimnasio|gym|centro)\b/u',
        /*
         * `lugar` y `sitio` son demasiado corrientes para bastar solos: «no hay
         * otro sitio para parquear» es información, no un ranking. Exigen
         * además la cola comparativa que los convierte en presunción.
         */
        '/\b(ningun otro|no hay otro|no existe otro)\b[^.!?]{0,20}?\b(lugar|sitio)\b[^.!?]{0,25}?'
            .'\b(asi|igual|como este|como nosotros|en neiva|en la ciudad|en el huila|del huila)\b/u',
        /*
         * El determinante del medio es lo que la dejaba pasar: «mejores que
         * LAS DE los demás» no es «mejores que los demás», y el primer test
         * end-to-end lo encontró. Sólo se admite ese relleno («las de», «los
         * de»), no cualquier cosa: con `[^.!?]{0,20}` en medio, «mejor que
         * esperes a los demás» se bloquearía sin motivo.
         */
        '/\b(mejor|mejores) que (l[oa]s? de )?(la competencia|'
            .'(l[oa]s )?otr[oa]s (gimnasios?|gyms?|centros?)|cualquier otro (gimnasio|gym|centro))\b/u',
        // Y la forma sin sustantivo —«mejores que los demás»—, que solo cuenta
        // cuando ahí se acaba la frase: «mejor que los demás días» no compara
        // negocios, compara días.
        '/\b(mejor|mejores) que (l[oa]s? de )?(los demas|las demas|los otros|las otras|cualquier otro)\s*([.,;!?]|$)/u',
        /*
         * «A diferencia de otros PLANES, este incluye clases» es una frase de
         * venta correcta y honesta, y la versión anterior la mataba con un 422:
         * bastaba «a diferencia de otros» para dispararse. La comparación solo
         * es superioridad cuando lo comparado es OTRO NEGOCIO, así que el
         * sustantivo es obligatorio —o se nombra a la competencia, que no
         * necesita sustantivo porque ya lo dice.
         */
        '/\ba diferencia de (l[oa]s? )?(otr[oa]s?|dem[a]s)\b[^.!?]{0,15}?'
            .'\b(gimnasio|gimnasios|gym|gyms|centro|centros|lugar|lugares|sitio|sitios)\b/u',
        '/\ba diferencia de (la competencia|los demas gimnasios)\b/u',
        /*
         * Tres clases más, encontradas midiendo paráfrasis contra la versión
         * anterior. No son sinónimos sueltos —eso sería ampliar el regex a
         * ciegas— sino tres FORMAS distintas de decir lo mismo que las de
         * arriba no podían ver:
         *
         *  · el alcance ANTES del superlativo: «De todos los gimnasios de
         *    Neiva, somos el mejor» leía al revés y se escapaba entera;
         *  · la exclusividad en negativo sin la palabra «otro»: «no hay nadie
         *    como nosotros», «ningún gimnasio de Neiva se nos compara»;
         *  · y ser el primero, que es un ranking con otro nombre. Exige el
         *    alcance detrás a propósito: «somos los primeros en apoyarte» es
         *    una frase honesta y no puede morir aquí.
         */
        '/\b(de|en|entre) (todos los|todas las) (gimnasios|gyms|centros)\b'
            .'[^.!?]{0,40}?(?<![a-z0-9])(mejor(es)?|numero uno|primer[oa]?s?|unic[oa]s?)\b/u',
        '/(?<![a-z0-9])(ningun[oa]?|nadie|nada|no hay|no existe)\b[^.!?]{0,30}?'
            .'\b(como nosotros|como aqui|se nos compara|nos iguala|como el nuestro|'
            .'te da lo que nosotros|da lo que nosotros|lo que nosotros te damos)\b/u',
        '/(?<![a-z0-9])(somos|fuimos|seguimos siendo) (el|la|los|las) primer[oa]?s?\b'
            .'[^.!?]{0,30}?\b'.self::ALCANCE.'/u',

        /*
         * ── Cuatro familias más, de una revisión que midió con un corpus que
         * no estaba escrito alrededor de este patrón. ──────────────────────
         *
         * A · INVERSIÓN SIMPLE. El alcance delante SIN el preámbulo «de todos
         * los»: «En Neiva, somos el mejor gimnasio». Exige sujeto («somos»,
         * «es») para no matar «En Neiva el mejor momento para entrenar es
         * temprano», que habla de la hora y no del gimnasio.
         */
        '/\b'.self::ALCANCE.'[^.!?]{0,25}?\b(somos|es|son) (el|la|los|las) '
            .'(mejor(es)?|numero uno|unic[oa]s?|primer[oa]s?|lider(es)?)\b/u',
        '/\bno hay (un |una |otro |otra |ningun |ninguna )?(gimnasio|gym|centro)\b[^.!?]{0,25}?'
            .'\b(mejor|igual|asi|como este|como nosotros)\b/u',
        /*
         * «JAMÁS encontrarÁS algo igual» pasaba entera: el patrón pedía «no» y
         * el infinitivo, y aquí no hay ni una cosa ni la otra. La negación en
         * español tiene tres adverbios y el verbo va en futuro la mitad de las
         * veces, así que se admiten los tres y la conjugación.
         *
         * Lo que se compara sí va cerrado —«algo igual», «otro gimnasio»— y no
         * un «igual» suelto: «no vas a encontrar parqueadero igual de cerca»
         * es información, no una jactancia.
         */
        '/\b(no|jamas|nunca)\b[^.!?]{0,15}?\bencontrar(as|a|an|emos|ias|e)?\b[^.!?]{0,30}?'
            .'\b((algo|nada) (asi|igual|parecido|como esto)|otro (gimnasio|gym|centro))\b/u',

        /*
         * C · EXCLUSIVIDAD SIN LA PALABRA «OTRO», que es la forma natural:
         * «Ningún gimnasio de Neiva te da este acompañamiento». La variante
         * con «otro» ya moría; quitarle esa palabra la liberaba entera.
         *
         * `ninguno de (los|las)` y no `ninguno de` a secas para que «ninguno de
         * NUESTROS planes tiene permanencia» siga saliendo.
         */
        '/(?<![a-z0-9])(ningun|ninguna|ninguno de (los|las)|ninguna de (los|las))\b[^.!?]{0,30}?'
            .'\b(gimnasio|gimnasios|gym|gyms|centro|centros)\b/u',

        /*
         * D · RANKING SIN ALCANCE. «Somos el número uno», «Iron Body es
         * insuperable». A diferencia de «mejor» o «más completo», estas
         * palabras NO tienen lectura honesta cuando uno se las aplica a sí
         * mismo: no existe un «número uno para ti».
         *
         * Por eso es la única familia anclada al SUJETO —y a nosotros, no a
         * cualquiera—: «llevamos el número uno en la camiseta» habla de un
         * dorsal, y «el entrenador es el líder del grupo» no es una jactancia.
         * Y con excepción explícita para «para ti», que sí es recomendación.
         */
        '/(?<![a-z0-9])(somos|iron body es|iron body sigue siendo|nuestro (gimnasio|equipo) es|'
            .'este gimnasio es)\s+(el |la |los |las )?(gimnasio |gym |centro |equipo )?'
            .'(numero uno|#\s?1|n[º°]\s?1|lider(es)?|insuperable|invencible|imbatible|'
            .'indiscutibles?|inigualable)\b(?![^.!?]{0,20}\bpara (ti|vos|usted|tu objetivo)\b)/u',

        /*
         * E · COMPARACIÓN PARAFRASEADA. Aquí NO se persiguen sinónimos del
         * superlativo —eso no acaba nunca—: se exige la estructura completa,
         * comparativo + a quién se compara. Nombrar a otros gimnasios sin
         * compararse es legítimo y tiene que seguir saliendo, porque «no te
         * puedo comparar con otros gimnasios» es la respuesta honesta a que
         * te pidan una comparación.
         */
        '/(?<![a-z0-9])(somos|estamos|es|son) (mejor(es)?|superior(es)?|por encima)\b[^.!?]{0,25}?'
            .'\b(que|de|a)\b[^.!?]{0,20}?\b(la competencia|cualquier (gimnasio|gym|centro)|otros gimnasios)\b/u',
        /*
         * «Cualquiera», «el resto» y «los demás» solo cuentan si ahí se acaba
         * la frase. Con un sustantivo detrás ya no comparan negocios: «es mejor
         * que los demás DÍAS de la semana» y «es mejor que esperes a los demás
         * para la clase» son frases honestas, y las dos murieron con 422 en la
         * primera versión de esta familia.
         */
        '/(?<![a-z0-9])(somos|estamos|es|son) (mejor(es)?|superior(es)?|por encima)\b[^.!?]{0,25}?'
            .'\b(que|de|a) (cualquiera|el resto|los demas|las demas|todos)\s*([.,;!?]|$)/u',
        '/(?<![a-z0-9])(superamos|le ganamos|les ganamos|ganamos) a\b[^.!?]{0,25}?'
            .'\b(cualquier (gimnasio|gym|centro)|otros gimnasios|la competencia|todos)\b/u',
        '/(?<![a-z0-9])(otros gimnasios|el resto de (los )?gimnasios|los demas gimnasios|la competencia)\b[^.!?]{0,30}?'
            .'\b(no (te )?(dan|tienen|ofrecen|llegan)|ni se acercan?|no se comparan?|'
            .'y ganamos|y salimos ganando|y no hay color)\b/u',
    ];

    /**
     * Especulación sobre por qué un precio cambió.
     *
     * Prueba física: la persona vio $80.000 y luego $45.000 para el mismo
     * plan y preguntó por qué; el modelo contestó «la diferencia puede ser
     * por confusión con otro plan o información previa». No lo sabía: se lo
     * inventó. Lo que el CRM sabe es el precio de hoy, y eso es lo único que
     * se afirma. Se detecta por ORACIÓN para poder retirar la frase y dejar
     * el resto, que suele traer el precio correcto.
     *
     * Disculparse no es especular: «disculpa la confusión» no afirma una
     * causa y no cae aquí.
     */
    private const EXCUSA_DE_PRECIO = [
        // Con cola CAUSAL obligatoria: «la diferencia de precio puede parecer
        // grande» es una frase honesta y la revisión la vio morir.
        '/\b(diferencia|cambio|variacion|discrepancia)\b[^.!?]{0,40}\b(precio|valor|costo|cifra|monto)\b[^.!?]{0,40}'
            .'\b(puede|podria|pudo|debe|deberia|quiza|quizas|tal vez|seguramente|probablemente|posiblemente)\s+'
            .'(ser|deberse|haber sido|tratarse|se deba|se debe|venir)\s+(por|a|de)\b/u',
        '/\b(puede|podria|pudo|quiza|quizas|tal vez|seguramente|probablemente|posiblemente)\s+'
            .'(ser|es|fue|era|deberse|se deba|se debe|se debio|haber sido|tratarse|se trate|se trata)\s+(por|a|de)\s+'
            .'(una |un |la |el )?(confusion|error|equivocacion|informacion (previa|anterior|desactualizada|vieja)|otro plan|un plan (distinto|diferente)|promocion)\b/u',
        '/\b(por (una )?confusion con otro plan|por informacion previa|por informacion anterior)\b/u',
        '/\b(el precio|ese precio|ese valor)\s+(anterior|de antes)\s+(era|fue|correspondia|seria)\s+(de|a|por|del)\s+(otro plan|una promocion|un error)\b/u',
    ];

    /** Lo que PUEDE ser presión o puede ser un hecho: lo decide el Critic. */
    private const URGENCIA_AMBIGUA = '/\b(termina (hoy|pronto|manana)|se acaba (hoy|pronto|ya|manana)|solo hasta|aprovecha (ahora|hoy)|quedan (pocos|solo) dias|ultim[oa]s? dias?|antes de que se (acabe|agote|llene)|precio (sube|subira|aumenta))\b/u';

    private const EDAD_O_CUERPO = '/\b(a tu edad|gord[oa]s?|obes[oa]s?|flac[oa]s?|barrig[oó]n|panz[oó]n)\b/u';

    /** Muletillas de bot y cierres automáticos. */
    private const MULETILLAS = '/\b(hay algo mas en (lo )?que (te )?pueda ayudar(te|le)?|no dudes en (consultar|preguntar|escribir)(me|nos)?|estoy aqui para ayudarte|estamos para servirte|quedo atent[oa] a (tus|cualquier)|sera un placer atenderte|como asistente virtual)\b/u';

    /** Más de esto en un mensaje de WhatsApp ya es un folleto. */
    public const MAX_PLANS_PER_MESSAGE = 3;

    public const MAX_BULLETS = 4;

    public const MAX_EMOJI = 2;

    public const BROCHURE_LENGTH = 900;

    /**
     * @param  array<int, array{id:int, name:string}>  $sellablePlans
     * @param  bool  $askedForAll  la persona pidió ver todos los planes
     * @param  string[]  $approvedClaims  frases de marca que el negocio aprobó por escrito
     * @return array{hard: string[], soft: string[], plans_mentioned: int}
     */
    public function inspect(
        string $replyFinal,
        array $sellablePlans,
        bool $askedForAll = false,
        bool $askedForDetail = false,
        array $approvedClaims = [],
    ): array {
        $t = $this->normalize($replyFinal);
        $hard = [];
        $soft = [];

        foreach (['fake_urgency' => self::URGENCIA_FALSA, 'guilt_or_body_shaming' => self::CULPA, 'invented_testimonial' => self::TESTIMONIO_INVENTADO] as $code => $patterns) {
            foreach ($patterns as $rx) {
                if (preg_match($rx, $t) === 1) {
                    $hard[] = $code;
                    break;
                }
            }
        }

        /*
         * La superioridad va aparte porque tiene una puerta que las demás no:
         * el negocio puede autorizarla por escrito. Lo que se comprueba no es
         * que la frase aprobada esté en algún sitio del mensaje, sino que el
         * TROZO que dispara la alarma esté dentro de una frase aprobada. Si no,
         * bastaría con incluir una frase autorizada para colar cualquier otra.
         */
        $aprobadas = array_values(array_filter(array_map(
            fn ($c) => $this->normalize((string) $c),
            $approvedClaims,
        )));

        /*
         * TODAS las coincidencias, no la primera.
         *
         * Con `preg_match` se medía sólo una, y bastaba con abrir el mensaje
         * con la frase que el negocio SÍ aprobó para que la siguiente —«y
         * somos los número uno del Huila»— saliera de gorra. La autorización
         * es por frase, así que hay que mirarlas una por una.
         */
        foreach (self::SUPERIORIDAD as $rx) {
            if (preg_match_all($rx, $t, $todas) < 1) {
                continue;
            }

            foreach ($todas[0] as $bruto) {
                $trozo = trim((string) $bruto);
                $autorizada = false;

                foreach ($aprobadas as $frase) {
                    if ($trozo !== '' && str_contains($frase, $trozo)) {
                        $autorizada = true;
                        break;
                    }
                }

                if ($autorizada) {
                    $soft[] = 'approved_superiority_claim';

                    continue;
                }

                $hard[] = 'unapproved_superiority_claim';
            }
        }

        $hard = array_values(array_unique($hard));
        $soft = array_values(array_unique($soft));

        if (preg_match(self::URGENCIA_AMBIGUA, $t) === 1) {
            $soft[] = 'urgency_wording';
        }
        if (preg_match(self::EDAD_O_CUERPO, $t) === 1) {
            $soft[] = 'age_or_body_reference';
        }
        $plans = $this->plansMentioned($t, $this->stripAccentsKeepCase($replyFinal), $sellablePlans);
        if ($plans > self::MAX_PLANS_PER_MESSAGE && ! $askedForAll) {
            $soft[] = 'plan_dump';
        }
        if ($this->bulletCount($replyFinal) > self::MAX_BULLETS) {
            $soft[] = 'bullet_abuse';
        }
        if (preg_match(self::MULETILLAS, $t) === 1) {
            $soft[] = 'bot_phrase';
        }
        if ($this->emojiCount($replyFinal) > self::MAX_EMOJI) {
            $soft[] = 'emoji_excess';
        }
        if (mb_strlen($replyFinal) > self::BROCHURE_LENGTH && ! $askedForDetail) {
            $soft[] = 'brochure_length';
        }

        return ['hard' => array_values(array_unique($hard)), 'soft' => array_values(array_unique($soft)), 'plans_mentioned' => $plans];
    }

    /** «Muéstrame todos los planes», «qué planes tienen»: pidió el catálogo. */
    public function askedForAllPlans(string $inbound): bool
    {
        $t = $this->normalize($inbound);

        return preg_match('/\b(todos los planes|(que|cuales|k) planes (tienen|hay|manejan|ofrecen|existen)|cuales son (los|sus) planes|los planes que (tienen|manejan)|opciones de planes|planes disponibles|lista de planes|todas las opciones|(info|informacion) de (los |sus )?planes|me (muestras|mandas|pasas|envias) los planes)\b/u', $t) === 1;
    }

    /** «Explícame bien», «con detalle», «todo lo que incluye»: pidió profundidad. */
    public function askedForDetail(string $inbound): bool
    {
        $t = $this->normalize($inbound);

        return preg_match('/\b(con (mas )?detalle|en detalle|detalladamente|explicame bien|explicamelo bien|todo lo que (incluye|trae)|cuentame todo|paso a paso|a fondo|bien explicado)\b/u', $t) === 1;
    }

    /**
     * Planes NOMBRADOS como planes: el nombre completo del catálogo («plan
     * semana»), el núcleo con artículo («el trimestre») o el núcleo con
     * mayúscula inicial en el texto original («Trimestre, Semestre y Élite»,
     * que es como se escribe un folleto). La palabra suelta en prosa («esta
     * semana no puedo», «el otro semestre») no cuenta.
     *
     * @param  array<int, array{id:int, name:string}>  $sellablePlans
     */
    private function plansMentioned(string $t, string $originalCase, array $sellablePlans): int
    {
        $n = 0;
        foreach ($sellablePlans as $p) {
            $full = $this->normalize((string) ($p['name'] ?? ''));
            if ($full === '') {
                continue;
            }
            $core = trim(preg_replace('/^plan\s+/u', '', $full) ?? $full);
            $q = preg_quote($core, '/');
            $nombrado = ($full !== $core && preg_match('/\b'.preg_quote($full, '/').'\b/u', $t) === 1)
                || preg_match('/\b(el|la|del|plan) '.$q.'\b/u', $t) === 1
                || preg_match('/\b'.mb_strtoupper(mb_substr($q, 0, 1)).mb_substr($q, 1).'\b/u', $originalCase) === 1;
            if ($nombrado) {
                $n++;
            }
        }

        return $n;
    }

    /** Sin tildes pero con mayúsculas: para distinguir «Semestre» (plan) de «semestre» (palabra). */
    private function stripAccentsKeepCase(string $s): string
    {
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
    }

    private function bulletCount(string $s): int
    {
        // Guion ASCII y números exigen espacio (para no contar guiones de prosa);
        // los marcadores tipográficos y de emoji cuentan con o sin él.
        return preg_match_all('/^\s*(?:[-*]\s+|\d+[.)]\s+|[–—•·▪▶✔✅👉➡]\s*)/mu', $s);
    }

    /** Grafemas emoji: una secuencia con tono de piel o ZWJ cuenta uno; una bandera cuenta una. */
    private function emojiCount(string $s): int
    {
        $picto = self::PICTO.'\x{FE0F}?(?:[\x{1F3FB}-\x{1F3FF}])?';

        return preg_match_all('/(?:[\x{1F1E6}-\x{1F1FF}]{2}|'.$picto.'(?:\x{200D}'.$picto.')*)/u', $s);
    }

    /**
     * La primera excusa de precio que hay en el texto, o null.
     *
     * Devuelve el trozo para que quien llama pueda retirar la oración entera
     * y dejar el resto; y para que en el log se vea QUÉ se retiró.
     */
    public function priceExcuseIn(string $texto): ?string
    {
        $t = $this->normalize($texto);
        foreach (self::EXCUSA_DE_PRECIO as $rx) {
            if (preg_match($rx, $t, $m) === 1) {
                return trim((string) $m[0]);
            }
        }

        return null;
    }

    private function normalize(string $s): string
    {
        return strtr(SalesAgentDecisionSchema::normalize($s), ['ü' => 'u']);
    }
}
