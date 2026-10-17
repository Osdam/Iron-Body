namespace IronBody.ReceiptAgent;

/// <summary>
/// Constantes publicadas del servicio (contrato §1). El backend exige <see cref="Protocol"/> ≥ su mínimo
/// y el CRM comprueba que lo soporta; cambiarlas es un cambio de contrato.
/// </summary>
public static class ServiceInfo
{
    public const string Name = "ironbody-receipt-printer";
    public const string Version = "1.0.0";
    public const int Protocol = 1;

    /// <summary>Nombre del servicio de Windows (sc.exe, install.ps1).</summary>
    public const string WindowsServiceName = "IronBodyReceiptPrinter";

    /// <summary>Origen del Visor de eventos; install.ps1 lo registra con permisos de administrador.</summary>
    public const string EventLogSource = "IronBodyReceiptPrinter";

    public const string RootBanner = "Iron Body · Servicio de impresión de recibos " + Version + " · en funcionamiento";
}
