namespace IronBody.ReceiptAgent.Configuration;

/// <summary>
/// Configuración de instalación (sección <c>ReceiptPrinter</c> de appsettings.json junto al exe).
/// La escribe install.ps1; no la cambia nadie desde el navegador.
/// </summary>
/// <remarks>
/// Va dentro de una sección y no en la raíz porque ASP.NET Core también lee variables de entorno sin
/// prefijo: una variable <c>PORT</c> del equipo pisaría <c>Port</c> en silencio.
/// </remarks>
public sealed class AgentOptions
{
    public const string SectionName = "ReceiptPrinter";

    public const string BackendWinspool = "winspool";
    public const string BackendFile = "file";

    public int Port { get; set; } = 17888;

    public string ApiBaseUrl { get; set; } = "https://api.ironbodyneiva.cloud";

    public List<string> AllowedOrigins { get; set; } = new();

    /// <summary>Vacío → %ProgramData%\IronBody\ReceiptPrinter.</summary>
    public string? DataDirectory { get; set; }

    /// <summary><c>winspool</c> (Windows) o <c>file</c> (solo Development: escribe .bin en spool-out).</summary>
    public string PrinterBackend { get; set; } = BackendWinspool;

    public static string DefaultDataDirectory() =>
        Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData), "IronBody", "ReceiptPrinter");

    public string ResolvedDataDirectory =>
        string.IsNullOrWhiteSpace(DataDirectory) ? DefaultDataDirectory() : DataDirectory!;

    public string LogDirectory => Path.Combine(ResolvedDataDirectory, "logs");

    public string SettingsPath => Path.Combine(ResolvedDataDirectory, "settings.json");

    public string SpoolOutDirectory => Path.Combine(ResolvedDataDirectory, "spool-out");

    /// <summary>Base de la API sin barra final; las rutas se construyen siempre desde aquí.</summary>
    public string ApiBase => ApiBaseUrl.TrimEnd('/');
}
