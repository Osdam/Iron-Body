using System.Text.Json.Nodes;
using IronBody.ReceiptAgent.Configuration;
using IronBody.ReceiptAgent.Diagnostics;
using IronBody.ReceiptAgent.Settings;
using IronBody.ReceiptAgent.Tests.Support;
using Microsoft.Extensions.Logging.Abstractions;

namespace IronBody.ReceiptAgent.Tests;

public sealed class StationSettingsStoreTests : IDisposable
{
    private readonly TempDirectory _data = new();

    public void Dispose() => _data.Dispose();

    private (StationSettingsStore Store, DiagnosticsBuffer Diagnostics) NewStore()
    {
        var options = new AgentOptions { DataDirectory = _data.Path };
        var diagnostics = new DiagnosticsBuffer(TimeProvider.System, NullLogger<DiagnosticsBuffer>.Instance);
        return (new StationSettingsStore(options, diagnostics, TimeProvider.System), diagnostics);
    }

    private string SettingsPath => Path.Combine(_data.Path, "settings.json");

    [Fact]
    public void First_start_generates_a_uuid_v4_key_and_defaults()
    {
        var settings = NewStore().Store.Initialize();

        Assert.True(StationSettingsRules.IsValidStationKey(settings.StationKey));
        Assert.Equal(new StationSettings(settings.StationKey, null, null, 32, "cp850", 4), settings);
        var onDisk = JsonNode.Parse(File.ReadAllText(SettingsPath))!.AsObject();
        Assert.Equal(settings.StationKey, (string?)onDisk["station_key"]);
        Assert.Equal(6, onDisk.Count);
    }

    [Fact]
    public void Station_key_is_stable_across_restarts()
    {
        var first = NewStore().Store.Initialize();
        var afterRestart = NewStore().Store.Initialize();
        var afterSecondRestart = NewStore().Store.Initialize();

        Assert.Equal(first.StationKey, afterRestart.StationKey);
        Assert.Equal(first.StationKey, afterSecondRestart.StationKey);
    }

    [Fact]
    public void Corrupt_file_is_set_aside_not_overwritten_and_regenerated_with_an_error_event()
    {
        File.WriteAllText(SettingsPath, "{ esto no es json");
        var (store, diagnostics) = NewStore();

        var settings = store.Initialize();

        var corrupt = Directory.GetFiles(_data.Path, "settings.json.corrupt-*");
        Assert.Single(corrupt);
        Assert.Equal("{ esto no es json", File.ReadAllText(corrupt[0]));
        Assert.True(StationSettingsRules.IsValidStationKey(settings.StationKey));
        Assert.Equal(settings, NewStore().Store.Initialize());
        var evt = Assert.Single(diagnostics.Snapshot(), e => e.Event == "settings_corrupt");
        Assert.Equal("error", evt.Level);
        Assert.Contains("NUEVA", evt.Message);
    }

    [Fact]
    public void Invalid_values_with_a_valid_key_keep_the_key_and_reset_the_rest()
    {
        var key = Guid.NewGuid().ToString("D");
        File.WriteAllText(SettingsPath,
            $$"""{"station_key":"{{key}}","station_name":"Recepción","printer_name":"POS-58","columns":40,"codepage":"cp850","feed_lines":4}""");
        var (store, diagnostics) = NewStore();

        var settings = store.Initialize();

        Assert.Equal(StationSettings.CreateNew(key), settings);
        Assert.Single(Directory.GetFiles(_data.Path, "settings.json.corrupt-*"));
        Assert.Contains(diagnostics.Snapshot(), e => e.Event == "settings_corrupt" && e.Message.Contains("conservando"));
    }

    [Fact]
    public void Key_that_is_not_a_uuid_v4_is_treated_as_corrupt()
    {
        File.WriteAllText(SettingsPath,
            """{"station_key":"no-es-uuid","station_name":null,"printer_name":null,"columns":32,"codepage":"cp850","feed_lines":4}""");

        var settings = NewStore().Store.Initialize();

        Assert.NotEqual("no-es-uuid", settings.StationKey);
        Assert.True(StationSettingsRules.IsValidStationKey(settings.StationKey));
    }

    [Fact]
    public void Apply_persists_atomically_and_keeps_the_key()
    {
        var (store, _) = NewStore();
        var original = store.Initialize();

        var applied = store.Apply(new ConfigureSettings("Recepción", "POS-58", 42, "cp437", 2));

        Assert.Equal(new StationSettings(original.StationKey, "Recepción", "POS-58", 42, "cp437", 2), applied);
        Assert.Equal(applied, store.Current);
        Assert.Equal(applied, NewStore().Store.Initialize());
        Assert.Empty(Directory.GetFiles(_data.Path, "*.tmp"));
    }

    [Theory]
    [InlineData("9e8efd16-b480-40de-89bb-9289bb2ae80b", true)]
    [InlineData("9E8EFD16-B480-40DE-89BB-9289BB2AE80B", false)] // forma canónica en minúsculas
    [InlineData("9e8efd16-b480-10de-89bb-9289bb2ae80b", false)] // versión 1
    [InlineData("9e8efd16-b480-40de-c9bb-9289bb2ae80b", false)] // variante no RFC
    [InlineData("9e8efd16b48040de89bb9289bb2ae80b", false)]
    [InlineData("", false)]
    public void Station_key_must_be_a_canonical_uuid_v4(string key, bool expected)
    {
        Assert.Equal(expected, StationSettingsRules.IsValidStationKey(key));
    }
}
