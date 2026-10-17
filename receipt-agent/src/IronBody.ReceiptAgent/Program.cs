using IronBody.ReceiptAgent;
using Microsoft.Extensions.Logging;

var (app, options, errors) = AgentHost.Build(args);

if (errors.Count > 0)
{
    // Configuración inválida: el servicio NO arranca. Cada error queda en la consola y en el Visor de
    // eventos; el log de archivo no, porque su carpeta sale de la misma configuración que falló.
    foreach (var error in errors)
    {
        app.Logger.LogCritical("Configuración inválida: {Error}", error);
    }

    app.Logger.LogCritical("El servicio no arranca hasta corregir la configuración (appsettings.json, variables de entorno o argumentos; {Count} errores).", errors.Count);
    await app.DisposeAsync();
    return 2;
}

try
{
    AgentHost.Initialize(app, options);
}
catch (Exception ex)
{
    app.Logger.LogCritical(ex, "No se pudieron preparar los ajustes de estación en {Directory}.", options.ResolvedDataDirectory);
    await app.DisposeAsync();
    return 3;
}

try
{
    await app.RunAsync();
    return 0;
}
catch (IOException ex)
{
    // El caso típico es el puerto ocupado por otro programa (o una segunda copia del servicio).
    app.Logger.LogCritical(ex, "No se pudo abrir 127.0.0.1:{Port}. ¿Hay otro programa usando ese puerto?", options.Port);
    return 4;
}
