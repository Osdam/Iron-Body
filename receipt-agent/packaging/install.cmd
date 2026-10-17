@echo off
rem Iron Body - instala o actualiza el servicio de impresion de recibos.
rem Doble clic, o desde una consola: install.cmd [-ServiceAccount LocalSystem] [-NoPause]
rem install.ps1 pide permisos de administrador (UAC) si hacen falta.
setlocal
"%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" %*
exit /b %ERRORLEVEL%
