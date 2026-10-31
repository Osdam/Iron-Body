<?php

namespace App\Services\Trainer;

/**
 * Restricciones alimentarias y señales clínicas, leídas de texto libre de forma
 * DETERMINISTA (docs/iron-ia/POTENCIAS-IRON-IA.md §4).
 *
 * POR QUÉ UN LÉXICO Y NO EL MODELO. Las restricciones y las alergias no existen
 * como campo: viven en texto (la última guía publicada, el perfil de Iron IA,
 * las notas de la valoración). Preguntarle al modelo si «choca» un ingrediente
 * es pedirle a quien se equivocó que se corrija solo. Aquí la comprobación es
 * una lista que cualquiera puede leer, y su resultado se puede repetir.
 *
 * LO QUE NO HACE. No diagnostica ni interpreta: si encuentra «diabetes» en las
 * notas, no sabe de qué tipo ni si está controlada; solo sabe que hay una señal
 * clínica y que, con ella, no se prescribe (plan general + revisión
 * profesional). Y una negación sencilla («sin diabetes», «niega patologías»)
 * no cuenta como señal. Lo que no reconoce no lo inventa: «alergias no
 * registradas» nunca significa «sin alergias».
 */
class PlanSafetyLexicon
{
    /**
     * Restricciones: clave → [etiqueta, disparadores fuertes, disparadores con
     * pista, ingredientes prohibidos, calificadores que lo permiten].
     *
     * Fuerte: la palabra ya implica la restricción («lactosa», «vegetariano»).
     * Con pista: solo cuenta si va precedida de «sin», «alergia», «no come»…
     * («leche» sola puede ser «toma leche»).
     *
     * TODO se compara por PALABRA COMPLETA (o su plural), sin tildes ni
     * mayúsculas: «res» es «res», nunca «respetando», «resto» ni «fresas».
     * Solo lo que termina en `*` es una raíz («vegetarian*» → vegetariano,
     * vegetariana); los prohibidos no llevan ninguna.
     */
    private const RESTRICTIONS = [
        'lactose' => [
            'label' => 'Sin lactosa',
            'strong' => ['lactosa'],
            'cued' => [],
            'forbidden' => ['leche', 'queso', 'yogur', 'yogurt', 'kumis', 'mantequilla', 'crema de leche', 'arequipe', 'cuajada', 'kefir', 'helado', 'suero de leche'],
            'allowed' => ['deslactosad', 'sin lactosa', 'vegetal', 'de almendra', 'de soya', 'de soja', 'de coco', 'de avena', 'de arroz', 'de mani'],
        ],
        'dairy' => [
            'label' => 'Sin lácteos',
            // «Lácteos» sola es débil: «come lácteos bajos en grasa» no es una
            // restricción. Solo cuenta con pista («sin lácteos», «alergia a…»).
            'strong' => ['proteina de la leche', 'proteina de leche'],
            'cued' => ['leche', 'lacteos', 'lacteo'],
            'forbidden' => ['leche', 'queso', 'yogur', 'yogurt', 'kumis', 'mantequilla', 'crema de leche', 'arequipe', 'cuajada', 'kefir', 'helado', 'suero de leche', 'whey'],
            'allowed' => ['vegetal', 'de almendra', 'de soya', 'de soja', 'de coco', 'de avena', 'de arroz', 'de mani'],
        ],
        'gluten' => [
            'label' => 'Sin gluten',
            'strong' => ['gluten', 'celiac*', 'celiaq*'],
            'cued' => ['trigo'],
            'forbidden' => ['trigo', 'pan', 'pasta', 'espagueti', 'galleta', 'cebada', 'centeno', 'avena', 'cuscus', 'seitan', 'harina de trigo', 'tortilla de trigo', 'tostada'],
            'allowed' => ['sin gluten', 'de maiz', 'de arroz', 'de yuca', 'de bono', 'certificad', 'de almendra'],
        ],
        'egg' => [
            'label' => 'Sin huevo',
            'strong' => [],
            'cued' => ['huevo', 'huevos'],
            'forbidden' => ['huevo', 'clara', 'yema', 'mayonesa', 'tortilla de huevo'],
            'allowed' => ['sin huevo'],
        ],
        'peanut' => [
            'label' => 'Sin maní',
            'strong' => [],
            'cued' => ['mani', 'cacahuate', 'cacahuete'],
            'forbidden' => ['mani', 'cacahuate', 'cacahuete'],
            'allowed' => ['sin mani'],
        ],
        'tree_nuts' => [
            'label' => 'Sin frutos secos',
            'strong' => [],
            'cued' => ['frutos secos', 'nueces', 'nuez', 'almendra', 'almendras', 'marañon', 'maranon', 'pistacho', 'avellana'],
            'forbidden' => ['nuez', 'nueces', 'almendra', 'maranon', 'pistacho', 'avellana', 'frutos secos', 'pecana', 'macadamia'],
            'allowed' => [],
        ],
        'fish_seafood' => [
            'label' => 'Sin pescado ni mariscos',
            'strong' => [],
            'cued' => ['pescado', 'pescados', 'mariscos', 'marisco', 'camaron', 'camarones', 'atun'],
            'forbidden' => ['pescado', 'atun', 'salmon', 'tilapia', 'mojarra', 'sardina', 'bagre', 'trucha', 'merluza', 'camaron', 'marisco', 'calamar', 'pulpo', 'langostino', 'mejillon', 'cazon', 'bocachico'],
            'allowed' => [],
        ],
        'pork' => [
            'label' => 'Sin cerdo',
            'strong' => [],
            'cued' => ['cerdo', 'porcino', 'carne de cerdo'],
            'forbidden' => ['cerdo', 'tocino', 'tocineta', 'chicharron', 'jamon', 'chorizo', 'longaniza', 'salchicha', 'costilla de cerdo', 'lomo de cerdo', 'pernil'],
            'allowed' => ['de pavo', 'de pollo', 'vegetal'],
        ],
        'red_meat' => [
            'label' => 'Sin carne roja',
            'strong' => [],
            'cued' => ['carne roja', 'carnes rojas', 'carne de res', 'res'],
            'forbidden' => ['res', 'carne de res', 'carne roja', 'cerdo', 'cordero', 'ternera', 'chorizo', 'tocino', 'tocineta', 'chicharron', 'costilla de res', 'costilla de cerdo', 'sobrebarriga', 'carne molida', 'lomo de cerdo', 'lomo de res', 'longaniza', 'salchichon'],
            'allowed' => ['de pollo', 'de pavo', 'vegetal'],
        ],
        'soy' => [
            'label' => 'Sin soya',
            'strong' => [],
            'cued' => ['soya', 'soja'],
            'forbidden' => ['soya', 'soja', 'tofu', 'tempeh'],
            'allowed' => [],
        ],
        'vegetarian' => [
            'label' => 'Vegetariano',
            'strong' => ['vegetarian*', 'ovolact*'],
            'cued' => ['carne', 'carnes'],
            // «Sin carne roja» o «sin carne de res» NO es vegetariano: es
            // `red_meat`.
            'cued_not_followed_by' => ['roja', 'rojas', 'de res', 'de cerdo'],
            'forbidden' => ['pollo', 'pechuga', 'res', 'carne', 'cerdo', 'pescado', 'atun', 'salmon', 'tilapia', 'pavo', 'jamon', 'salchicha', 'chorizo', 'tocino', 'tocineta', 'camaron', 'marisco', 'higado', 'chicharron', 'costilla', 'lomo', 'muslo', 'mojarra', 'trucha', 'sardina'],
            'allowed' => ['vegetal', 'de soya', 'de soja', 'de lenteja', 'de garbanzo', 'sin carne'],
        ],
        'vegan' => [
            'label' => 'Vegano',
            'strong' => ['vegan*'],
            'cued' => [],
            'forbidden' => ['pollo', 'pechuga', 'res', 'carne', 'cerdo', 'pescado', 'atun', 'salmon', 'tilapia', 'pavo', 'jamon', 'salchicha', 'chorizo', 'tocino', 'camaron', 'marisco', 'higado', 'huevo', 'clara', 'leche', 'queso', 'yogur', 'yogurt', 'kumis', 'mantequilla', 'arequipe', 'miel', 'whey', 'cuajada', 'mayonesa'],
            'allowed' => ['vegetal', 'de almendra', 'de soya', 'de soja', 'de coco', 'de avena', 'de arroz', 'vegana', 'vegano'],
        ],
        'sugar' => [
            'label' => 'Sin azúcar añadida',
            'strong' => [],
            'cued' => ['azucar', 'dulce', 'dulces'],
            'forbidden' => ['azucar', 'panela', 'arequipe', 'gaseosa', 'bocadillo', 'mermelada'],
            'allowed' => ['sin azucar', 'edulcorante', 'stevia'],
        ],
    ];

    /** Palabras que convierten un disparador «con pista» en restricción. */
    private const CUES = ['sin', 'alergia', 'alergico', 'alergica', 'alergias', 'alergicos', 'intolerancia', 'intolerante', 'evitar', 'evita', 'excluir', 'excluye', 'nada', 'prohibido', 'prohibida', 'cero'];

    /** Verbos que son pista solo detrás de «no»: «no come», «no toma». */
    private const CUE_VERBS = ['come', 'consume', 'toma', 'tolera', 'puede', 'comer', 'consumir', 'tomar', 'bebe'];

    /** Lo que puede haber entre la pista y el alimento («alergia al maní y al huevo»). */
    private const CUE_GLUE = [',', 'y', 'e', 'ni', 'o', 'u', 'a', 'al', 'el', 'la', 'los', 'las', 'de', 'del', 'con', 'ningun', 'ninguna', 'ningunos', 'tipo'];

    /** Lo que puede haber dentro de una enumeración negada («niega hipertensión, diabetes tipo 2 y asma»). */
    private const NEGATION_GLUE = [',', 'y', 'e', 'ni', 'o', 'u', 'tipo', 'de', 'la', 'el', 'los', 'las'];

    /**
     * Usos NO clínicos de palabras clínicas: se tapan antes de buscar señales.
     * «Frecuencia cardíaca» o «trabajo cardiovascular» son entrenamiento.
     */
    private const CLINICAL_EXCLUDE = [
        '/(?<![a-z])(frecuencia|ritmo|zona|pulso) cardiac[a-z]*/',
        '/(?<![a-z])(ejercicio|ejercicios|trabajo|entrenamiento|resistencia|capacidad|acondicionamiento|rendimiento|actividad|sesion|sesiones|zona|condicion|salud|cardio)( [a-z]+)? cardiovascular[a-z]*/',
    ];

    /**
     * Lo que NUNCA puede aparecer en un plan generado, ni para desaconsejarlo
     * (M2): fármacos, ayunos prolongados, prácticas purgativas. Si aparece, la
     * sección se repara o, en el nivel 1, el plan se rechaza.
     */
    private const RISK = [
        'ayuno prolongado', 'ayunos prolongados', 'ayuno de 24', 'ayuno de 36', 'ayuno de 48', 'ayuno de 72', 'ayuno seco', 'ayuno intermitente prolongado',
        'diuretic*', 'laxant*', 'purgante*', 'purga', 'vomito inducido', 'inducir el vomito', 'provocar el vomito',
        'clembuterol', 'esteroide*', 'anabolic*', 'anabolizante*', 'testosterona', 'trembolona', 'oxandrolona', 'estanozolol', 'nandrolona',
        'sarm', 'sarms', 'efedrina', 'dinitrofenol', 'dnp', 'sibutramina', 'hormona de crecimiento', 'quemadores de grasa', 'quemador de grasa',
        'menos de 800 kcal', 'menos de 1000 kcal',
    ];

    /** Señales clínicas: clave → [etiqueta, fragmentos]. */
    private const CLINICAL = [
        'pregnancy' => ['Embarazo o lactancia', ['embaraz*', 'gestacion', 'gestante', 'lactancia materna', 'lactando', 'amamant*', 'posparto', 'postparto']],
        'diabetes' => ['Diabetes o control de glucosa', ['diabet*', 'insulina', 'metformina', 'hipoglucem*', 'prediabet*', 'resistencia a la insulina']],
        'cardio' => ['Condición cardiovascular o hipertensión', ['hipertens*', 'hta', 'presion alta', 'tension alta', 'cardiovascular*', 'cardiopat*', 'cardiac*', 'infarto', 'arritmia', 'marcapaso', 'anticoagul*']],
        'renal_hepatic' => ['Condición renal o hepática', ['renal', 'rinon', 'dialisis', 'hepat*', 'cirrosis', 'higado graso']],
        'oncology' => ['Tratamiento oncológico', ['cancer', 'oncolog*', 'quimioterap*', 'radioterap*']],
        'eating_disorder' => ['Trastorno de la conducta alimentaria', ['anorexi*', 'bulimi*', 'trastorno alimentario', 'trastornos alimentarios', 'trastorno de la conducta alimentaria', 'atracon']],
        'neuro' => ['Epilepsia o convulsiones', ['epileps*', 'convuls*']],
        'thyroid' => ['Alteración tiroidea', ['*tiroid*']],
        'surgery' => ['Cirugía reciente o bariátrica', ['bariatric*', 'cirugia reciente', 'recien operad*', 'postoperator*', 'posoperator*']],
        'metabolic' => ['Alteración de lípidos', ['dislipidem*', 'colesterol alto', 'trigliceridos altos', 'hipercolesterol*']],
        'respiratory' => ['Asma o condición respiratoria', ['asma', 'epoc', 'enfermedad pulmonar']],
    ];

    /**
     * Negaciones que anulan una señal clínica. SOLO estas, justo antes del
     * término o al principio de su enumeración: «sin diabetes» o «no refiere
     * diabetes» SÍ cuentan como señal. Ante la duda, fallo cerrado.
     */
    private const NEGATIONS = ['no', 'niega', 'descarta'];

    /**
     * Restricciones declaradas en esos textos.
     *
     * @param  list<string|null>  $texts
     * @return list<array{key: string, label: string}>
     */
    public function restrictionsIn(array $texts): array
    {
        $texto = $this->normalize(implode(' . ', array_filter($texts, fn ($t) => is_string($t) && trim($t) !== '')));
        if ($texto === '') {
            return [];
        }

        $out = [];
        foreach (self::RESTRICTIONS as $clave => $r) {
            if ($this->matchesRestriction($texto, $r)) {
                $out[] = ['key' => $clave, 'label' => $r['label']];
            }
        }

        // Vegano incluye a vegetariano y a sin lácteos: se dejan las tres para
        // que cada ingrediente diga con qué choca, pero no se duplica el aviso.
        return $out;
    }

    /**
     * ¿Choca este texto (un ingrediente, una preparación, una sustitución) con
     * alguna de las restricciones? Devuelve la PRIMERA colisión en el orden del
     * texto, con la palabra tal como aparece y un fragmento alrededor, para
     * poder decirle al modelo exactamente qué quitar.
     *
     * @param  list<string>  $keys  claves de {@see restrictionsIn()}
     * @return array{key: string, label: string, term: string, found: string, snippet: string}|null
     */
    public function conflict(string $text, array $keys): ?array
    {
        return $this->conflicts($text, $keys)[0] ?? null;
    }

    /**
     * TODAS las colisiones del texto, en orden de aparición y sin repetir
     * palabra. Una reparación que solo nombra la primera deja las demás.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, term: string, found: string, snippet: string}>
     */
    public function conflicts(string $text, array $keys): array
    {
        $t = $this->normalize($text);
        if ($t === '') {
            return [];
        }

        $hallados = [];
        foreach ($keys as $clave) {
            $r = self::RESTRICTIONS[$clave] ?? null;
            if ($r === null) {
                continue;
            }

            foreach ($r['forbidden'] as $prohibido) {
                foreach ($this->matches($t, $prohibido) as [$pos, $palabra]) {
                    // El calificador cuenta si va en la MISMA mención: unas
                    // letras antes cubren «sin azúcar»; unas después, «leche
                    // deslactosada». «leche y queso de soya» no salva a la leche.
                    $desde = max(0, $pos - 12);
                    $ventana = mb_substr($t, $desde, ($pos - $desde) + mb_strlen($palabra) + 28);
                    foreach ($r['allowed'] as $ok) {
                        if (str_contains($ventana, $ok)) {
                            continue 2;
                        }
                    }
                    $hallados[$pos] ??= [
                        'key' => $clave,
                        'label' => $r['label'],
                        'term' => $prohibido,
                        'found' => $palabra,
                        'snippet' => trim(mb_substr($t, max(0, $pos - 25), mb_strlen($palabra) + 50)),
                    ];
                }
            }
        }

        ksort($hallados);
        $out = [];
        foreach ($hallados as $h) {
            $out[$h['found']] ??= $h;
        }

        return array_values($out);
    }

    /**
     * Lo que no puede aparecer con estas restricciones, para decírselo al
     * modelo ANTES de que escriba.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    public function forbiddenTerms(array $keys): array
    {
        $out = [];
        foreach ($keys as $clave) {
            foreach (self::RESTRICTIONS[$clave]['forbidden'] ?? [] as $t) {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Señales clínicas en esos textos.
     *
     * @param  list<string|null>  $texts
     * @return list<array{key: string, label: string}>
     */
    public function clinicalSignalsIn(array $texts): array
    {
        $texto = $this->normalize(implode(' . ', array_filter($texts, fn ($t) => is_string($t) && trim($t) !== '')));
        if ($texto === '') {
            return [];
        }

        foreach (self::CLINICAL_EXCLUDE as $patron) {
            $texto = (string) preg_replace_callback($patron, fn ($m) => str_repeat(' ', mb_strlen($m[0])), $texto);
        }

        $out = [];
        foreach (self::CLINICAL as $clave => [$etiqueta, $fragmentos]) {
            foreach ($fragmentos as $f) {
                if ($this->affirmed($texto, $f)) {
                    $out[] = ['key' => $clave, 'label' => $etiqueta];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * El primer término de riesgo del texto generado (M2), o null.
     *
     * @return array{term: string, found: string, snippet: string}|null
     */
    public function riskIn(string $text): ?array
    {
        $t = $this->normalize($text);
        $primero = null;
        foreach (self::RISK as $r) {
            foreach ($this->matches($t, $r) as [$pos, $palabra]) {
                if ($primero === null || $pos < $primero[0]) {
                    $primero = [$pos, $r, $palabra];
                }
            }
        }

        return $primero === null ? null : [
            'term' => rtrim($primero[1], '*'),
            'found' => $primero[2],
            'snippet' => trim(mb_substr($t, max(0, $primero[0] - 25), mb_strlen($primero[2]) + 50)),
        ];
    }

    /** Lo que el motor usa para comparar: minúsculas, sin tildes, sin signos. */
    public function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $s = (string) preg_replace('/[^a-z0-9.,;:\s]/u', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    // ── Internas ────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $r */
    private function matchesRestriction(string $texto, array $r): bool
    {
        // Aquí «sin» no niega: «sin lactosa» ES la restricción. Por eso los
        // fuertes cuentan con solo aparecer, al revés que las señales clínicas.
        foreach ($r['strong'] as $f) {
            if ($this->occurrences($texto, $f) !== []) {
                return true;
            }
        }

        foreach ($r['cued'] as $f) {
            foreach ($this->matches($texto, $f) as [$pos, $palabra]) {
                $despues = ltrim(mb_substr($texto, $pos + mb_strlen($palabra), 12));
                foreach ($r['cued_not_followed_by'] ?? [] as $no) {
                    if (str_starts_with($despues, $no)) {
                        continue 2;
                    }
                }
                if ($this->cuedBefore($texto, $pos)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ¿Hay una pista («sin», «alergia», «no come»…) en la MISMA cláusula y
     * conectada al alimento solo por artículos, preposiciones, comas o
     * conjunciones, u otros alimentos de una enumeración? «Sin lactosa. Come
     * huevo» y «sin lactosa, come huevo» no restringen el huevo; «alergia al
     * maní y al huevo» sí.
     */
    private function cuedBefore(string $texto, int $pos): bool
    {
        $tokens = $this->clauseTokens($texto, $pos);
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $w = $tokens[$i];
            if (in_array($w, self::CUES, true)) {
                return true;
            }
            if (in_array($w, self::CUE_VERBS, true)) {
                return ($tokens[$i - 1] ?? null) === 'no';
            }
            if (in_array($w, self::CUE_GLUE, true) || $this->isTriggerWord($w)) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * El fragmento aparece y no va NEGADO. Solo niegan «no», «niega» y
     * «descarta» justo antes del término o al principio de una enumeración
     * de términos clínicos, y la negación acaba con la frase: «niega
     * hipertensión, diabetes y asma» → nada; «no controla su diabetes», «sin
     * control de la diabetes» o «sin diabetes» → señal (fallo cerrado).
     */
    private function affirmed(string $texto, string $fragmento): bool
    {
        foreach ($this->occurrences($texto, $fragmento) as $pos) {
            if (! $this->negatedBefore($texto, $pos)) {
                return true;
            }
        }

        return false;
    }

    private function negatedBefore(string $texto, int $pos): bool
    {
        $tokens = $this->clauseTokens($texto, $pos);
        for ($i = count($tokens) - 1; $i >= 0; $i--) {
            $w = $tokens[$i];
            if (in_array($w, self::NEGATIONS, true)) {
                return true;
            }
            if (in_array($w, self::NEGATION_GLUE, true) || ctype_digit($w) || $this->isClinicalWord($w)) {
                continue;
            }

            return false;
        }

        return false;
    }

    /**
     * Las palabras (y comas) de la cláusula antes de `$pos`: desde el último
     * «.», «;», «:» o salto de línea. Nada cruza una frase.
     *
     * @return list<string>
     */
    private function clauseTokens(string $texto, int $pos): array
    {
        $antes = mb_substr($texto, 0, $pos);
        $partes = preg_split('/[.;:\n]/u', $antes);
        $clausula = (string) end($partes);
        preg_match_all('/[a-z0-9]+|,/u', $clausula, $m);

        return $m[0];
    }

    private function isClinicalWord(string $w): bool
    {
        foreach (self::CLINICAL as [, $fragmentos]) {
            foreach ($fragmentos as $f) {
                foreach (explode(' ', $f) as $parte) {
                    $base = trim($parte, '*');
                    if ($base === '' || in_array($base, self::NEGATION_GLUE, true) || mb_strlen($base) < 3) {
                        continue;
                    }
                    $raiz = str_ends_with($parte, '*');
                    $infijo = str_starts_with($parte, '*');
                    if ($w === $base || $w === $base.'s' || $w === $base.'es'
                        || ($raiz && ! $infijo && str_starts_with($w, $base))
                        || ($infijo && str_contains($w, $base))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isTriggerWord(string $w): bool
    {
        foreach (self::RESTRICTIONS as $r) {
            foreach (array_merge($r['strong'], $r['cued']) as $f) {
                foreach (explode(' ', $f) as $parte) {
                    $base = rtrim($parte, '*');
                    if (mb_strlen($base) < 3 || in_array($base, self::CUE_GLUE, true)) {
                        continue;
                    }
                    if ($w === $base || $w === $base.'s' || $w === $base.'es' || (str_ends_with($parte, '*') && str_starts_with($w, $base))) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Dónde aparece el fragmento como PALABRA COMPLETA (o su plural en -s/-es)
     * y qué palabra es. Con `*` final es una raíz y casa con cualquier
     * terminación («diabet*» → diabetes, diabética), pero nunca a mitad de
     * otra palabra.
     *
     * @return list<array{0: int, 1: string}> [posición en caracteres, palabra hallada]
     */
    private function matches(string $texto, string $fragmento): array
    {
        $raiz = str_ends_with($fragmento, '*');
        $infijo = str_starts_with($fragmento, '*');
        $base = preg_quote(trim($fragmento, '*'), '/');
        $patron = match (true) {
            // «*tiroid*»: en cualquier parte de la palabra (hipotiroidismo).
            $infijo => '/(?<![a-z0-9])[a-z]*'.$base.'[a-z]*/u',
            $raiz => '/(?<![a-z0-9])'.$base.'[a-z]*/u',
            default => '/(?<![a-z0-9])'.$base.'(?:s|es)?(?![a-z0-9])/u',
        };

        $out = [];
        if (preg_match_all($patron, $texto, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$palabra, $byte]) {
                $out[] = [mb_strlen(substr($texto, 0, $byte)), $palabra];
            }
        }

        return $out;
    }

    /** @return list<int> */
    private function occurrences(string $texto, string $fragmento): array
    {
        return array_column($this->matches($texto, $fragmento), 0);
    }
}
