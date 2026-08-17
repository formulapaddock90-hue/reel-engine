@echo off
title F1 Reel Generator - FormulaPaddock
color 0C

echo.
echo  ===============================================
echo   ___  _   ____  ____  ___  _     ____
echo  ^|  _^|^/ ^| ^|  _ \^| ___^|^| __^|^| ^|   ^| __ ^|
echo  ^| ^|_  ^| ^| ^| ^|_^) ^) _^|  ^| _^|  ^| ^|   ^|  _^|
echo  ^|___^|^|_^| ^|_^|__/^|___^|^|___^|^|___^|^|_^|
echo.
echo   F1 REEL GENERATOR - formulapaddock.it
echo  ===============================================
echo.

:: Vai nella cartella dello script
cd /d "%~dp0"

:: 1. Trova ed esporta percorsi Node.js
if exist "C:\Program Files\nodejs" set "PATH=C:\Program Files\nodejs;%PATH%"
if exist "%LOCALAPPDATA%\Programs\nodejs" set "PATH=%LOCALAPPDATA%\Programs\nodejs;%PATH%"

:: 2. Trova ed esporta percorsi FFmpeg da WinGet o standard
for /d %%i in ("%LOCALAPPDATA%\Microsoft\WinGet\Packages\Gyan.FFmpeg*") do (
    if exist "%%i\ffmpeg-*\bin" set "PATH=%%i\ffmpeg-*\bin;%PATH%"
    if exist "%%i\bin" set "PATH=%%i\bin;%PATH%"
)
if exist "C:\ffmpeg\bin" set "PATH=C:\ffmpeg\bin;%PATH%"

:: Verifica Node.js
where node >nul 2>&1
if errorlevel 1 (
    echo [ERRORE] Node.js non trovato!
    echo Scarica e installa Node.js da: https://nodejs.org
    echo.
    pause
    exit /b 1
)
echo [OK] Node.js trovato

:: Verifica FFmpeg
where ffmpeg >nul 2>&1
if errorlevel 1 (
    echo [ATTENZIONE] FFmpeg non trovato direttamente nel PATH, ma il server cerchera' nei percorsi noti.
) else (
    echo [OK] FFmpeg trovato
)

:: Crea cartelle necessarie
if not exist "music" mkdir music
if not exist "output" mkdir output
if not exist "temp" mkdir temp
if not exist "public" mkdir public
echo [OK] Cartelle verificate

:: Installa dipendenze se mancanti
if not exist "node_modules" (
    echo.
    echo [INFO] Prima installazione - download dipendenze npm...
    npm install
    if errorlevel 1 (
        echo [ERRORE] npm install fallito!
        pause
        exit /b 1
    )
    echo [OK] Dipendenze installate
) else (
    echo [OK] Dipendenze gia' presenti
)

:: Avvio browser in automatico
echo.
echo [INFO] Apertura browser su http://localhost:3000 ...
start "" "http://localhost:3000"

:: Avvio server
echo.
echo  ===============================================
echo   Server in ascolto su: http://localhost:3000
echo   Premi CTRL+C per fermare il server
echo  ===============================================
echo.

node server.js

pause
