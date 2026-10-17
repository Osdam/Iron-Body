using System.Diagnostics;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Tests.Support;
using Microsoft.Extensions.Configuration;

namespace IronBody.ReceiptAgent.Tests;

/// <summary>Configuración de instalación: si es inválida el servicio no arranca.</summary>
public sealed class AgentOptionsValidatorTests
{
    private static AgentOptions Production() => new()
    {
        Port = 17888,
        ApiBaseUrl = "https://api.ironbodyneiva.cloud",
        AllowedOrigins = { "https://ironbodyneiva.cloud" },
        PrinterBackend = "winspool",
    };

    private static IReadOnlyList<string> Validate(AgentOptions options, string environment = "Production", bool isWindows = true) =>
        AgentOptionsValidator.Validate(options, environment, isWindows);

    [Fact]
    public void Production_defaults_are_valid_on_windows()
    {
        Assert.Empty(Validate(Production()));
    }

    [Theory]
    [InlineData("http://api.ironbodyneiva.cloud")]
    [InlineData("http://192.168.1.10:8000")]
    [InlineData("ftp://api.ironbodyneiva.cloud")]
    [InlineData("api.ironbodyneiva.cloud")]
    [InlineData("https://user:pass@api.ironbodyneiva.cloud")]
    [InlineData("")]
    public void Api_base_url_must_be_https(string url)
    {
        var options = Production();
        options.ApiBaseUrl = url;
        Assert.NotEmpty(Validate(options));
    }

    [Theory]
    [InlineData("http://localhost:8000")]
    [InlineData("http://127.0.0.1:8000")]
    [InlineData("https://api.ironbodyneiva.cloud/")]
    public void Http_is_allowed_only_against_localhost(string url)
    {
        var options = Production();
        options.ApiBaseUrl = url;
        Assert.Empty(Validate(options));
    }

    [Fact]
    public void File_printer_backend_is_refused_in_production()
    {
        var options = Production();
        options.PrinterBackend = "file";

        var error = Assert.Single(Validate(options));
        Assert.Contains("Development", error);
        Assert.Empty(Validate(options, "Development"));
        Assert.NotEmpty(Validate(options, "Staging"));
    }

    [Fact]
    public void Winspool_is_refused_outside_windows_and_unknown_backends_always()
    {
        Assert.NotEmpty(Validate(Production(), isWindows: false));

        var options = Production();
        options.PrinterBackend = "raw";
        Assert.NotEmpty(Validate(options));
    }

    [Theory]
    [InlineData("https://ironbodyneiva.cloud/")]
    [InlineData("https://ironbodyneiva.cloud/caja")]
    [InlineData("https://IronBodyNeiva.cloud")]
    [InlineData("https://ironbodyneiva.cloud:443")]
    [InlineData("ironbodyneiva.cloud")]
    [InlineData("null")]
    [InlineData("*")]
    public void Origins_must_be_canonical_absolute_without_trailing_slash(string origin)
    {
        var options = Production();
        options.AllowedOrigins = new List<string> { origin };
        Assert.NotEmpty(Validate(options));
    }

    [Theory]
    [InlineData("http://ironbodyneiva.cloud")]
    [InlineData("http://www.ironbodyneiva.cloud")]
    [InlineData("http://192.168.1.10:4200")]
    [InlineData("http://caja.local")]
    public void Origins_must_be_https_except_local_development(string origin)
    {
        var options = Production();
        options.AllowedOrigins = new List<string> { origin };

        var error = Assert.Single(Validate(options));
        Assert.Contains("https://", error);
    }

    [Theory]
    [InlineData("http://localhost:4200")]
    [InlineData("http://127.0.0.1:4200")]
    [InlineData("http://localhost")]
    [InlineData("https://ironbodyneiva.cloud")]
    [InlineData("https://www.ironbodyneiva.cloud")]
    [InlineData("https://crm.ejemplo.com:8443")]
    public void Https_origins_and_local_development_origins_are_valid(string origin)
    {
        var options = Production();
        options.AllowedOrigins = new List<string> { origin };
        Assert.Empty(Validate(options));
    }

    [Fact]
    public void At_least_one_origin_is_required()
    {
        var options = Production();
        options.AllowedOrigins = new List<string>();
        Assert.NotEmpty(Validate(options));
    }

    [Theory]
    [InlineData(0)]
    [InlineData(-1)]
    [InlineData(65536)]
    public void Port_must_be_a_valid_tcp_port(int port)
    {
        var options = Production();
        options.Port = port;
        Assert.NotEmpty(Validate(options));
    }

    [Fact]
    public void Data_directory_must_be_absolute()
    {
        var options = Production();
        options.DataDirectory = "datos";
        Assert.NotEmpty(Validate(options));
    }

    [Fact]
    public void Shipped_production_appsettings_allow_both_crm_origins_and_are_valid_on_windows()
    {
        // El mismo appsettings.json que se publica en el paquete (copiado junto a las pruebas).
        var path = Path.Combine(AppContext.BaseDirectory, "appsettings.json");
        var section = System.Text.Json.Nodes.JsonNode.Parse(File.ReadAllText(path))!["ReceiptPrinter"]!;
        var options = new AgentOptions
        {
            Port = (int)section["Port"]!,
            ApiBaseUrl = (string)section["ApiBaseUrl"]!,
            AllowedOrigins = section["AllowedOrigins"]!.AsArray().Select(o => (string)o!).ToList(),
            PrinterBackend = (string)section["PrinterBackend"]!,
        };

        Assert.Equal(new[] { "https://ironbodyneiva.cloud", "https://www.ironbodyneiva.cloud" }, options.AllowedOrigins);
        Assert.Equal(17888, options.Port);
        Assert.Equal("https://api.ironbodyneiva.cloud", options.ApiBaseUrl);
        Assert.Empty(Validate(options));
    }

    [Fact]
    public async Task Host_reports_errors_for_file_backend_in_production()
    {
        using var data = new TempDirectory();
        var (app, _, errors) = AgentHost.Build(Array.Empty<string>(), new AgentHostOverrides
        {
            EnvironmentName = "Production",
            Configuration = new Dictionary<string, string?>
            {
                ["ReceiptPrinter:PrinterBackend"] = "file",
                ["ReceiptPrinter:DataDirectory"] = data.Path,
            },
        });

        Assert.Contains(errors, e => e.Contains("\"file\" solo se permite en el entorno Development"));
        await app.DisposeAsync();
    }

    [Theory]
    [InlineData("Kestrel:Endpoints:Extra:Url", "http://0.0.0.0:17992")]
    [InlineData("Kestrel:Endpoints:Lan:Url", "http://192.168.20.22:17888")]
    [InlineData("Kestrel:Limits:MaxConcurrentConnections", "100000")]
    [InlineData("Kestrel", "x")]
    public void A_kestrel_section_is_a_configuration_error(string key, string value)
    {
        var configuration = new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?> { [key] = value })
            .Build();

        var error = Assert.Single(AgentOptionsValidator.ValidateHostConfiguration(configuration));
        Assert.Contains("Kestrel", error);
        Assert.Contains("127.0.0.1", error);
    }

    [Fact]
    public void Configuration_without_kestrel_section_is_fine()
    {
        var configuration = new ConfigurationBuilder()
            .AddInMemoryCollection(new Dictionary<string, string?> { ["ReceiptPrinter:Port"] = "17888", ["KestrelX:Url"] = "http://0.0.0.0:1" })
            .Build();

        Assert.Empty(AgentOptionsValidator.ValidateHostConfiguration(configuration));
    }

    [Fact]
    public async Task Host_reports_a_kestrel_section_as_configuration_error()
    {
        using var data = new TempDirectory();
        var (app, _, errors) = AgentHost.Build(Array.Empty<string>(), new AgentHostOverrides
        {
            EnvironmentName = "Development",
            Configuration = new Dictionary<string, string?>
            {
                ["ReceiptPrinter:PrinterBackend"] = "file",
                ["ReceiptPrinter:DataDirectory"] = data.Path,
                ["ReceiptPrinter:AllowedOrigins:0"] = "https://ironbodyneiva.cloud",
                ["Kestrel:Endpoints:Extra:Url"] = "http://0.0.0.0:17992",
            },
        });

        Assert.Contains(errors, e => e.Contains("\"Kestrel\""));
        await app.DisposeAsync();
    }

    [Fact]
    public async Task Service_process_refuses_to_start_with_a_kestrel_endpoint_from_the_environment()
    {
        // El caso del revisor: una variable de máquina pensada para otra app .NET abría 0.0.0.0.
        using var data = new TempDirectory();
        var port = KestrelAndLoggingTests.FreeLoopbackPort();
        var result = await RunServiceProcess(data.Path, "Development",
            new Dictionary<string, string> { ["Kestrel__Endpoints__Extra__Url"] = $"http://0.0.0.0:{port}" },
            "--ReceiptPrinter:PrinterBackend=file", "--ReceiptPrinter:AllowedOrigins:0=https://ironbodyneiva.cloud",
            $"--ReceiptPrinter:Port={KestrelAndLoggingTests.FreeLoopbackPort()}");

        Assert.Equal(2, result.ExitCode);
        Assert.Contains("Configuración inválida", result.Output);
        Assert.Contains("\"Kestrel\"", result.Output);
        Assert.False(File.Exists(Path.Combine(data.Path, "settings.json")), "no debe llegar a preparar la estación");
    }

    [Fact]
    public async Task Service_process_refuses_to_start_with_invalid_configuration()
    {
        using var data = new TempDirectory();
        var result = await RunServiceProcess(data.Path, "Production",
            "--ReceiptPrinter:PrinterBackend=file", "--ReceiptPrinter:ApiBaseUrl=http://api.ironbodyneiva.cloud");

        Assert.Equal(2, result.ExitCode);
        Assert.Contains("Configuración inválida", result.Output);
        Assert.Contains("https://", result.Output);
        Assert.False(File.Exists(Path.Combine(data.Path, "settings.json")), "no debe llegar a preparar la estación");
    }

    internal static Task<(int ExitCode, string Output)> RunServiceProcess(string dataDirectory, string environment, params string[] extraArgs) =>
        RunServiceProcess(dataDirectory, environment, new Dictionary<string, string>(), extraArgs);

    internal static async Task<(int ExitCode, string Output)> RunServiceProcess(string dataDirectory, string environment,
        IReadOnlyDictionary<string, string> variables, params string[] extraArgs)
    {
        var dll = typeof(AgentHost).Assembly.Location;
        var dotnet = Environment.GetEnvironmentVariable("DOTNET_HOST_PATH") ?? "dotnet";
        var start = new ProcessStartInfo(dotnet)
        {
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
        };
        start.ArgumentList.Add(dll);
        start.ArgumentList.Add($"--ReceiptPrinter:DataDirectory={dataDirectory}");
        foreach (var arg in extraArgs)
        {
            start.ArgumentList.Add(arg);
        }

        start.Environment["DOTNET_ENVIRONMENT"] = environment;
        start.Environment["ASPNETCORE_ENVIRONMENT"] = environment;
        foreach (var (name, value) in variables)
        {
            start.Environment[name] = value;
        }

        using var process = Process.Start(start)!;
        var stdout = process.StandardOutput.ReadToEndAsync();
        var stderr = process.StandardError.ReadToEndAsync();
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
        try
        {
            await process.WaitForExitAsync(timeout.Token);
        }
        catch (OperationCanceledException)
        {
            // Si arrancó cuando no debía, no se deja escuchando tras la prueba.
            process.Kill(entireProcessTree: true);
            throw;
        }

        return (process.ExitCode, await stdout + await stderr);
    }
}
