using System.Net;
using System.Net.Http.Headers;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json.Nodes;
using IronBody.ReceiptAgent.Backend;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Settings;
using IronBody.ReceiptAgent.Tickets;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.TestHost;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Logging;

namespace IronBody.ReceiptAgent.Tests.Support;

/// <summary>
/// El servicio real (misma tubería que Program.cs: guardas, límite, endpoints, cola, cliente HTTP)
/// sobre TestServer, con el backend y la impresora simulados.
/// </summary>
public sealed class AgentTestHost : IAsyncDisposable
{
    public const string AllowedOrigin = "https://ironbodyneiva.cloud";
    public const string WwwOrigin = "https://www.ironbodyneiva.cloud";
    public const int Port = 17888;

    private readonly bool _ownsData;

    private AgentTestHost(WebApplication app, AgentOptions options, FakeBackendHandler backend, FakePrinterBackend printer,
        CapturingLoggerProvider logs, TempDirectory data, bool ownsData)
    {
        _ownsData = ownsData;
        App = app;
        Options = options;
        Backend = backend;
        Printer = printer;
        Logs = logs;
        Data = data;
        Client = app.GetTestClient();
        Client.BaseAddress = new Uri($"http://127.0.0.1:{Port}/");
    }

    public WebApplication App { get; }

    public AgentOptions Options { get; }

    public FakeBackendHandler Backend { get; }

    public FakePrinterBackend Printer { get; }

    public CapturingLoggerProvider Logs { get; }

    public TempDirectory Data { get; }

    public HttpClient Client { get; }

    /// <param name="remoteAddress">
    /// Dirección remota de cada petición. TestServer no tiene conexión de red; Kestrel en producción
    /// siempre la rellena (127.0.0.1 para el CRM). null = la deja como la pone TestServer.
    /// </param>
    public static async Task<AgentTestHost> StartAsync(TempDirectory? data = null, Action<IServiceCollection>? services = null,
        IDictionary<string, string?>? configuration = null, TicketTimeBudget? budget = null, string? remoteAddress = "127.0.0.1")
    {
        var ownsData = data is null;
        data ??= new TempDirectory();
        var backend = new FakeBackendHandler();
        var printer = new FakePrinterBackend();
        var logs = new CapturingLoggerProvider();

        var (app, options, errors) = AgentHost.Build(Array.Empty<string>(), new AgentHostOverrides
        {
            EnvironmentName = "Development",
            Configuration = Merge(new Dictionary<string, string?>
            {
                ["ReceiptPrinter:Port"] = Port.ToString(),
                ["ReceiptPrinter:ApiBaseUrl"] = "https://api.ironbodyneiva.cloud",
                ["ReceiptPrinter:AllowedOrigins:0"] = AllowedOrigin,
                ["ReceiptPrinter:AllowedOrigins:1"] = WwwOrigin,
                ["ReceiptPrinter:DataDirectory"] = data.Path,
                ["ReceiptPrinter:PrinterBackend"] = AgentOptions.BackendFile,
            }, configuration),
            ConfigureBuilder = builder =>
            {
                builder.WebHost.UseTestServer();
                builder.Logging.AddProvider(logs);
            },
            ConfigureServices = s =>
            {
                s.AddSingleton<IPrinterBackend>(printer);
                s.AddSingleton(budget ?? new TicketTimeBudget { ReportRetryDelay = TimeSpan.Zero });
                s.AddHttpClient(BackendClient.HttpClientName).ConfigurePrimaryHttpMessageHandler(() => backend);
                if (remoteAddress is not null)
                {
                    s.AddSingleton<IStartupFilter>(new RemoteAddressStartupFilter(IPAddress.Parse(remoteAddress)));
                }

                services?.Invoke(s);
            },
        });

        Assert.Empty(errors);
        AgentHost.Initialize(app, options);
        await app.StartAsync();
        return new AgentTestHost(app, options, backend, printer, logs, data, ownsData);
    }

    private static Dictionary<string, string?> Merge(Dictionary<string, string?> defaults, IDictionary<string, string?>? overrides)
    {
        foreach (var (key, value) in overrides ?? new Dictionary<string, string?>())
        {
            defaults[key] = value;
        }

        return defaults;
    }

    /// <summary>Configura la estación como lo haría un ticket configure ya aplicado.</summary>
    public StationSettings ConfigureStation(string? printerName = "POS-58", int columns = 32, string codepage = "cp850", int feedLines = 4) =>
        App.Services.GetRequiredService<StationSettingsStore>().Apply(new ConfigureSettings("Recepción", printerName, columns, codepage, feedLines));

    public StationSettings CurrentSettings => App.Services.GetRequiredService<StationSettingsStore>().Current;

    /// <summary>Ticket con el formato del contrato: 32 bytes aleatorios en base64url sin relleno.</summary>
    public static string NewTicket() =>
        Convert.ToBase64String(RandomNumberGenerator.GetBytes(32)).TrimEnd('=').Replace('+', '-').Replace('/', '_');

    /// <summary>Petición como la haría el CRM: origen permitido, JSON y cabecera propia.</summary>
    public static HttpRequestMessage CrmPost(string path, string json)
    {
        var request = new HttpRequestMessage(HttpMethod.Post, path)
        {
            Content = new StringContent(json, Encoding.UTF8, "application/json"),
        };
        request.Headers.Add("Origin", AllowedOrigin);
        request.Headers.Add("X-IronBody-Client", "crm");
        return request;
    }

    public static HttpRequestMessage CrmGet(string path)
    {
        var request = new HttpRequestMessage(HttpMethod.Get, path);
        request.Headers.Add("Origin", AllowedOrigin);
        return request;
    }

    public async Task<JsonObject> PostTicketAsync(string ticket)
    {
        using var response = await Client.SendAsync(CrmPost("/v1/tickets", new JsonObject { ["ticket"] = ticket }.ToJsonString()));
        Assert.Equal(System.Net.HttpStatusCode.OK, response.StatusCode);
        return JsonNode.Parse(await response.Content.ReadAsStringAsync())!.AsObject();
    }

    public async Task<JsonObject> GetJsonAsync(string path)
    {
        using var response = await Client.SendAsync(CrmGet(path));
        Assert.Equal(System.Net.HttpStatusCode.OK, response.StatusCode);
        return JsonNode.Parse(await response.Content.ReadAsStringAsync())!.AsObject();
    }

    public async ValueTask DisposeAsync()
    {
        Client.Dispose();
        await App.StopAsync();
        await App.DisposeAsync();
        if (_ownsData)
        {
            Data.Dispose();
        }
    }
}

/// <summary>Fija la dirección remota antes de toda la tubería del servicio (guardas incluidas).</summary>
internal sealed class RemoteAddressStartupFilter(IPAddress address) : IStartupFilter
{
    public Action<IApplicationBuilder> Configure(Action<IApplicationBuilder> next) => app =>
    {
        app.Use((context, nextMiddleware) =>
        {
            context.Connection.RemoteIpAddress = address;
            return nextMiddleware(context);
        });
        next(app);
    };
}

public sealed class TempDirectory : IDisposable
{
    public TempDirectory()
    {
        Path = System.IO.Path.Combine(System.IO.Path.GetTempPath(), "ironbody-receipt-tests", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(Path);
    }

    public string Path { get; }

    public void Dispose()
    {
        if (Directory.Exists(Path))
        {
            Directory.Delete(Path, recursive: true);
        }
    }
}
