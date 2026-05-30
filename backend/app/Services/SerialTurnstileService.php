<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Abre el torniquete enviando una cadena ASCII por un puerto COM (Windows).
 *
 * Replica exactamente lo que hace NetGymValidator: usa System.IO.Ports.SerialPort
 * vía PowerShell para escribir "PULSE 3000\r\n" en el COM4/COM5 conectado al
 * adaptador USB-Serial CH340, que lleva la señal por RS485 a la placa SATT.
 *
 * Sin dependencias PHP extra (no dio/no pecl) — invoca PowerShell que ya
 * viene con Windows y carga la misma clase .NET que NetGym.
 */
class SerialTurnstileService
{
    private const TIMEOUT_SECONDS = 15;

    /**
     * @return array{ok:bool, port:string, baud:int, command:string, error?:string, output?:string}
     */
    public function open(
        string $port,
        int $baud = 9600,
        string $command = 'PULSE 3000',
    ): array {
        // Validación defensiva — sólo aceptamos COMxx.
        if (! preg_match('/^COM\d{1,3}$/i', $port)) {
            return ['ok' => false, 'port' => $port, 'baud' => $baud, 'command' => $command,
                'error' => "Nombre de puerto inválido: {$port}"];
        }
        if ($baud < 300 || $baud > 921600) {
            return ['ok' => false, 'port' => $port, 'baud' => $baud, 'command' => $command,
                'error' => "Baud rate fuera de rango: {$baud}"];
        }

        // 1) Configura el puerto via "mode" desde PHP. Sin handshake hardware
        //    para evitar bloqueos cuando el otro extremo no devuelve CTS/DSR.
        $modeCmd = sprintf(
            'mode %s: BAUD=%d PARITY=n DATA=8 STOP=1 2>&1',
            $port,
            $baud,
        );
        @shell_exec($modeCmd);

        $tmp = tempnam(sys_get_temp_dir(), 'irbsr_') . '.ps1';
        try {
            file_put_contents($tmp, $this->buildPowershellScript($port, $baud, $command));

            // Usamos shell_exec con redirección de stderr — más estable en
            // Windows que proc_open con pipes (deadlock conocido en PHP/Win).
            $cmd = sprintf(
                'powershell.exe -NoProfile -NoLogo -NonInteractive -ExecutionPolicy Bypass -File %s < NUL 2>&1',
                escapeshellarg($tmp),
            );

            // shell_exec bloquea hasta que el proceso termine. PowerShell
            // tarda ~0.7s en correr este script. max_execution_time del PHP
            // hace de límite duro (default 30s, sobra).
            $output = (string) @shell_exec($cmd);
            $exitCode = 0; // shell_exec no devuelve exit code; lo deducimos del output

            $trim = trim($output);
            $ok = str_starts_with($trim, 'OK') || str_contains($trim, "\nOK");

            if (! $ok) {
                Log::warning('Serial open failed', [
                    'port' => $port, 'baud' => $baud, 'command' => $command,
                    'exit' => $exitCode, 'output' => $trim,
                ]);
            }

            return [
                'ok' => $ok,
                'port' => $port,
                'baud' => $baud,
                'command' => $command,
                'output' => substr($trim, 0, 480),
                'error' => $ok ? null : ($trim ?: "exit {$exitCode}"),
            ];
        } catch (Throwable $e) {
            Log::warning('Serial open exception', ['error' => $e->getMessage()]);
            return ['ok' => false, 'port' => $port, 'baud' => $baud, 'command' => $command,
                'error' => $e->getMessage()];
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Devuelve el script PowerShell que abre el COM con la API cruda de Win32
     * (CreateFile + WriteFile + CloseHandle) y configura los parámetros con el
     * comando "mode" antes de abrir.
     *
     * Por qué no `System.IO.Ports.SerialPort`: con muchos CH340 (clones chinos
     * de USB-Serial) la inicialización extra de la clase .NET falla con
     * "Uno de los dispositivos conectados al sistema no funciona", aún cuando
     * la API cruda del SO acepta el handle perfectamente. Replicamos lo que
     * hace el SDK nativo de Windows.
     */
    private function buildPowershellScript(string $port, int $baud, string $command): string
    {
        // Interpretamos secuencias \r \n \t literales del usuario.
        $payload = str_replace(['\\r', '\\n', '\\t'], ["\r", "\n", "\t"], $command);
        if (! str_ends_with($payload, "\n") && ! str_ends_with($payload, "\r")) {
            $payload .= "\r\n";
        }
        $hexPairs = implode(',', array_map(
            fn ($pair) => "0x{$pair}",
            str_split(strtoupper(bin2hex($payload)), 2),
        ));

        return <<<PS1
\$ErrorActionPreference = 'Stop'
\$h = [System.IntPtr]::Zero
\$exitCode = 0
try {
    # Win32 P/Invoke completo: CreateFile + SetCommState + SetCommTimeouts + WriteFile.
    # Reemplaza "mode" y .NET SerialPort. Esencial: timeouts cortos + sin flow
    # control para que WriteFile no bloquee si el otro extremo no responde.
    # PHP ya corrió "mode {$port}: BAUD={$baud} PARITY=n DATA=8 STOP=1" — el driver
    # tiene la config aplicada. Aquí sólo abrimos, forzamos timeouts no-bloqueantes
    # y escribimos los bytes.
    Add-Type -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
public static class IBSer {
    [StructLayout(LayoutKind.Sequential)]
    public struct CTO {
        public uint ReadIntervalTimeout;
        public uint ReadTotalTimeoutMultiplier;
        public uint ReadTotalTimeoutConstant;
        public uint WriteTotalTimeoutMultiplier;
        public uint WriteTotalTimeoutConstant;
    }
    [DllImport("kernel32.dll", SetLastError=true, CharSet=CharSet.Auto)]
    public static extern IntPtr CreateFile(string n, int a, int s, IntPtr sa, int cd, int fa, IntPtr h);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool CloseHandle(IntPtr h);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool WriteFile(IntPtr h, byte[] b, int n, out int w, IntPtr o);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool SetCommTimeouts(IntPtr h, ref CTO t);
    [DllImport("kernel32.dll", SetLastError=true)] public static extern bool PurgeComm(IntPtr h, uint f);
}
"@

    # CreateFile  GENERIC_RW=0xC0000000=-1073741824, share=0 (exclusivo en COM), OPEN_EXISTING=3.
    \$h = [IBSer]::CreateFile("\\\\.\\{$port}", -1073741824, 0, [IntPtr]::Zero, 3, 0, [IntPtr]::Zero)
    if (\$h.ToInt64() -le 0) {
        \$werr = [System.Runtime.InteropServices.Marshal]::GetLastWin32Error()
        throw "CreateFile fallo Win32=\$werr"
    }

    # Timeouts cortos. Sin esto WriteFile espera hasta que el otro extremo lea.
    \$to = New-Object IBSer+CTO
    \$to.ReadIntervalTimeout         = 0
    \$to.ReadTotalTimeoutMultiplier  = 0
    \$to.ReadTotalTimeoutConstant    = 0
    \$to.WriteTotalTimeoutMultiplier = 0
    \$to.WriteTotalTimeoutConstant   = 1000
    [void][IBSer]::SetCommTimeouts(\$h, [ref]\$to)

    [void][IBSer]::PurgeComm(\$h, 0x000F)

    \$bytes = [byte[]]({$hexPairs})
    \$written = 0
    \$ok = [IBSer]::WriteFile(\$h, \$bytes, \$bytes.Length, [ref] \$written, [IntPtr]::Zero)
    if (-not \$ok) {
        \$werr = [System.Runtime.InteropServices.Marshal]::GetLastWin32Error()
        throw "WriteFile fallo Win32=\$werr"
    }
    Write-Host ('OK bytes=' + \$written)
} catch {
    Write-Host ('ERROR: ' + \$_.Exception.Message)
    \$exitCode = 1
} finally {
    if (\$h -ne [IntPtr]::Zero -and \$h.ToInt64() -gt 0) {
        try { [void][IBSer]::CloseHandle(\$h) } catch {}
    }
}
exit \$exitCode
PS1;
    }

    /** Ejecuta un comando con timeout duro. */
    private function runWithTimeout(string $command, int $seconds, string &$output, int &$exitCode): void
    {
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($command, $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('No se pudo lanzar PowerShell.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $seconds;
        $buf = '';
        while (microtime(true) < $deadline) {
            $status = proc_get_status($proc);
            $buf .= stream_get_contents($pipes[1]) ?: '';
            $buf .= stream_get_contents($pipes[2]) ?: '';
            if (! $status['running']) {
                break;
            }
            usleep(50000); // 50 ms
        }

        $status = proc_get_status($proc);
        if ($status['running']) {
            // Timeout — matar.
            proc_terminate($proc, 9);
            $buf .= "\n[killed: timeout]";
        }

        $buf .= stream_get_contents($pipes[1]) ?: '';
        $buf .= stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($proc);

        $output = $buf;
    }
}
