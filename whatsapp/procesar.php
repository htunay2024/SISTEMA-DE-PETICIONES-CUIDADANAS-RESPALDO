<?php
/**
 * whatsapp/procesar.php
 * Procesa el mensaje entrante, usa la IA, crea ticket y prepara respuesta
 * 
 * Flujo:
 * 1. Recibe mensaje y remitente
 * 2. Detecta comandos especiales (estado, ayuda, etc.)
 * 3. Llama al cerebro IA para clasificar
 * 4. Crea o actualiza ticket según corresponda
 * 5. Genera respuesta personalizada
 * 
 * @version 1.1 — apunta a "IA 2/cerebro.php" (única carpeta de la IA)
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/IA 2/cerebro.php';
require_once dirname(__DIR__) . '/tickets/crear.php';
require_once dirname(__DIR__) . '/tickets/estado.php';

// Comandos especiales reconocidos por el sistema
$COMANDOS_ESPECIALES = [
    'ayuda' => 'ayuda',
    'menu' => 'menu',
    'hola' => 'saludo',
    'gracias' => 'agradecimiento',
    'estado' => 'estado',     // Se procesa con regex para extraer ID
    'status' => 'estado',
    'seguimiento' => 'estado'
];

/**
 * Procesa un mensaje de WhatsApp y genera respuesta
 * 
 * @param string $remitente Número de teléfono del ciudadano
 * @param string $mensaje   Texto del mensaje
 * @return string           Respuesta a enviar al ciudadano
 */
function procesarMensajeWhatsApp($remitente, $mensaje) {
    // Limpiar y normalizar mensaje
    $mensaje = trim($mensaje);
    $mensajeLower = mb_strtolower($mensaje, 'UTF-8');
    
    // Registrar mensaje entrante
    registrarLog("WhatsApp de $remitente: $mensaje", 'info', 'whatsapp_mensajes.log');
    
    // Verificar si es un comando de estado (ej: "estado #123" o "estado 123")
    if (preg_match('/^(estado|status|seguimiento)\s*#?(\d+)$/i', $mensajeLower, $matches)) {
        $ticketId = (int)$matches[2];
        return consultarEstadoTicketPorWhatsApp($remitente, $ticketId);
    }
    
    // Verificar comandos cortos (solo saludos básicos)
    $palabraInicial = strtok($mensajeLower, ' ');
    if ($palabraInicial === 'ayuda' || $palabraInicial === 'menu') {
        return generarMenuAyuda();
    }
    
    if (in_array($palabraInicial, ['hola', 'buenas', 'saludos', 'hey'])) {
        return obtenerRespuestaBienvenida();
    }
    
    if (in_array($palabraInicial, ['gracias', 'muchas gracias', 'thank you'])) {
        return obtenerRespuestaAgradecimiento();
    }
    
    // Si no es comando especial, procesar como petición normal
    // El cerebro IA se encarga de clasificar y crear el ticket
    $respuesta = procesarMensaje($remitente, $mensaje);
    
    // Registrar respuesta generada
    registrarLog("Respuesta a $remitente: " . substr($respuesta, 0, 200) . (strlen($respuesta) > 200 ? '...' : ''), 'info', 'whatsapp_mensajes.log');
    
    return $respuesta;
}

/**
 * Consulta el estado de un ticket específico para respuesta por WhatsApp
 * 
 * @param string $remitente Número del ciudadano
 * @param int $ticketId     ID del ticket
 * @return string           Mensaje de estado
 */
function consultarEstadoTicketPorWhatsApp($remitente, $ticketId) {
    // Cargar tickets
    $tickets = [];
    if (file_exists(TICKETS_JSON)) {
        $contenido = file_get_contents(TICKETS_JSON);
        $tickets = json_decode($contenido, true) ?: [];
    }
    
    // Buscar ticket que pertenezca al remitente
    $ticketEncontrado = null;
    foreach ($tickets as $ticket) {
        if (isset($ticket['id']) && $ticket['id'] == $ticketId && isset($ticket['remitente']) && $ticket['remitente'] === $remitente) {
            $ticketEncontrado = $ticket;
            break;
        }
    }
    
    if (!$ticketEncontrado) {
        return "No encontré ningún ticket con número #{$ticketId} asociado a tu número de teléfono. Verifica el número o envía tu solicitud para crear un nuevo ticket.";
    }
    
    // Mapeo de estados a texto amigable
    $estados = [
        'pendiente' => 'pendiente de revisión',
        'en_proceso' => 'en proceso de atención',
        'resuelto' => 'resuelto',
        'cancelado' => 'cancelado'
    ];
    
    $estado = $ticketEncontrado['estado'] ?? 'pendiente';
    $estadoTexto = $estados[$estado] ?? $estado;
    
    $fecha = isset($ticketEncontrado['fecha_creacion']) ? date('d/m/Y H:i', strtotime($ticketEncontrado['fecha_creacion'])) : 'fecha desconocida';
    $categoria = ucfirst(str_replace('_', ' ', $ticketEncontrado['categoria'] ?? 'general'));
    
    $respuesta = "📋 *Ticket #{$ticketId}*\n";
    $respuesta .= "📅 Creado: {$fecha}\n";
    $respuesta .= "📂 Categoría: {$categoria}\n";
    $respuesta .= "📌 Estado: *{$estadoTexto}*\n";
    
    // Agregar detalle adicional según estado
    if ($estado === 'pendiente') {
        $respuesta .= "\n⏳ Tu solicitud está en cola de espera. Pronto un funcionario la revisará.";
    } elseif ($estado === 'en_proceso') {
        $respuesta .= "\n🔄 Tu solicitud ya está siendo atendida por el departamento correspondiente.";
    } elseif ($estado === 'resuelto') {
        $respuesta .= "\n✅ Tu solicitud ha sido resuelta. ¡Gracias por tu paciencia!";
    } elseif ($estado === 'cancelado') {
        $respuesta .= "\n❌ Este ticket fue cancelado. Si necesitas ayuda, crea una nueva solicitud.";
    }
    
    return $respuesta;
}

/**
 * Genera menú de ayuda para el ciudadano
 * 
 * @return string Mensaje con opciones disponibles
 */
function generarMenuAyuda() {
    $menu = "📋 *Sistema de Peticiones Ciudadanas - San Pablo Jocopilas*\n\n";
    $menu .= "*¿Cómo usar este sistema?*\n";
    $menu .= "1️⃣ Envía tu solicitud describiendo el problema\n";
    $menu .= "   Ej: \"Bache en la calle principal\"\n";
    $menu .= "   Ej: \"Fuga de agua en mi colonia\"\n\n";
    $menu .= "2️⃣ Consulta el estado de tu ticket:\n";
    $menu .= "   Envía \"*estado #123*\" (con el número de tu ticket)\n\n";
    $menu .= "3️⃣ Comandos disponibles:\n";
    $menu .= "   • *ayuda* - Muestra este menú\n";
    $menu .= "   • *hola* - Saludo de bienvenida\n";
    $menu .= "   • *estado #ID* - Estado de tu ticket\n\n";
    $menu .= "📌 *Categorías que atendemos:*\n";
    $menu .= "🏗️ Obras Públicas (baches, calles)\n";
    $menu .= "💧 Servicios Básicos (agua, basura)\n";
    $menu .= "🗺️ Catastro-COMRED\n";
    $menu .= "🏥 Salud Pública\n\n";
    $menu .= "¿Tienes más dudas? Llama a la municipalidad al: 1234-5678";
    
    return $menu;
}

/**
 * Obtiene mensaje de bienvenida
 * 
 * @return string
 */
function obtenerRespuestaBienvenida() {
    $bienvenida = "👋 *Bienvenido al Sistema de Peticiones Ciudadanas*\n\n";
    $bienvenida .= "Municipalidad de San Pablo Jocopilas\n\n";
    $bienvenida .= "Para hacer una solicitud, solo describe tu problema:\n";
    $bienvenida .= "• \"Bache en la 5ta calle\"\n";
    $bienvenida .= "• \"Fuga de agua en el barrio Centro\"\n";
    $bienvenida .= "• \"Basura acumulada en mi colonia\"\n\n";
    $bienvenida .= "Envía *ayuda* para ver todos los comandos disponibles.";
    
    return $bienvenida;
}

/**
 * Obtiene mensaje de agradecimiento
 * 
 * @return string
 */
function obtenerRespuestaAgradecimiento() {
    $agradecimientos = [
        "¡Gracias a ti por comunicarte! Tu participación nos ayuda a mejorar.",
        "Agradecemos tu mensaje. Seguimos trabajando por San Pablo Jocopilas.",
        "¡Gracias por confiar en nosotros! Estamos para servirte."
    ];
    return $agradecimientos[array_rand($agradecimientos)];
}
?>
