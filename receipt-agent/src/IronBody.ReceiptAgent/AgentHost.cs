using System.Net;
using System.Threading.RateLimiting;
using IronBody.ReceiptAgent.Backend;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Diagnostics;
using IronBody.ReceiptAgent.Http;
using IronBody.ReceiptAgent.Printing;
using IronBody.ReceiptAgent.Printing.Windows;
using IronBody.ReceiptAgent.Settings;
using IronBody.ReceiptAgent.Tickets;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Hosting;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.RateLimiting;
using Microsoft.Extensions.Configuration;
using Microsoft.Extensions.DependencyInjection;
using Microsoft.Extensions.Hosting;
using Microsoft.Extensions.Logging;
using Microsoft.Extensions.Logging.EventLog;

namespace IronBody.ReceiptAgent;

/// <summary>Ajustes para construir el host desde las pruebas (TestServer, dobles, entorno).</summary>
public sealed class AgentHostOverrides
{
    public string? EnvironmentName { get; init; }

    /// <summary>Claves de configuración que pisan appsettings.json (p. ej. "ReceiptPrinter:Port").</summary>
    public IDictionary<string, string?>? Configuration { get; init; }

    public Action<WebApplicationBuilder>? ConfigureBuilder { get; init; }

    /// <summary>Se aplica después de los registros del servicio: sirve para cambiar uno por un doble.</summary>
    public Action<IServiceCollection>? ConfigureServices { get; init; }
}

public sealed record AgentHostBuild(WebApplication App, AgentOptions Options, IReadOnlyList<string> ConfigurationErrors);

/// <summary>
/// Composición del servicio. Program.cs y las pruebas usan exactamente esta tubería: lo que se prueba
/// en el Mac con TestServer es el mismo orden de guardas que corre en Windows.
/// </summary>
public static class AgentHost
{
    public const int PostsPerMinute = 30;
    public const int GetsPerMinute = 120;
    public const int MaxConcurrentConnections = 64;

    public static AgentHostBuild Build(string[] args, AgentHostOverrides? overrides = null)
    {
        var builder = WebApplication.CreateSlimBuilder(new WebApplicationOptions
        {
            Args = args,
            // appsettings.json va junto al exe; como servicio de Windows el directorio actual es System32.
            ContentRootPath = AppContext.BaseDirectory,
            EnvironmentName = overrides?.EnvironmentName,
        });

        if (overrides?.Configuration is not null)
        {
            builder.Configuration.AddInMemoryCollection(overrides.Configuration);
        }

        var options = builder.Configuration.GetSection(AgentOptions.SectionName).Get<AgentOptions>() ?? new AgentOptions();
        var errors = AgentOptionsValidator.Validate(options, builder.Environment.EnvironmentName, OperatingSystem.IsWindows())
            .Concat(AgentOptionsValidator.ValidateHostConfiguration(builder.Configuration))
            .ToList();

        ConfigureLogging(builder, options, errors.Count == 0);
        ConfigureServices(builder.Services, options);

        builder.Services.AddWindowsService(o => o.ServiceName = ServiceInfo.WindowsServiceName);
        builder.WebHost.ConfigureKestrel(kestrel =>
        {
            kestrel.AddServerHeader = false;
            // Guarda 1: solo 127.0.0.1. Nada de 0.0.0.0 ni IPv6: otro equipo de la red no puede llegar.
            kestrel.Listen(IPAddress.Loopback, options.Port);
            // Respaldo del límite de 16 KB que aplica la guarda (que responde con JSON).
            kestrel.Limits.MaxRequestBodySize = 64 * 1024;
            // El CRM usa unas pocas conexiones; el tope frena que un proceso local agote los sockets
            // (conexiones abiertas sin terminar la petición). Kestrel cierra la que sobra al aceptarla.
            kestrel.Limits.MaxConcurrentConnections = MaxConcurrentConnections;
        });

        overrides?.ConfigureBuilder?.Invoke(builder);
        overrides?.ConfigureServices?.Invoke(builder.Services);

        var app = builder.Build();
        app.UseMiddleware<ResponseHeadersMiddleware>();
        app.UseMiddleware<RequestGuardMiddleware>();
        app.UseRateLimiter();
        app.MapAgentEndpoints();

        return new AgentHostBuild(app, options, errors);
    }

    /// <summary>
    /// Preparación previa a escuchar: carga (o crea) settings.json y fija la hora de arranque. Si falla,
    /// el servicio no debe aceptar peticiones: no tendría clave de estación con la que canjear.
    /// </summary>
    public static StationSettings Initialize(WebApplication app, AgentOptions options)
    {
        var settings = app.Services.GetRequiredService<StationSettingsStore>().Initialize();
        _ = app.Services.GetRequiredService<AgentState>();
        app.Services.GetRequiredService<DiagnosticsBuffer>().Info("service_started",
            $"Servicio {ServiceInfo.Version} (protocolo {ServiceInfo.Protocol}) escuchando en 127.0.0.1:{options.Port}; impresora configurada: «{settings.PrinterName ?? "ninguna"}».");
        return settings;
    }

    private static void ConfigureLogging(WebApplicationBuilder builder, AgentOptions options, bool optionsValid)
    {
        builder.Logging.AddConfiguration(builder.Configuration.GetSection("Logging"));

        // Sin un DataDirectory válido no hay dónde escribir el log de archivo; el error de
        // configuración se ve igual en la consola y en el Visor de eventos.
        if (optionsValid)
        {
            builder.Logging.AddProvider(new JsonFileLoggerProvider(options.LogDirectory, TimeProvider.System));
        }

        if (OperatingSystem.IsWindows())
        {
            AddEventLog(builder);
        }
    }

    /// <summary>Visor de eventos (Aplicación). install.ps1 registra el origen con permisos de administrador.</summary>
    [System.Runtime.Versioning.SupportedOSPlatform("windows")]
    private static void AddEventLog(WebApplicationBuilder builder)
    {
        builder.Services.Configure<EventLogSettings>(s =>
        {
            s.SourceName = ServiceInfo.EventLogSource;
            s.LogName = "Application";
        });
        builder.Logging.AddEventLog();
    }

    private static void ConfigureServices(IServiceCollection services, AgentOptions options)
    {
        services.AddSingleton(options);
        services.AddSingleton(TimeProvider.System);
        services.AddSingleton<DiagnosticsBuffer>();
        services.AddSingleton<StationSettingsStore>();
        services.AddSingleton<AgentState>();
        services.AddSingleton<PrintQueue>();
        services.AddHostedService(sp => sp.GetRequiredService<PrintQueue>());
        services.AddSingleton<PrintJobExecutor>();
        services.AddSingleton<TicketService>();
        services.AddSingleton(new TicketTimeBudget());
        services.AddSingleton<BackendClient>();

        if (options.PrinterBackend == AgentOptions.BackendFile)
        {
            services.AddSingleton<IPrinterBackend, FilePrinterBackend>();
        }
        else if (OperatingSystem.IsWindows())
        {
            services.AddSingleton<IPrinterBackend, WinSpoolPrinterBackend>();
        }
        // En otro caso la configuración ya es inválida y el servicio no llega a arrancar.

        services.AddHttpClient(BackendClient.HttpClientName, client =>
            {
                client.Timeout = BackendClient.BackstopTimeout;
                client.MaxResponseContentBufferSize = BackendClient.MaxResponseBytes;
                client.DefaultRequestHeaders.Accept.ParseAdd("application/json");
                client.DefaultRequestHeaders.UserAgent.ParseAdd($"IronBody-ReceiptPrinter/{ServiceInfo.Version}");
            })
            .ConfigurePrimaryHttpMessageHandler(() => new SocketsHttpHandler
            {
                // Una redirección podría llevar el ticket a otro host: no se sigue ninguna.
                AllowAutoRedirect = false,
                ConnectTimeout = TimeSpan.FromSeconds(5),
                PooledConnectionLifetime = TimeSpan.FromMinutes(5),
            });

        services.AddRateLimiter(limiter =>
        {
            // Guarda 7: ventana fija global por método.
            limiter.GlobalLimiter = PartitionedRateLimiter.Create<HttpContext, string>(context =>
                HttpMethods.IsPost(context.Request.Method)
                    ? RateLimitPartition.GetFixedWindowLimiter("post", _ => FixedWindow(PostsPerMinute))
                    : HttpMethods.IsGet(context.Request.Method)
                        ? RateLimitPartition.GetFixedWindowLimiter("get", _ => FixedWindow(GetsPerMinute))
                        : RateLimitPartition.GetNoLimiter("other"));
            limiter.RejectionStatusCode = StatusCodes.Status429TooManyRequests;
            limiter.OnRejected = (context, _) =>
                new ValueTask(GuardResponses.WriteAsync(context.HttpContext, StatusCodes.Status429TooManyRequests, "rate_limited"));
        });
    }

    private static FixedWindowRateLimiterOptions FixedWindow(int permits) => new()
    {
        PermitLimit = permits,
        Window = TimeSpan.FromMinutes(1),
        QueueLimit = 0,
        AutoReplenishment = true,
    };
}
