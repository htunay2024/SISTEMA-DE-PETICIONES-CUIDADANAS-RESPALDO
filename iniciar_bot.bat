@echo off
title Bot de WhatsApp - Sistema de Peticiones
cd /d "%~dp0"

where node >nul 2>nul
if %errorlevel% neq 0 (
    echo ============================================================
    echo   No se encontro Node.js en este equipo.
    echo ============================================================
    echo.
    echo Descargalo (gratis, instalador simple) desde:
    echo   https://nodejs.org/
    echo Luego vuelve a ejecutar este archivo.
    echo.
    pause
    exit /b 1
)

if not exist "node_modules" (
    echo Instalando dependencias del bot por primera vez, un momento...
    call npm install
)

echo ============================================================
echo   Bot de WhatsApp - Sistema de Peticiones Ciudadanas
echo   La primera vez, va a aparecer un codigo QR en esta ventana:
echo   escanealo desde WhatsApp en tu telefono
echo   (Dispositivos vinculados - Vincular un dispositivo)
echo ============================================================
echo.

call npm start
pause
