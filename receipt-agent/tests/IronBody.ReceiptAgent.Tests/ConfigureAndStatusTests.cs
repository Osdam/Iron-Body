using System.Net;
using System.Text.Json.Nodes;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Tests.Support;
using IronBody.ReceiptAgent.Tickets;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>Ticket configure, estado, impresoras y diagnóstico.</summary>
public sealed class ConfigureAndStatusTests
{
    private static JsonObject Settings(string? printer = "POS-58", int columns = 42, string codepage = "cp437", int feed = 2, string? name = "Recepción") => new()
    {
        ["station_name"] = name,
        ["printer_name"] = printer,
        ["columns"] = columns,
        ["codepage"] = codepage,
        ["feed_lines"] = feed,
    };

    [Fact]
    public async Task Configure_applies_persists_reports_and_returns_effective_settings()
    {
        await using var host = await AgentTestHost.StartAsync();
        var key = host.CurrentSettings.StationKey;
        host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.ConfigureRedeem(Settings())));

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.True((bool)result["ok"]!);
        Assert.Equal("configure", (string?)result["purpose"]);
        Assert.Equal("applied", (string?)result["state"]);
        Assert.Equal(CashierMessages.SettingsApplied, (string?)result["message"]);
        Assert.True((bool)result["reported"]!);
        Assert.Equal(
            """{"station_name":"Recepción","printer_name":"POS-58","columns":42,"codepage":"cp437","feed_lines":2,"cut":false}""",
            result["settings"]!.ToJsonString(new System.Text.Json.JsonSerializerOptions { Encoder = System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping }));

        var report = Assert.Single(host.Backend.ReportCalls).Body;
        Assert.Equal("applied", (string?)report["outcome"]);
        Assert.Null(report["error_code"]);

        var onDisk = JsonNode.Parse(await File.ReadAllTextAsync(host.Options.SettingsPath))!;
        Assert.Equal(key, (string?)onDisk["station_key"]);
        Assert.Equal(42, (int)onDisk["columns"]!);
        Assert.Equal("cp437", (string?)onDisk["codepage"]);

        var status = await host.GetJsonAsync("/v1/status");
        Assert.Equal("Recepción", (string?)status["station_name"]);
        Assert.Equal("""{"columns":42,"codepage":"cp437","feed_lines":2,"cut":false}""", status["settings"]!.ToJsonString());
        Assert.Null(status["last_job"]); // configure no es un trabajo de impresión
    }

    [Fact]
    public async Task After_configure_the_next_print_uses_font_b_and_the_new_codepage()
    {
        await using var host = await AgentTestHost.StartAsync();
        host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK, FakeBackendHandler.ConfigureRedeem(Settings())));
        await host.PostTicketAsync(AgentTestHost.NewTicket());
        host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK,
            FakeBackendHandler.PrintRedeem(FakeBackendHandler.SampleJob(columns: 42, lines: new[] { ("Año", "normal") }))));

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.Equal("accepted", (string?)result["state"]);
        var data = Assert.Single(host.Printer.Jobs).Data;
        Assert.Equal(new byte[] { 0x1B, 0x40, 0x1C, 0x2E, 0x1B, 0x74, 0x00, 0x1B, 0x4D, 0x01, 0x1B, 0x32 }, data.Take(12).ToArray());
        Assert.Equal((byte)0xA4, data[12 + 9 + 1]); // ñ en CP437
        var redeemAgent = host.Backend.RedeemCalls[^1].Body["agent"]!;
        Assert.Equal(42, (int)redeemAgent["columns"]!);
        Assert.Equal("cp437", (string?)redeemAgent["codepage"]);
    }

    [Theory]
    [InlineData(40, "cp850", 4, "Recepción")]
    [InlineData(32, "utf8", 4, "Recepción")]
    [InlineData(32, "cp850", 9, "Recepción")]
    [InlineData(32, "cp850", 4, "AB")]
    public async Task Invalid_configure_settings_are_not_applied(int columns, string codepage, int feed, string name)
    {
        await using var host = await AgentTestHost.StartAsync();
        var before = host.CurrentSettings;
        host.Backend.Redeem = _ => Task.FromResult(FakeBackendHandler.Json(HttpStatusCode.OK,
            FakeBackendHandler.ConfigureRedeem(Settings(columns: columns, codepage: codepage, feed: feed, name: name))));

        var result = await host.PostTicketAsync(AgentTestHost.NewTicket());

        Assert.False((bool)result["ok"]!);
        Assert.Equal("configure", (string?)result["purpose"]);
        Assert.Equal("invalid_job", (string?)result["error_code"]);
        Assert.Null(result["settings"]);
        Assert.Equal(before, host.CurrentSettings);
        Assert.Equal("error", (string?)Assert.Single(host.Backend.ReportCalls).Body["outcome"]);
    }

    [Fact]
    public async Task Station_key_survives_a_service_restart()
    {
        using var data = new TempDirectory();
        string key;
        await using (var first = await AgentTestHost.StartAsync(data))
        {
            key = (string)(await first.GetJsonAsync("/v1/status"))["station_key"]!;
        }

        await using var second = await AgentTestHost.StartAsync(data);
        Assert.Equal(key, (string?)(await second.GetJsonAsync("/v1/status"))["station_key"]);
    }

    [Fact]
    public async Task Status_follows_the_contract_shape()
    {
        await using var host = await AgentTestHost.StartAsync();

        var status = await host.GetJsonAsync("/v1/status");

        Assert.True((bool)status["ok"]!);
        Assert.Equal("ironbody-receipt-printer", (string?)status["service"]);
        Assert.Equal("1.0.0", (string?)status["version"]);
        Assert.Equal(1, (int)status["protocol"]!);
        Assert.Equal(host.CurrentSettings.StationKey, (string?)status["station_key"]);
        Assert.Equal("https://api.ironbodyneiva.cloud", (string?)status["api_base"]);
        Assert.Equal("""{"name":null,"found":false,"status":"not_configured","driver":null,"port":null}""", status["printer"]!.ToJsonString());
        Assert.Equal(0, (int)status["queue"]!["pending"]!);
        Assert.Matches(@"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$", (string?)status["started_at"]);
        Assert.Matches(@"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$", (string?)status["now"]);
        Assert.Equal(
            new[] { "ok", "service", "version", "protocol", "station_key", "station_name", "api_base", "printer", "settings", "queue", "last_job", "started_at", "now" },
            status.Select(p => p.Key).ToArray());
    }

    [Fact]
    public async Task Status_reports_found_and_missing_printers()
    {
        await using var host = await AgentTestHost.StartAsync();

        host.ConfigureStation("POS-58");
        Assert.Equal("""{"name":"POS-58","found":true,"status":"ready","driver":"POS-58","port":"USB001"}""",
            (await host.GetJsonAsync("/v1/status"))["printer"]!.ToJsonString());

        host.ConfigureStation("Otra");
        Assert.Equal("""{"name":"Otra","found":false,"status":"not_found","driver":null,"port":null}""",
            (await host.GetJsonAsync("/v1/status"))["printer"]!.ToJsonString());
    }

    [Fact]
    public async Task Printers_lists_windows_printers_and_flags_the_thermal_one()
    {
        await using var host = await AgentTestHost.StartAsync();
        host.Printer.Printers.Add(new PrinterInfo("HP LaserJet", "HP Universal Printing PCL 6", "USB002", false, PrinterStatuses.Offline));

        var printers = (await host.GetJsonAsync("/v1/printers"))["printers"]!.AsArray();

        // «Microsoft Print to PDF» (puerto PORTPROMPT:, predeterminada en el doble) no se ofrece.
        Assert.Equal(
            """[{"name":"POS-58","driver":"POS-58","port":"USB001","is_default":false,"status":"ready","looks_thermal":true},""" +
            """{"name":"HP LaserJet","driver":"HP Universal Printing PCL 6","port":"USB002","is_default":false,"status":"offline","looks_thermal":false}]""",
            printers.ToJsonString());
    }

    [Fact]
    public async Task Diagnostics_lists_recent_events_and_paths()
    {
        await using var host = await AgentTestHost.StartAsync();
        host.ConfigureStation();
        await host.PostTicketAsync(AgentTestHost.NewTicket());

        var diagnostics = await host.GetJsonAsync("/v1/diagnostics");

        Assert.True((bool)diagnostics["ok"]!);
        Assert.Equal(host.Options.LogDirectory, (string?)diagnostics["log_dir"]);
        Assert.Equal(host.Options.SettingsPath, (string?)diagnostics["settings_path"]);
        var events = diagnostics["events"]!.AsArray();
        var accepted = events.First(e => (string?)e!["event"] == "job_accepted")!;
        Assert.Equal("info", (string?)accepted["level"]);
        Assert.Equal("print", (string?)accepted["purpose"]);
        Assert.Equal("RG-000123", (string?)accepted["receipt_number"]);
        Assert.Equal(
            new[] { "at", "level", "event", "purpose", "receipt_number", "error_code", "message" },
            accepted.AsObject().Select(p => p.Key).ToArray());
        Assert.Contains(events, e => (string?)e!["event"] == "service_started");
    }

    [Fact]
    public async Task Diagnostics_keep_only_the_last_100_events()
    {
        await using var host = await AgentTestHost.StartAsync();
        var buffer = (IronBody.ReceiptAgent.Diagnostics.DiagnosticsBuffer)host.App.Services.GetService(typeof(IronBody.ReceiptAgent.Diagnostics.DiagnosticsBuffer))!;
        for (var i = 0; i < 150; i++)
        {
            buffer.Info("prueba", $"evento {i}");
        }

        var events = buffer.Snapshot();

        Assert.Equal(100, events.Count);
        Assert.Equal("evento 149", events[0].Message);
        Assert.Equal("evento 50", events[^1].Message);
    }
}
