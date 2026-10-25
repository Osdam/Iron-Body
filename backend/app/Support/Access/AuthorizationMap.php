<?php

namespace App\Support\Access;

use App\Http\Middleware\ProtectAdminPaths;
use Illuminate\Routing\Route;

/**
 * Mapa CENTRAL de autorización: qué permiso exige cada ruta administrativa.
 *
 * Por qué existe. La cobertura anterior se anotaba ruta por ruta con
 * `->middleware('admin.can:…')`, y eso produce lo que produjo: 43 rutas
 * protegidas de 341. Se anota donde uno se acuerda y se olvida en la
 * siguiente, y una ruta nueva nace desprotegida sin que nada avise.
 *
 * Aquí la relación se declara UNA vez, por CONTROLADOR —que es la unidad
 * semántica real— con excepciones explícitas por ruta cuando un controlador
 * mezcla cosas distintas. Cabe en una pantalla, se revisa de un vistazo, y
 * `AuthorizationCoverageTest` falla si aparece una ruta que este mapa no sabe
 * resolver. La cobertura deja de depender de la memoria de nadie.
 *
 * FALLA CERRADO. Lo que no está mapeado se deniega. Esa es la diferencia entre
 * un sistema de permisos y una colección de recordatorios.
 */
final class AuthorizationMap
{
    /**
     * Ruta accesible para cualquiera, sin sesión. Solo la puerta de entrada.
     */
    public const PUBLIC = '@public';

    /**
     * Ruta que cualquier administrador AUTENTICADO puede usar sobre sí mismo o
     * sobre el funcionamiento básico del panel: quién soy, cerrar sesión, mis
     * notificaciones, el vale para abrir un stream.
     *
     * No es un agujero: exigen sesión válida y no exponen datos de negocio de
     * otros. Pedirles un permiso obligaría a concedérselo a todo el mundo, que
     * es la forma de vaciar de sentido un catálogo.
     */
    public const SELF = '@self';

    /**
     * Ruta cuyo permiso DEPENDE DE LOS DATOS, no de la URL: lo resuelve el
     * controlador en cuanto sabe sobre qué está operando.
     *
     * No es una excepción a la autorización: es autorización que este mapa no
     * puede expresar. El caso concreto son los turnos de caja. `cash.products`
     * y `cash.gym` son permisos SEPARADOS, y la ruta `shifts/{shift}` no sabe
     * de qué caja es el turno hasta cargarlo. Poner aquí un permiso fijo
     * obligaba a elegir uno de los dos, y eso es justo lo que estaba mal:
     * exigir `cash.products.view` para todo el módulo bloqueaba a quien solo
     * tuviera `cash.gym.view` antes de que nadie mirase el tipo del turno.
     *
     * Condiciones para usarlo, y son las tres a la vez:
     *
     *  1. El middleware exige sesión de administrador igual que en cualquier
     *     otra ruta: sin ella no se pasa. Esto NO abre nada.
     *  2. El controlador DEBE denegar explícitamente, y por defecto: un camino
     *     que no compruebe nada es un agujero, no una ruta permisiva.
     *  3. La ruta va en la lista blanca de {@see AuthorizationMap::CONTROLLER_RESOLVED},
     *     que una prueba compara con lo que el mapa resuelve de verdad. Añadir
     *     una cuarta ruta a este centinela sin declararla rompe CI.
     *
     * Los permisos que se comprueban así se declaran en CONTROLLER_ENFORCED
     * para que el catálogo pueda ofrecerlos.
     */
    public const CONTROLLER = '@controller';

    /**
     * Las ÚNICAS rutas que pueden resolver a {@see AuthorizationMap::CONTROLLER}.
     *
     * Es una lista blanca, no documentación: `AuthorizationCoverageTest` la
     * compara con lo que el mapa resuelve realmente, así que el centinela no
     * puede extenderse a una ruta nueva sin que alguien lo escriba aquí y
     * justifique por qué su permiso depende de los datos.
     *
     * @var array<string>
     */
    public const CONTROLLER_RESOLVED = [
        'GET api/admin/caja/shift',
        'POST api/admin/caja/shift/open',
        'POST api/admin/caja/shift/close',
        'GET api/admin/caja/shifts',
        'GET api/admin/caja/shifts/{shift}',
        'GET api/admin/caja/shifts/{shift}/pdf',
        'POST api/admin/caja/shifts/{shift}/difference',
    ];

    /**
     * La puerta de los RECIBOS TÉRMICOS: cualquier permiso de cualquiera de las
     * dos cajas.
     *
     * Es una lista y no un `@controller` porque aquí sí hay algo que exigir en la
     * puerta —quien no tiene ninguna caja no tiene nada que imprimir— y una lista
     * no amplía la lista blanca del centinela.
     *
     * ReceiptController NO está en CONTROLLERS a propósito: allí sus lecturas
     * resolverían a un `*.view` de dominio propio, que el token compartido
     * obtiene por ser lectura. Lo que sigue lo hace el controlador: exige una
     * PERSONA (el token llega hasta aquí con `cash.*.view` y allí se le cierra) y
     * el permiso de LA CAJA del recibo o del turno concreto.
     *
     * @var list<string>
     */
    public const RECEIPT_CASH_PERMISSIONS = [
        'cash.gym.view',
        'cash.gym.operate',
        'cash.gym.manage',
        'cash.products.view',
        'cash.products.operate',
        'cash.products.manage',
    ];

    /**
     * AGENDA DE VALORACIONES: leer citas. `view` acota a las del propio
     * entrenador vinculado y `view_all` las abre todas; basta CUALQUIERA para
     * entrar, y el alcance lo aplica el controlador (ValuationAccess).
     *
     * Los tres controladores de la agenda NO están en CONTROLLERS a propósito:
     * allí sus lecturas resolverían a `valuations.view` por el verbo, y una ruta
     * nueva quedaría clasificada sin que nadie lo decidiera. Aquí cada ruta está
     * escrita una a una en OVERRIDES; la que falte se deniega.
     *
     * @var list<string>
     */
    public const VALUATIONS_READ = ['valuations.view', 'valuations.view_all'];

    /**
     * Cualquier llave de la agenda: para `capabilities`, que solo dice qué puede
     * hacer la sesión y lo necesita también quien solo gestiona disponibilidad
     * o ajustes.
     *
     * @var list<string>
     */
    public const VALUATIONS_ANY = [
        'valuations.view',
        'valuations.view_all',
        'valuations.create',
        'valuations.update',
        'valuations.cancel',
        'valuations.complete',
        'valuations.manage_availability',
        'valuations.manage_settings',
    ];

    /**
     * Leer la disponibilidad o los ajustes: quien los gestiona, o quien ve la
     * agenda (el controlador limita la disponibilidad al propio entrenador y los
     * ajustes a solo lectura).
     *
     * @var list<string>
     */
    public const VALUATIONS_AVAILABILITY_READ = ['valuations.manage_availability', 'valuations.view', 'valuations.view_all'];

    /** @var list<string> */
    public const VALUATIONS_SETTINGS_READ = ['valuations.manage_settings', 'valuations.view', 'valuations.view_all'];

    /**
     * Permiso por CONTROLADOR. Es el caso normal: un controlador administra un
     * dominio y todas sus acciones exigen lo mismo, con la distinción de
     * lectura/escritura resuelta en {@see resolve()} según el verbo HTTP.
     *
     * Formato: 'ControllerShortName' => 'dominio' (se le añade .view o el
     * permiso de escritura declarado en WRITE_PERMISSION).
     *
     * @var array<string, string>
     */
    private const CONTROLLERS = [
        // ── Miembros y su expediente ────────────────────────────────────────
        'UserController' => 'members',
        'MemberStaffController' => 'members',
        'MemberRegistrationController' => 'members',
        'MemberIdentityReviewController' => 'members',
        'MembershipController' => 'members',
        'ContractAdminController' => 'members',
        'MemberTrainerController' => 'members',
        'PhysicalEvaluationAdminController' => 'members',
        'NutritionAdminController' => 'members',
        'FiscalProfileController' => 'members',
        'MemberRiskController' => 'members',

        // ── Planes ──────────────────────────────────────────────────────────
        'PlanController' => 'plans',

        // ── Pagos y suscripciones ───────────────────────────────────────────
        'PaymentController' => 'payments',
        'AdminSubscriptionController' => 'payments',

        // ── Caja ────────────────────────────────────────────────────────────
        // El punto de venta de productos. Los turnos de AMBAS cajas viven en
        // CashShiftController y se resuelven por ruta, porque el tipo va en el
        // cuerpo o en la query: ver OVERRIDES.
        'CajaController' => 'cash.products',

        // Cuentas por cobrar. Dominio propio y no `cash.products`, porque una
        // cuenta puede ser de cualquiera de las dos cajas y consultarlas no es
        // lo mismo que operar un turno.
        'ReceivableController' => 'receivables',

        // ── Comisiones (piso de entrenadores) ───────────────────────────────
        'TrainerCommissionController' => 'commissions',

        // ── Inventario ──────────────────────────────────────────────────────
        'ProductController' => 'inventory',
        'InventoryController' => 'inventory',

        // ── Facturación electrónica ─────────────────────────────────────────
        'ElectronicInvoiceController' => 'billing',
        'ManualInvoiceBundleController' => 'billing',
        'BillingTaxController' => 'billing',
        'BillingQuoteController' => 'billing',

        // ── Clases, asistencia y torniquete ─────────────────────────────────
        'ClassController' => 'classes',
        'ClassSupervisionController' => 'classes',
        'ClassEnrollmentController' => 'classes',
        'AttendanceController' => 'classes',
        'TurnstileController' => 'classes',

        // ── Rutinas y ejercicios ────────────────────────────────────────────
        'RoutineController' => 'routines',
        'MemberRoutineController' => 'routines',
        'ExerciseController' => 'routines',

        // ── Entrenadores ────────────────────────────────────────────────────
        'TrainerController' => 'trainers',
        'TrainerAdminController' => 'trainers',
        'TrainerTaskController' => 'trainers',

        // ── Personal, jornadas, biometría y dispositivos ────────────────────
        // Dominios propios y no `trainers` ni `classes`: ver la asistencia
        // laboral de la gente no es ver clases (que tiene recepción), y
        // revocar un dato biométrico o un dispositivo no es editar una ficha.
        'WorkforceSupervisionController' => 'workforce',
        'WorkforceStaffController' => 'workforce',
        // Vincular la ficha de socio: `workforce.manage` aquí; `members.edit`
        // además, dentro del controlador (se escribe la ficha de un socio).
        'WorkforceMemberLinkController' => 'workforce',
        'BiometricAdminController' => 'biometrics',
        'DeviceAdminController' => 'devices',

        // ── Mercadeo ────────────────────────────────────────────────────────
        'MarketingController' => 'marketing',
        'MarketingInboxController' => 'marketing',
        'MarketingAgentActionController' => 'marketing',
        'MarketingAgentController' => 'marketing',
        'MarketingAppointmentController' => 'marketing',
        'MarketingAnalyticsController' => 'marketing',
        // El panel «Mercadeo digital (Meta)»: lo mismo que /overview para leer
        // (marketing.view); pedir la sincronización, marketing.manage.
        'MetaDashboardController' => 'marketing',
        'MarketingAttachmentController' => 'marketing',
        // Aceptar o descartar el reclamo de un pago huérfano del CRM. Mismo
        // dominio que el Inbox a propósito: es el cierre de una revisión del
        // Inbox, no un módulo aparte. La capacidad fina (CAP_RESOLVE_REVIEW)
        // la comprueba además el propio controlador.
        'MarketingPaymentClaimController' => 'marketing',
        'SupervisionController' => 'marketing',

        // ── Soporte ─────────────────────────────────────────────────────────
        'SupportController' => 'support',
        'SecuritySupportController' => 'support',

        // ── Moderación de comunidad ─────────────────────────────────────────
        // Conserva además su propia comprobación interna (ModerationPermission),
        // que es defensa en profundidad y NO contradice a esta: aquella es más
        // fina, esta es la puerta.
        'ModerationController' => 'moderation',
        'ModerationRealtimeController' => 'moderation',
        'StoriesController' => 'moderation',
        'LiveController' => 'moderation',

        // ── Nutrición (catálogo de alimentos) ───────────────────────────────
        'NutritionFoodAdminController' => 'nutrition',

        // ── Contenido de la app: eventos, anuncios, rachas, push ────────────
        'EventController' => 'content',
        'AdController' => 'content',
        'WeeklyStreakAdminController' => 'content',
        'NotificationAdminController' => 'content',

        // ── Equipos del gimnasio ────────────────────────────────────────────
        'GymEquipmentController' => 'equipment',

        // ── Informes y dinero ───────────────────────────────────────────────
        'ReportsOverviewController' => 'reports',
        'EarningsController' => 'earnings',

        // ── Auditoría ───────────────────────────────────────────────────────
        'AuditLogController' => 'audit',

        // ── Integraciones ───────────────────────────────────────────────────
        'WhatsappIntegrationController' => 'integrations',

        // ── Seguridad de la plataforma ──────────────────────────────────────
        'IronGuardController' => 'security',

        // ── Identidad: usuarios administrativos y roles ─────────────────────
        'AdminUserController' => 'users',
        'AdminRoleController' => 'roles',
        'RolePermissionController' => 'roles',

        // ── Asistente IA del CRM ────────────────────────────────────────────
        'IronCrmAiController' => 'ai',
    ];

    /**
     * Excepciones y casos por RUTA, cuando el controlador no basta.
     *
     * Se consulta ANTES que el mapa de controladores. La clave es
     * "MÉTODO uri" tal y como los expone el router.
     *
     * @var array<string, string>
     */
    private const OVERRIDES = [
        /*
         * Aceptar un reclamo de pago CREA un cobro y encola una factura
         * electrónica ante la DIAN. Vive en el Inbox porque es el cierre de una
         * revisión del Inbox, pero el dominio de mercadeo no es una llave de
         * dinero: una revisión de seguridad demostró que un rol con solo
         * `marketing.manage` acuñaba membresía y factura. Se exige la llave de
         * quien cobra, y la capacidad del Inbox la sigue comprobando el propio
         * controlador: dos puertas, no una.
         *
         * DESCARTAR el reclamo no mueve dinero ni emite nada, así que se queda
         * con el permiso del Inbox: pedir la llave de caja para decir «este pago
         * no es de esta persona» dejaría la alerta sin quien la cierre.
         */
        'POST api/admin/marketing/inbox/payment-claims/{transaction}/accept' => 'payments.create',

        /*
         * Pausar o reactivar el agente IA es una decisión sobre TODO el negocio,
         * no una tarea del Inbox: `marketing.manage` lo tiene cualquiera que
         * conteste, y no basta. Leer el estado y escucharlo sí van con
         * `marketing.view`, como el resto de Mercadeo.
         */
        'PUT api/admin/marketing/agent' => 'marketing.agent.manage',

        // Registrar un abono es COBRAR, no crear deuda: el verbo POST no
        // distingue las dos cosas y aquí sí importa. Quien está en el mostrador
        // recibiendo dinero no tiene por qué poder fiar.
        'POST api/admin/receivables/{receivable}/payments' => 'receivables.operate',
        'POST api/admin/receivables/payments/{payment}/reverse' => 'receivables.manage',

        // Correcciones administrativas. Pactar otro plazo es mostrador; decidir
        // que una deuda deja de cobrarse —o dejarla sin fecha, que levanta la
        // retención sin que se note— es supervisión.
        'POST api/admin/receivables/{receivable}/cancel' => 'receivables.manage',
        'POST api/admin/receivables/{receivable}/reopen' => 'receivables.manage',
        'PATCH api/admin/receivables/{receivable}/due-date' => 'receivables.operate',
        'DELETE api/admin/receivables/{receivable}/due-date' => 'receivables.manage',

        /*
         * EL CANAL FINANCIERO EN TIEMPO REAL. No entrega dinero: entrega una
         * huella opaca que solo sirve para decir «algo cambió, vuelve a
         * preguntar». Quien lo abre ya está viendo una pantalla de dinero.
         *
         * Vivía bajo el dominio `earnings` y por tanto exigía `earnings.view`,
         * que recepción NO tiene. El navegador recibía 403, `EventSource`
         * reintentaba en silencio para siempre y la pantalla se quedaba sin
         * tiempo real sin que apareciera un solo error: 902 rechazos seguidos
         * a una misma recepcionista antes de que nadie lo notara.
         *
         * Basta CUALQUIERA de estos: son los permisos de las pantallas que
         * escuchan el canal. Sin ninguno no hay nada que refrescar.
         */
        'GET api/admin/earnings/stream' => [
            'earnings.view',
            'receivables.view',
            'payments.view',
            'cash.gym.view',
            'cash.products.view',
        ],

        /*
         * INSCRIBIR A UN SOCIO EN UNA CLASE desde el mostrador. Es el trabajo
         * de recepción, pero `classes.manage` además crea, edita y borra
         * horarios, y eso no lo es: por eso existe `classes.enroll`. Quien
         * administra las clases también puede inscribir —puede hasta borrar la
         * clase entera—, así que basta cualquiera de las dos.
         *
         * Ver la lista de inscritos sigue siendo `classes.view` (lectura del
         * controlador), sin excepción.
         */
        'POST api/admin/classes/{myClass}/enrollments' => ['classes.enroll', 'classes.manage'],
        'DELETE api/admin/classes/{myClass}/enrollments/{member}' => ['classes.enroll', 'classes.manage'],

        /*
         * El canal que confirma los cambios de contraseña de las cuentas del
         * CRM. Solo lo abre quien puede hacer esos cambios: ver la lista de
         * usuarios no basta para seguir quién restablece la contraseña de quién.
         */
        'GET api/admin/users/stream' => 'users.manage',

        // Puerta de entrada del CRM: sin ella nadie podría autenticarse nunca.
        'POST api/admin/auth/login' => self::PUBLIC,

        // Sobre uno mismo. Exigen sesión, no exponen negocio ajeno.
        'GET api/admin/auth/me' => self::SELF,
        'POST api/admin/auth/logout' => self::SELF,
        'GET api/admin/notifications' => self::SELF,
        'GET api/admin/notifications/stream' => self::SELF,
        'GET api/admin/notifications/unread-count' => self::SELF,
        'POST api/admin/notifications/read-all' => self::SELF,
        'POST api/admin/notifications/{uuid}/read' => self::SELF,
        // Vale de corta vida para abrir un SSE. Quien lo canjea vuelve a pasar
        // por el permiso del stream que abra.
        'GET api/admin/stream-ticket' => self::SELF,

        // Crear una notificación a mano. Antes bastaba cualquier sesión (SELF), y
        // con ella se podía fabricar un aviso «alta» que ahora SUENA en el CRM de
        // todo el personal, o difundir una notificación a la app de todos los
        // socios. Ninguna pantalla del CRM la usa. Exige lo mismo que enviar un
        // push a los socios: `content.manage`.
        'POST api/admin/notifications' => 'content.manage',

        // Turnos de caja: el tipo (products|gym) llega en el cuerpo o la query,
        // así que el permiso fino lo decide el controlador caja por caja. Aquí
        // se exige poder ver ALGUNA caja; el orquestador hace el resto.
        // Estado del turno y apertura/cierre. El tipo llega en la query o en el
        // cuerpo, así que tampoco aquí puede la ruta saber de qué caja se
        // habla. `current()` exige el `view` de ESE tipo; abrir y cerrar exigen
        // el `operate` de CADA caja tocada, y eso lo aplica el orquestador caja
        // por caja: marcar `also` no concede nada.
        'GET api/admin/caja/shift' => self::CONTROLLER,
        'POST api/admin/caja/shift/open' => self::CONTROLLER,
        'POST api/admin/caja/shift/close' => self::CONTROLLER,

        // Consulta e informe de turnos: el permiso depende del TIPO de la caja,
        // y el tipo está en el dato, no en la URL. `cash.products` y `cash.gym`
        // son permisos separados; fijar uno aquí bloquearía a quien tenga el
        // otro antes de que nadie mire de qué turno se trata. Lo resuelve
        // CashShiftController —`denegarSiNoPuedeVer()` para leer, el permiso de
        // supervisión de esa caja para arquear, y el filtrado por cajas
        // visibles en el historial—, siempre denegando por defecto.
        'GET api/admin/caja/shifts' => self::CONTROLLER,
        'GET api/admin/caja/shifts/{shift}' => self::CONTROLLER,
        'GET api/admin/caja/shifts/{shift}/pdf' => self::CONTROLLER,
        'POST api/admin/caja/shifts/{shift}/difference' => self::CONTROLLER,

        // Recibos térmicos. La caja concreta y la persona las exige el
        // controlador; omitir la impresión exige además `receipts.print_optional`
        // (CONTROLLER_ENFORCED). Ver RECEIPT_CASH_PERMISSIONS.
        'POST api/admin/receipts' => self::RECEIPT_CASH_PERMISSIONS,
        'GET api/admin/receipts/{receipt}' => self::RECEIPT_CASH_PERMISSIONS,
        'POST api/admin/receipts/{receipt}/prints' => self::RECEIPT_CASH_PERMISSIONS,
        'PATCH api/admin/receipts/{receipt}/prints/{attempt}' => self::RECEIPT_CASH_PERMISSIONS,
        'POST api/admin/receipts/{receipt}/skip' => self::RECEIPT_CASH_PERMISSIONS,
        'GET api/admin/caja/shifts/{shift}/receipts' => self::RECEIPT_CASH_PERMISSIONS,
        'GET api/admin/receipts/pending' => self::RECEIPT_CASH_PERMISSIONS,

        // Equipos con el servicio local de impresión. Misma puerta que los
        // recibos; la persona y, para escribir o leer los eventos de un equipo,
        // `cash.gym.manage` o `cash.products.manage`, las exige
        // ReceiptStationController. Las rutas
        // del propio servicio (/api/receipt-agent/*) no son administrativas:
        // las autoriza el ticket de un solo uso, no una sesión.
        'GET api/admin/receipt-stations' => self::RECEIPT_CASH_PERMISSIONS,
        'POST api/admin/receipt-stations' => self::RECEIPT_CASH_PERMISSIONS,
        'PATCH api/admin/receipt-stations/{station}' => self::RECEIPT_CASH_PERMISSIONS,
        'POST api/admin/receipt-stations/{station}/tickets' => self::RECEIPT_CASH_PERMISSIONS,
        'GET api/admin/receipt-stations/{station}/events' => self::RECEIPT_CASH_PERMISSIONS,

        // Cobro presencial de membresía: es la caja del GIMNASIO, aunque el
        // controlador sea el de pagos.
        'POST api/payments' => 'cash.gym.operate',
        'PUT api/payments/{payment}' => 'payments.cancel',
        'PATCH api/payments/{payment}' => 'payments.cancel',

        // Liberar el vínculo de un equipo es soporte de acceso, no "auth".
        'POST api/admin/devices/{deviceId}/release' => 'support.manage',

        // Paneles de inicio: agregados de negocio, no un dominio propio.
        // Devuelve solo los contadores que el actor ya puede ver por su cuenta,
        // y `revenue` únicamente con `reports.view`. Exigir ese permiso en la
        // puerta dejaba a recepción sin pantalla de inicio. Ver routes/api.php.
        'GET api/dashboard' => self::SELF,
        'GET api/reports/stats' => 'reports.view',
        'GET api/admin/reports/overview' => 'reports.view',
        'GET api/admin/reports/payment-channels' => 'reports.view',

        // ── Centro de informes ──────────────────────────────────────────────
        // Todo el panel exige `reports.view`, que es el permiso del dinero:
        // recepción no lo tiene, y por eso no ve cuánto factura el gimnasio.
        'GET api/admin/reports/summary' => 'reports.view',
        'GET api/admin/reports/options' => 'reports.view',
        'GET api/admin/reports/money' => 'reports.view',
        'GET api/admin/reports/money/transactions' => 'reports.view',
        'GET api/admin/reports/memberships' => 'reports.view',
        'GET api/admin/reports/memberships/expiring' => 'reports.view',
        'GET api/admin/reports/memberships/expired' => 'reports.view',
        'GET api/admin/reports/members' => 'reports.view',
        'GET api/admin/reports/members/lapsed' => 'reports.view',
        'GET api/admin/reports/members/search' => 'reports.view',
        'GET api/admin/reports/sales' => 'reports.view',
        'GET api/admin/reports/sales/rows' => 'reports.view',
        'GET api/admin/reports/staff' => 'reports.view',
        'GET api/admin/reports/staff/profile' => 'reports.view',
        'GET api/admin/reports/staff/timeline' => 'reports.view',
        // La actividad del sistema NO: eso es auditoría, el permiso más alto
        // del CRM, y ni el rol Administrador lo tiene por defecto.
        'GET api/admin/reports/activity' => 'audit.view',
        // El catálogo de descargas solo dice qué formatos existen.
        'GET api/admin/reports/exports' => self::SELF,
        'GET api/admin/reports/exports/transactions' => 'reports.view',
        'GET api/admin/reports/exports/sales' => 'reports.view',
        'GET api/admin/reports/exports/expiring' => 'reports.view',
        'GET api/admin/reports/exports/expired' => 'reports.view',
        'GET api/admin/reports/exports/staff' => 'reports.view',
        'GET api/admin/reports/exports/activity' => 'audit.view',
        // El historial de pagos del perfil es información de PAGOS, aunque se
        // mire desde la ficha del socio: quien no ve pagos no lo ve aquí.
        'GET api/admin/users/{user}/payment-history' => 'payments.view',

        // Exportación. «Exportar» es un permiso propio y no «ver»: mirar la
        // lista de socios y llevarse a todos en un fichero con documento y
        // teléfono son actos distintos. El catálogo solo exige sesión porque
        // devuelve únicamente lo que ESA sesión puede descargar.
        'GET api/admin/exports' => self::SELF,
        'GET api/admin/exports/members' => 'members.export',
        'GET api/admin/exports/payments' => 'payments.export',
        // Cotizar es un cálculo fiscal previo a cobrar: pertenece a facturación.
        'POST api/admin/billing/quote' => 'billing.view',

        // Anular un contrato es una acción sobre el expediente del socio.
        'POST api/admin/contracts/{contract}/void' => 'members.edit',

        // Congelar una membresía mueve su vencimiento, y con él lo que el socio
        // ya pagó. Por eso es un permiso propio y no «editar miembros»: no todo
        // el que corrige un teléfono debe poder alargar una vigencia.
        'POST api/users/{user}/freeze' => 'members.freeze',
        'POST api/users/{user}/resume' => 'members.freeze',

        // Consultar el acceso es parte de atender en el mostrador: quien ve la
        // ficha tiene que poder ver si hoy puede entrar y cuántas entradas le
        // quedan. Sumarle días o entradas NO: eso es regalar lo que se vende, y
        // va con su propio permiso para poder dárselo solo a quien deba.
        'GET api/users/{user}/access' => 'members.view',
        'POST api/users/{user}/adjustments' => 'members.adjust',
        // Mejorar de plan es VENDER: lo hace quien cobra en el mostrador.
        'GET api/users/{user}/plan-upgrade' => 'payments.view',
        'POST api/users/{user}/plan-upgrade' => 'payments.create',
        'GET api/admin/memberships/stream' => ['members.view', 'plans.view', 'payments.view'],

        /*
         * TRASLADAR SOCIOS ENTRE PLANES. Cambia el plan de un grupo sin cobro:
         * lo que el socio puede hacer en la puerta y en la app cambia para
         * decenas de personas de una vez. Las SIETE rutas van aquí, una a una,
         * y el controlador NO está en CONTROLLERS: si lo estuviera, las
         * lecturas resolverían a `plans.view`, que tiene recepción y que el
         * token compartido obtiene por ser lectura. Ver la lista de candidatos
         * ya es preparar un traslado.
         */
        'GET api/admin/plan-transfers/candidates' => 'plans.transfer',
        'POST api/admin/plan-transfers/dry-run' => 'plans.transfer',
        'GET api/admin/plan-transfers' => 'plans.transfer',
        'POST api/admin/plan-transfers' => 'plans.transfer',
        'GET api/admin/plan-transfers/{batch}' => 'plans.transfer',
        'POST api/admin/plan-transfers/{batch}/revert/dry-run' => 'plans.transfer',
        'POST api/admin/plan-transfers/{batch}/revert' => 'plans.transfer',

        /*
         * El canal del CRM lleva ahora también los hechos de personal,
         * biometría y dispositivos (outbox `class_events`, un solo stream por
         * pestaña). Lo abre quien vea cualquiera de las pantallas que lo
         * escuchan; QUÉ hechos recibe lo decide el canal, hecho a hecho, con
         * el permiso de cada uno (ClassesChannel).
         */
        'GET api/admin/classes/stream' => ['classes.view', 'workforce.view', 'biometrics.view', 'devices.view'],

        /*
         * «Borrar rostro» de la ficha del entrenador es revocar un dato
         * biométrico: lo mismo que la pantalla de biometría, con su permiso, y
         * no `trainers.manage` (editar la ficha no es borrar un dato sensible).
         */
        'DELETE api/admin/trainers/{trainer}/face' => 'biometrics.manage',
        // Consultar quién es empleado va con ver la ficha; conceder el acceso
        // gratuito, no: eso es regalar lo que el gimnasio vende.
        'GET api/users/{user}/employee-access' => 'members.view',
        'PUT api/users/{user}/employee-access' => 'members.employee',
        'DELETE api/users/{user}/employee-access' => 'members.employee',

        /*
         * Disparo de prueba del relé del torniquete. Estuvo aquí como PÚBLICO
         * con la nota «lo llama el hardware», y no es así: es el SERVIDOR el
         * que lanza una petición a la URL que viaja en el cuerpo y devuelve
         * parte de la respuesta. Abierto, el token compartido —o cualquier
         * sesión— hacía que el servidor consultara direcciones internas.
         *
         * Es gestionar el torniquete, igual que configurarlo o abrirlo por
         * ZKTeco o puerto serie; el controlador exige además una persona.
         */
        'POST api/turnstile/webhook/fire' => 'classes.manage',

        // Movimientos de existencias y foto de producto: EDITAN un producto que
        // ya existe. Crear es solo `POST api/admin/products`. La regla genérica
        // POST→create era demasiado gruesa aquí, y la anotación anterior —que
        // exigía inventory.edit— tenía razón.
        'POST api/admin/products/{product}/entry' => 'inventory.edit',
        'POST api/admin/products/{product}/exit' => 'inventory.edit',
        'POST api/admin/products/{product}/stock' => 'inventory.edit',
        'POST api/admin/products/{product}/image' => 'inventory.edit',
        'DELETE api/admin/products/{product}/image' => 'inventory.edit',

        // Anular una venta ya cobrada es supervisión, no operación de mostrador.
        'POST api/admin/caja/sales/{sale}/cancel' => 'cash.products.manage',

        // Registrar que un entrenador pagó su piso —o abrirle la deuda— es
        // trabajo de mostrador. Decidir CUÁNTO cuesta, no: eso es precio, y el
        // precio no lo cambia quien cobra.
        'POST api/admin/commissions/agreements/{agreement}/charge' => 'commissions.charge',
        // Deshacer un cobro es supervisión, no mostrador.
        'POST api/admin/commissions/charges/{charge}/cancel' => 'commissions.cancel',

        /*
         * AGENDA DE VALORACIONES (Entrenadores › Calendario de citas), ruta a
         * ruta. Además de esto, los controladores exigen una PERSONA (el token
         * compartido no recibe nada de `valuations`, ver CrmPermission) y el
         * ALCANCE de la cita: sin `view_all`, solo las del entrenador vinculado.
         */
        'GET api/admin/valuations/capabilities' => self::VALUATIONS_ANY,
        'GET api/admin/valuations' => self::VALUATIONS_READ,
        'GET api/admin/valuations/{uuid}' => self::VALUATIONS_READ,
        'GET api/admin/valuations/slots' => self::VALUATIONS_READ,
        'GET api/admin/valuations/trainers' => self::VALUATIONS_READ,
        'GET api/admin/valuations/members/by-user/{userId}' => self::VALUATIONS_READ,
        // Buscar socios es el primer paso de agendar.
        'GET api/admin/valuations/members/search' => 'valuations.create',
        'POST api/admin/valuations' => 'valuations.create',
        // Mover, cambiar de entrenador o de tipo, y editar notas.
        'PATCH api/admin/valuations/{uuid}' => 'valuations.update',
        'POST api/admin/valuations/{uuid}/reschedule' => 'valuations.update',
        'POST api/admin/valuations/{uuid}/cancel' => 'valuations.cancel',
        // Lo que hace el entrenador con la cita delante.
        'POST api/admin/valuations/{uuid}/confirm' => 'valuations.complete',
        'POST api/admin/valuations/{uuid}/start' => 'valuations.complete',
        'POST api/admin/valuations/{uuid}/complete' => 'valuations.complete',
        'POST api/admin/valuations/{uuid}/no-show' => 'valuations.complete',
        'GET api/admin/valuations/trainers/{trainerId}/availability' => self::VALUATIONS_AVAILABILITY_READ,
        'GET api/admin/valuations/trainers/{trainerId}/blocks' => self::VALUATIONS_AVAILABILITY_READ,
        'PUT api/admin/valuations/trainers/{trainerId}/availability' => 'valuations.manage_availability',
        'POST api/admin/valuations/trainers/{trainerId}/blocks' => 'valuations.manage_availability',
        'DELETE api/admin/valuations/trainers/{trainerId}/blocks/{blockId}' => 'valuations.manage_availability',
        // Agendar en el pasado (con motivo) también es `manage_settings`, pero
        // va en el mismo POST que cualquier cita: lo decide el servicio.
        'GET api/admin/valuations/settings' => self::VALUATIONS_SETTINGS_READ,
        'PUT api/admin/valuations/settings' => 'valuations.manage_settings',

        /*
         * ULTRON STUDIO (contrato docs/ultron-studio/ULTRON_STUDIO_F_CONTRACT.md
         * §A3–§A4), ruta a ruta. Sus tres controladores NO están en CONTROLLERS:
         * allí las lecturas resolverían a `<dominio>.view` por el verbo, y una
         * ruta nueva quedaría clasificada sin que nadie lo decidiera.
         *
         * `marketing.view` y `marketing.agent.manage` no abren nada de esto. Los
         * controladores exigen además una PERSONA (el token compartido no
         * recibe nada de `ultron`, ver CrmPermission) y los CUATRO OJOS por
         * identidad: `ultron.approve` no deja aprobar lo propio. Publicar y
         * revertir responden 409 siempre en esta fase: el motor no lee
         * versiones del Studio.
         */
        'GET api/admin/ultron-studio/status' => 'ultron.view',
        'GET api/admin/ultron-studio/catalog' => 'ultron.view',
        'GET api/admin/ultron-studio/draft' => 'ultron.view',
        'PUT api/admin/ultron-studio/draft' => 'ultron.edit_draft',
        'POST api/admin/ultron-studio/draft/discard' => 'ultron.edit_draft',
        'GET api/admin/ultron-studio/versions' => 'ultron.view',
        'POST api/admin/ultron-studio/versions' => 'ultron.edit_draft',
        'GET api/admin/ultron-studio/versions/{uuid}' => 'ultron.view',
        // Simulación: valida contra el catálogo actual y no escribe nada.
        'POST api/admin/ultron-studio/versions/{uuid}/validate' => 'ultron.view',
        // Enviar a revisión: además, solo quien la creó (lo exige el servicio).
        'POST api/admin/ultron-studio/versions/{uuid}/submit' => 'ultron.edit_draft',
        'POST api/admin/ultron-studio/versions/{uuid}/approve' => 'ultron.approve',
        'POST api/admin/ultron-studio/versions/{uuid}/reject' => 'ultron.approve',
        'POST api/admin/ultron-studio/versions/{uuid}/publish' => 'ultron.publish',
        'POST api/admin/ultron-studio/versions/{uuid}/rollback' => 'ultron.rollback',
        'GET api/admin/ultron-studio/rules' => 'ultron.view',
        'POST api/admin/ultron-studio/rules' => 'ultron.manage_rules',
        'PATCH api/admin/ultron-studio/rules/{uuid}' => 'ultron.manage_rules',
        'POST api/admin/ultron-studio/rules/{uuid}/archive' => 'ultron.manage_rules',
        // Los eventos de borrador ajenos solo los ve quien tenga `audit.view`
        // (lo filtra el servicio); el resto del libro, cualquiera del Studio.
        'GET api/admin/ultron-studio/audit' => 'ultron.view',
        // Hito G (contrato docs/ultron-studio/ULTRON_STUDIO_G_CONTRACT.md §A6,
        // decisión 2 del lead): lo que lee HOY el motor, de solo lectura y sin
        // ids ni secretos, y la comparación (`draft` es siempre el borrador de
        // quien pide). Sin llaves nuevas.
        'GET api/admin/ultron-studio/engine/readings' => 'ultron.view',
        'GET api/admin/ultron-studio/compare' => 'ultron.view',
        // Hito 4 (contrato docs/ultron-studio/ULTRON_STUDIO_H4_CONTRACT.md §A7,
        // decisión 2 del lead): el laboratorio de IA. Leer, estimar (no escribe)
        // y el libro, con `ultron.view`; los escenarios, con `ultron.lab_manage`;
        // lanzar y cancelar ejecuciones, que gastan presupuesto, con
        // `ultron.lab_run` (solo Super Admin por defecto); el veredicto, con
        // `ultron.approve` y cuatro ojos respecto al autor de la versión (lo
        // exige el servicio). Con la bandera apagada, todo menos `lab/status`
        // responde 409 DESPUÉS de esta puerta: sin permiso, 403.
        'GET api/admin/ultron-studio/lab/status' => 'ultron.view',
        'GET api/admin/ultron-studio/lab/scenarios' => 'ultron.view',
        'GET api/admin/ultron-studio/lab/runs' => 'ultron.view',
        'GET api/admin/ultron-studio/lab/runs/{uuid}' => 'ultron.view',
        'POST api/admin/ultron-studio/lab/estimate' => 'ultron.view',
        'GET api/admin/ultron-studio/lab/events' => 'ultron.view',
        'POST api/admin/ultron-studio/lab/scenarios' => 'ultron.lab_manage',
        'PATCH api/admin/ultron-studio/lab/scenarios/{uuid}' => 'ultron.lab_manage',
        'POST api/admin/ultron-studio/lab/scenarios/{uuid}/archive' => 'ultron.lab_manage',
        'POST api/admin/ultron-studio/lab/runs' => 'ultron.lab_run',
        'POST api/admin/ultron-studio/lab/runs/{uuid}/cancel' => 'ultron.lab_run',
        'POST api/admin/ultron-studio/lab/runs/{uuid}/verdict' => 'ultron.approve',

        // Ver el catálogo de roles ya revela el organigrama y qué puede cada
        // uno. Se exige el mismo permiso que para tocarlo: un dominio con una
        // sola llave se entiende mejor que uno con dos que casi coinciden.
        'GET api/admin/roles' => 'roles.manage',
        'GET api/admin/roles/assignable' => 'roles.manage',
        'GET api/admin/roles/permissions' => 'roles.manage',
    ];

    /**
     * Qué permiso exige ESCRIBIR en cada dominio. Sin entrada aquí, escribir
     * exige `<dominio>.manage`.
     *
     * Existe porque los dominios no tienen todos la misma granularidad: en
     * inventario se distingue crear de editar y de archivar, mientras que en
     * planes basta con poder gestionarlos.
     *
     * @var array<string, string>
     */
    private const WRITE_PERMISSION = [
        'cash.products' => 'cash.products.operate',
        'cash.gym' => 'cash.gym.operate',
        'ai' => 'ai.use',
    ];

    /**
     * Dominios donde el verbo HTTP distingue la acción, porque existen
     * endpoints separados para cada una.
     *
     * Se declara solo donde la distinción es real: en planes o mercadeo no hay
     * un «archivar» distinto de un «editar», y fabricar la llave produciría un
     * permiso que no protege nada.
     *
     * @var array<string, array<string, string>>
     */
    private const GRANULAR_WRITE = [
        'members' => [
            'POST' => 'members.create',
            'PUT' => 'members.edit',
            'PATCH' => 'members.edit',
            'DELETE' => 'members.archive',
        ],
        'inventory' => [
            'POST' => 'inventory.create',
            'DELETE' => 'inventory.delete',
            'PUT' => 'inventory.edit',
            'PATCH' => 'inventory.edit',
        ],
        'payments' => ['POST' => 'payments.create', 'DELETE' => 'payments.cancel'],
        // Las rutas de receivables declaran su permiso una a una (ver
        // routes/api.php), porque crear deuda y cobrarla son actos distintos y
        // el verbo POST no los distingue.
        'receivables' => ['POST' => 'receivables.create'],
    ];

    /**
     * Dominios donde escribir NO está permitido a nadie por esta vía.
     *
     * `audit` es el caso: el libro de auditoría se escribe desde dentro del
     * sistema, no desde el panel. Borrar o inventar entradas destruiría
     * justamente lo que hace útil el registro.
     *
     * @var array<string>
     */
    private const READ_ONLY_DOMAINS = ['audit', 'reports', 'earnings'];

    /**
     * Permisos REALES que no exige ninguna ruta, porque se comprueban DENTRO
     * del controlador con una granularidad que la ruta no puede expresar.
     *
     * Un caso son los turnos de caja: la ruta es la misma para productos y para
     * gimnasio —el tipo va en el cuerpo— y cerrar el turno de otra persona
     * depende de quién lo abrió, no de la URL. CashShiftController y su
     * orquestador lo resuelven caja por caja.
     *
     * El otro es `payments.backdate`. Cobrar es `payments.create` y lo tiene el
     * mostrador; fechar ese cobro en el pasado es otra cosa —mueve una vigencia
     * hacia atrás y puede dejar al socio vencido— y no puede depender de la URL
     * porque va en el mismo POST que cualquier otro cobro. Lo decide
     * PaymentController al ver la fecha pedida.
     *
     * Y `receipts.print_optional`: omitir la tirilla de un cobro va en la misma
     * ruta que cualquier otra acción sobre el recibo, y quien no lo tiene no
     * recibe un 403 en la puerta sino que su intento se REGISTRA como denegado
     * antes de rechazarlo. Eso solo lo puede hacer el controlador.
     *
     * Se declaran aquí para que el catálogo los ofrezca: un permiso que se
     * comprueba pero que nadie puede conceder es peor que no tenerlo.
     *
     * @var array<string>
     */
    private const CONTROLLER_ENFORCED = [
        'payments.backdate',
        // Ver CUÁNTAS unidades quedan. No es una ruta aparte: viaja dentro del
        // mismo catálogo que usa el mostrador para vender, y el mostrador lo
        // necesita aunque no pueda ver la cifra.
        'inventory.stock',
        // Ver el COSTO de compra. Tampoco es una ruta: el costo viaja en la
        // misma ficha que el precio de venta, en el valor del inventario, en
        // cada movimiento de existencias y en la utilidad de cafetería. Lo
        // recorta App\Support\Access\InventoryCosts en la respuesta, campo a
        // campo, porque negar la ruta entera dejaría sin catálogo a quien solo
        // no puede ver lo que costó.
        'inventory.cost',
        'receipts.print_optional',
        'cash.products.view',
        'cash.products.operate',
        'cash.products.manage',
        'cash.gym.view',
        'cash.gym.operate',
        'cash.gym.manage',
    ];

    /**
     * Permiso exigido por una ruta, o uno de los centinelas PUBLIC/SELF.
     *
     * Devuelve null si la ruta NO se sabe clasificar: el middleware lo trata
     * como denegación y el test de cobertura lo convierte en un fallo de CI.
     */
    /**
     * El permiso que exige una ruta.
     *
     * Puede devolver una LISTA cuando la ruta la pueden abrir varios perfiles
     * por motivos distintos: entonces basta con tener UNO. Ver el canal
     * financiero y la inscripción a clases en OVERRIDES.
     *
     * @return string|list<string>|null
     */
    public static function resolve(Route $route): string|array|null
    {
        $clave = self::routeKey($route);
        if (isset(self::OVERRIDES[$clave])) {
            return self::OVERRIDES[$clave];
        }

        $dominio = self::CONTROLLERS[self::controllerName($route)] ?? null;
        if ($dominio === null) {
            return null;
        }

        if (self::isRead($route)) {
            return "{$dominio}.view";
        }

        if (in_array($dominio, self::READ_ONLY_DOMAINS, true)) {
            // Escribir en un dominio de solo lectura exige el permiso más alto
            // del CRM; en la práctica, nadie lo hace desde el panel.
            return 'roles.manage';
        }

        $verbo = self::writeVerb($route);
        if (isset(self::GRANULAR_WRITE[$dominio][$verbo])) {
            return self::GRANULAR_WRITE[$dominio][$verbo];
        }

        return self::WRITE_PERMISSION[$dominio] ?? "{$dominio}.manage";
    }

    /**
     * ¿Esta ruta pertenece al universo que hay que autorizar?
     *
     * Es EXACTAMENTE el mismo universo que exige credencial administrativa en
     * {@see ProtectAdminPaths}, más las rutas del CRM
     * que se blindan por alias. Definirlo aparte habría dejado que las dos
     * definiciones se separaran con el tiempo, y el hueco entre ambas es
     * precisamente donde vive una ruta sin proteger.
     */
    public static function isAdministrative(Route $route): bool
    {
        $uri = $route->uri();

        if (str_starts_with($uri, 'api/admin')) {
            return true;
        }

        // Pagos legacy del CRM. Los pagos in-app del socio (wompi) NO: llevan
        // su propia autenticación de miembro y no son administrativos.
        if (str_starts_with($uri, 'api/payments')) {
            return ! str_starts_with($uri, 'api/payments/wompi');
        }

        // Resto del CRM fuera del prefijo /admin: comparte espacio con rutas de
        // la app móvil, así que el discriminante es su middleware.
        return in_array('auth.admin', $route->gatherMiddleware(), true);
    }

    public static function routeKey(Route $route): string
    {
        $metodo = collect($route->methods())
            ->first(fn (string $m) => ! in_array($m, ['HEAD', 'OPTIONS'], true)) ?? 'GET';

        return $metodo.' '.$route->uri();
    }

    /**
     * Permisos que este mapa exige DE VERDAD.
     *
     * Se calcula recorriendo las rutas reales, no enumerando combinaciones
     * posibles: un permiso que ninguna ruta pide es un permiso que no protege
     * nada, y no debe aparecer en la matriz que se le enseña a nadie.
     *
     * @return list<string>
     */
    public static function referencedPermissions(): array
    {
        $out = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            if (! self::isAdministrative($route)) {
                continue;
            }
            // Una ruta puede resolver a VARIOS permisos («cualquiera de
            // estos»): todos cuentan como referenciados, porque todos abren
            // esa puerta y ninguno debe desaparecer del catálogo.
            foreach ((array) self::resolve($route) as $p) {
                if ($p !== null && ! in_array($p, [self::PUBLIC, self::SELF, self::CONTROLLER], true)) {
                    $out[$p] = true;
                }
            }
        }
        foreach (self::CONTROLLER_ENFORCED as $p) {
            $out[$p] = true;
        }

        $claves = array_keys($out);
        sort($claves);

        return $claves;
    }

    /** Verbo de escritura de la ruta, normalizado. */
    private static function writeVerb(Route $route): string
    {
        foreach (['POST', 'DELETE', 'PUT', 'PATCH'] as $v) {
            if (in_array($v, $route->methods(), true)) {
                return $v;
            }
        }

        return 'POST';
    }

    private static function isRead(Route $route): bool
    {
        return in_array('GET', $route->methods(), true);
    }

    private static function controllerName(Route $route): string
    {
        $action = $route->getActionName();
        if (! str_contains($action, '@')) {
            return 'Closure';
        }

        $clase = explode('@', $action)[0];
        $partes = explode('\\', $clase);

        return end($partes);
    }
}
