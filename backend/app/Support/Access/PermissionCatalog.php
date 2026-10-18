<?php

namespace App\Support\Access;

/**
 * Catálogo de permisos del CRM: qué existe, cómo se llama en castellano y a
 * qué dominio pertenece.
 *
 * ÚNICA fuente de verdad del vocabulario. El CRM ya no traduce claves por su
 * cuenta —lo hacía porque el servidor no las nombraba— y no puede inventarse
 * un permiso que aquí no esté: la matriz se pinta con lo que devuelve este
 * catálogo.
 *
 * Regla de admisión: una llave entra solo si {@see AuthorizationMap} la exige
 * en alguna ruta real. Un permiso que no protege ningún endpoint es un
 * interruptor desconectado, y de esos había 44 en la pantalla anterior.
 */
final class PermissionCatalog
{
    /**
     * Dominios en el orden en que se muestran. El orden no es alfabético a
     * propósito: primero lo que se usa a diario en el mostrador, después la
     * gestión, y al final lo que solo toca la dirección.
     *
     * @var array<string, array{label: string, icon: string, hint: string}>
     */
    private const DOMAINS = [
        'members' => ['label' => 'Miembros', 'icon' => 'group', 'hint' => 'Fichas de socios, membresías y contratos'],
        'payments' => ['label' => 'Pagos', 'icon' => 'payments', 'hint' => 'Cobros de membresías y suscripciones'],
        'exports' => ['label' => 'Exportaciones', 'icon' => 'file_export', 'hint' => 'Descargar datos a Excel o CSV. Cada descarga queda auditada'],
        'cash.products' => ['label' => 'Caja de productos', 'icon' => 'storefront', 'hint' => 'Mostrador y venta de productos'],
        'cash.gym' => ['label' => 'Caja del gimnasio', 'icon' => 'fitness_center', 'hint' => 'Turno de caja de membresías'],
        'receivables' => ['label' => 'Cuentas por cobrar', 'icon' => 'request_quote', 'hint' => 'Saldos pendientes, créditos y abonos'],
        'commissions' => ['label' => 'Comisiones', 'icon' => 'handshake', 'hint' => 'El piso que pagan los entrenadores por cada cliente personalizado'],
        'receipts' => ['label' => 'Recibos de caja', 'icon' => 'receipt', 'hint' => 'La tirilla impresa de cada cobro de mostrador'],
        'plans' => ['label' => 'Planes', 'icon' => 'card_membership', 'hint' => 'Catálogo de planes y sus funciones'],
        'inventory' => ['label' => 'Inventario', 'icon' => 'inventory_2', 'hint' => 'Productos y existencias'],
        'classes' => ['label' => 'Clases y asistencia', 'icon' => 'calendar_month', 'hint' => 'Horarios, reservas, ingreso y torniquete'],
        'routines' => ['label' => 'Rutinas y ejercicios', 'icon' => 'exercise', 'hint' => 'Rutinas asignadas y catálogo de ejercicios'],
        'trainers' => ['label' => 'Entrenadores', 'icon' => 'sports', 'hint' => 'Fichas, asignaciones y tareas de entrenadores'],
        'valuations' => ['label' => 'Agenda de valoraciones', 'icon' => 'event_available', 'hint' => 'Citas de valoración física con los entrenadores: agenda, disponibilidad y avisos'],
        'workforce' => ['label' => 'Personal y jornadas', 'icon' => 'badge', 'hint' => 'Entrada y salida del personal, incidencias y códigos de respaldo'],
        'biometrics' => ['label' => 'Biometría', 'icon' => 'face', 'hint' => 'Estado del rostro registrado, autorizar un registro y revocarlo. Dato sensible'],
        'devices' => ['label' => 'Dispositivos autorizados', 'icon' => 'tablet_android', 'hint' => 'Tablets que pueden registrar jornadas y clases: vincular y revocar'],
        'support' => ['label' => 'Soporte', 'icon' => 'support_agent', 'hint' => 'Reportes de acceso y seguridad de socios'],
        'equipment' => ['label' => 'Equipos', 'icon' => 'fitness_center', 'hint' => 'Maquinaria del gimnasio'],
        'billing' => ['label' => 'Facturación electrónica', 'icon' => 'receipt_long', 'hint' => 'Comprobantes DIAN y tarifas fiscales'],
        'marketing' => ['label' => 'Mercadeo', 'icon' => 'campaign', 'hint' => 'Bandeja, campañas, citas y agente comercial'],
        'ultron' => ['label' => 'ULTRON Studio', 'icon' => 'account_tree', 'hint' => 'Borradores, versiones, reglas y aprobaciones del agente comercial. El motor no lee nada de esto todavía'],
        'content' => ['label' => 'Contenido de la app', 'icon' => 'smartphone', 'hint' => 'Eventos, anuncios, rachas y notificaciones push'],
        'moderation' => ['label' => 'Moderación', 'icon' => 'gavel', 'hint' => 'Historias, lives y sanciones de comunidad'],
        'nutrition' => ['label' => 'Nutrición', 'icon' => 'nutrition', 'hint' => 'Catálogo de alimentos'],
        'reports' => ['label' => 'Informes', 'icon' => 'analytics', 'hint' => 'Panel de inicio y estadísticas'],
        'earnings' => ['label' => 'Ganancias', 'icon' => 'trending_up', 'hint' => 'Ingresos del negocio'],
        'ai' => ['label' => 'Asistente IA', 'icon' => 'smart_toy', 'hint' => 'Asistente del CRM'],
        'security' => ['label' => 'Seguridad', 'icon' => 'security', 'hint' => 'Incidentes y estado de la plataforma'],
        'integrations' => ['label' => 'Integraciones', 'icon' => 'hub', 'hint' => 'WhatsApp Business y canales externos'],
        'audit' => ['label' => 'Auditoría', 'icon' => 'history', 'hint' => 'Registro de quién hizo qué'],
        'users' => ['label' => 'Usuarios administrativos', 'icon' => 'manage_accounts', 'hint' => 'Cuentas que entran al CRM'],
        'roles' => ['label' => 'Roles y permisos', 'icon' => 'admin_panel_settings', 'hint' => 'Quién puede hacer qué'],
    ];

    /**
     * Etiqueta de cada acción. Corta a propósito: en la matriz se lee bajo el
     * nombre del dominio, y «Ver» dice lo mismo que «Ver miembros» con la mitad
     * de ruido.
     *
     * @var array<string, string>
     */
    private const ACTIONS = [
        'view' => 'Ver',
        'create' => 'Crear',
        'edit' => 'Editar',
        'archive' => 'Archivar',
        'delete' => 'Archivar',
        'cancel' => 'Anular',
        'operate' => 'Operar',
        'manage' => 'Administrar',
        'use' => 'Usar',
        'export' => 'Exportar',
        'freeze' => 'Congelar',
        'adjust' => 'Ajustar',
        'employee' => 'Acceso de empleado',
        'backdate' => 'Fechar hacia atrás',
        'charge' => 'Cobrar',
        'cancel' => 'Anular',
        'enroll' => 'Inscribir',
        'transfer' => 'Trasladar socios',
    ];

    /**
     * Permisos que se MUESTRAN bajo otro dominio que el de su clave.
     *
     * `members.export` pertenece por clave a Miembros —protege datos de socios—,
     * pero en la matriz se buscaba como lo que es para quien reparte permisos:
     * un módulo propio, «Exportaciones». Metido al final de Miembros y de Pagos
     * no lo encontraba nadie.
     *
     * Solo cambia DÓNDE se pinta. La clave, lo que exige la ruta y lo que ya
     * está concedido o revocado no se tocan: renombrar un permiso desplegado
     * obligaría a migrar lo guardado en role_permissions.
     *
     * @var array<string, string>
     */
    private const DISPLAY_DOMAIN = [
        'members.export' => 'exports',
        'payments.export' => 'exports',
        'marketing.agent.manage' => 'marketing',
    ];

    /**
     * Etiqueta por clave, cuando la de la acción no basta. Dentro del grupo
     * «Exportaciones» las dos serían «Exportar»; lo que las distingue es QUÉ.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'members.export' => 'Miembros',
        'payments.export' => 'Pagos',
        'marketing.agent.manage' => 'Pausar o reactivar el agente IA',
        'receipts.print_optional' => 'Permitir omitir la impresión de recibos',
        'valuations.view' => 'Ver la agenda',
        'valuations.view_all' => 'Ver las citas de todos los entrenadores',
        'valuations.create' => 'Agendar',
        'valuations.update' => 'Reprogramar y editar',
        'valuations.cancel' => 'Cancelar citas',
        'valuations.complete' => 'Confirmar, iniciar, completar y marcar ausencia',
        'valuations.manage_availability' => 'Gestionar la disponibilidad',
        'valuations.manage_settings' => 'Configurar la agenda',
        'ultron.view' => 'Ver ULTRON Studio',
        'ultron.edit_draft' => 'Editar el borrador y crear versiones',
        'ultron.manage_rules' => 'Administrar reglas del Studio',
        'ultron.approve' => 'Aprobar o rechazar versiones de otra persona',
        'ultron.publish' => 'Publicar versiones',
        'ultron.rollback' => 'Revertir versiones',
    ];

    /**
     * Aclaración para las acciones cuyo alcance no es obvio por el nombre.
     *
     * @var array<string, string>
     */
    private const HELP = [
        'cash.products.operate' => 'Abrir y cerrar el turno de productos, y registrar ventas.',
        'cash.products.manage' => 'Cerrar turnos de otra persona y registrar el arqueo físico.',
        'cash.gym.operate' => 'Abrir y cerrar el turno del gimnasio, y cobrar membresías en mostrador.',
        'cash.gym.manage' => 'Cerrar turnos de otra persona y registrar el arqueo físico.',
        'receivables.view' => 'Consultar quién debe, cuánto y sus abonos.',
        'receivables.create' => 'Registrar una deuda nueva: un consumo a crédito o un saldo acordado.',
        'receivables.operate' => 'Registrar el dinero que alguien abona sobre su saldo y pactar una fecha de pago nueva.',
        'receivables.manage' => 'Anular un abono mal registrado, anular o reabrir una deuda entera y dejarla sin fecha de pago. Nada se borra: todo queda con motivo y autor.',
        'members.archive' => 'Retirar la ficha de un socio. No borra su historial.',
        'members.adjust' => 'Sumarle o quitarle días y entradas a una membresía a mano: por un día que no pudo venir, una entrada que el lector contó dos veces o un error al venderle el plan. Cada ajuste queda con autor y motivo.',
        'members.employee' => 'Dar y retirar el acceso de empleado: entrar a entrenar porque trabaja aquí, sin comprar un plan y sin que entre dinero en caja. Se le pueden poner días y franjas horarias, igual que a las horas valle de un plan, y se apaga el día que la persona deja de trabajar en el gimnasio. Convive con una membresía pagada: un entrenador puede ser empleado y además socio.',
        'members.freeze' => 'Pausar la membresía de un socio y devolverle después los días que le quedaban. Mientras está congelada no puede entrar, y queda registrado quién la pausó y por qué.',
        'payments.cancel' => 'Anular un pago ya registrado.',
        'commissions.view' => 'Ver el panel del mes y el histórico de los pisos: quién pagó, quién debe y a quién no se le ha registrado todavía.',
        'commissions.charge' => 'Registrar que alguien pagó el piso del mes, o abrirle la deuda. El dinero entra en la caja del gimnasio, así que hace falta tenerla abierta para cobrar en el acto.',
        'commissions.cancel' => 'Anular un mes ya cobrado: devuelve el dinero al estado anterior —el abono se revierte y sale del arqueo del turno en que entró— y deja la deuda anulada con motivo y autor. Nada se borra. Si ese mes era el que le tenía la puerta abierta al entrenador, deja de tenerla.',
        'commissions.manage' => 'Pactar con qué entrenador y por qué cliente se cobra piso, cuánto cuesta y quién lo asume. Es decidir un precio, no cobrarlo.',
        'payments.backdate' => 'Registrar un cobro con fecha de inicio ANTERIOR a hoy, para el socio que pagó hace días y se está registrando ahora. El dinero no se mueve: el cobro sigue entrando en la caja de hoy. Lo que retrocede es la vigencia, y con ella el vencimiento, así que puede dejar al socio vencido en el mismo momento del registro. Programar un inicio futuro no necesita este permiso.',
        'classes.enroll' => 'Inscribir socios en una clase y quitarlos, desde el mostrador. Aplica las mismas reglas que la app: plan con clases, cuenta al día y cupo. No permite crear ni editar horarios. Para buscar al socio hace falta también «Ver» en Miembros.',
        'classes.manage' => 'Crear, editar y eliminar clases y horarios. Incluye inscribir socios.',
        'members.export' => 'Descargar socios con su membresía en Excel o CSV, incluidos documento y teléfono si se eligen. Queda auditado.',
        'payments.export' => 'Descargar cobros con su desglose por medio de pago en Excel o CSV. Queda auditado.',
        'earnings.view' => 'Ver cuánto factura el negocio.',
        'audit.view' => 'Consultar el registro de acciones. Nadie puede escribirlo ni borrarlo.',
        'roles.manage' => 'Crear roles y repartir permisos. Es el permiso más alto del CRM.',
        'users.manage' => 'Crear cuentas del CRM y asignarles rol.',
        'integrations.manage' => 'Conectar y desconectar WhatsApp Business.',
        'moderation.manage' => 'Sancionar, retirar contenido y resolver apelaciones.',
        'marketing.agent.manage' => 'Pausar o reactivar las respuestas automáticas del agente IA para TODAS las conversaciones de WhatsApp. En pausa los mensajes se siguen recibiendo y guardando para atenderlos a mano. Queda auditado.',
        'receipts.print_optional' => 'Cerrar un cobro de mostrador SIN imprimir su recibo: la impresora no responde, se quedó sin papel o el cliente no lo quiere. Sin este permiso, el CRM exige imprimir y el intento de omitirlo queda registrado como denegado. Cada omisión queda con autor, hora y motivo, y el recibo se puede imprimir después desde el informe del turno.',
        'valuations.view' => 'Abrir el calendario de citas. Una cuenta vinculada a un entrenador, sin «Ver las citas de todos», ve solo las suyas; sin vínculo ni ese permiso, no ve ninguna.',
        'valuations.view_all' => 'Ver las citas de valoración de todos los entrenadores, con sus notas administrativas.',
        'valuations.create' => 'Agendar valoraciones y buscar al socio. Quien solo ve lo suyo, solo agenda con su propio entrenador.',
        'valuations.update' => 'Mover una cita de hora o de entrenador, cambiar su tipo y editar sus notas. Cada cambio queda en el historial.',
        'valuations.cancel' => 'Cancelar una cita con motivo. Avisa al socio si el canal de la app está activo.',
        'valuations.complete' => 'Confirmar la cita, iniciarla, completarla o marcar que el socio no asistió. Pensado para el entrenador con la cita delante.',
        'valuations.manage_availability' => 'Definir las franjas semanales de cada entrenador y sus bloqueos (descansos, ausencias, días libres). Un bloqueo no cancela las citas que ya existen.',
        'valuations.manage_settings' => 'Duración, intervalos, márgenes, avisos y canales de la agenda, y agendar una cita en el pasado con motivo. No llega por defecto al Administrador.',
        'plans.transfer' => 'Cambiar de plan a un grupo de socios sin cobrar nada: conserva sus fechas, sus pagos y sus entradas usadas. Exige una simulación previa, queda auditado por lote y por socio, y se puede revertir mientras el socio no haya cambiado después.',
        'ultron.view' => 'Abrir el tablero del agente comercial: el catálogo, las versiones, las reglas y la auditoría del Studio. El motor de ULTRON no lee nada de esto todavía: ver o cambiar cosas aquí no cambia al agente.',
        'ultron.edit_draft' => 'Guardar el propio borrador del tablero y crear versiones a partir de él, y enviarlas a revisión. Cada guardado queda en la auditoría.',
        'ultron.manage_rules' => 'Proponer reglas comerciales nuevas, editarlas y archivarlas. Nacen en borrador y el motor no las aplica; las reglas del código no se tocan desde aquí.',
        'ultron.approve' => 'Aprobar o rechazar versiones enviadas a revisión, reconociendo sus avisos. Nadie aprueba lo que creó: la versión tiene que ser de otra persona.',
        'ultron.publish' => 'Publicar una versión aprobada para que la use el agente. Hoy desactivado en el servidor; no llega por defecto al Administrador.',
        'ultron.rollback' => 'Volver a una versión publicada anterior. Hoy desactivado en el servidor; no llega por defecto al Administrador.',
    ];

    /**
     * Todas las llaves, en el orden de los dominios.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_map(fn (array $p) => $p['key'], self::rows());
    }

    /**
     * Catálogo completo para el CRM: cada permiso con su dominio, su acción y
     * su explicación, ya ordenado y agrupable sin trabajo por parte del cliente.
     *
     * @return list<array{key: string, domain: string, domain_label: string, domain_icon: string, domain_hint: string, action: string, label: string, help: string|null}>
     */
    public static function rows(): array
    {
        $reales = AuthorizationMap::referencedPermissions();
        $porDominio = [];

        foreach ($reales as $clave) {
            [$dominio, $accion] = self::split($clave);
            $dominio = self::DISPLAY_DOMAIN[$clave] ?? $dominio;
            $porDominio[$dominio][] = ['key' => $clave, 'action' => $accion];
        }

        $filas = [];
        foreach (self::DOMAINS as $dominio => $meta) {
            foreach (self::sortActions($porDominio[$dominio] ?? []) as $p) {
                $filas[] = [
                    'key' => $p['key'],
                    'domain' => $dominio,
                    'domain_label' => $meta['label'],
                    'domain_icon' => $meta['icon'],
                    'domain_hint' => $meta['hint'],
                    'action' => $p['action'],
                    'label' => self::LABELS[$p['key']] ?? self::ACTIONS[$p['action']] ?? $p['action'],
                    'help' => self::HELP[$p['key']] ?? null,
                ];
            }
            unset($porDominio[$dominio]);
        }

        // Un dominio nuevo en AuthorizationMap sin entrada en DOMAINS no se
        // pierde: aparece al final con su clave por nombre, y se ve enseguida
        // que falta describirlo.
        foreach ($porDominio as $dominio => $permisos) {
            foreach (self::sortActions($permisos) as $p) {
                $filas[] = [
                    'key' => $p['key'],
                    'domain' => $dominio,
                    'domain_label' => $dominio,
                    'domain_icon' => 'help',
                    'domain_hint' => 'Dominio sin describir en PermissionCatalog',
                    'action' => $p['action'],
                    'label' => self::LABELS[$p['key']] ?? self::ACTIONS[$p['action']] ?? $p['action'],
                    'help' => null,
                ];
            }
        }

        return $filas;
    }

    /** Dominios presentes, en orden de presentación. @return list<array<string,string>> */
    public static function domains(): array
    {
        $vistos = [];
        foreach (self::rows() as $f) {
            $vistos[$f['domain']] ??= [
                'key' => $f['domain'],
                'label' => $f['domain_label'],
                'icon' => $f['domain_icon'],
                'hint' => $f['domain_hint'],
            ];
        }

        return array_values($vistos);
    }

    /** `cash.products.operate` → ['cash.products', 'operate'] */
    public static function split(string $clave): array
    {
        $pos = strrpos($clave, '.');

        return [substr($clave, 0, $pos), substr($clave, $pos + 1)];
    }

    /**
     * Ver primero, administrar al final. Leer la matriz de menos a más
     * privilegio es lo que permite entender un rol de un vistazo.
     *
     * @param  list<array{key:string,action:string}>  $permisos
     * @return list<array{key:string,action:string}>
     */
    private static function sortActions(array $permisos): array
    {
        $orden = ['view' => 0, 'view_all' => 0.5, 'use' => 1, 'create' => 2, 'operate' => 2, 'edit' => 3, 'update' => 3, 'edit_draft' => 3, 'cancel' => 4, 'complete' => 4.5, 'archive' => 5, 'delete' => 5, 'manage_rules' => 6, 'approve' => 7, 'publish' => 8, 'rollback' => 8.2, 'manage_availability' => 8, 'manage_settings' => 8.5, 'manage' => 9];
        usort($permisos, fn ($a, $b) => ($orden[$a['action']] ?? 5) <=> ($orden[$b['action']] ?? 5));

        return $permisos;
    }
}
