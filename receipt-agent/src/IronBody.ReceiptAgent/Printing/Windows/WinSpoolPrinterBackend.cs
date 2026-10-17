using System.ComponentModel;
using System.Diagnostics;
using System.Runtime.InteropServices;
using System.Runtime.Versioning;
using IronBody.ReceiptAgent.Tickets;
using Microsoft.Extensions.Logging;
using static IronBody.ReceiptAgent.Printing.Windows.WinSpoolNative;

namespace IronBody.ReceiptAgent.Printing.Windows;

/// <summary>
/// Impresión RAW por el spooler de Windows (contrato §3.3). El texto ESC/POS va tal cual al puerto:
/// el driver no rasteriza nada, que es lo que llenaba los 32 KB de búfer de la DIG-58IIK.
/// Solo trabaja con impresoras locales (ver <see cref="LocalPrinterPolicy"/>).
/// </summary>
[SupportedOSPlatform("windows")]
public sealed class WinSpoolPrinterBackend : IPrinterBackend
{
    private readonly ILogger<WinSpoolPrinterBackend> _logger;

    public WinSpoolPrinterBackend(ILogger<WinSpoolPrinterBackend> logger)
    {
        _logger = logger;
    }

    public IReadOnlyList<PrinterInfo> ListPrinters()
    {
        var flags = PrinterEnumLocal;
        EnumPrinters(flags, null, 2, IntPtr.Zero, 0, out var needed, out _);
        if (needed == 0)
        {
            var error = Marshal.GetLastWin32Error();
            if (error is 0 or ErrorInsufficientBuffer)
            {
                return Array.Empty<PrinterInfo>();
            }

            throw new Win32Exception(error, "EnumPrintersW no pudo calcular el tamaño de la lista de impresoras.");
        }

        var buffer = Marshal.AllocHGlobal((int)needed);
        try
        {
            if (!EnumPrinters(flags, null, 2, buffer, needed, out _, out var returned))
            {
                throw new Win32Exception(Marshal.GetLastWin32Error(), "EnumPrintersW falló al listar las impresoras.");
            }

            var defaultName = GetDefaultPrinterName();
            var monitors = ReadPortMonitors();
            var size = Marshal.SizeOf<PrinterInfo2>();
            var printers = new List<PrinterInfo>((int)returned);
            for (var i = 0; i < returned; i++)
            {
                var info = Marshal.PtrToStructure<PrinterInfo2>(buffer + (i * size));
                printers.Add(ToPrinterInfo(info, defaultName, monitors));
            }

            return printers;
        }
        finally
        {
            Marshal.FreeHGlobal(buffer);
        }
    }

    /// <summary>PRINTER_INFO_2 de una impresora ya abierta (GetPrinterW nivel 2).</summary>
    private PrinterInfo ReadPrinterInfo(IntPtr handle, string name)
    {
        GetPrinter(handle, 2, IntPtr.Zero, 0, out var needed);
        if (needed == 0)
        {
            throw new Win32Exception(Marshal.GetLastWin32Error(), $"GetPrinterW no devolvió tamaño para «{name}».");
        }

        var buffer = Marshal.AllocHGlobal((int)needed);
        try
        {
            if (!GetPrinter(handle, 2, buffer, needed, out _))
            {
                throw new Win32Exception(Marshal.GetLastWin32Error(), $"GetPrinterW falló para «{name}».");
            }

            return ToPrinterInfo(Marshal.PtrToStructure<PrinterInfo2>(buffer), null, ReadPortMonitors());
        }
        finally
        {
            Marshal.FreeHGlobal(buffer);
        }
    }

    public async Task<SpoolResult> PrintAsync(string printerName, string documentName, byte[] data, CancellationToken cancellationToken)
    {
        // Segunda barrera (la primera es la resolución contra la lista local): OpenPrinterW con
        // «\\host\cola» ya conectaría con otro equipo.
        if (LocalPrinterPolicy.IsRemoteName(printerName))
        {
            return SpoolResult.Fail(ErrorCodes.PrinterNotFound);
        }

        if (!OpenPrinter(printerName, out var handle, IntPtr.Zero))
        {
            var error = Marshal.GetLastWin32Error();
            return error is ErrorInvalidPrinterName or ErrorFileNotFound
                ? SpoolResult.Fail(ErrorCodes.PrinterNotFound)
                : SpoolFailure("OpenPrinterW", error);
        }

        try
        {
            // La impresora pudo reconfigurarse entre la enumeración y ahora: se vuelve a mirar su
            // atributo de red y sus puertos justo antes de mandarle el recibo.
            var rejection = LocalPrinterPolicy.FindRejectionReason(ReadPrinterInfo(handle, printerName));
            if (rejection is not null)
            {
                _logger.LogError("Impresora «{Printer}» rechazada antes de imprimir: {Reason}", printerName, rejection);
                return SpoolResult.Fail(ErrorCodes.PrinterNotFound, CashierMessages.PrinterNotLocal(printerName));
            }

            var docInfo = new DocInfo1 { pDocName = documentName, pOutputFile = null, pDatatype = "RAW" };
            var jobId = StartDocPrinter(handle, 1, ref docInfo);
            if (jobId == 0)
            {
                var error = Marshal.GetLastWin32Error();
                if (error == ErrorInvalidDatatype)
                {
                    // El driver no admite RAW: no hay forma de imprimir texto ESC/POS por él.
                    return SpoolResult.Fail(ErrorCodes.SpoolerError, CashierMessages.RawNotSupported);
                }

                return SpoolFailure("StartDocPrinterW", error);
            }

            if (!StartPagePrinter(handle))
            {
                return AbortWith("StartPagePrinter", handle, (int)jobId);
            }

            if (!WriteAll(handle, data))
            {
                return AbortWith("WritePrinter", handle, (int)jobId);
            }

            if (!EndPagePrinter(handle))
            {
                return AbortWith("EndPagePrinter", handle, (int)jobId);
            }

            if (!EndDocPrinter(handle))
            {
                var error = Marshal.GetLastWin32Error();
                // El documento pudo quedar en la cola: se cancela para que no salga más tarde.
                CancelJob(handle, jobId);
                return SpoolFailure("EndDocPrinter", error, (int)jobId, data.Length);
            }

            var clock = Stopwatch.StartNew();
            var decision = await SpoolJobMonitor.TrackAsync(
                () => QueryJob(handle, jobId),
                () => clock.Elapsed,
                delay => Task.Delay(delay, cancellationToken),
                JobTracker.PollInterval,
                JobTracker.Timeout).ConfigureAwait(false);

            if (decision.Verdict == TrackVerdict.Accepted)
            {
                return SpoolResult.Ok((int)jobId, data.Length);
            }

            if (decision.Cancel)
            {
                CancelJob(handle, jobId);
            }

            return SpoolResult.Fail(decision.ErrorCode ?? ErrorCodes.PrinterError, null, (int)jobId, data.Length);
        }
        finally
        {
            ClosePrinter(handle);
        }
    }

    private static bool WriteAll(IntPtr handle, byte[] data)
    {
        var pin = GCHandle.Alloc(data, GCHandleType.Pinned);
        try
        {
            var start = pin.AddrOfPinnedObject();
            var offset = 0;
            while (offset < data.Length)
            {
                if (!WritePrinter(handle, start + offset, (uint)(data.Length - offset), out var written) || written == 0)
                {
                    return false;
                }

                offset += (int)written;
            }

            return true;
        }
        finally
        {
            pin.Free();
        }
    }

    private static JobObservation QueryJob(IntPtr handle, uint jobId)
    {
        GetJob(handle, jobId, 1, IntPtr.Zero, 0, out var needed);
        if (needed == 0)
        {
            var error = Marshal.GetLastWin32Error();
            // ERROR_INVALID_PARAMETER: el id ya no existe en la cola, el trabajo salió.
            return error == ErrorInvalidParameter ? JobObservation.Gone() : JobObservation.Failed(error);
        }

        var buffer = Marshal.AllocHGlobal((int)needed);
        try
        {
            if (!GetJob(handle, jobId, 1, buffer, needed, out _))
            {
                var error = Marshal.GetLastWin32Error();
                return error == ErrorInvalidParameter ? JobObservation.Gone() : JobObservation.Failed(error);
            }

            return JobObservation.Present(Marshal.PtrToStructure<JobInfo1>(buffer).Status);
        }
        finally
        {
            Marshal.FreeHGlobal(buffer);
        }
    }

    private void CancelJob(IntPtr handle, uint jobId)
    {
        if (!SetJob(handle, jobId, 0, IntPtr.Zero, JobControlDelete))
        {
            var error = Marshal.GetLastWin32Error();
            // Si ya no existe, no hay nada que cancelar; cualquier otro fallo se registra porque el
            // trabajo podría acabar saliendo tarde.
            if (error != ErrorInvalidParameter)
            {
                _logger.LogError("No se pudo cancelar el trabajo {JobId} de la cola: {Error}", jobId, new Win32Exception(error).Message);
            }
        }
    }

    private SpoolResult AbortWith(string step, IntPtr handle, int jobId)
    {
        var error = Marshal.GetLastWin32Error();
        if (!AbortPrinter(handle))
        {
            _logger.LogError("AbortPrinter falló tras {Step} en el trabajo {JobId}: {Error}",
                step, jobId, new Win32Exception(Marshal.GetLastWin32Error()).Message);
        }

        return SpoolFailure(step, error, jobId);
    }

    private SpoolResult SpoolFailure(string step, int error, int? jobId = null, int bytes = 0)
    {
        var detail = new Win32Exception(error).Message;
        _logger.LogError("{Step} falló: {Error} (código Windows {Code})", step, detail, error);
        return SpoolResult.Fail(ErrorCodes.SpoolerError,
            $"{CashierMessages.For(ErrorCodes.SpoolerError)} (Windows {error}: {detail})", jobId, bytes);
    }

    private static PrinterInfo ToPrinterInfo(PrinterInfo2 info, string? defaultName, IReadOnlyDictionary<string, string>? monitors)
    {
        var name = Marshal.PtrToStringUni(info.pPrinterName) ?? string.Empty;
        var ports = Marshal.PtrToStringUni(info.pPortName);
        return new PrinterInfo(
            name,
            Marshal.PtrToStringUni(info.pDriverName),
            ports,
            defaultName is not null && string.Equals(name, defaultName, StringComparison.OrdinalIgnoreCase),
            PrinterStatusMapper.Map(info.Status, info.Attributes),
            (info.Attributes & PrinterAttributeNetwork) != 0,
            LocalPrinterPolicy.NetworkPortsOf(ports, monitors));
    }

    /// <summary>
    /// «Puerto → monitor» de este equipo (EnumPortsW nivel 2). Si Windows no lo da, se registra y se
    /// devuelve null: siguen valiendo las comprobaciones por nombre de puerto. No se bloquea la impresión
    /// por no poder leer esta lista: dejaría sin recibos a la caja por un dato que es una segunda red.
    /// </summary>
    private IReadOnlyDictionary<string, string>? ReadPortMonitors()
    {
        try
        {
            EnumPorts(null, 2, IntPtr.Zero, 0, out var needed, out _);
            if (needed == 0)
            {
                _logger.LogWarning("EnumPortsW no devolvió tamaño (Windows {Code}); se clasifica la impresora solo por el nombre del puerto.", Marshal.GetLastWin32Error());
                return null;
            }

            var buffer = Marshal.AllocHGlobal((int)needed);
            try
            {
                if (!EnumPorts(null, 2, buffer, needed, out _, out var returned))
                {
                    _logger.LogWarning("EnumPortsW falló (Windows {Code}); se clasifica la impresora solo por el nombre del puerto.", Marshal.GetLastWin32Error());
                    return null;
                }

                var size = Marshal.SizeOf<PortInfo2>();
                var map = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
                for (var i = 0; i < returned; i++)
                {
                    var port = Marshal.PtrToStructure<PortInfo2>(buffer + (i * size));
                    var portName = Marshal.PtrToStringUni(port.pPortName);
                    if (!string.IsNullOrEmpty(portName))
                    {
                        map[portName] = Marshal.PtrToStringUni(port.pMonitorName) ?? string.Empty;
                    }
                }

                return map;
            }
            finally
            {
                Marshal.FreeHGlobal(buffer);
            }
        }
        catch (Exception ex) when (ex is not OutOfMemoryException)
        {
            _logger.LogWarning(ex, "No se pudo leer la lista de puertos de Windows; se clasifica la impresora solo por el nombre del puerto.");
            return null;
        }
    }

    /// <summary>
    /// Predeterminada del usuario del servicio. Con LocalService casi nunca hay una: es por usuario, no
    /// del equipo. Solo se usa para marcar <c>is_default</c>, así que su ausencia no es un error.
    /// </summary>
    private static string? GetDefaultPrinterName()
    {
        uint size = 0;
        GetDefaultPrinter(null, ref size);
        if (size == 0)
        {
            return null;
        }

        var buffer = new char[size];
        return GetDefaultPrinter(buffer, ref size) ? new string(buffer, 0, (int)Math.Max(0, size - 1)) : null;
    }
}
