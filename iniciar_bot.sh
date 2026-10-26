#!/bin/bash
# Bot de WhatsApp - Sistema de Peticiones Ciudadanas
cd "$(dirname "$0")"

if ! command -v node >/dev/null 2>&1; then
    echo "============================================================"
    echo "  No se encontro Node.js instalado en este equipo."
    echo "============================================================"
    echo ""
    echo "Descargalo desde: https://nodejs.org/"
    echo ""
    read -p "Presiona Enter para salir..."
    exit 1
fi

if [ ! -d "node_modules" ]; then
    echo "Instalando dependencias del bot por primera vez, un momento..."
    npm install
fi

echo "============================================================"
echo "  Bot de WhatsApp - Sistema de Peticiones Ciudadanas"
echo "  La primera vez va a aparecer un codigo QR aqui abajo:"
echo "  escanealo desde WhatsApp en tu telefono"
echo "  (Dispositivos vinculados - Vincular un dispositivo)"
echo "============================================================"
echo ""

npm start
