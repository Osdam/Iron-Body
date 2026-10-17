using IronBody.ReceiptAgent.Printing;
using static IronBody.ReceiptAgent.Printing.PrinterStatusMapper;

namespace IronBody.ReceiptAgent.Tests;

public sealed class PrinterStatusMapperTests
{
    [Theory]
    [InlineData(0u, 0u, "ready")]
    [InlineData(0x00000200u | 0x00000400u, 0u, "ready")] // ocupada + imprimiendo
    [InlineData(PaperOut, 0u, "paper_out")]
    [InlineData(PaperProblem | Error, 0u, "paper_out")]
    [InlineData(Offline, 0u, "offline")]
    [InlineData(NotAvailable, 0u, "offline")]
    [InlineData(0u, AttributeWorkOffline, "offline")]
    [InlineData(Error, 0u, "error")]
    [InlineData(DoorOpen, 0u, "error")]
    [InlineData(PaperJam, 0u, "error")]
    [InlineData(Paused, 0u, "error")]
    [InlineData(ServerUnknown, 0u, "unknown")]
    public void Maps_windows_printer_status(uint status, uint attributes, string expected)
    {
        Assert.Equal(expected, Map(status, attributes));
    }

    [Theory]
    [InlineData("POS-58", "POS-58", true)]
    [InlineData("Impresora recibos", "Generic / Text Only", false)]
    [InlineData("XP-58", null, true)]
    [InlineData("DIG-58IIK", null, true)]
    [InlineData("EPSON TM-T20 Receipt", "EPSON TM-T20 Receipt5", true)]
    [InlineData("Generic / Text Only", "Generic / Text Only", false)]
    [InlineData("Microsoft Print to PDF", "Microsoft Print To PDF", false)]
    [InlineData("HP LaserJet", "HP Universal Printing PCL 6", false)]
    [InlineData("Caja", "Thermal Printer Driver", true)]
    public void Looks_thermal_uses_the_contract_pattern(string name, string? driver, bool expected)
    {
        Assert.Equal(expected, LooksThermal(name, driver));
    }
}
