@echo off
rem Poseidon en ligne de commande sous Windows : poseidon.bat search C:\Docs "contrat preavis"
setlocal
chcp 65001 >nul
set "PHP=%POSEIDON_PHP%"
if "%PHP%"=="" if exist "%~dp0..\php\php.exe" set "PHP=%~dp0..\php\php.exe"
if "%PHP%"=="" set "PHP=php"
"%PHP%" "%~dp0poseidon" %*
exit /b %ERRORLEVEL%
