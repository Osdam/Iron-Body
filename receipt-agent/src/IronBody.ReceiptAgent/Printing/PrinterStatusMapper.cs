using System.Text.RegularExpressions;

namespace IronBody.ReceiptAgent.Printing;

/// <summary>
/// PRINTER_INFO_2 (Status + Attributes) → ready|offline|error|paper_out|unknown. Solo informativo
/// (contrato §3.3.4): con USB + spooler la DIG-58IIK no devuelve su estado real, y Windows a menudo
/// dice «lista» con la impresora apagada.
/// </summary>
public static partial class PrinterStatusMapper
{
    // PRINTER_STATUS_* (winspool.h).
    public const uint Paused = 0x00000001;
    public const uint Error = 0x00000002;
    public const uint PaperJam = 0x00000008;
    public const uint PaperOut = 0x00000010;
    public const uint PaperProblem = 0x00000040;
    public const uint Offline = 0x00000080;
    public const uint OutputBinFull = 0x00000800;
    public const uint NotAvailable = 0x00001000;
    public const uint NoToner = 0x00040000;
    public const uint UserIntervention = 0x00100000;
    public const uint OutOfMemory = 0x00200000;
    public const uint DoorOpen = 0x00400000;
    public const uint ServerUnknown = 0x00800000;
    public const uint ServerOffline = 0x02000000;

    /// <summary>PRINTER_ATTRIBUTE_WORK_OFFLINE: «Usar impresora sin conexión» marcado en Windows.</summary>
    public const uint AttributeWorkOffline = 0x00000400;

    public static string Map(uint status, uint attributes)
    {
        if ((status & (PaperOut | PaperProblem)) != 0)
        {
            return PrinterStatuses.PaperOut;
        }

        if ((status & (Offline | NotAvailable | ServerOffline)) != 0 || (attributes & AttributeWorkOffline) != 0)
        {
            return PrinterStatuses.Offline;
        }

        if ((status & (Error | PaperJam | DoorOpen | UserIntervention | OutputBinFull | NoToner | OutOfMemory | Paused)) != 0)
        {
            return PrinterStatuses.Error;
        }

        if ((status & ServerUnknown) != 0)
        {
            return PrinterStatuses.Unknown;
        }

        // Ocupada, imprimiendo, calentando, ahorro de energía… o 0: para el cajero es «lista».
        return PrinterStatuses.Ready;
    }

    /// <summary>Heurística del contrato §3.2 para destacar las térmicas en el selector del CRM.</summary>
    public static bool LooksThermal(string? name, string? driver) =>
        (name is not null && ThermalPattern().IsMatch(name)) || (driver is not null && ThermalPattern().IsMatch(driver));

    [GeneratedRegex("pos|58|thermal|receipt|xp-|dig", RegexOptions.IgnoreCase | RegexOptions.CultureInvariant)]
    private static partial Regex ThermalPattern();
}
