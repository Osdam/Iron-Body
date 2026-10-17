@echo off
rem Iron Body - desinstala el servicio de impresion de recibos.
rem Doble clic, o desde una consola: uninstall.cmd [-Purge] [-NoPause]
rem Por defecto conserva ajustes y logs; -Purge los borra tambien.
setlocal
rem Se sale de la carpeta del programa para que uninstall.ps1 pueda borrarla.
pushd "%TEMP%"
rem Todo en un bloque: cmd lo lee entero antes de ejecutarlo, asi no necesita volver a leer
rem este archivo despues de que uninstall.ps1 lo haya borrado.
(
  "%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0uninstall.ps1" %*
  exit /b
)
