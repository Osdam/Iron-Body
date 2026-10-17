using Microsoft.Extensions.Configuration;

namespace IronBody.ReceiptAgent.Configuration;

/// <summary>
/// Validación de la configuración de instalación. Si devuelve errores el servicio NO arranca: un
/// servicio a medio configurar (API en http, origen mal escrito) es peor que uno parado, porque
/// imprimiría lo que no debe o rechazaría al CRM sin explicar por qué.
/// </summary>
public static class AgentOptionsValidator
{
    public static IReadOnlyList<string> Validate(AgentOptions options, string environmentName, bool isWindows)
    {
        var errors = new List<string>();

        if (options.Port is < 1 or > 65535)
        {
            errors.Add($"Port debe estar entre 1 y 65535 (valor: {options.Port}).");
        }

        ValidateApiBaseUrl(options.ApiBaseUrl, errors);

        if (options.AllowedOrigins.Count == 0)
        {
            errors.Add("AllowedOrigins no puede estar vacío: sin origen permitido el CRM no podría imprimir.");
        }

        foreach (var origin in options.AllowedOrigins)
        {
            ValidateOrigin(origin, errors);
        }

        if (!string.IsNullOrWhiteSpace(options.DataDirectory) && !Path.IsPathFullyQualified(options.DataDirectory))
        {
            errors.Add("DataDirectory debe ser una ruta absoluta.");
        }

        switch (options.PrinterBackend)
        {
            case AgentOptions.BackendWinspool:
                if (!isWindows)
                {
                    errors.Add("PrinterBackend \"winspool\" solo funciona en Windows; en desarrollo usa \"file\" con el entorno Development.");
                }
                break;
            case AgentOptions.BackendFile:
                // El backend de archivo «imprime» en disco: en producción daría por aceptado un recibo
                // que nunca llegó a la impresora.
                if (!string.Equals(environmentName, "Development", StringComparison.Ordinal))
                {
                    errors.Add($"PrinterBackend \"file\" solo se permite en el entorno Development (entorno actual: {environmentName}).");
                }
                break;
            default:
                errors.Add($"PrinterBackend desconocido: \"{options.PrinterBackend}\" (admitidos: winspool, file).");
                break;
        }

        return errors;
    }

    /// <summary>
    /// Configuración del host que no pertenece a <see cref="AgentOptions"/>. Una sección «Kestrel»
    /// (en appsettings.json, una variable de entorno <c>Kestrel__Endpoints__…</c> de la máquina pensada
    /// para otra aplicación .NET, o un argumento <c>--Kestrel:…</c>) AÑADE direcciones de escucha a la
    /// de 127.0.0.1, por ejemplo 0.0.0.0: el servicio quedaría abierto a la red. No se arranca con ella.
    /// </summary>
    public static IReadOnlyList<string> ValidateHostConfiguration(IConfiguration configuration)
    {
        var errors = new List<string>();
        if (configuration.GetSection("Kestrel").Exists())
        {
            errors.Add("La configuración trae una sección \"Kestrel\" (appsettings.json, variable de entorno Kestrel__… o argumento --Kestrel:…). " +
                "El servicio solo escucha en 127.0.0.1 y no admite otras direcciones: quítala.");
        }

        return errors;
    }

    private static void ValidateApiBaseUrl(string? value, List<string> errors)
    {
        if (string.IsNullOrWhiteSpace(value) || !Uri.TryCreate(value, UriKind.Absolute, out var uri))
        {
            errors.Add("ApiBaseUrl debe ser una URL absoluta.");
            return;
        }

        var isLoopbackHost = uri.Host is "localhost" or "127.0.0.1";
        if (uri.Scheme == Uri.UriSchemeHttps)
        {
            // https siempre vale.
        }
        else if (uri.Scheme == Uri.UriSchemeHttp && isLoopbackHost)
        {
            // http solo contra un backend local de pruebas.
        }
        else
        {
            errors.Add("ApiBaseUrl debe usar https:// (http:// solo con localhost o 127.0.0.1 para pruebas).");
        }

        if (!string.IsNullOrEmpty(uri.UserInfo) || !string.IsNullOrEmpty(uri.Query) || !string.IsNullOrEmpty(uri.Fragment))
        {
            errors.Add("ApiBaseUrl no puede llevar usuario, consulta ni fragmento.");
        }
    }

    private static void ValidateOrigin(string? origin, List<string> errors)
    {
        if (string.IsNullOrWhiteSpace(origin) || !Uri.TryCreate(origin, UriKind.Absolute, out var uri)
            || (uri.Scheme != Uri.UriSchemeHttps && uri.Scheme != Uri.UriSchemeHttp))
        {
            errors.Add($"Origen permitido inválido: \"{origin}\" (debe ser https://host[:puerto]).");
            return;
        }

        // Una página servida por http:// la puede suplantar cualquiera en la red (o el Wi-Fi) del
        // cajero; con su origen permitido podría canjear permisos y leer la estación. http:// solo
        // para el CRM de desarrollo en este mismo equipo.
        if (uri.Scheme == Uri.UriSchemeHttp && uri.Host is not ("localhost" or "127.0.0.1"))
        {
            errors.Add($"Origen permitido \"{origin}\" debe usar https:// (http:// solo con localhost o 127.0.0.1 para desarrollo).");
            return;
        }

        // El navegador manda el Origin en forma canónica (minúsculas, sin puerto por defecto, sin
        // barra). La comparación es exacta, así que un origen escrito de otra forma nunca casaría.
        var canonical = uri.GetLeftPart(UriPartial.Authority);
        if (!string.Equals(origin, canonical, StringComparison.Ordinal))
        {
            errors.Add($"Origen permitido \"{origin}\" no está en forma canónica: debe escribirse \"{canonical}\" (sin barra final ni ruta).");
        }
    }
}
