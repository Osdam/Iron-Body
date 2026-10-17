namespace IronBody.ReceiptAgent.Tickets;

/// <summary>
/// Textos para el cajero. Son nuestros, no los del backend: así nunca se muestra (ni se registra) un
/// mensaje del servidor que pudiera traer datos internos. Se dice «aceptado por el sistema», nunca
/// «impreso»: el spooler no prueba que el papel saliera (contrato §0).
/// </summary>
public static class CashierMessages
{
    public const string PrintAccepted = "Aceptado por el sistema. Comprueba que el recibo haya salido de la impresora.";
    public const string TestAccepted = "Prueba aceptada por el sistema. Comprueba que la hoja de prueba haya salido.";
    public const string SettingsApplied = "Ajustes aplicados y guardados en este equipo.";

    public const string RawNotSupported =
        "Windows no acepta impresión directa con este driver. Instala el driver «Generic / Text Only» en el mismo puerto USB y configúralo en el CRM.";

    public const string ServiceStopping =
        "El servicio de impresión se detuvo antes de imprimir este recibo. No se imprimió nada: reintenta en un momento.";

    /// <summary>404 sin code ticket_invalid: el servidor no tiene las rutas /api/receipt-agent.</summary>
    public const string BackendWithoutAgent = "El servidor no tiene el servicio de impresión (¿backend sin actualizar?).";

    /// <summary>Configure con una impresora que no es local (o no existe en este equipo).</summary>
    public const string ChooseLocalPrinter = "Elige una impresora conectada a este equipo.";

    public static string PrinterNotLocal(string printerName) =>
        $"La impresora «{printerName}» no está conectada a este equipo. Elige en el CRM una impresora conectada a este equipo.";

    public const string SettingsNotSaved =
        "No se pudieron guardar los ajustes en este equipo (permisos de la carpeta de datos). Reinstala el servicio y vuelve a intentarlo.";

    public static string For(string errorCode, string? printerName = null) => errorCode switch
    {
        ErrorCodes.TicketInvalid => "El permiso de impresión no es válido. Vuelve a intentarlo desde el CRM.",
        ErrorCodes.TicketExpired => "El permiso de impresión caducó. Vuelve a intentarlo desde el CRM.",
        ErrorCodes.TicketUsed => "Ese permiso de impresión ya se usó. Si el recibo no salió, pide una reimpresión.",
        ErrorCodes.StationMismatch => "Este equipo no es la estación a la que se envió el recibo. Revisa la estación configurada en el CRM.",
        ErrorCodes.StationInactive => "Esta estación de impresión está desactivada en el CRM. Pide a un administrador que la active.",
        ErrorCodes.AgentDisabled => "La impresión con el servicio local está desactivada temporalmente. Usa el modo de impresión anterior.",
        ErrorCodes.ProtocolUnsupported => "Este servicio de impresión es demasiado antiguo para el CRM. Hay que actualizarlo.",
        ErrorCodes.AttemptClosed => "Este intento de impresión ya estaba cerrado. Si hace falta, pide una reimpresión.",
        ErrorCodes.ApiUnreachable => "No hay conexión con el servidor de Iron Body. Revisa internet e inténtalo de nuevo.",
        ErrorCodes.ApiError => "El servidor de Iron Body respondió con un error. Inténtalo de nuevo en un momento.",
        ErrorCodes.RateLimited => "Demasiadas solicitudes al servidor; espera un momento y reintenta.",
        ErrorCodes.IncompatibleJob => "El servidor envió un trabajo que este servicio no entiende. Hay que actualizar el servicio de impresión.",
        ErrorCodes.PrinterNotConfigured => "No hay impresora configurada en esta estación. Configúrala desde el CRM.",
        ErrorCodes.PrinterNotFound => printerName is null
            ? "No se encuentra la impresora en Windows. Revisa que esté instalada."
            : $"No se encuentra la impresora «{printerName}» en Windows. Revisa que esté instalada con ese nombre.",
        ErrorCodes.PrinterOffline => "La impresora no responde: revisa que esté encendida, con papel y conectada por USB.",
        ErrorCodes.PaperOut => "La impresora no tiene papel o la tapa está abierta. Pon papel, cierra la tapa y reintenta.",
        ErrorCodes.PrinterError => "La impresora informó un error. Revisa papel, tapa y cable USB, y reintenta.",
        // Empieza igual que printer_offline: con USB, una impresora apagada suele verse así (el trabajo
        // se queda en cola sin error) y el cajero debe leer lo mismo.
        ErrorCodes.JobTimeout => "La impresora no responde: no tomó el recibo en 12 segundos y se canceló para que no salga duplicado más tarde. Revisa que esté encendida, con papel y conectada por USB.",
        ErrorCodes.SpoolerError => "Windows no aceptó el trabajo de impresión. Revisa la impresora en Windows y reintenta.",
        ErrorCodes.InvalidJob => "El recibo recibido no cumple el formato de impresión. No se imprimió nada.",
        ErrorCodes.QueueFull => "Hay demasiados recibos esperando para imprimir. Espera un momento y reintenta.",
        _ => "No se pudo completar la impresión.",
    };
}
