using System.Net;
using System.Text.Json.Nodes;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Tests.Support;
using IronBody.ReceiptAgent.Tickets;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>
/// Hallazgo de seguridad M-4: el nombre de impresora llega del backend; solo se aceptan impresoras de
/// ESTE equipo, enumeradas como locales y sin puerto de red. Se prueba con un enumerador falso.
/// </summary>
public sealed class LocalPrinterTests
{
    private static readonly PrinterInfo Usb = new("POS-58", "POS-58", "USB001", false, PrinterStatuses.Ready);

    private static IReadOnlyList<PrinterInfo> List(params PrinterInfo[] extra) => new[] { Usb }.Concat(extra).ToArray();

    [Theory]
    [InlineData(@"\\servidor\POS-58")]
    [InlineData(@"\\192.168.1.20\caja")]
    [InlineData("//servidor/POS-58")]
    [InlineData("http://192.168.1.20:631/printers/caja")]
    [InlineData("ipp://impresora.local/ipp/print")]
    public void Remote_names_are_rejected_even_if_listed(string name)
    {
        var resolution = LocalPrinterPolicy.Resolve(List(new PrinterInfo(name, "x", "USB009", false, PrinterStatuses.Ready)), name);

        Assert.Equal(PrinterResolutionStatus.Rejected, resolution.Status);
        Assert.Null(resolution.Printer);
    }

    [Theory]
    [InlineData("IP_192.168.1.50")]
    [InlineData("ip_10.0.0.5")]
    [InlineData("WSD-0d8c4c0a-7bd8-4b2c-a5c2-3b8d12f8e3a1")]
    [InlineData(@"\\servidor\cola")]
    [InlineData("http://192.168.1.20/ipp")]
    [InlineData("192.168.1.50")]
    [InlineData("10.0.0.5:9100")]
    [InlineData("[fe80::1]:9100")]
    public void Local_printers_with_a_network_port_are_rejected(string port)
    {
        var printer = new PrinterInfo("Caja red", "Generic / Text Only", port, false, PrinterStatuses.Ready);

        Assert.NotNull(LocalPrinterPolicy.FindRejectionReason(printer));
        Assert.Equal(PrinterResolutionStatus.Rejected, LocalPrinterPolicy.Resolve(List(printer), "Caja red").Status);
    }

    [Fact]
    public void Printer_flagged_as_network_by_windows_is_rejected()
    {
        var printer = new PrinterInfo("Compartida", "POS-58", "USB001", false, PrinterStatuses.Ready, IsNetwork: true);

        Assert.Equal(PrinterResolutionStatus.Rejected, LocalPrinterPolicy.Resolve(List(printer), "Compartida").Status);
    }

    [Theory]
    [InlineData("USB001")]
    [InlineData("USB002")]
    [InlineData("COM3:")]
    [InlineData("LPT1:")]
    [InlineData("DOT4_001")]
    [InlineData("USB001,USB002")]
    [InlineData("LPT1:, LPT2:")]
    // Nombres de puerto propios de drivers POS de fabricante: por eso es lista de denegación.
    [InlineData("POS58USB")]
    [InlineData("XP-58 Port")]
    [InlineData("USBPRN1")]
    [InlineData("TSCUSB001")]
    [InlineData("TS")]
    [InlineData("")]
    public void Usb_serial_parallel_and_vendor_ports_are_local(string port)
    {
        Assert.Null(LocalPrinterPolicy.FindRejectionReason(new PrinterInfo("Térmica", "POS-58", port, false, PrinterStatuses.Ready)));
    }

    /// <summary>
    /// Revisión de seguridad (notas): puertos que no son una impresora física de este equipo. TS###
    /// es una impresora redirigida por Escritorio remoto (el recibo se imprime en el equipo cliente);
    /// FILE:, PORTPROMPT: y las rutas escriben un archivo (el spooler lo hace como SYSTEM); NUL: lo
    /// descarta y el servicio lo daría por aceptado.
    /// </summary>
    [Theory]
    [InlineData("TS001", "Escritorio remoto")]
    [InlineData("ts002", "Escritorio remoto")]
    [InlineData("TS1234", "Escritorio remoto")]
    [InlineData("FILE:", "archivo")]
    [InlineData("file:", "archivo")]
    [InlineData("FILE", "archivo")]
    [InlineData("PORTPROMPT:", "PDF/XPS")]
    [InlineData("NUL:", "descarta")]
    [InlineData("nul:", "descarta")]
    [InlineData(@"C:\recibos\salida.prn", "ruta de archivo")]
    [InlineData("C:salida.prn", "ruta de archivo")]
    [InlineData("D:/recibos/x.prn", "ruta de archivo")]
    [InlineData(@"recibos\x.prn", "ruta de archivo")]
    [InlineData(@"\\?\C:\x.prn", "ruta")]
    public void Ports_that_are_not_a_physical_printer_of_this_pc_are_rejected(string port, string reason)
    {
        var printer = new PrinterInfo("Caja", "Generic / Text Only", port, false, PrinterStatuses.Ready);

        Assert.Contains(reason, LocalPrinterPolicy.FindRejectionReason(printer));
        Assert.Equal(PrinterResolutionStatus.Rejected, LocalPrinterPolicy.Resolve(List(printer), "Caja").Status);
        Assert.DoesNotContain(printer, LocalPrinterPolicy.Selectable(List(printer)));
    }

    /// <summary>
    /// Una impresora con varios puertos (agrupación) llega como «USB001,IP_…»: basta uno que no sea
    /// local para rechazarla, porque Windows puede mandar el recibo por cualquiera.
    /// </summary>
    [Theory]
    [InlineData("USB001,IP_10.0.0.5")]
    [InlineData("USB001, 192.168.1.50:9100")]
    [InlineData("USB001,TS001")]
    [InlineData("COM3:,FILE:")]
    [InlineData("USB001,WSD-0d8c4c0a-7bd8-4b2c-a5c2-3b8d12f8e3a1")]
    public void A_pooled_printer_with_any_port_that_is_not_local_is_rejected(string port)
    {
        var printer = new PrinterInfo("Caja", "POS-58", port, false, PrinterStatuses.Ready);

        Assert.NotNull(LocalPrinterPolicy.FindRejectionReason(printer));
        Assert.Equal(PrinterResolutionStatus.Rejected, LocalPrinterPolicy.Resolve(List(printer), "Caja").Status);
    }

    [Theory]
    [InlineData("Standard TCP/IP Port")]
    [InlineData("WSD Port")]
    [InlineData("LPR Port")]
    [InlineData("Microsoft IPP Port")]
    [InlineData("Internet Port")]
    public void A_renamed_port_whose_windows_monitor_is_network_is_rejected(string monitor)
    {
        // Un puerto TCP/IP estándar se puede llamar «CAJA»: el nombre no lo delata, el monitor sí.
        var monitors = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase) { ["CAJA"] = monitor, ["USB001"] = "USB Monitor" };
        var printer = new PrinterInfo("POS-58", "POS-58", "CAJA", false, PrinterStatuses.Ready,
            NetworkPorts: LocalPrinterPolicy.NetworkPortsOf("CAJA", monitors));

        var resolution = LocalPrinterPolicy.Resolve(new[] { printer }, "POS-58");

        Assert.Equal(PrinterResolutionStatus.Rejected, resolution.Status);
        Assert.Contains("CAJA", resolution.Reason);
    }

    [Theory]
    [InlineData("USB001", "USB Monitor")]
    [InlineData("USB002", "Dynamic Print Monitor")]
    [InlineData("COM3", "Local Port")]
    [InlineData("LPT1:", "Local Port")]
    [InlineData("POS-58 Port", "POS58 Port Monitor")]
    public void Local_monitors_and_vendor_monitors_are_accepted(string port, string monitor)
    {
        var monitors = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase) { [port] = monitor };
        var printer = new PrinterInfo("POS-58", "POS-58", port, false, PrinterStatuses.Ready,
            NetworkPorts: LocalPrinterPolicy.NetworkPortsOf(port, monitors));

        Assert.Equal(PrinterResolutionStatus.Found, LocalPrinterPolicy.Resolve(new[] { printer }, "POS-58").Status);
    }

    [Fact]
    public void A_pooled_printer_with_one_network_monitor_port_is_rejected_and_unknown_monitors_map_is_not_a_block()
    {
        var monitors = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase) { ["USB001"] = "USB Monitor", ["oficina"] = "Standard TCP/IP Port" };

        Assert.Equal(new[] { "oficina" }, LocalPrinterPolicy.NetworkPortsOf("USB001, oficina", monitors));
        // Sin lista de puertos de Windows (EnumPortsW falló) no hay veredicto por monitor: siguen las reglas por nombre.
        Assert.Null(LocalPrinterPolicy.NetworkPortsOf("USB001", null));
        Assert.Equal(PrinterResolutionStatus.Found, LocalPrinterPolicy.Resolve(new[] { Usb }, "POS-58").Status);
    }

    [Fact]
    public void Name_not_in_the_local_list_is_not_found()
    {
        Assert.Equal(PrinterResolutionStatus.NotFound, LocalPrinterPolicy.Resolve(List(), "POS-80").Status);
        Assert.Equal(PrinterResolutionStatus.NotFound, LocalPrinterPolicy.Resolve(List(), "").Status);
    }

    [Fact]
    public void Match_ignores_case_and_returns_the_windows_name()
    {
        var resolution = LocalPrinterPolicy.Resolve(List(), "pos-58");

        Assert.Equal(PrinterResolutionStatus.Found, resolution.Status);
        Assert.Equal("POS-58", resolution.Printer!.Name);
    }

    private static JsonObject ConfigureWith(string printer) => new()
    {
        ["station_name"] = "Recepción",
        ["printer_name"] = printer,
        ["columns"] = 32,
        ["codepage"] = "cp850",
        ["feed_lines"] = 4,
    };

    [Theory]
    [InlineData(@"\\equipo-ajeno\cola")]
    [InlineData("//equipo-ajeno/cola")]
    [InlineData("http://equipo-ajeno:631/printers/cola")]
    [InlineData("POS-80 que no está instalada")]
    [InlineData("Caja red")]
    [InlineData("Compartida")]
    [InlineData("POS-58 (redirigida 2)")]
    [InlineData("Microsoft Print to PDF")]
    public async Task Configure_with_a_printer_that_is_not_local_is_rejected_and_not_applied(string printer)
    {
        await using var host = await AgentTestHost.StartAsync();
        host.Printer.Printers.Add(new PrinterInfo("Caja red", "Generic / Text Only", "IP_192.168.1.50", false, PrinterStatuses.Ready));
        host.Printer.Printers.Add(new PrinterInfo("Compartida", "POS-58", "USB003", false, PrinterStatuses.Ready, IsNetwork: true));
        host.Printer.Printers.Add(new PrinterInfo("POS-58 (redirigida 2)", "Remote Desktop Easy Print", "TS001", false, PrinterStatuses.Ready));
        var before = host.CurrentSettings;
        host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.ConfigureRedeem(ConfigureWith(printer))));

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.False((bool)result["ok"]!);
        Assert.Equal("configure", (string?)result["purpose"]);
        Assert.Equal("error", (string?)result["state"]);
        Assert.Equal("printer_not_found", (string?)result["error_code"]);
        Assert.Equal("Elige una impresora conectada a este equipo.", (string?)result["message"]);
        Assert.Null(result["settings"]);
        Assert.Equal(before, host.CurrentSettings);
        var report = Assert.Single(host.Backend.ReportCalls).Body;
        Assert.Equal("error", (string?)report["outcome"]);
        Assert.Equal("printer_not_found", (string?)report["error_code"]);
        Assert.Empty(host.Printer.PrintCalls);
        var events = (await host.GetJsonAsync("/v1/diagnostics"))["events"]!.AsArray();
        Assert.Contains(events, e => (string?)e!["event"] == "printer_rejected" && (string?)e["level"] == "error");
    }

    [Fact]
    public async Task Configure_stores_the_windows_name_of_the_local_printer()
    {
        await using var host = await AgentTestHost.StartAsync();
        host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.ConfigureRedeem(ConfigureWith("pos-58"))));

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("applied", (string?)result["state"]);
        Assert.Equal("POS-58", host.CurrentSettings.PrinterName);
    }

    [Theory]
    [InlineData(@"\\equipo-ajeno\cola", true)]
    [InlineData("Caja red", true)]
    [InlineData("Compartida", true)]
    [InlineData("POS-58 (redirigida 2)", true)]
    [InlineData("Archivo", true)]
    [InlineData("POS-80 que no está instalada", false)]
    public async Task Print_never_reaches_windows_with_a_printer_that_is_not_local(string printer, bool rejectedAsRemote)
    {
        await using var host = await AgentTestHost.StartAsync();
        host.Printer.Printers.Add(new PrinterInfo("Caja red", "Generic / Text Only", "IP_192.168.1.50", false, PrinterStatuses.Ready));
        host.Printer.Printers.Add(new PrinterInfo("Compartida", "POS-58", "USB003", false, PrinterStatuses.Ready, IsNetwork: true));
        host.Printer.Printers.Add(new PrinterInfo("POS-58 (redirigida 2)", "Remote Desktop Easy Print", "TS001", false, PrinterStatuses.Ready));
        host.Printer.Printers.Add(new PrinterInfo("Archivo", "Generic / Text Only", @"C:\recibos\salida.prn", false, PrinterStatuses.Ready));
        // Como si settings.json lo trajera de antes (o editado a mano): se comprueba otra vez al imprimir.
        host.ConfigureStation(printer);

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("printer_not_found", (string?)result["error_code"]);
        Assert.Equal(rejectedAsRemote ? CashierMessages.PrinterNotLocal(printer) : CashierMessages.For(ErrorCodes.PrinterNotFound, printer),
            (string?)result["message"]);
        Assert.True((bool)result["reported"]!);
        Assert.Empty(host.Printer.PrintCalls);
    }

    [Fact]
    public async Task Printers_endpoint_offers_only_local_printers()
    {
        await using var host = await AgentTestHost.StartAsync();
        host.Printer.Printers.Add(new PrinterInfo("Caja red", "Generic / Text Only", "IP_192.168.1.50", false, PrinterStatuses.Ready));
        host.Printer.Printers.Add(new PrinterInfo("Compartida", "POS-58", "USB003", false, PrinterStatuses.Ready, IsNetwork: true));
        host.Printer.Printers.Add(new PrinterInfo(@"\\servidor\cola", "POS-58", "USB004", false, PrinterStatuses.Ready));
        host.Printer.Printers.Add(new PrinterInfo("POS-58 (redirigida 2)", "Remote Desktop Easy Print", "TS001", false, PrinterStatuses.Ready));
        host.Printer.Printers.Add(new PrinterInfo("Agrupada", "POS-58", "USB005,IP_10.0.0.5", false, PrinterStatuses.Ready));
        host.Printer.Printers.Add(new PrinterInfo("Térmica 2", "POS-80", "USB006", false, PrinterStatuses.Ready));

        var names = (await host.GetJsonAsync("/v1/printers"))["printers"]!.AsArray().Select(p => (string?)p!["name"]).ToArray();

        // «Microsoft Print to PDF» (PORTPROMPT:) tampoco se ofrece.
        Assert.Equal(new[] { "POS-58", "Térmica 2" }, names);
    }

    [Fact]
    public async Task Status_reports_a_remote_printer_name_as_not_found()
    {
        await using var host = await AgentTestHost.StartAsync();
        host.ConfigureStation(@"\\equipo-ajeno\cola");

        var printer = (await host.GetJsonAsync("/v1/status"))["printer"]!;

        Assert.False((bool)printer["found"]!);
        Assert.Equal("not_found", (string?)printer["status"]);
        Assert.Empty(host.Printer.PrintCalls);
    }
}
