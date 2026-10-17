using System.Text.RegularExpressions;

namespace IronBody.ReceiptAgent.Printing;

public enum PrinterResolutionStatus
{
    Found,
    NotFound,
    Rejected,
}

/// <summary>Resultado de resolver un nombre de impresora. <see cref="Reason"/> es para el log, no para el cajero.</summary>
public sealed record PrinterResolution(PrinterResolutionStatus Status, PrinterInfo? Printer, string? Reason)
{
    public static PrinterResolution NotFound(string reason) => new(PrinterResolutionStatus.NotFound, null, reason);

    public static PrinterResolution Rejected(string reason) => new(PrinterResolutionStatus.Rejected, null, reason);
}

/// <summary>
/// Solo impresoras conectadas a ESTE equipo. El nombre de impresora llega del backend (configure); si
/// se aceptara «\\host\cola», una impresora http:// o una con puerto TCP/IP, el PC de caja mandaría los
/// recibos (con nombres de clientes e importes) a otro host, y como LocalSystem con su identidad.
/// Tampoco valen los puertos que no son una impresora física de este equipo: TS### (impresora
/// redirigida por Escritorio remoto, imprime en el equipo cliente), FILE:, PORTPROMPT: (PDF/XPS),
/// NUL: y los puertos que son una ruta de archivo (el spooler escribiría ahí como SYSTEM).
/// Es una lista de DENEGACIÓN a propósito: una térmica USB tiene un puerto USBxxx, COM y LPT también
/// son locales, y los drivers POS de fabricante usan nombres de puerto propios que una lista de
/// permitidos rechazaría.
/// </summary>
public static partial class LocalPrinterPolicy
{
    /// <summary>Nombres que Windows interpreta como impresora remota aunque no estén en la lista local.</summary>
    public static bool IsRemoteName(string name) =>
        name.StartsWith(@"\\", StringComparison.Ordinal)
        || name.StartsWith("//", StringComparison.Ordinal)
        || name.Contains("://", StringComparison.Ordinal);

    /// <summary>
    /// Motivo por el que una impresora de la lista local no se acepta (apunta a la red o su puerto no
    /// es una impresora física de este equipo), o null si es local.
    /// </summary>
    public static string? FindRejectionReason(PrinterInfo printer)
    {
        if (printer.IsNetwork)
        {
            return "Windows la marca como impresora de red";
        }

        // Lo que dice Windows del puerto manda sobre lo que sugiere su nombre: un puerto TCP/IP estándar
        // renombrado («CAJA») no empieza por IP_ pero manda los recibos por la red.
        if (printer.NetworkPorts is { Count: > 0 } porRed)
        {
            return $"su puerto «{porRed[0]}» es de red según Windows";
        }

        // Una impresora con varios puertos (agrupación de impresoras) los trae separados por comas,
        // p. ej. «USB001,IP_10.0.0.5»: Windows puede mandar el recibo por cualquiera de ellos.
        foreach (var port in (printer.Port ?? string.Empty).Split(','))
        {
            var reason = FindPortRejectionReason(port.Trim());
            if (reason is not null)
            {
                return reason;
            }
        }

        return null;
    }

    private static string? FindPortRejectionReason(string port)
    {
        if (port.Length == 0)
        {
            return null;
        }

        if (port.StartsWith(@"\\", StringComparison.Ordinal) || port.StartsWith("//", StringComparison.Ordinal) || port.Contains("://", StringComparison.Ordinal))
        {
            return $"su puerto «{port}» es una ruta de red";
        }

        if (port.StartsWith("IP_", StringComparison.OrdinalIgnoreCase))
        {
            return $"su puerto «{port}» es TCP/IP";
        }

        if (port.StartsWith("WSD-", StringComparison.OrdinalIgnoreCase))
        {
            return $"su puerto «{port}» es de servicios web (WSD)";
        }

        if (IpPortPattern().IsMatch(port))
        {
            return $"su puerto «{port}» es una dirección IP";
        }

        if (TerminalServicesPortPattern().IsMatch(port))
        {
            return $"su puerto «{port}» es una impresora redirigida por Escritorio remoto (imprime en el equipo cliente)";
        }

        var bare = port.TrimEnd(':');
        if (bare.Equals("FILE", StringComparison.OrdinalIgnoreCase))
        {
            return $"su puerto «{port}» imprime a un archivo";
        }

        if (bare.Equals("PORTPROMPT", StringComparison.OrdinalIgnoreCase))
        {
            return $"su puerto «{port}» pide un archivo de destino (impresora PDF/XPS)";
        }

        if (bare.Equals("NUL", StringComparison.OrdinalIgnoreCase))
        {
            return $"su puerto «{port}» descarta lo que recibe";
        }

        if (port.Contains('\\') || port.Contains('/') || DrivePattern().IsMatch(port))
        {
            return $"su puerto «{port}» es una ruta de archivo";
        }

        return null;
    }

    /// <summary>
    /// Busca el nombre en la lista local (sin distinguir mayúsculas, como Windows) y devuelve la
    /// impresora con su nombre canónico, o el motivo por el que no vale.
    /// </summary>
    public static PrinterResolution Resolve(IReadOnlyList<PrinterInfo> localPrinters, string name)
    {
        if (string.IsNullOrWhiteSpace(name))
        {
            return PrinterResolution.NotFound("nombre vacío");
        }

        if (IsRemoteName(name))
        {
            return PrinterResolution.Rejected("el nombre apunta a una impresora de otro equipo");
        }

        var printer = localPrinters.FirstOrDefault(p => string.Equals(p.Name, name, StringComparison.OrdinalIgnoreCase));
        if (printer is null)
        {
            return PrinterResolution.NotFound("no está en la lista de impresoras locales de Windows");
        }

        var rejection = FindRejectionReason(printer);
        return rejection is null
            ? new PrinterResolution(PrinterResolutionStatus.Found, printer, null)
            : PrinterResolution.Rejected(rejection);
    }

    /// <summary>
    /// ¿Es de red el monitor de un puerto? Los nombres de monitor son las claves de registro de
    /// Print\Monitors (no se traducen): «Standard TCP/IP Port», «WSD Port», «LPR Port», los de IPP o
    /// Internet. Los locales son «Local Port» (COM, LPT, FILE:, rutas) y «USB Monitor»/«Dynamic Print
    /// Monitor» (USB001…). Un monitor desconocido de fabricante cuenta como local: es la misma decisión
    /// que con los nombres de puerto propios de los drivers POS.
    /// </summary>
    public static bool IsNetworkMonitor(string? monitor)
    {
        if (string.IsNullOrWhiteSpace(monitor))
        {
            return false;
        }

        foreach (var marca in NetworkMonitorMarks)
        {
            if (monitor.Contains(marca, StringComparison.OrdinalIgnoreCase))
            {
                return true;
            }
        }

        return false;
    }

    private static readonly string[] NetworkMonitorMarks = ["TCP/IP", "WSD", "LPR", "IPP", "Internet", "HTTP"];

    /// <summary>
    /// De «puerto → monitor» (EnumPortsW) a los puertos de esta impresora que son de red. Null si no hay
    /// mapa (Windows no lo dio): entonces solo quedan las comprobaciones por nombre.
    /// </summary>
    public static IReadOnlyList<string>? NetworkPortsOf(string? portList, IReadOnlyDictionary<string, string>? monitorsByPort)
    {
        if (monitorsByPort is null)
        {
            return null;
        }

        return (portList ?? string.Empty)
            .Split(',')
            .Select(p => p.Trim())
            .Where(p => p.Length > 0 && monitorsByPort.TryGetValue(p, out var monitor) && IsNetworkMonitor(monitor))
            .ToList();
    }

    /// <summary>Impresoras que se pueden ofrecer al CRM: locales y con un puerto aceptado.</summary>
    public static IEnumerable<PrinterInfo> Selectable(IEnumerable<PrinterInfo> localPrinters) =>
        localPrinters.Where(p => !IsRemoteName(p.Name) && FindRejectionReason(p) is null);

    // IPv4 con puerto opcional («192.168.1.50», «10.0.0.5:9100») o IPv6 entre corchetes.
    [GeneratedRegex(@"^(\d{1,3}(\.\d{1,3}){3}(:\d+)?|\[[0-9A-Fa-f:.]+\](:\d+)?)$", RegexOptions.CultureInvariant)]
    private static partial Regex IpPortPattern();

    // Puertos de las impresoras redirigidas por Escritorio remoto: TS001, TS002…
    [GeneratedRegex(@"^TS\d+$", RegexOptions.CultureInvariant | RegexOptions.IgnoreCase)]
    private static partial Regex TerminalServicesPortPattern();

    // «C:salida.prn», «D:\recibos\x.prn»: letra de unidad y dos puntos (COM3: o LPT1: no casan).
    [GeneratedRegex(@"^[A-Za-z]:", RegexOptions.CultureInvariant)]
    private static partial Regex DrivePattern();
}
