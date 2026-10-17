using System.Globalization;
using System.Text.Json;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Diagnostics;

namespace IronBody.ReceiptAgent.Settings;

/// <summary>
/// Dueño de settings.json. La station_key se genera una vez y no cambia entre reinicios: es la
/// identidad con la que el administrador registró la estación en el CRM.
/// </summary>
public sealed class StationSettingsStore
{
    private static readonly JsonSerializerOptions FileJson = new() { WriteIndented = true };

    private readonly string _path;
    private readonly string _directory;
    private readonly DiagnosticsBuffer _diagnostics;
    private readonly TimeProvider _time;
    private readonly object _writeGate = new();
    private StationSettings? _current;

    public StationSettingsStore(AgentOptions options, DiagnosticsBuffer diagnostics, TimeProvider time)
    {
        _directory = options.ResolvedDataDirectory;
        _path = options.SettingsPath;
        _diagnostics = diagnostics;
        _time = time;
    }

    public string SettingsPath => _path;

    public StationSettings Current =>
        Volatile.Read(ref _current) ?? throw new InvalidOperationException("Los ajustes de estación no se han cargado (falta Initialize).");

    /// <summary>
    /// Carga los ajustes o los crea en el primer arranque. Un archivo ilegible no se pisa: se aparta
    /// como .corrupt-&lt;fecha&gt; para que el técnico vea qué tenía, y se regenera.
    /// </summary>
    public StationSettings Initialize()
    {
        lock (_writeGate)
        {
            Directory.CreateDirectory(_directory);

            if (!File.Exists(_path))
            {
                var created = StationSettings.CreateNew(NewStationKey());
                if (!TryWriteNew(created))
                {
                    // Otro proceso lo creó entre la comprobación y la escritura: manda el suyo.
                    return Initialize();
                }

                Volatile.Write(ref _current, created);
                _diagnostics.Info("settings_created", "Primer arranque: se generó la clave de estación y los ajustes por defecto.");
                return created;
            }

            var (loaded, problem, salvagedKey) = TryLoad();
            if (loaded is not null)
            {
                Volatile.Write(ref _current, loaded);
                _diagnostics.Info("settings_loaded", "Ajustes de estación cargados.");
                return loaded;
            }

            var corruptPath = $"{_path}.corrupt-{_time.GetUtcNow().UtcDateTime.ToString("yyyyMMdd'T'HHmmss'Z'", CultureInfo.InvariantCulture)}";
            File.Move(_path, corruptPath);

            // Si la clave sobrevivió se conserva: perderla obligaría a registrar de nuevo la estación.
            var regenerated = StationSettings.CreateNew(salvagedKey ?? NewStationKey());
            WriteAtomic(regenerated);
            Volatile.Write(ref _current, regenerated);
            _diagnostics.Error("settings_corrupt",
                $"settings.json no era válido ({problem}); se apartó como {Path.GetFileName(corruptPath)} y se regeneró" +
                (salvagedKey is null ? " con una clave de estación NUEVA: hay que registrarla otra vez en el CRM." : " conservando la clave de estación."));
            return regenerated;
        }
    }

    /// <summary>Aplica un configure conservando la station_key. Escribe antes de publicar en memoria.</summary>
    public StationSettings Apply(ConfigureSettings incoming)
    {
        lock (_writeGate)
        {
            var next = new StationSettings(Current.StationKey, incoming.StationName, incoming.PrinterName,
                incoming.Columns, incoming.Codepage, incoming.FeedLines);
            WriteAtomic(next);
            Volatile.Write(ref _current, next);
            return next;
        }
    }

    private (StationSettings? Settings, string? Problem, string? SalvagedKey) TryLoad()
    {
        string raw;
        try
        {
            raw = File.ReadAllText(_path);
        }
        catch (IOException ex)
        {
            // Un archivo que no se puede leer no es «corrupto»: puede estar bloqueado. Apartarlo
            // cambiaría la clave por un fallo transitorio, así que se propaga y el servicio no arranca.
            throw new IOException($"No se pudo leer {_path}: {ex.Message}", ex);
        }

        JsonElement root;
        try
        {
            using var doc = JsonDocument.Parse(raw);
            root = doc.RootElement.Clone();
        }
        catch (JsonException)
        {
            return (null, "JSON ilegible", null);
        }

        if (root.ValueKind != JsonValueKind.Object)
        {
            return (null, "no es un objeto JSON", null);
        }

        var key = ReadString(root, "station_key");
        var salvagedKey = StationSettingsRules.IsValidStationKey(key) ? key : null;

        StationSettings? parsed;
        try
        {
            parsed = root.Deserialize<StationSettings>();
        }
        catch (JsonException)
        {
            return (null, "campos con tipos inválidos", salvagedKey);
        }

        if (parsed is null || parsed.Codepage is null)
        {
            return (null, "faltan campos", salvagedKey);
        }

        var problem = StationSettingsRules.FindProblem(parsed);
        return problem is null ? (parsed, null, null) : (null, problem, salvagedKey);
    }

    private static string? ReadString(JsonElement root, string name) =>
        root.TryGetProperty(name, out var value) && value.ValueKind == JsonValueKind.String ? value.GetString() : null;

    private bool TryWriteNew(StationSettings settings)
    {
        var tmp = WriteTemp(settings);
        try
        {
            File.Move(tmp, _path, overwrite: false);
            return true;
        }
        catch (IOException) when (File.Exists(_path))
        {
            File.Delete(tmp);
            return false;
        }
    }

    /// <summary>Temporal + reemplazo: un corte de luz a mitad deja el archivo anterior o el nuevo, nunca medio.</summary>
    private void WriteAtomic(StationSettings settings)
    {
        var tmp = WriteTemp(settings);
        try
        {
            File.Move(tmp, _path, overwrite: true);
        }
        catch
        {
            TryDelete(tmp);
            throw;
        }
    }

    private string WriteTemp(StationSettings settings)
    {
        Directory.CreateDirectory(_directory);
        var tmp = Path.Combine(_directory, $"settings.json.{Guid.NewGuid():N}.tmp");
        var bytes = JsonSerializer.SerializeToUtf8Bytes(settings, FileJson);
        using (var stream = new FileStream(tmp, FileMode.CreateNew, FileAccess.Write, FileShare.None))
        {
            stream.Write(bytes);
            stream.Flush(flushToDisk: true);
        }

        return tmp;
    }

    private static void TryDelete(string path)
    {
        try
        {
            File.Delete(path);
        }
        catch (IOException)
        {
            // Se deja el temporal: no afecta a settings.json y el error original es el que importa.
        }
        catch (UnauthorizedAccessException)
        {
            // Igual que arriba: el temporal huérfano es inocuo y no debe tapar el error de escritura.
        }
    }

    private static string NewStationKey() => Guid.NewGuid().ToString("D");
}
