#Requires -Version 5.1
<#
.SYNOPSIS
    Instala o actualiza el servicio de impresión de recibos de Iron Body (IronBodyReceiptPrinter).

.DESCRIPTION
    Copia el ejecutable a Program Files (comprobando su SHA-256), escribe appsettings.json, prepara la
    carpeta de datos en ProgramData con sus permisos, crea (o actualiza) el servicio de Windows y
    comprueba que responde en http://127.0.0.1:<puerto>/.

    Es idempotente: ejecutarlo otra vez actualiza la instalación y CONSERVA settings.json (la clave de
    estación con la que el CRM conoce este equipo).

.PARAMETER InstallDir
    Carpeta del programa, SIEMPRE dentro de Program Files. Por defecto C:\Program Files\IronBody\ReceiptPrinter.
    Fuera de Program Files (por ejemplo en D:\) la carpeta heredaría permisos que dejan a cualquier
    usuario del equipo cambiar el ejecutable del servicio: el instalador lo rechaza.

.PARAMETER Port
    Puerto local (solo 127.0.0.1). El CRM usa 17888: cámbialo solo si el CRM también se cambia.

.PARAMETER ApiBaseUrl
    API de Iron Body. Debe ser https:// (http:// solo contra localhost para pruebas).

.PARAMETER AllowedOrigin
    Orígenes del CRM autorizados a usar el servicio, separados por comas. Por defecto los dos por los
    que se sirve el CRM: https://ironbodyneiva.cloud y https://www.ironbodyneiva.cloud (son orígenes
    distintos para el navegador). Coincidencia exacta, sin comodines. https:// obligatorio
    (http:// solo con localhost o 127.0.0.1, para desarrollo).

.PARAMETER ServiceAccount
    LocalService (por defecto, mínimo privilegio) o LocalSystem (solo si el driver de la impresora lo exige).

.PARAMETER NoPause
    No espera una tecla al terminar (para instalaciones desatendidas).

.EXAMPLE
    .\install.ps1
.EXAMPLE
    .\install.ps1 -ServiceAccount LocalSystem
#>
[CmdletBinding()]
param(
    [string] $InstallDir = (Join-Path $env:ProgramFiles 'IronBody\ReceiptPrinter'),
    [ValidateRange(1024, 65535)]
    [int] $Port = 17888,
    [string] $ApiBaseUrl = 'https://api.ironbodyneiva.cloud',
    [string] $AllowedOrigin = 'https://ironbodyneiva.cloud,https://www.ironbodyneiva.cloud',
    [ValidateSet('LocalService', 'LocalSystem')]
    [string] $ServiceAccount = 'LocalService',
    [switch] $NoPause
)

Set-StrictMode -Version 3.0
$ErrorActionPreference = 'Stop'

$ServiceName = 'IronBodyReceiptPrinter'
$DisplayName = 'Iron Body · Impresora de recibos'
$Description = 'Imprime en la impresora térmica los recibos autorizados por el CRM de Iron Body (texto ESC/POS en RAW). Solo escucha en 127.0.0.1. No toca cobros.'
$ExeName = 'IronBody.ReceiptPrinter.exe'
$ProcessName = 'IronBody.ReceiptPrinter'
$EventSource = 'IronBodyReceiptPrinter'
$DataRoot = Join-Path $env:ProgramData 'IronBody'
$DataDir = Join-Path $DataRoot 'ReceiptPrinter'
$LogDir = Join-Path $DataDir 'logs'

# SIDs y no nombres: en un Windows en español el grupo se llama «Administradores», no «Administrators».
$SidSystem = 'S-1-5-18'
$SidAdministrators = 'S-1-5-32-544'
$SidUsers = 'S-1-5-32-545'
$SidLocalService = 'S-1-5-19'
$SidTrustedInstaller = 'S-1-5-80-956008885-3418522649-1831038044-1853292631-2271478464'
# Propietarios aceptables en ProgramData\IronBody: los del sistema y la cuenta del servicio (los
# archivos que escribe el servicio, settings.json y logs, son suyos). Cualquier otro = lo creó otra cuenta.
$TrustedOwnerSids = @($SidSystem, $SidAdministrators, $SidTrustedInstaller, $SidLocalService)

# SHA-256 de los archivos que ejecutan código, incrustados por build-windows.sh al generar el paquete.
# Se comprueban sobre la copia ya protegida en la carpeta del programa, no sobre la carpeta extraída.
$PackageHashes = [ordered]@{
    'IronBody.ReceiptPrinter.exe' = '@@SHA256:IronBody.ReceiptPrinter.exe@@'
    'uninstall.ps1'               = '@@SHA256:uninstall.ps1@@'
    'uninstall.cmd'               = '@@SHA256:uninstall.cmd@@'
}
# Archivos del paquete. Unblock-File solo toca estos; los de la segunda lista se copian sin comprobar
# su hash porque no ejecutan nada.
$PackageFiles = @('IronBody.ReceiptPrinter.exe', 'appsettings.json', 'VERSION.txt', 'install.ps1', 'install.cmd', 'uninstall.ps1', 'uninstall.cmd',
    'INSTALACION.md', 'DESINSTALACION.md', 'ROLLBACK.md', 'SOLUCION_DE_PROBLEMAS.md', 'PRUEBAS_FISICAS.md')
$DocumentFiles = @('VERSION.txt', 'INSTALACION.md', 'DESINSTALACION.md', 'ROLLBACK.md', 'SOLUCION_DE_PROBLEMAS.md', 'PRUEBAS_FISICAS.md')

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

# Sin permisos de administrador no se puede crear un servicio: se relanza elevado (UAC) y esta
# ventana solo espera el resultado; el resumen se ve en la ventana elevada.
if (-not (Test-Administrator)) {
    Write-Host 'Se necesitan permisos de administrador. Windows pedirá confirmación (UAC)...' -ForegroundColor Yellow
    $relaunch = @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', ('"{0}"' -f $PSCommandPath),
        '-InstallDir', ('"{0}"' -f $InstallDir), '-Port', $Port,
        '-ApiBaseUrl', ('"{0}"' -f $ApiBaseUrl), '-AllowedOrigin', ('"{0}"' -f $AllowedOrigin),
        '-ServiceAccount', $ServiceAccount
    )
    if ($NoPause) { $relaunch += '-NoPause' }
    try {
        $child = Start-Process -FilePath 'powershell.exe' -ArgumentList $relaunch -Verb RunAs -Wait -PassThru
        exit $child.ExitCode
    }
    catch {
        Write-Host "No se pudo obtener permisos de administrador: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host 'Haz clic derecho sobre install.cmd y elige «Ejecutar como administrador».'
        Wait-Key
        exit 1
    }
}

function Invoke-Sc {
    param([Parameter(Mandatory)] [string[]] $Arguments)
    $output = & sc.exe @Arguments 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) {
        throw "sc.exe $($Arguments -join ' ') falló (código $LASTEXITCODE): $($output.Trim())"
    }
    Write-Host "  sc.exe $($Arguments[0]): correcto"
}

function Test-ApiBaseUrl([string] $Value) {
    $uri = $null
    if (-not [Uri]::TryCreate($Value, [UriKind]::Absolute, [ref] $uri)) { return $false }
    if ($uri.Scheme -eq 'https') { return $true }
    return ($uri.Scheme -eq 'http' -and ($uri.Host -eq 'localhost' -or $uri.Host -eq '127.0.0.1'))
}

function Test-Origin([string] $Value) {
    $uri = $null
    if (-not [Uri]::TryCreate($Value, [UriKind]::Absolute, [ref] $uri)) { return $false }
    # https obligatorio: una página http:// la suplanta cualquiera en la red del cajero. http:// solo
    # para el CRM de desarrollo en el propio equipo (lo mismo que exige el servicio al arrancar).
    $isLocalHost = ($uri.Host -eq 'localhost' -or $uri.Host -eq '127.0.0.1')
    if ($uri.Scheme -ne 'https' -and -not ($uri.Scheme -eq 'http' -and $isLocalHost)) { return $false }
    # Comparación exacta con la forma que manda el navegador: sin barra final, ruta ni mayúsculas.
    return ($uri.GetLeftPart([UriPartial]::Authority) -ceq $Value)
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

function Get-OwnerSid([string] $Path) {
    # $null si no se puede leer (otra cuenta pudo dejar un permiso que bloquea a los administradores).
    try {
        return (Get-Acl -LiteralPath $Path).GetOwner([Security.Principal.SecurityIdentifier]).Value
    }
    catch {
        return $null
    }
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

function Assert-NotLink([string] $Path) {
    # Una carpeta que existe tiene que ser una carpeta de verdad, no un enlace a otro sitio.
    $attributes = Get-PathAttributes $Path
    if ($null -eq $attributes) { return $false }
    if (Test-Attribute $attributes ([IO.FileAttributes]::ReparsePoint)) {
        throw "$Path es un enlace (unión o vínculo simbólico) a otra carpeta. Por seguridad no se instala: el instalador, como administrador, escribiría o cambiaría permisos en el destino del enlace. Bórralo sin seguirlo (rmdir ""$Path"") y vuelve a instalar."
    }
    if (-not (Test-Attribute $attributes ([IO.FileAttributes]::Directory))) {
        throw "$Path existe pero no es una carpeta. Revísalo, bórralo y vuelve a instalar."
    }
    return $true
}

function Invoke-Icacls {
    param([Parameter(Mandatory)] [string[]] $Arguments)
    $output = & icacls.exe @Arguments 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) {
        throw "icacls $($Arguments -join ' ') falló (código $LASTEXITCODE): $($output.Trim())"
    }
}

function Set-ProtectedAcl {
    # Propietario Administradores y DACL explícita, sin herencia, con SOLO estas concesiones. /L en
    # todas las llamadas: icacls actúa sobre el propio elemento y nunca sigue un enlace.
    param([Parameter(Mandatory)] [string] $Path, [Parameter(Mandatory)] [string[]] $Grants)
    Invoke-Icacls @($Path, '/setowner', "*$SidAdministrators", '/L', '/Q')
    $arguments = @($Path, '/inheritance:r')
    foreach ($grant in $Grants) { $arguments += @('/grant:r', $grant) }
    Invoke-Icacls ($arguments + @('/L', '/Q'))

    # /grant:r solo sustituye las entradas de esos SIDs: se quita cualquier otra entrada explícita
    # (por ejemplo la que dejó la cuenta que creó la carpeta, o la de LocalService al pasar a LocalSystem).
    $allowed = @($Grants | ForEach-Object { $_.Substring(1, $_.IndexOf(':') - 1) })
    $acl = Get-Acl -LiteralPath $Path
    foreach ($rule in @($acl.GetAccessRules($true, $false, [Security.Principal.SecurityIdentifier]))) {
        $sid = $rule.IdentityReference.Value
        if ($allowed -notcontains $sid) {
            Invoke-Icacls @($Path, '/remove', "*$sid", '/L', '/Q')
        }
    }

    $acl = Get-Acl -LiteralPath $Path
    $owner = $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value
    $foreign = @($acl.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]) |
        Where-Object { $_.IsInherited -or ($allowed -notcontains $_.IdentityReference.Value) })
    if (-not $acl.AreAccessRulesProtected -or $owner -ne $SidAdministrators -or $foreign.Count -gt 0) {
        throw "Los permisos de $Path no quedaron como se esperaba (propietario $owner, herencia desactivada: $($acl.AreAccessRulesProtected), entradas de más: $($foreign.Count))."
    }
}

function Initialize-DataDirectory {
    # ProgramData deja a cualquier usuario crear carpetas, y quien crea una tiene control total sobre
    # ella. Si otra cuenta creó C:\ProgramData\IronBody antes de instalar, podría haber dejado enlaces
    # para que este instalador (administrador) escriba o cambie permisos fuera, o conservar el control
    # de la carpeta después. Antes de escribir NADA aquí se revisa el árbol sin seguir enlaces.
    param([Parameter(Mandatory)] [string[]] $DataDirGrants)
    $rootGrants = @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-32-545:(OI)(CI)RX')

    $rootExists = Assert-NotLink $DataRoot
    [void] (Assert-NotLink $DataDir)
    if ($rootExists) {
        $report = Get-DataTreeReport -Root $DataRoot -OwnerOf ${function:Get-OwnerSid} -TrustedOwners $TrustedOwnerSids
        if ($report.ReparsePoints.Count -gt 0) {
            throw "Hay enlaces (uniones o vínculos simbólicos) dentro de $($DataRoot): $($report.ReparsePoints -join '; '). Por seguridad no se instala: alguien pudo prepararlos para que el instalador escriba o cambie permisos fuera de esa carpeta. Bórralos sin seguirlos (rmdir ""<enlace>"") y vuelve a instalar."
        }
        if ($report.Unreadable.Count -gt 0) {
            throw "No se puede revisar el contenido de $DataRoot ($($report.Unreadable -join '; ')): otra cuenta dejó permisos que bloquean a los administradores. Toma posesión y bórralo a mano (takeown /F ""$DataRoot"" /A /R /D S; rmdir /s /q ""$DataRoot""; la clave de estación se perderá) y vuelve a instalar."
        }
        if ($report.Untrusted.Count -gt 0) {
            # No se adopta: rehacer los permisos no cierra lo que esa cuenta ya tuviera abierto, y en una
            # instalación normal esta carpeta solo la crean el instalador y el propio servicio.
            throw "Elementos de $DataRoot creados por otra cuenta: $($report.Untrusted -join '; '). Por seguridad no se instala sobre ellos. Toma posesión y bórralo a mano (takeown /F ""$DataRoot"" /A /R /D S; rmdir /s /q ""$DataRoot""; la clave de estación se perderá y habrá que registrar el equipo otra vez en el CRM) y vuelve a instalar."
        }
    }
    else {
        # Sin -Force: si otra cuenta la crea justo ahora, falla en vez de usar la suya.
        New-Item -ItemType Directory -Path $DataRoot | Out-Null
    }

    Set-ProtectedAcl -Path $DataRoot -Grants $rootGrants

    if (-not (Assert-NotLink $DataDir)) { New-Item -ItemType Directory -Path $DataDir | Out-Null }
    Set-ProtectedAcl -Path $DataDir -Grants $DataDirGrants
    if (-not (Assert-NotLink $LogDir)) { New-Item -ItemType Directory -Path $LogDir | Out-Null }

    # Comprobación final: nada cambió entre la revisión y los permisos nuevos.
    [void] (Assert-NotLink $DataRoot)
    $after = Get-DataTreeReport -Root $DataRoot -OwnerOf ${function:Get-OwnerSid} -TrustedOwners $TrustedOwnerSids
    if ($after.ReparsePoints.Count + $after.Unreadable.Count + $after.Untrusted.Count -gt 0) {
        throw "$DataRoot cambió mientras se instalaba ($(@($after.ReparsePoints) + @($after.Unreadable) + @($after.Untrusted) -join '; ')). Por seguridad se detiene la instalación."
    }
}

function Test-PackageHash([string] $Path, [string] $Expected) {
    return ((Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash -eq $Expected)
}

function Move-StagedFile([string] $Staged, [string] $Final) {
    if (Test-Path -LiteralPath $Final -PathType Leaf) {
        # Sin copia de respaldo. PowerShell convierte $null en "" al llamar a .NET: hace falta NullString.
        [IO.File]::Replace($Staged, $Final, [System.Management.Automation.Language.NullString]::Value)
    }
    else {
        [IO.File]::Move($Staged, $Final)
    }
}

function Assert-QuotedServicePath([string] $Actual, [string] $Expected) {
    # Una ruta con espacios sin comillas deja que Windows pruebe C:\Program.exe antes que el servicio.
    if ($null -eq $Actual -or -not $Actual.StartsWith('"') -or $Actual -ne $Expected) {
        throw "La ruta del servicio quedó como '$Actual'; se esperaba '$Expected' (entre comillas)."
    }
}

function Get-NativeEmptyArgument {
    # Windows PowerShell 5.1 no pasa una cadena vacía a un ejecutable: hay que escribir "".
    $passing = Get-Variable -Name PSNativeCommandArgumentPassing -ValueOnly -ErrorAction SilentlyContinue
    if ($null -ne $passing -and $passing -ne 'Legacy') { return '' }
    return '""'
}

# El registro se escribe primero en %TEMP% del administrador (carpeta propia) y se copia a ProgramData
# al final, cuando esa carpeta ya tiene sus permisos: antes de revisarla no se escribe nada en ella.
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$InstallLog = Join-Path $env:TEMP ("IronBody-ReceiptPrinter-install-$stamp.log")
$FinalLog = Join-Path $LogDir ("install-$stamp.log")
Start-Transcript -Path $InstallLog | Out-Null
$exitCode = 0
$dataReady = $false
$stagedFiles = New-Object 'System.Collections.Generic.List[string]'

try {
    Write-Host '== Iron Body · instalación del servicio de impresión de recibos ==' -ForegroundColor Cyan

    # 1. Validación de parámetros y del paquete (el servicio también valida al arrancar).
    Write-Host '[1/9] Comprobando parámetros y paquete...'
    $InstallDir = Resolve-InstallDirectory -Path $InstallDir -ProgramFiles $env:ProgramFiles
    $origins = @($AllowedOrigin.Split(',') | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
    if (-not (Test-ApiBaseUrl $ApiBaseUrl)) {
        throw "ApiBaseUrl no válida: '$ApiBaseUrl'. Debe ser https:// (http:// solo con localhost o 127.0.0.1)."
    }
    if ($origins.Count -eq 0) { throw 'AllowedOrigin no puede estar vacío.' }
    foreach ($origin in $origins) {
        if (-not (Test-Origin $origin)) {
            throw "Origen no válido: '$origin'. Escríbelo como https://dominio, sin barra final ni ruta (http:// solo con localhost o 127.0.0.1)."
        }
    }
    foreach ($name in $PackageHashes.Keys) {
        if ($PackageHashes[$name] -notmatch '^[0-9a-f]{64}$') {
            throw 'Este install.ps1 no viene de un paquete generado por build-windows.sh (no lleva las sumas SHA-256 del programa). Usa el install.cmd del ZIP.'
        }
    }

    $source = $PSScriptRoot
    foreach ($name in $PackageHashes.Keys) {
        if (-not (Test-Path -LiteralPath (Join-Path $source $name) -PathType Leaf)) {
            throw "No se encuentra $name junto a install.ps1 ($source). Extrae el ZIP completo antes de instalar."
        }
    }
    $resolvedSource = (Resolve-Path -LiteralPath $source).Path.TrimEnd('\')
    if ((Test-Path -LiteralPath $InstallDir) -and ((Resolve-Path -LiteralPath $InstallDir).Path.TrimEnd('\') -eq $resolvedSource)) {
        throw 'Ejecuta install.ps1 desde la carpeta extraída del ZIP, no desde la carpeta de instalación.'
    }

    # 2. Archivos descargados de internet: Windows los marca y podría bloquear el exe. Solo los del
    #    paquete, no cualquier cosa que haya en la carpeta.
    Write-Host '[2/9] Desbloqueando los archivos del paquete...'
    foreach ($name in $PackageFiles) {
        $path = Join-Path $source $name
        if (Test-Path -LiteralPath $path -PathType Leaf) { Unblock-File -LiteralPath $path }
    }

    if ($ServiceAccount -eq 'LocalService') {
        $serviceObject = 'NT AUTHORITY\LocalService'
        $dataGrants = @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-19:(OI)(CI)M', '*S-1-5-32-545:(OI)(CI)RX')
        $programGrants = @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-19:(OI)(CI)RX', '*S-1-5-32-545:(OI)(CI)RX')
    }
    else {
        # SYSTEM ya tiene control total; la entrada de una instalación anterior con LocalService se quita.
        $serviceObject = 'LocalSystem'
        $dataGrants = @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-32-545:(OI)(CI)RX')
        $programGrants = @('*S-1-5-18:(OI)(CI)F', '*S-1-5-32-544:(OI)(CI)F', '*S-1-5-32-545:(OI)(CI)RX')
    }

    # 3. Datos (settings.json y logs): revisión sin seguir enlaces y permisos propios, sin heredar los
    #    de ProgramData (que dejan a cualquier usuario crear archivos).
    Write-Host "[3/9] Revisando y preparando $DataRoot y sus permisos..."
    Initialize-DataDirectory -DataDirGrants $dataGrants
    $dataReady = $true

    # 4. Carpeta del programa con permisos explícitos, y copia comprobada de lo que ejecuta código.
    #    Se copia como «.new» y se comprueba su SHA-256 YA dentro de la carpeta protegida: lo que se
    #    comprueba es lo que va a ejecutarse, y si no coincide el servicio actual sigue intacto.
    Write-Host "[4/9] Preparando $InstallDir y comprobando el programa..."
    if (Assert-NotLink $InstallDir) {
        $current = @(Get-ChildItem -LiteralPath $InstallDir -Force)
        if ($current.Count -gt 0 -and -not (Test-Path -LiteralPath (Join-Path $InstallDir $ExeName) -PathType Leaf)) {
            throw "$InstallDir ya existe y no es una instalación de este servicio (no contiene $ExeName). Elige otra carpeta: el instalador le pondría sus propios permisos."
        }
    }
    else {
        New-Item -ItemType Directory -Path $InstallDir | Out-Null
    }
    Set-ProtectedAcl -Path $InstallDir -Grants $programGrants

    foreach ($name in $PackageHashes.Keys) {
        $staged = Join-Path $InstallDir "$name.new"
        $stagedFiles.Add($staged)
        Copy-Item -LiteralPath (Join-Path $source $name) -Destination $staged -Force
        if (-not (Test-PackageHash $staged $PackageHashes[$name])) {
            throw "El SHA-256 de $name no coincide con el del paquete: el archivo está dañado o alguien lo cambió después de generar el ZIP. No se instala nada; descarga el ZIP de nuevo y compruébalo con SHA256SUMS.txt."
        }
    }
    Write-Host "  SHA-256 correcto: $(@($PackageHashes.Keys) -join ', ')"

    # 5. Actualización: se detiene el servicio existente (settings.json no se toca).
    $existing = Get-Service -Name $ServiceName -ErrorAction SilentlyContinue
    if ($null -ne $existing) {
        Write-Host "[5/9] El servicio ya existe (estado: $($existing.Status)); se actualiza."
        if ($existing.Status -ne 'Stopped') {
            Stop-Service -Name $ServiceName -Force
            $existing.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(30))
        }
        $running = Get-Process -Name $ProcessName -ErrorAction SilentlyContinue
        if ($null -ne $running) {
            $running | Wait-Process -Timeout 15 -ErrorAction SilentlyContinue
            if ($null -ne (Get-Process -Name $ProcessName -ErrorAction SilentlyContinue)) {
                throw "El proceso $ProcessName sigue en ejecución y bloquea el ejecutable. Ciérralo y vuelve a instalar."
            }
        }
    }
    else {
        Write-Host '[5/9] Instalación nueva.'
    }

    # 6. Programa: los archivos comprobados pasan a su nombre definitivo (mismo archivo, renombrado
    #    dentro de la carpeta protegida); la documentación se copia tal cual.
    Write-Host "[6/9] Copiando el programa a $InstallDir ..."
    foreach ($name in $PackageHashes.Keys) {
        $final = Join-Path $InstallDir $name
        Move-StagedFile (Join-Path $InstallDir "$name.new") $final
        if (-not (Test-PackageHash $final $PackageHashes[$name])) {
            throw "El SHA-256 de $final no coincide después de colocarlo. Vuelve a instalar desde un ZIP descargado de nuevo."
        }
    }
    foreach ($file in $DocumentFiles) {
        $path = Join-Path $source $file
        if (Test-Path -LiteralPath $path -PathType Leaf) {
            Copy-Item -LiteralPath $path -Destination $InstallDir -Force
        }
    }

    # 7. Configuración de instalación (siempre desde los parámetros) y origen del Visor de eventos
    #    (registrarlo exige administrador; el servicio no puede).
    Write-Host '[7/9] Escribiendo appsettings.json ...'
    $config = [ordered]@{
        ReceiptPrinter = [ordered]@{
            Port           = $Port
            ApiBaseUrl     = $ApiBaseUrl
            AllowedOrigins = $origins
            PrinterBackend = 'winspool'
        }
        Logging        = [ordered]@{
            LogLevel = [ordered]@{
                'Default'                    = 'Information'
                'Microsoft.AspNetCore'       = 'Warning'
                'System.Net.Http.HttpClient' = 'Warning'
            }
            EventLog = [ordered]@{ LogLevel = [ordered]@{ 'Default' = 'Warning' } }
        }
    }
    $json = $config | ConvertTo-Json -Depth 6
    [IO.File]::WriteAllText((Join-Path $InstallDir 'appsettings.json'), $json, (New-Object Text.UTF8Encoding($false)))

    if (-not [Diagnostics.EventLog]::SourceExists($EventSource)) {
        [Diagnostics.EventLog]::CreateEventSource($EventSource, 'Application')
        Write-Host "  Origen del Visor de eventos '$EventSource' registrado."
    }

    # 8. Servicio. La ruta se fija por WMI y no con «sc.exe config binPath=»: Windows PowerShell 5.1
    #    le quita las comillas al pasársela a sc.exe y la ruta (con espacios) quedaría sin comillas.
    Write-Host '[8/9] Configurando el servicio de Windows...'
    $exePath = Join-Path $InstallDir $ExeName
    $binPath = '"{0}"' -f $exePath
    if ($null -eq $existing) {
        New-Service -Name $ServiceName -BinaryPathName $binPath -DisplayName $DisplayName -Description $Description -StartupType Automatic | Out-Null
    }
    else {
        $service = Get-CimInstance -ClassName Win32_Service -Filter "Name='$ServiceName'"
        $change = Invoke-CimMethod -InputObject $service -MethodName Change -Arguments @{ PathName = $binPath }
        if ($change.ReturnValue -ne 0) {
            throw "Win32_Service.Change no pudo fijar la ruta del servicio (código $($change.ReturnValue))."
        }
        Set-Service -Name $ServiceName -DisplayName $DisplayName -Description $Description
    }

    # Inicio automático NO retrasado: un programa de inicio de sesión no gana la carrera por el
    # puerto 17888 (ver SOLUCION_DE_PROBLEMAS.md). La dependencia de Spooler mantiene el orden.
    $objArgs = @('config', $ServiceName, 'start=', 'auto', 'depend=', 'Spooler', 'obj=', $serviceObject)
    if ($ServiceAccount -eq 'LocalService') { $objArgs += @('password=', (Get-NativeEmptyArgument)) }
    Invoke-Sc $objArgs
    Invoke-Sc @('failure', $ServiceName, 'reset=', '86400', 'actions=', 'restart/5000/restart/10000/restart/60000')

    $wmi = Get-CimInstance -ClassName Win32_Service -Filter "Name='$ServiceName'"
    Assert-QuotedServicePath $wmi.PathName $binPath
    $delayedProperty = $wmi.CimInstanceProperties['DelayedAutoStart']
    $delayed = ($null -ne $delayedProperty -and $true -eq $delayedProperty.Value)
    Write-Host "  Ruta: $($wmi.PathName)"
    Write-Host "  Cuenta: $($wmi.StartName) · Inicio: $($wmi.StartMode) (retrasado: $delayed)"
    if ($delayed) {
        Write-Warning "Windows sigue marcando el inicio como retrasado. Ejecuta: sc.exe config $ServiceName start= auto"
    }

    # 9. Arranque y comprobación.
    Write-Host '[9/9] Iniciando el servicio...'
    Start-Service -Name $ServiceName
    (Get-Service -Name $ServiceName).WaitForStatus('Running', [TimeSpan]::FromSeconds(30))

    Write-Host "  Comprobando http://127.0.0.1:$Port/ ..."
    $client = New-Object Net.WebClient
    $client.Proxy = $null
    $client.Encoding = [Text.Encoding]::UTF8
    $banner = $null
    for ($i = 0; $i -lt 15 -and $null -eq $banner; $i++) {
        try {
            $banner = $client.DownloadString("http://127.0.0.1:$Port/")
        }
        catch {
            Start-Sleep -Seconds 1
        }
    }
    if ($null -eq $banner -or $banner -notlike '*en funcionamiento*') {
        throw "El servicio arrancó pero no responde en http://127.0.0.1:$Port/. Revisa $LogDir y el Visor de eventos (origen $EventSource)."
    }
    Write-Host "  $banner" -ForegroundColor Green

    $stationKey = '(no disponible)'
    $settingsPath = Join-Path $DataDir 'settings.json'
    if (Test-Path -LiteralPath $settingsPath) {
        $stationKey = (Get-Content -LiteralPath $settingsPath -Raw | ConvertFrom-Json).station_key
    }
    $version = '(desconocida)'
    $versionPath = Join-Path $InstallDir 'VERSION.txt'
    if (Test-Path -LiteralPath $versionPath) {
        $version = (Get-Content -LiteralPath $versionPath | Select-String -Pattern '^Versión' | Select-Object -First 1).Line
    }

    Write-Host ''
    Write-Host '== Instalación terminada ==' -ForegroundColor Green
    Write-Host "  $version"
    Write-Host "  Servicio:        $ServiceName ($((Get-Service -Name $ServiceName).Status)), cuenta $serviceObject, inicio automático"
    Write-Host "  Programa:        $InstallDir"
    Write-Host "  Datos y logs:    $DataDir"
    Write-Host "  Escucha en:      http://127.0.0.1:$Port/ (solo este equipo)"
    Write-Host "  API:             $ApiBaseUrl"
    Write-Host "  Origen CRM:      $($origins -join ', ')"
    Write-Host "  Clave estación:  $stationKey" -ForegroundColor Yellow
    Write-Host ''
    Write-Host 'Siguiente paso: en el CRM, registra esta estación con la clave de arriba y configura la impresora (ver INSTALACION.md).'
}
catch {
    $exitCode = 1
    Write-Host ''
    Write-Host "ERROR: $($_.Exception.Message)" -ForegroundColor Red
    # Deshace la copia a medias: los «.new» que no llegaron a su sitio no se quedan en Program Files.
    foreach ($staged in $stagedFiles) {
        if (Test-Path -LiteralPath $staged -PathType Leaf) {
            Remove-Item -LiteralPath $staged -Force -ErrorAction SilentlyContinue
            if (Test-Path -LiteralPath $staged) { Write-Warning "No se pudo borrar $staged; bórralo a mano." }
        }
    }
}
finally {
    Stop-Transcript | Out-Null
}

if ($dataReady) {
    try {
        Copy-Item -LiteralPath $InstallLog -Destination $FinalLog
        Remove-Item -LiteralPath $InstallLog -Force
        $InstallLog = $FinalLog
    }
    catch {
        Write-Warning "No se pudo copiar el registro a $($LogDir): $($_.Exception.Message)"
    }
}
Write-Host "Registro de la instalación: $InstallLog"

Wait-Key
exit $exitCode
