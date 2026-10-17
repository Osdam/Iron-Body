#Requires -Version 5.1
<#
.SYNOPSIS
    Desinstala el servicio de impresión de recibos de Iron Body (IronBodyReceiptPrinter).

.DESCRIPTION
    Detiene y elimina el servicio y borra la carpeta del programa. Por defecto CONSERVA
    %ProgramData%\IronBody\ReceiptPrinter (settings.json con la clave de estación, y los logs): una
    reinstalación posterior sigue siendo la misma estación para el CRM.

.PARAMETER InstallDir
    Carpeta del programa, dentro de Program Files. Por defecto C:\Program Files\IronBody\ReceiptPrinter.

.PARAMETER Purge
    Borra también los ajustes y los logs de ProgramData y el origen del Visor de eventos. Tras esto,
    una reinstalación genera una clave de estación NUEVA que hay que registrar otra vez en el CRM.

.PARAMETER NoPause
    No espera una tecla al terminar.
#>
[CmdletBinding()]
param(
    [string] $InstallDir = (Join-Path $env:ProgramFiles 'IronBody\ReceiptPrinter'),
    [switch] $Purge,
    [switch] $NoPause
)

Set-StrictMode -Version 3.0
$ErrorActionPreference = 'Stop'

$ServiceName = 'IronBodyReceiptPrinter'
$ProcessName = 'IronBody.ReceiptPrinter'
$EventSource = 'IronBodyReceiptPrinter'
$DataRoot = Join-Path $env:ProgramData 'IronBody'
$DataDir = Join-Path $DataRoot 'ReceiptPrinter'
$LogDir = Join-Path $DataDir 'logs'

function Test-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($identity)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Wait-Key {
    if (-not $NoPause) {
        Write-Host ''
        Read-Host 'Pulsa Enter para cerrar esta ventana' | Out-Null
    }
}

if (-not (Test-Administrator)) {
    Write-Host 'Se necesitan permisos de administrador. Windows pedirá confirmación (UAC)...' -ForegroundColor Yellow
    $relaunch = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"{0}"' -f $PSCommandPath), '-InstallDir', ('"{0}"' -f $InstallDir))
    if ($Purge) { $relaunch += '-Purge' }
    if ($NoPause) { $relaunch += '-NoPause' }
    try {
        $child = Start-Process -FilePath 'powershell.exe' -ArgumentList $relaunch -Verb RunAs -Wait -PassThru -WorkingDirectory $env:TEMP
        exit $child.ExitCode
    }
    catch {
        Write-Host "No se pudo obtener permisos de administrador: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host 'Haz clic derecho sobre uninstall.cmd y elige «Ejecutar como administrador».'
        Wait-Key
        exit 1
    }
}

function Resolve-InstallDirectory {
    # Solo dentro de Program Files (comprobación de texto, sin tocar el disco). Fuera, la carpeta
    # heredaría los permisos de su padre; en un disco secundario eso suele ser «Usuarios autenticados:
    # Modificar», y cualquier cuenta del PC podría cambiar el ejecutable que corre como servicio.
    param([Parameter(Mandatory)] [string] $Path, [Parameter(Mandatory)] [string] $ProgramFiles)
    $base = $ProgramFiles.TrimEnd('\')
    $candidate = $Path.TrimEnd('\')
    $problem = $null
    if ($base -notmatch '^[A-Za-z]:\\[^\\]') {
        $problem = "la carpeta Program Files de este equipo no es una ruta local ('$ProgramFiles')"
    }
    elseif ($candidate -notmatch '^[A-Za-z]:\\') {
        $problem = 'debe ser una ruta completa, por ejemplo C:\Program Files\IronBody\ReceiptPrinter'
    }
    elseif (-not $candidate.StartsWith($base + '\', [StringComparison]::OrdinalIgnoreCase)) {
        $problem = "debe estar dentro de $base"
    }
    else {
        foreach ($segment in $candidate.Substring($base.Length + 1).Split('\')) {
            # Sin «..», partes vacías, caracteres no válidos (/, :, *…) ni puntos/espacios finales,
            # que Windows reinterpreta: así el texto comprobado es la carpeta que se usará.
            if ($segment -notmatch '^[^\\/:*?"<>|]*[^\\/:*?"<>|. ]$') {
                $problem = "la parte '$segment' no es un nombre de carpeta válido"
                break
            }
        }
    }
    if ($null -ne $problem) {
        throw "InstallDir no válido ('$Path'): $problem. El programa solo se instala dentro de Program Files, cuyos permisos impiden que otros usuarios cambien el ejecutable del servicio."
    }
    return $candidate
}

function Get-PathAttributes([string] $Path) {
    # GetFileAttributes NO sigue enlaces: ve una unión (junction) o un vínculo simbólico aunque su
    # destino no exista. $null = no existe nada con ese nombre.
    try {
        return [IO.File]::GetAttributes($Path)
    }
    catch {
        $inner = $_.Exception
        while ($null -ne $inner.InnerException) { $inner = $inner.InnerException }
        if ($inner -is [IO.FileNotFoundException] -or $inner -is [IO.DirectoryNotFoundException]) { return $null }
        throw
    }
}

function Test-Attribute($Attributes, [IO.FileAttributes] $Flag) {
    return (([int] $Attributes -band [int] $Flag) -ne 0)
}

function Get-DataTreeReport {
    # Recorre la carpeta SIN entrar en uniones ni vínculos simbólicos (Get-ChildItem -Recurse de
    # Windows PowerShell 5.1 sí entra en ellos). Devuelve los enlaces encontrados, lo que no se pudo
    # leer y, si se pasa OwnerOf, lo que tiene un propietario fuera de TrustedOwners.
    param(
        [Parameter(Mandatory)] [string] $Root,
        [scriptblock] $OwnerOf,
        [string[]] $TrustedOwners = @()
    )
    $report = [pscustomobject]@{
        ReparsePoints = New-Object 'System.Collections.Generic.List[string]'
        Unreadable    = New-Object 'System.Collections.Generic.List[string]'
        Untrusted     = New-Object 'System.Collections.Generic.List[string]'
    }
    $entries = New-Object 'System.Collections.Generic.List[string]'
    $entries.Add($Root)
    $pending = New-Object 'System.Collections.Generic.Queue[string]'
    $pending.Enqueue($Root)
    while ($pending.Count -gt 0) {
        $directory = $pending.Dequeue()
        try {
            $children = (New-Object IO.DirectoryInfo $directory).GetFileSystemInfos()
        }
        catch {
            $report.Unreadable.Add($directory)
            continue
        }
        foreach ($child in $children) {
            if (Test-Attribute $child.Attributes ([IO.FileAttributes]::ReparsePoint)) {
                $report.ReparsePoints.Add($child.FullName)
                continue
            }
            $entries.Add($child.FullName)
            if (Test-Attribute $child.Attributes ([IO.FileAttributes]::Directory)) {
                $pending.Enqueue($child.FullName)
            }
        }
    }
    if ($null -ne $OwnerOf) {
        foreach ($entry in $entries) {
            $owner = & $OwnerOf $entry
            if ($null -eq $owner) { $report.Unreadable.Add($entry) }
            elseif ($TrustedOwners -notcontains $owner) { $report.Untrusted.Add("$entry (propietario $owner)") }
        }
    }
    return $report
}

function Test-SafeDataTree {
    # $true si ProgramData\IronBody\ReceiptPrinter existe, ni ella ni su padre son enlaces y no hay
    # enlaces dentro. Devuelve los problemas en $Problems para explicarlos.
    param([System.Collections.Generic.List[string]] $Problems)
    foreach ($path in @($DataRoot, $DataDir)) {
        $attributes = Get-PathAttributes $path
        if ($null -eq $attributes) { return $false }
        if (Test-Attribute $attributes ([IO.FileAttributes]::ReparsePoint)) {
            $Problems.Add("$path es un enlace (unión o vínculo simbólico)")
            return $false
        }
    }
    $report = Get-DataTreeReport -Root $DataDir
    foreach ($link in $report.ReparsePoints) { $Problems.Add("$link es un enlace (unión o vínculo simbólico)") }
    foreach ($unreadable in $report.Unreadable) { $Problems.Add("no se puede leer $unreadable") }
    return ($Problems.Count -eq 0)
}

# El registro va a %TEMP% del administrador; sin -Purge se copia al final a ProgramData si esa carpeta
# es segura (antes de revisarla no se escribe nada en ella).
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$UninstallLog = Join-Path $env:TEMP ("IronBody-ReceiptPrinter-uninstall-$stamp.log")
Start-Transcript -Path $UninstallLog | Out-Null
$exitCode = 0

# Puede estar ejecutándose desde la propia carpeta del programa: se sale de ella para poder borrarla.
Set-Location -LiteralPath $env:TEMP

try {
    Write-Host '== Iron Body · desinstalación del servicio de impresión de recibos ==' -ForegroundColor Cyan
    $InstallDir = Resolve-InstallDirectory -Path $InstallDir -ProgramFiles $env:ProgramFiles

    # Con -Purge se revisa ANTES de tocar nada: si hay enlaces, no se desinstala a medias.
    if ($Purge) {
        $problems = New-Object 'System.Collections.Generic.List[string]'
        if (-not (Test-SafeDataTree -Problems $problems) -and $problems.Count -gt 0) {
            throw "No se borra $DataDir porque $($problems -join '; '). Un enlace ahí podría llevar el borrado (como administrador) a otra carpeta. Revísalo, bórralo sin seguirlo (rmdir ""<enlace>"") y vuelve a ejecutar la desinstalación."
        }
    }

    $service = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($null -ne $service) {
        if ($service.Status -ne 'Stopped') {
            Write-Host '[1/4] Deteniendo el servicio...'
            Stop-Service -Name $ServiceName -Force
            $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
        }
        else {
            Write-Host '[1/4] El servicio ya estaba detenido.'
        }

        Write-Host '[2/4] Eliminando el servicio...'
        $output = & sc.exe delete $ServiceName 2>&1 | Out-String
        if ($LASTEXITCODE -ne 0) {
            throw "sc.exe delete falló (código $LASTEXITCODE): $($output.Trim())"
        }
        for ($i = 0; $i -lt 15 -and $null -ne (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue); $i++) {
            Start-Sleep -Seconds 1
        }
        if ($null -ne (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue)) {
            Write-Warning 'Windows marcó el servicio para eliminar, pero sigue listado (¿está abierta la consola de Servicios?). Desaparecerá al cerrarla o al reiniciar.'
        }
    }
    else {
        Write-Host '[1/4] El servicio no estaba instalado.'
        Write-Host '[2/4] Nada que eliminar.'
    }

    $running = Get-Process -Name $ProcessName -ErrorAction SilentlyContinue
    if ($null -ne $running) {
        $running | Wait-Process -Timeout 15 -ErrorAction SilentlyContinue
    }

    Write-Host "[3/4] Borrando $InstallDir ..."
    $programAttributes = Get-PathAttributes $InstallDir
    if ($null -ne $programAttributes) {
        if (Test-Attribute $programAttributes ([IO.FileAttributes]::ReparsePoint)) {
            throw "$InstallDir es un enlace (unión o vínculo simbólico); no se borra. Revísalo a mano."
        }
        # Solo se borra una carpeta que de verdad es la del servicio: un -InstallDir equivocado dentro
        # de Program Files no puede llevarse por delante otro programa.
        if (-not (Test-Path -LiteralPath (Join-Path $InstallDir 'IronBody.ReceiptPrinter.exe') -PathType Leaf)) {
            throw "$InstallDir no contiene IronBody.ReceiptPrinter.exe; no se borra. Revisa -InstallDir."
        }
        Remove-Item -LiteralPath $InstallDir -Recurse -Force
    }

    if ($Purge) {
        Write-Host "[4/4] Borrando ajustes y logs ($DataDir) y el origen del Visor de eventos..."
        if ($null -ne (Get-PathAttributes $DataDir)) {
            # Se vuelve a revisar justo antes de borrar, y se borra con «rmdir /s /q», que elimina las
            # uniones y vínculos simbólicos sin entrar en ellos (Remove-Item -Recurse de Windows
            # PowerShell 5.1 sí podría seguirlos y borrar el destino como administrador).
            $problems = New-Object 'System.Collections.Generic.List[string]'
            if (-not (Test-SafeDataTree -Problems $problems)) {
                throw "No se borra $DataDir porque $($problems -join '; ')."
            }
            $output = & cmd.exe /d /c rmdir /s /q $DataDir 2>&1 | Out-String
            if ($null -ne (Get-PathAttributes $DataDir)) {
                throw "No se pudo borrar $DataDir por completo: $($output.Trim())"
            }
        }
        if ([Diagnostics.EventLog]::SourceExists($EventSource)) {
            [Diagnostics.EventLog]::DeleteEventSource($EventSource)
        }
    }
    else {
        Write-Host "[4/4] Se conservan ajustes y logs en $DataDir (la clave de estación sigue valiendo si se reinstala)."
    }

    Write-Host ''
    Write-Host '== Desinstalación terminada ==' -ForegroundColor Green
    Write-Host 'El CRM de este equipo debe volver a su modo de impresión anterior (ver ROLLBACK.md).'
}
catch {
    $exitCode = 1
    Write-Host ''
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
}
finally {
    Stop-Transcript | Out-Null
}

if (-not $Purge) {
    # Solo a una carpeta de logs que siga siendo segura (sin enlaces en el camino).
    $problems = New-Object 'System.Collections.Generic.List[string]'
    $logAttributes = Get-PathAttributes $LogDir
    if ((Test-SafeDataTree -Problems $problems) -and $null -ne $logAttributes -and -not (Test-Attribute $logAttributes ([IO.FileAttributes]::ReparsePoint))) {
        try {
            $finalLog = Join-Path $LogDir ("uninstall-$stamp.log")
            Copy-Item -LiteralPath $UninstallLog -Destination $finalLog
            Remove-Item -LiteralPath $UninstallLog -Force
            $UninstallLog = $finalLog
        }
        catch {
            Write-Warning "No se pudo copiar el registro a $($LogDir): $($_.Exception.Message)"
        }
    }
}
Write-Host "Registro: $UninstallLog"

Wait-Key
exit $exitCode
