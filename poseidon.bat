@echo off
rem ==========================================================================
rem  Poseidon - lanceur Windows de l'interface web
rem  Double-clic : demarre le serveur et ouvre le navigateur.
rem  Options : poseidon.bat [port]          (defaut : 8080)
rem  PHP : utilise %%POSEIDON_PHP%%, sinon php\php.exe a cote de ce fichier,
rem        sinon le php du PATH.
rem ==========================================================================
setlocal
chcp 65001 >nul
cd /d "%~dp0"

set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"

set "PHP=%POSEIDON_PHP%"
if "%PHP%"=="" if exist "%~dp0php\php.exe" set "PHP=%~dp0php\php.exe"
if "%PHP%"=="" set "PHP=php"

"%PHP%" -v >nul 2>&1
if errorlevel 1 (
  echo [Poseidon] PHP introuvable.
  echo   Installe PHP 8.2 ou plus recent ^(https://windows.php.net/download^),
  echo   ajoute-le au PATH, ou place-le dans le dossier "php" a cote de ce fichier.
  pause
  exit /b 1
)

"%PHP%" -r "exit(extension_loaded('zip') && extension_loaded('mbstring') ? 0 : 1);"
if errorlevel 1 (
  echo [Poseidon] Les extensions PHP "zip" et "mbstring" sont requises.
  echo   Active-les dans php.ini : extension=zip et extension=mbstring
  pause
  exit /b 1
)

echo [Poseidon] Interface sur http://127.0.0.1:%PORT%  ^(Ctrl+C pour arreter^)
start "" "http://127.0.0.1:%PORT%"
"%PHP%" "%~dp0bin\poseidon" serve --port=%PORT%
endlocal
