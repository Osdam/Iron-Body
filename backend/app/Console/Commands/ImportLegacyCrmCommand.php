<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Support\LegacyPlanMap;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Importa los DOS export nativos del sistema anterior sin depurarlos a mano.
 *
 *   • «Listado de clientes»  → una fila por persona (incluidas las que nunca
 *     compraron): es la fuente de los datos personales.
 *   • «Membresías Todas»     → una fila por MEMBRESÍA comprada (histórico
 *     completo, vencidas incluidas): es la fuente del plan y de los pagos.
 *
 * A diferencia de {@see ImportLegacyMembersCommand} —que consume un CSV ya
 * aplanado a una fila por socio y por tanto sólo puede traer el último pago—,
 * aquí entra el historial entero: cada membresía deja su propio `payments`.
 *
 * ── Qué escribe ────────────────────────────────────────────────────────────
 *   `users`    ficha CRM (documento, contacto, plan y vigencia real).
 *   `members`  ficha de la app enlazada por `user_id` (login documento + OTP).
 *   `payments` UN pago por membresía, referencia `MIGR-<id de membresía>`.
 *
 * ── Qué NO hace ────────────────────────────────────────────────────────────
 * No borra ni desactiva nada. Sobre una persona que ya existe sólo rellena los
 * huecos: un dato ya cargado en Iron Body gana siempre al del export, porque
 * puede haberse corregido después de exportar. La única excepción es el nombre,
 * que sí se refresca — recepción lo mantiene al día en el sistema viejo.
 *
 * La vigencia sólo se EXTIENDE, nunca se acorta: si alguien renovó ya dentro de
 * Iron Body, una membresía vieja del export no puede quitarle días.
 *
 *   php artisan iron:import-legacy-crm --clientes=... --membresias=... --dry-run
 *   php artisan iron:import-legacy-crm --clientes=... --membresias=...
 */
class ImportLegacyCrmCommand extends Command
{
    protected $signature = 'iron:import-legacy-crm
        {--clientes= : Export «Listado de clientes» (CSV delimitado por ;)}
        {--membresias= : Export «Membresías Todas» (CSV delimitado por ;)}
        {--dry-run : Simula todo en una transacción y hace rollback}
        {--limit=0 : Procesa sólo los primeros N clientes (0 = todos)}';

    protected $description = 'Importa clientes, membresías y pagos de los export nativos del sistema anterior.';

    /**
     * Documento basura del export: 49 fichas del sistema viejo llevan la palabra
     * «repetido» como identificación Y como nombre. No son personas, y como el
     * documento es la clave colapsarían todas en un mismo socio.
     */
    private const DOCUMENTO_BASURA = 'repetido';

    /** Marcadores de «vacío» del export. */
    private const VACIOS = ['', 'N/A', '-', 'NULL'];

    /**
     * Columnas del export que realmente se usan, con el nombre corto por el que
     * viajan dentro del comando.
     *
     * Los dos export traen 31 y 65 columnas. Quedarse con todas obligaba a
     * sostener 9.360 arreglos asociativos de 65 claves largas a la vez y agotaba
     * los 128 MB del intérprete antes de escribir la primera fila. Leer sólo
     * estas 12 deja el consumo en una fracción y hace explícito, además, de qué
     * depende de verdad la importación.
     */
    private const COLUMNAS_CLIENTE = [
        'doc' => 'Identificación del cliente',
        'nombre' => 'Nombre completo del cliente',
        'celular' => 'Celular del cliente',
        'email' => 'Correo electrónico del cliente',
        'direccion' => 'Dirección del cliente',
        'nacimiento' => 'Fecha de nacimiento del cliente',
        'observaciones' => 'Observaciones del cliente',
        'respaldo_nombre' => 'Nombre completo del contacto de respaldo',
        'respaldo_tel' => 'Celular del contacto de respaldo',
    ];

    private const COLUMNAS_MEMBRESIA = [
        'doc' => 'Identificación del cliente',
        'id' => 'ID de la membrecía',
        'plan' => 'Nombre del plan',
        'comprada' => 'Fecha y hora de compra de la membrecía',
        'inicio' => 'Fecha de inicio de la membrecía',
        'fin' => 'Fecha de finalización de la membrecía',
        'fin_prorroga' => 'Fecha de finalización de la membrecía + Prorroga',
        'anulada' => 'La membrecía está anulada?',
        'pagada' => 'La membrecía está totalmente paga?',
        'total_pagado' => 'Total pagado',
        'valor_final' => 'Valor con descuento de la membrecía',
        'pagos' => 'Pagos realizados',
    ];

    /** @var array<string,int> nombre de plan → id */
    private array $planIds = [];

    /** @var array<string,true> referencias `MIGR-*` ya existentes */
    private array $pagosExistentes = [];

    /** @var array<string,User> documento → ficha CRM ya existente */
    private array $usuarios = [];

    /** @var array<string,Member> documento → ficha de app ya existente */
    private array $socios = [];

    /** @var array<string,int> correo en minúsculas → id del `users` que lo ocupa */
    private array $correosOcupados = [];

    /** @var array<string,string> planes del export que no están en `plans` */
    private array $planesFaltantes = [];

    public function handle(): int
    {
        $rutaClientes = (string) $this->option('clientes');
        $rutaMembresias = (string) $this->option('membresias');

        foreach (['--clientes' => $rutaClientes, '--membresias' => $rutaMembresias] as $flag => $ruta) {
            if ($ruta === '' || ! is_file($ruta)) {
                $this->error("Falta o no existe {$flag}: ".($ruta !== '' ? $ruta : '(vacío)'));

                return self::FAILURE;
            }
        }

        try {
            $clientes = $this->leerCsv($rutaClientes, self::COLUMNAS_CLIENTE);
            $membresias = $this->leerCsv($rutaMembresias, self::COLUMNAS_MEMBRESIA);
        } catch (RuntimeException $e) {
            // Un export con la cabecera cambiada se atiende leyendo el mensaje,
            // no una traza: quien corre esto está en una consola del servidor.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($clientes === []) {
            $this->error('El export de clientes no tiene filas.');

            return self::FAILURE;
        }

        $this->info('Clientes leídos: '.count($clientes).' · Membresías leídas: '.count($membresias));

        // Membresías agrupadas por documento: cada socio se resuelve de una vez
        // (plan vigente + todos sus pagos) sin volver a recorrer el archivo.
        $porDocumento = [];
        $huerfanas = 0;
        foreach ($membresias as $fila) {
            $doc = $this->documento($fila['doc'] ?? null);
            if ($doc === null) {
                $huerfanas++;

                continue;
            }
            $porDocumento[$doc][] = $fila;
        }
        unset($membresias);

        $limite = (int) $this->option('limit');
        if ($limite > 0) {
            $clientes = array_slice($clientes, 0, $limite);
        }

        $this->planIds = Plan::query()->pluck('id', 'name')->all();
        $this->precargarPagos();
        // Sólo los documentos del export de clientes: es el único recorrido que
        // crea o toca fichas. Las membresías se cuelgan del socio ya resuelto.
        $this->precargarSocios($clientes);

        $c = [
            'users_nuevos' => 0, 'users_actualizados' => 0,
            'members_nuevos' => 0, 'members_actualizados' => 0,
            'pagos_nuevos' => 0, 'pagos_ya_estaban' => 0, 'pagos_anulados' => 0,
            'sin_membresia' => 0, 'sin_telefono' => 0,
            'doc_basura' => 0, 'omitidos' => 0, 'errores' => 0,
        ];
        if ($huerfanas > 0) {
            $this->warn("{$huerfanas} membresías sin documento de cliente: se ignoran.");
        }

        $seco = (bool) $this->option('dry-run');
        $barra = $this->output->createProgressBar(count($clientes));
        $barra->start();

        DB::beginTransaction();
        try {
            foreach ($clientes as $i => $fila) {
                $linea = $i + 2; // +1 cabecera, +1 base-0
                try {
                    // Cada socio va en su propio punto de guardado. Sin esto, un
                    // choque de clave en una sola fila dejaría la transacción
                    // entera abortada en PostgreSQL —donde todo lo que viniera
                    // después fallaría en cascada— y la importación se perdería
                    // completa por un dato suelto del sistema anterior.
                    DB::transaction(function () use ($fila, $porDocumento, &$c): void {
                        $doc = $this->documento($fila['doc'] ?? null);
                        $this->importarSocio($fila, $doc !== null ? ($porDocumento[$doc] ?? []) : [], $c);
                    });
                } catch (Throwable $e) {
                    $c['errores']++;
                    $this->newLine();
                    $this->warn("Fila {$linea}: {$e->getMessage()}");
                }
                $barra->advance();
            }
            $barra->finish();
            $this->newLine(2);

            if ($seco) {
                DB::rollBack();
                $this->warn('DRY-RUN: todo revertido, no se escribió nada en la base de datos.');
            } else {
                DB::commit();
                $this->info('Importación confirmada.');
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Abortado (rollback total): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->resumen($c);

        return self::SUCCESS;
    }

    /** Crea o completa el socio y vuelca todas sus membresías como pagos. */
    private function importarSocio(array $fila, array $membresias, array &$c): void
    {
        $nombre = trim((string) ($fila['nombre'] ?? ''));
        $docCrudo = trim((string) ($fila['doc'] ?? ''));

        if (Str::lower($docCrudo) === self::DOCUMENTO_BASURA || Str::lower($nombre) === self::DOCUMENTO_BASURA) {
            $c['doc_basura']++;

            return;
        }

        $doc = $this->documento($docCrudo);
        if ($doc === null || $nombre === '') {
            $c['omitidos']++;

            return;
        }

        $telefono = $this->telefono($fila['celular'] ?? null);
        if ($telefono === null) {
            $c['sin_telefono']++;
        }
        $email = $this->email($fila['email'] ?? null);
        $nacimiento = $this->fecha($fila['nacimiento'] ?? null)?->toDateString();

        // Vigencia: la membresía que termina MÁS TARDE manda.
        $vigente = $this->membresiaDominante($membresias);
        if ($membresias === []) {
            $c['sin_membresia']++;
        }

        // ── users ────────────────────────────────────────────────────────────
        $user = $this->usuarios[$doc] ?? null;
        $esNuevo = $user === null;
        if ($esNuevo) {
            $user = new User;
            // El acceso es por OTP, no por contraseña; el cast `hashed` la cifra.
            $user->password = Str::random(40);
        }

        $user->name = $nombre;
        $user->document = $doc;
        $user->email = $this->emailLibre($email, $doc, $user->id);
        $this->completarSiVacio($user, 'phone', $telefono);
        $this->completarSiVacio($user, 'birth_date', $nacimiento);
        $this->completarSiVacio($user, 'address', $this->texto($fila['direccion'] ?? null));
        $this->completarSiVacio($user, 'emergency_contact', $this->contactoRespaldo($fila));
        $this->completarSiVacio($user, 'notes', $this->texto($fila['observaciones'] ?? null));

        if ($vigente !== null) {
            $this->aplicarVigencia($user, $vigente);
        }
        $user->status = $user->status !== null && $user->status !== '' ? $user->status : 'active';
        $user->save();
        $this->usuarios[$doc] = $user;
        $esNuevo ? $c['users_nuevos']++ : $c['users_actualizados']++;

        // ── members ──────────────────────────────────────────────────────────
        $member = $this->socios[$doc] ?? null;
        $memberEsNuevo = $member === null;
        if ($memberEsNuevo) {
            $member = new Member; // member_uuid / access_hash se generan solos
        }
        $member->user_id = $user->id;
        $member->full_name = $nombre;
        $member->document_number = $doc;
        $this->completarSiVacio($member, 'email', $email);
        $this->completarSiVacio($member, 'phone', $telefono);
        $this->completarSiVacio($member, 'birth_date', $nacimiento);

        // Una cuenta borrada o suspendida en Iron Body NO revive por aparecer en
        // un export: reactivarla se saltaría la decisión que la cerró.
        if (! in_array($member->status, [Member::STATUS_DELETED, Member::STATUS_SUSPENDED], true)) {
            $member->status = Member::STATUS_ACTIVE;
        }
        // El rostro no se migra: el sistema viejo usaba huella. Se captura en la
        // app o el torniquete. No se toca a quien ya lo registró aquí.
        if ($member->biometric_status !== Member::BIOMETRIC_REGISTERED) {
            $member->biometric_status = Member::BIOMETRIC_PENDING;
        }
        $member->save();
        $this->socios[$doc] = $member;
        $memberEsNuevo ? $c['members_nuevos']++ : $c['members_actualizados']++;

        // ── payments: el historial COMPLETO, una fila por membresía ──────────
        foreach ($membresias as $membresia) {
            $this->importarPago($membresia, $user, $member, $c);
        }
    }

    /**
     * Pago histórico de una membresía. Idempotente por `MIGR-<id membresía>`:
     * el id lo asigna el sistema anterior y es único en los 9.360 registros, así
     * que re-ejecutar la importación no puede duplicar ingresos.
     */
    private function importarPago(array $m, User $user, Member $member, array &$c): void
    {
        $idMembresia = trim((string) ($m['id'] ?? ''));
        if ($idMembresia === '') {
            return;
        }

        $referencia = 'MIGR-'.$idMembresia;
        if (isset($this->pagosExistentes[$referencia])) {
            $c['pagos_ya_estaban']++;

            return;
        }

        $anulada = $this->esSi($m['anulada'] ?? null);
        $totalPagado = $this->dinero($m['total_pagado'] ?? null);
        $valorFinal = $this->dinero($m['valor_final'] ?? null);
        $pagada = $this->esSi($m['pagada'] ?? null);

        // Una membresía anulada se conserva como traza, no como ingreso: entra
        // con `cancelled` para que no sume en ninguna cuenta de caja.
        // Sin anular, el importe es el dinero REALMENTE recibido; si no se
        // recibió nada, queda `pending` con lo que se esperaba cobrar.
        if ($anulada) {
            $estado = 'cancelled';
            $importe = $totalPagado;
        } elseif ($pagada || $totalPagado > 0) {
            $estado = 'paid';
            $importe = $totalPagado;
        } else {
            $estado = 'pending';
            $importe = $valorFinal;
        }

        $pago = new Payment;
        $pago->user_id = $user->id;
        $pago->member_id = $member->id;
        $pago->plan_id = $this->planId($m['plan'] ?? null);
        $pago->amount = $importe;
        $pago->method = $this->metodoPago($m['pagos'] ?? null);
        $pago->reference = $referencia;
        $pago->status = $estado;
        $pago->paid_at = $estado === 'pending'
            ? null
            : ($this->fecha($m['comprada'] ?? null)
                ?? $this->fecha($m['inicio'] ?? null)
                ?? now());
        $pago->save();

        $this->pagosExistentes[$referencia] = true;
        $c['pagos_nuevos']++;
        if ($anulada) {
            $c['pagos_anulados']++;
        }
    }

    /**
     * Membresía que define el plan y la vigencia: la que termina más tarde,
     * descartando las anuladas. El export lista el historial completo y no viene
     * ordenado, así que quedarse con la última fila leída daría un plan al azar.
     */
    private function membresiaDominante(array $membresias): ?array
    {
        $mejor = null;
        $mejorFin = null;

        foreach ($membresias as $m) {
            if ($this->esSi($m['anulada'] ?? null)) {
                continue;
            }
            $fin = $this->finDe($m);
            if ($fin === null) {
                continue;
            }
            if ($mejorFin === null || $fin->gt($mejorFin)) {
                $mejorFin = $fin;
                $mejor = $m;
            }
        }

        return $mejor;
    }

    /** Fin real de la membresía: la prórroga cuenta como vigencia comprada. */
    private function finDe(array $m): ?Carbon
    {
        return $this->fecha($m['fin_prorroga'] ?? null)
            ?? $this->fecha($m['fin'] ?? null);
    }

    /**
     * Copia plan y vigencia al socio SÓLO si el export le da más días de los que
     * ya tiene. Alguien que renovó dentro de Iron Body después de la exportación
     * no puede perder esa renovación por reimportar.
     */
    private function aplicarVigencia(User $user, array $m): void
    {
        $fin = $this->finDe($m);
        if ($fin === null) {
            return;
        }

        $finActual = $user->membership_end_date ? Carbon::parse($user->membership_end_date) : null;
        if ($finActual !== null && $finActual->gte($fin)) {
            return;
        }

        $plan = LegacyPlanMap::resolve($m['plan'] ?? null);
        if ($plan !== null) {
            $user->plan = $plan;
            if (! isset($this->planIds[$plan])) {
                $this->planesFaltantes[$plan] = $plan;
            }
        }
        $user->membership_start_date = $this->fecha($m['inicio'] ?? null)?->toDateString();
        $user->membership_end_date = $fin->toDateString();
    }

    /** Id de catálogo del plan del export (null si ese plan no existe en `plans`). */
    private function planId(?string $legacy): ?int
    {
        $nombre = LegacyPlanMap::resolve($legacy);
        if ($nombre === null) {
            return null;
        }
        if (! isset($this->planIds[$nombre])) {
            $this->planesFaltantes[$nombre] = $nombre;

            return null;
        }

        return $this->planIds[$nombre];
    }

    /**
     * Método de cobro normalizado. El export lo escribe dentro del texto de los
     * pagos: «$ 80.000 (Efectivo)». Una membresía saldada en varios pagos con
     * medios distintos queda como `manual`: no hay un método único que sea cierto.
     */
    private function metodoPago(?string $texto): string
    {
        preg_match_all('/\(([^)]+)\)/u', (string) $texto, $coincidencias);
        $medios = array_unique(array_map('trim', $coincidencias[1] ?? []));

        if (count($medios) !== 1) {
            return 'manual';
        }

        return match (Str::lower((string) reset($medios))) {
            'efectivo' => 'efectivo',
            'transferencia' => 'transferencia',
            'pago por datáfono o tarjeta', 'pago por datafono o tarjeta' => 'datafono',
            default => 'manual',
        };
    }

    /** Rellena un atributo sólo si está vacío: lo ya cargado en Iron Body manda. */
    private function completarSiVacio(object $modelo, string $campo, mixed $valor): void
    {
        if ($valor !== null && $valor !== '' && blank($modelo->{$campo})) {
            $modelo->{$campo} = $valor;
        }
    }

    /** Contacto de emergencia legible a partir de las dos columnas del export. */
    private function contactoRespaldo(array $fila): ?string
    {
        $nombre = $this->texto($fila['respaldo_nombre'] ?? null);
        $tel = $this->texto($fila['respaldo_tel'] ?? null);

        if ($nombre !== null && $tel !== null) {
            return "{$nombre} - {$tel}";
        }

        return $nombre ?? $tel;
    }

    /**
     * Documento normalizado EXACTAMENTE como lo deja el login, o el texto queda
     * inservible como clave. Se quita antes cualquier carácter que no sea
     * alfanumérico —el export trae basura tipo `1075270160|`— pero se conservan
     * las letras: un PPT venezolano (`5125938ppt`) es un documento válido.
     */
    private function documento(?string $valor): ?string
    {
        $limpio = preg_replace('/[^\p{L}\p{N}]+/u', '', trim((string) $valor)) ?? '';

        return Member::normalizeDocumentNumber($limpio);
    }

    /** Celular colombiano de 10 dígitos; cualquier otra cosa no sirve para el OTP. */
    private function telefono(?string $valor): ?string
    {
        $tel = Member::normalizePhone($valor);

        return ($tel !== null && preg_match('/^3\d{9}$/', $tel) === 1) ? $tel : null;
    }

    private function email(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return ($valor !== '' && filter_var($valor, FILTER_VALIDATE_EMAIL)) ? Str::lower($valor) : null;
    }

    /**
     * `users.email` es único y el export repite correos —familiares que
     * comparten cuenta, sobre todo—, así que el segundo en llegar necesita uno
     * técnico. No es un correo de contacto: es la clave de acceso de la ficha,
     * y el contacto real sigue guardado en `members.email`.
     *
     * Se resuelve contra el mapa precargado y no contra la base, porque durante
     * la importación la reserva tiene que valer también para los correos que
     * acaba de tomar un cliente anterior de este mismo archivo.
     */
    private function emailLibre(?string $email, string $doc, ?int $userId): string
    {
        $ocupado = function (string $candidato) use ($userId): bool {
            $dueno = $this->correosOcupados[Str::lower($candidato)] ?? null;

            return $dueno !== null && $dueno !== $userId;
        };

        $elegido = null;
        if ($email !== null && ! $ocupado($email)) {
            $elegido = $email;
        } else {
            $tecnico = "socio-{$doc}@ironbody.local";
            $elegido = $ocupado($tecnico)
                ? "socio-{$doc}-".Str::lower(Str::random(4)).'@ironbody.local'
                : $tecnico;
        }

        // Queda reservado en el acto. El id definitivo de un socio nuevo aún no
        // existe; basta con que el mapa lo dé por tomado para que el siguiente
        // cliente del archivo no vuelva a elegirlo.
        $this->correosOcupados[Str::lower($elegido)] = $userId ?? 0;

        return $elegido;
    }

    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return in_array(Str::upper($valor), self::VACIOS, true) ? null : $valor;
    }

    private function esSi(?string $valor): bool
    {
        return Str::lower(trim((string) $valor)) === 'si';
    }

    /** «$ 80.000» / «80000» → 80000.0 (el export usa el punto como millar). */
    private function dinero(?string $valor): float
    {
        return (float) preg_replace('/[^\d]/', '', (string) $valor);
    }

    private function fecha(?string $valor): ?Carbon
    {
        if ($this->texto($valor) === null) {
            return null;
        }
        try {
            return Carbon::parse(trim((string) $valor));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Trae en bloque las fichas que ya existen para los documentos del export,
     * más los correos ocupados.
     *
     * Sin esto cada cliente costaba cuatro consultas sueltas —buscar el user,
     * buscar el member y comprobar dos veces el correo—, unas 15.000 en total.
     * No es sólo lentitud: todo eso ocurre dentro de la transacción que sostiene
     * la importación, y alargarla es tener la base bloqueada más rato mientras
     * el gimnasio sigue cobrando en el mostrador.
     *
     * @param  array<int,array<string,string>>  $clientes
     */
    private function precargarSocios(array $clientes): void
    {
        $documentos = [];
        foreach ($clientes as $fila) {
            $doc = $this->documento($fila['doc'] ?? null);
            if ($doc !== null) {
                $documentos[$doc] = true;
            }
        }
        $documentos = array_keys($documentos);

        // En trozos: un `IN` con miles de valores es un plan de consulta peor
        // que varios cortos, y algunos motores tienen tope de parámetros.
        foreach (array_chunk($documentos, 1000) as $trozo) {
            foreach (User::query()->whereIn('document', $trozo)->get() as $user) {
                $this->usuarios[(string) $user->document] = $user;
            }
            foreach (Member::query()->whereIn('document_number', $trozo)->get() as $member) {
                $this->socios[(string) $member->document_number] = $member;
            }
        }

        // Los correos se comprueban contra TODA la tabla, no sólo contra los
        // documentos del export: el choque puede venir de alguien que se
        // registró por la app y no aparece en el sistema anterior.
        $this->correosOcupados = User::query()
            ->whereNotNull('email')
            ->pluck('id', 'email')
            ->mapWithKeys(fn ($id, $email) => [Str::lower((string) $email) => (int) $id])
            ->all();
    }

    /**
     * Trae de una sola consulta las referencias `MIGR-*` que ya existen. Sin
     * esto habría una consulta por membresía (9.360) sólo para preguntar si el
     * pago lo había cargado ya una importación anterior.
     */
    private function precargarPagos(): void
    {
        $this->pagosExistentes = Payment::query()
            ->where('reference', 'like', 'MIGR-%')
            ->pluck('reference')
            ->flip()
            ->map(fn () => true)
            ->all();
    }

    /**
     * Lee un CSV `;` en UTF-8 (con o sin BOM) quedándose SÓLO con las columnas
     * pedidas, ya renombradas a su clave corta.
     *
     * @param  array<string,string>  $columnas  clave corta → nombre en el export
     * @return array<int,array<string,string>>
     *
     * @throws RuntimeException si al export le falta una columna esperada: es
     *                          preferible parar a importar en silencio a media gente con medio dato.
     */
    private function leerCsv(string $ruta, array $columnas): array
    {
        $fh = fopen($ruta, 'r');
        if ($fh === false) {
            return [];
        }

        $filas = [];
        $posiciones = null;
        while (($cols = fgetcsv($fh, 0, ';')) !== false) {
            if ($cols === [null]) {
                continue;
            }

            if ($posiciones === null) {
                // El BOM va pegado a la primera comilla y rompe el entrecomillado
                // de la primera columna si no se quita antes de comparar.
                $cols[0] = trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $cols[0]), '"');
                $cabecera = array_flip(array_map(fn ($h) => trim((string) $h), $cols));

                $posiciones = [];
                $faltan = [];
                foreach ($columnas as $corta => $original) {
                    if (! isset($cabecera[$original])) {
                        $faltan[] = $original;

                        continue;
                    }
                    $posiciones[$corta] = $cabecera[$original];
                }
                if ($faltan !== []) {
                    fclose($fh);
                    throw new RuntimeException(
                        'Al export '.basename($ruta).' le faltan columnas: '.implode(' · ', $faltan)
                    );
                }

                continue;
            }

            $fila = [];
            foreach ($posiciones as $corta => $j) {
                $fila[$corta] = $cols[$j] ?? '';
            }
            $filas[] = $fila;
        }
        fclose($fh);

        return $filas;
    }

    private function resumen(array $c): void
    {
        $this->table(
            ['Métrica', 'Total'],
            collect($c)->map(fn ($v, $k) => [$k, $v])->values()->all(),
        );

        if ($this->planesFaltantes !== []) {
            $this->warn('Planes del export que NO existen en `plans`: '.implode(', ', $this->planesFaltantes));
            $this->warn('→ El socio entra con los módulos bloqueados. Corre PlansSeeder + LegacyPlansSeeder y repite (es idempotente).');
        }
        if ($c['doc_basura'] > 0) {
            $this->warn("{$c['doc_basura']} fichas con documento «repetido» omitidas: no son personas, comparten clave y se pisarían entre sí.");
        }
        if ($c['sin_telefono'] > 0) {
            $this->warn("{$c['sin_telefono']} socios sin celular válido → no reciben OTP hasta corregirlo en el CRM.");
        }
    }
}
