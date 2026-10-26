<?php
/**
 * reportes/imprimir.php
 * Muestra el reporte generado por generar.php y lo envía directamente a la impresión del navegador.
 *
 * @version 1.1 — BUGFIX: antes se usaba file_get_contents("generar.php?id=..")
 * para "pedirle" el HTML a generar.php. Como esa cadena no tiene esquema
 * (no empieza con "http://"), PHP la trata como una ruta de ARCHIVO LOCAL,
 * no como una petición HTTP: intenta abrir un archivo que literalmente se
 * llama "generar.php?id=5" en disco, que nunca existe, así que
 * file_get_contents() siempre devolvía false y el reporte jamás se
 * generaba (moría con "No se pudo generar el reporte."). Y aunque hubiera
 * funcionado como URL, tampoco habría llevado la cookie de sesión del
 * admin logueado, así que generar.php lo hubiera rechazado con 403 de
 * todas formas.
 *
 * La forma correcta de "incluir" la salida de otro script PHP dentro del
 * mismo request es con output buffering + include(), no con una ida y
 * vuelta HTTP a uno mismo.
 */

require_once dirname(__DIR__) . '/config/config.php';
session_start();

if (!isset($_SESSION['autenticado']) || $_SESSION['autenticado'] !== true) {
    header('Location: ../login/iniciar_sesion.php');
    exit;
}

$ticketId = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($ticketId <= 0) {
    die('ID de ticket inválido.');
}

// ------------------------------------------------------------
// Las consultas informativas (eventos, trámites, horarios, ferias,
// cursos, etc.) no generan oficio: son solo preguntas del ciudadano
// que se registran para admin_tickets.php y el informe mensual. Se
// verifica acá ANTES de incluir generar.php para no gastar recursos
// generando el oficio completo ni dejar el buffer a medias.
// ------------------------------------------------------------
$ticketsParaChequeo = [];
if (file_exists(TICKETS_JSON)) {
    $ticketsParaChequeo = json_decode(file_get_contents(TICKETS_JSON), true) ?: [];
}
foreach ($ticketsParaChequeo as $tChk) {
    if ($tChk['id'] != $ticketId) continue;
    if (($tChk['categoria'] ?? '') === 'informacion_ciudadana' || !empty($tChk['tipo_informacion'])) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<title>Consulta informativa — sin oficio</title>'
            . '<style>body{font-family:"Segoe UI",Tahoma,sans-serif;background:#f0f2f5;padding:40px 20px;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.card{background:#fff;max-width:540px;padding:32px;border-radius:14px;box-shadow:0 4px 18px rgba(0,0,0,.1);text-align:center}'
            . '.icon{font-size:46px;margin-bottom:14px}'
            . 'h2{margin:0 0 10px;color:#2d3748;font-size:20px}'
            . 'p{color:#4a5568;line-height:1.6;margin:0 0 20px;font-size:14px}'
            . 'a{display:inline-block;padding:10px 22px;background:#667eea;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}'
            . 'a:hover{background:#556cd6}'
            . '</style></head><body>'
            . '<div class="card">'
            . '<div class="icon">ℹ️</div>'
            . '<h2>Esta petición es una consulta informativa</h2>'
            . '<p>El ciudadano hizo una pregunta (sobre un evento, un trámite, un horario, una feria, un curso, etc.) '
            . 'y no reportó un problema de servicios públicos. Las consultas informativas se registran en el sistema '
            . 'para el informe mensual, pero no generan oficio.</p>'
            . '<a href="../vistas/admin_tickets.php">← Volver a Gestión de Peticiones</a>'
            . '</div></body></html>';
        exit;
    }
    break;
}

// Capturar la salida de generar.php dentro del mismo request (misma
// sesión ya iniciada, mismo $_GET['id']), en vez de pedírsela por HTTP.
ob_start();
include __DIR__ . '/generar.php';
$reporteHtml = ob_get_clean();

if (empty($reporteHtml)) {
    die('No se pudo generar el reporte.');
}

// ------------------------------------------------------------
// Controles de edición: al ver el reporte (esta misma pantalla, NO el
// formulario de edición del dashboard) el usuario puede:
//   1) Clic en "✏️ Editar" → el contenido del oficio se vuelve editable
//      directamente sobre la vista impresa (contenteditable).
//   2) Clic en "🖨️ Imprimir" → imprime tal como está en pantalla. Si había
//      cambios sin guardar, se guardan automáticamente justo después de
//      imprimir (evento afterprint), sin que el usuario tenga que hacer
//      nada más.
//   3) Clic en "💾 Guardar cambios" → guarda los cambios en cualquier
//      momento, sin necesidad de imprimir.
//   4) Clic en "📷 Capturar pantalla del chat" → pide permiso al
//      navegador para compartir pantalla/ventana/pestaña, toma un
//      fotograma real de eso, deja recortarlo con un recuadro rojo, y
//      sube ese recorte como anexo del oficio — sin tener que buscar ni
//      subir ningún archivo desde el explorador de Windows.
//   5) Clic en "📋 Pegar captura (Ctrl+V)" → lee la imagen del portapapeles
//      (imagen copiada), la ESCALA AUTOMÁTICAMENTE y la SUBE DIRECTAMENTE
//      sin necesidad de recuadro rojo. Compatible con PrtSc (tecla de
//      captura de pantalla en portátiles).
//   6) Clic en "🖼️ Agregar imagen" → elige un archivo de imagen y lo sube
//      al anexo de capturas (reportes/subir_captura.php).
// Los cambios se envían a reportes/guardar_edicion.php, que los persiste
// por ticket en reportes/oficios_editados.json; reportes/generar.php los
// vuelve a mostrar automáticamente la próxima vez que se abra este mismo
// oficio.
//
// NOTA: antes este script imprimía automáticamente al cargar la página
// (window.onload → window.print()). Se quitó esa impresión automática
// porque ahora el usuario primero puede querer editar el oficio; el botón
// "Imprimir" sigue disponible para imprimir cuando así lo decida.
// ------------------------------------------------------------
$controlesEdicion = <<<HTML
<div class="no-print" id="barra-controles-oficio" style="position:fixed; bottom:20px; right:20px; z-index:1000; display:flex; gap:10px; align-items:center;">
    <span id="estado-guardado-oficio" style="font-size:12px; color:#2e7d32; background:#fff; padding:4px 10px; border-radius:6px; box-shadow:0 2px 5px rgba(0,0,0,0.15); display:none;"></span>
    <button id="btn-editar-oficio" type="button" style="padding:10px 18px; background:#495057; color:white; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.2);">
        ✏️ Editar
    </button>
    <button id="btn-capturar-pantalla" type="button" style="padding:10px 18px; background:#0d6efd; color:white; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.2);">
        📷 Capturar pantalla del chat
    </button>
    <button id="btn-pegar-captura" type="button" style="padding:10px 18px; background:#6c757d; color:white; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.2);">
        📋 Pegar captura (Ctrl+V)
    </button>
    <button id="btn-agregar-imagen" type="button" style="padding:10px 18px; background:#198754; color:white; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.2);">
        🖼️ Agregar imagen
    </button>
    <input type="file" id="input-agregar-imagen" accept="image/jpeg,image/png,image/webp" style="display:none;">
    <button id="btn-imprimir-oficio" type="button" style="padding:10px 20px; background:#667eea; color:white; border:none; border-radius:8px; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,0.2);">
        🖨️ Imprimir / Guardar PDF
    </button>
</div>

<!-- ------------------------------------------------------------
     Overlay de captura de pantalla + recorte con recuadro rojo.
     Se activa con "📷 Capturar pantalla del chat" (NO se usa para
     "Pegar captura", que sube directamente sin recorte).
     ------------------------------------------------------------ -->
<div id="overlay-captura-pantalla" class="no-print" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:2000; flex-direction:column; align-items:center; justify-content:center; gap:14px;">
    <div style="color:#fff; font-size:14px; text-align:center; max-width:640px; padding:0 12px;">
        Arrastra sobre la imagen para dibujar el <strong style="color:#ff5252;">recuadro rojo</strong> alrededor de la conversación que quieres adjuntar, luego confirma.
    </div>
    <div id="wrap-captura-pantalla" style="position:relative; cursor:crosshair; box-shadow:0 4px 30px rgba(0,0,0,0.5); line-height:0;">
        <img id="img-captura-pantalla" style="display:block; user-select:none;" draggable="false" alt="Captura de pantalla tomada">
        <div id="rect-seleccion-captura" style="display:none; position:absolute; border:3px solid #ff0000; background:rgba(255,0,0,0.12); pointer-events:none;"></div>
    </div>
    <div style="display:flex; gap:10px;">
        <button id="btn-confirmar-recorte" type="button" disabled style="padding:10px 20px; background:#2e7d32; color:white; border:none; border-radius:8px; cursor:pointer; opacity:0.5;">
            ✅ Cortar y adjuntar
        </button>
        <button id="btn-cancelar-captura" type="button" style="padding:10px 20px; background:#b00020; color:white; border:none; border-radius:8px; cursor:pointer;">
            ✖ Cancelar
        </button>
    </div>
</div>

<script>
(function() {
    var contenedor = document.getElementById('oficio-contenido');
    if (!contenedor) return; // por seguridad, si algo cambia en generar.php

    // Contenedor del anexo de imágenes. Se acepta también el id anterior
    // (#anexo-capturas-chat) por si reportes/generar.php aún no se actualizó.
    var anexoContenedor = document.getElementById('anexo-evidencia') || document.getElementById('anexo-capturas-chat');
    var ticketId = contenedor.getAttribute('data-ticket-id');
    var btnEditar = document.getElementById('btn-editar-oficio');
    var btnImprimir = document.getElementById('btn-imprimir-oficio');
    var estadoEl = document.getElementById('estado-guardado-oficio');
    var btnAgregarImagen = document.getElementById('btn-agregar-imagen');
    var inputAgregarImagen = document.getElementById('input-agregar-imagen');
    var editando = false;
    var huboCambios = false;

    function mostrarEstado(texto, esError) {
        estadoEl.textContent = texto;
        estadoEl.style.color = esError ? '#b00020' : '#2e7d32';
        estadoEl.style.display = 'inline-block';
        setTimeout(function() { estadoEl.style.display = 'none'; }, 4000);
    }

    function marcarCambio() {
        huboCambios = true;
    }

    // Muestra/oculta las barras de herramientas (⤢ ✂️ ➖ ➕ 🗑️) de TODAS las
    // hojas de imágenes. Se hace también por estilo en línea, además de la
    // clase CSS, para que funcione aunque la hoja de estilos en caché sea una
    // versión anterior o el contenedor del anexo tenga otro id. Al imprimir
    // siguen ocultas (oficio.css: .anexo-foto-toolbar { display:none !important }
    // dentro de @media print).
    function alternarBarrasAnexo(mostrar) {
        document.querySelectorAll('.anexo-foto-toolbar').forEach(function(barra) {
            barra.style.display = mostrar ? 'flex' : '';
        });
    }

    function activarEdicion() {
        editando = true;
        contenedor.setAttribute('contenteditable', 'true');
        contenedor.style.outline = '2px dashed #667eea';
        contenedor.style.outlineOffset = '6px';
        contenedor.addEventListener('input', marcarCambio);
        btnEditar.textContent = '✅ Terminar edición';
        // El modo edición también habilita la barra de herramientas
        // (✂️ recortar, ➖ achicar, ➕ agrandar, 🗑️ borrar) sobre cada
        // captura de chat anexada — ver .anexo-modo-edicion en oficio.css.
        contenedor.classList.add('anexo-modo-edicion');
        if (anexoContenedor) anexoContenedor.classList.add('anexo-modo-edicion');
        alternarBarrasAnexo(true);
        // Hacer editable el TÍTULO del anexo ("Anexo — Captura(s) de la
        // conversación de WhatsApp y Evidencias"). Se edita directo sobre
        // la hoja y se guarda junto con el oficio (ver guardarEdicion() y
        // guardar_edicion.php).
        if (anexoContenedor) {
            [].slice.call(anexoContenedor.querySelectorAll('.anexo-titulo-texto')).forEach(prepararTituloEditable);
            // Rótulos "Fotografía N" / "Captura N" editables; se guardan al
            // pulsar "✅ Terminar edición" (ver guardarEdicion()).
            [].slice.call(anexoContenedor.querySelectorAll('.anexo-foto-num')).forEach(function(el) {
                el.setAttribute('contenteditable', 'true');
                el.style.outline = '1px dashed #667eea';
                el.style.outlineOffset = '2px';
                el.style.cursor = 'text';
            });
        }
    }

    function desactivarEdicion() {
        editando = false;
        contenedor.setAttribute('contenteditable', 'false');
        contenedor.style.outline = 'none';
        btnEditar.textContent = '✏️ Editar';
        contenedor.classList.remove('anexo-modo-edicion');
        if (anexoContenedor) anexoContenedor.classList.remove('anexo-modo-edicion');
        alternarBarrasAnexo(false);
        if (anexoContenedor) {
            [].slice.call(anexoContenedor.querySelectorAll('.anexo-titulo-texto')).forEach(function(el) {
                el.setAttribute('contenteditable', 'false');
                el.style.outline = 'none';
            });
            [].slice.call(anexoContenedor.querySelectorAll('.anexo-foto-num')).forEach(function(el) {
                el.setAttribute('contenteditable', 'false');
                el.style.outline = 'none';
                el.style.cursor = '';
            });
        }
    }

    // Renumera "Fotografía N" / "Captura N" SOLO en las celdas cuyo rótulo
    // NO es personalizado ni fue editado en esta sesión. Los contadores
    // avanzan por todas las celdas, así los números reflejan la posición
    // real en la secuencia del anexo.
    function renumerarCapturasAnexo() {
        if (!anexoContenedor) return;
        var nFoto = 0, nCaptura = 0;
        [].slice.call(anexoContenedor.querySelectorAll('.anexo-foto-caja')).forEach(function(caja) {
            var etiqueta = caja.querySelector('.anexo-foto-num');
            var esCustom = etiqueta && etiqueta.getAttribute('data-titulo-custom') === '1';
            var esEditado = etiqueta && etiqueta.getAttribute('data-editado') === '1';
            if (caja.getAttribute('data-origen') === 'whatsapp') {
                nFoto++;
                if (etiqueta && !esCustom && !esEditado) etiqueta.textContent = 'Fotografía ' + nFoto;
            } else {
                nCaptura++;
                if (etiqueta && !esCustom && !esEditado) etiqueta.textContent = 'Captura ' + nCaptura;
            }
        });
    }

    // "✏️ Editar" es un toggle real: al pulsarlo estando en modo edición se
    // guardan TODOS los cambios (oficio + título del anexo + rótulos de las
    // imágenes) y solo entonces se sale del modo edición. Si el guardado
    // falla, se permanece en modo edición para no dar por guardado algo que
    // no lo está. Si no hubo cambios, se sale sin guardar (así no se
    // "congela" el oficio sin necesidad).
    btnEditar.addEventListener('click', function() {
        if (!editando) {
            activarEdicion();
            return;
        }
        if (!huboCambios) {
            desactivarEdicion();
            return;
        }
        guardarEdicion(function() {
            if (!huboCambios) desactivarEdicion();
        });
    });

    function guardarEdicion(callback) {
        if (!ticketId) { if (callback) callback(); return; }

        // El título del anexo vive FUERA de #oficio-contenido, así que no
        // viaja dentro de contenedor.innerHTML: se recoge aparte (texto
        // plano de la primera hoja; las demás lo replican).
        var tituloAnexo = null;
        if (anexoContenedor) {
            var tituloEl = anexoContenedor.querySelector('.anexo-titulo-texto');
            if (tituloEl) tituloAnexo = (tituloEl.textContent || '').trim();
        }

        // Rótulos de imágenes editados en esta sesión (data-editado="1").
        // Los que siguen por defecto NO se envían: se seguirán renumerando
        // al intercambiar imágenes. Un texto vacío borra el personalizado.
        var titulosImagenes = {};
        if (anexoContenedor) {
            [].slice.call(anexoContenedor.querySelectorAll('.anexo-foto-caja')).forEach(function(caja) {
                var etiqueta = caja.querySelector('.anexo-foto-num');
                if (!etiqueta || etiqueta.getAttribute('data-editado') !== '1') return;
                var ruta = caja.getAttribute('data-ruta');
                var origen = caja.getAttribute('data-origen') || 'chat';
                if (!ruta) return;
                titulosImagenes[origen + '|' + ruta] = (etiqueta.textContent || '').trim();
            });
        }

        var fallo = false;
        var promesas = [];

        promesas.push(
            fetch('guardar_edicion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: ticketId, html: contenedor.innerHTML, titulo_anexo: tituloAnexo })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data && data.success) {
                    huboCambios = false;
                } else {
                    fallo = true;
                    mostrarEstado('No se pudo guardar: ' + (data && data.error ? data.error : 'error desconocido'), true);
                }
            })
            .catch(function() {
                fallo = true;
                mostrarEstado('No se pudo guardar (sin conexión).', true);
            })
        );

        if (Object.keys(titulosImagenes).length > 0) {
            promesas.push(
                fetch('anexo_titulos.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket_id: ticketId, titulos: titulosImagenes })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        // Los rótulos guardados pasan a ser "personalizados"
                        // (un intercambio futuro no los renumera); los que
                        // quedaron vacíos vuelven al valor por defecto.
                        [].slice.call(anexoContenedor.querySelectorAll('.anexo-foto-num')).forEach(function(etiqueta) {
                            if (etiqueta.getAttribute('data-editado') !== '1') return;
                            etiqueta.removeAttribute('data-editado');
                            if ((etiqueta.textContent || '').trim() === '') {
                                etiqueta.removeAttribute('data-titulo-custom');
                            } else {
                                etiqueta.setAttribute('data-titulo-custom', '1');
                            }
                        });
                        renumerarCapturasAnexo();
                    } else {
                        fallo = true;
                        mostrarEstado('No se pudieron guardar los rótulos de las imágenes.', true);
                    }
                })
                .catch(function() {
                    fallo = true;
                    mostrarEstado('No se pudieron guardar los rótulos (sin conexión).', true);
                })
            );
        }

        Promise.all(promesas).then(function() {
            if (fallo) {
                huboCambios = true; // sigue habiendo algo sin guardar
            } else {
                mostrarEstado('Cambios guardados ✔', false);
            }
            if (callback) callback();
        });
    }


    btnImprimir.addEventListener('click', function() {
        window.print();
    });

    // Guardado automático después de imprimir, SOLO si hubo cambios sin
    // guardar (pedido explícito: "si lo edito tiene que guardarse
    // automáticamente después de imprimirlo").
    window.onafterprint = function() {
        if (huboCambios) {
            guardarEdicion();
        }
    };

    // ------------------------------------------------------------
    // "📷 Capturar pantalla del chat": usa la Screen Capture API del
    // navegador (getDisplayMedia) para pedirle al usuario que comparta su
    // pantalla, ventana o pestaña (donde tiene abierto WhatsApp Web), toma
    // UN fotograma real de eso (una captura real, no simulada ni
    // generada), y deja recortarla con un recuadro rojo antes de
    // adjuntarla al oficio. No hay que buscar ni subir ningún archivo:
    // todo ocurre dentro del navegador.
    //
    // Requisito del navegador: getDisplayMedia solo funciona en un
    // "contexto seguro" (el sitio servido por HTTPS, o accedido como
    // localhost). Si el sistema se sirve por HTTP plano en la red local,
    // el navegador bloqueará el permiso y se avisa con un mensaje.
    // ------------------------------------------------------------
    // ------------------------------------------------------------
    // "📋 Pegar captura (Ctrl+V)": lee el portapapeles del sistema
    // (clipboard) usando la API moderna navigator.clipboard.read().
    // Busca una imagen (PNG, JPEG, WEBP) y la SUBE DIRECTAMENTE,
    // escalándola automáticamente a un tamaño seguro (max 1200px)
    // para evitar errores de "imagen demasiado grande".
    // Compatible con PrtSc (captura de pantalla completa en portátiles).
    // ------------------------------------------------------------
    var btnCapturarPantalla = document.getElementById('btn-capturar-pantalla');
    var btnPegarCaptura = document.getElementById('btn-pegar-captura');
    var overlayCaptura = document.getElementById('overlay-captura-pantalla');
    var imgCaptura = document.getElementById('img-captura-pantalla');
    var wrapCaptura = document.getElementById('wrap-captura-pantalla');
    var rectSeleccion = document.getElementById('rect-seleccion-captura');
    var btnConfirmarRecorte = document.getElementById('btn-confirmar-recorte');
    var btnCancelarCaptura = document.getElementById('btn-cancelar-captura');

    var estadoCaptura = {
        canvasOriginal: null,
        naturalWidth: 0,
        naturalHeight: 0,
        scale: 1,
        sel: null,
        arrastrando: false,
        inicioX: 0,
        inicioY: 0,
        // Si tiene valor, "✅ Cortar y adjuntar" no agrega una captura
        // nueva sino que REEMPLAZA la captura de esta ruta en el mismo
        // lugar (ver "✂️ Recortar de nuevo" más abajo).
        reemplazarRuta: null
    };

    function habilitarBotonConfirmar(habilitado) {
        btnConfirmarRecorte.disabled = !habilitado;
        btnConfirmarRecorte.style.opacity = habilitado ? '1' : '0.5';
    }

    function iniciarCapturaPantalla() {
        if (!ticketId) {
            mostrarEstado('No se pudo identificar el ticket.', true);
            return;
        }
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            mostrarEstado('Este navegador no soporta captura de pantalla (se requiere HTTPS/localhost y un navegador moderno).', true);
            return;
        }

        // Al elegir "Pestaña" en el selector de captura, Chrome/Edge saltan
        // a la pestaña compartida (WhatsApp Web). La forma correcta de
        // evitarlo es CaptureController.setFocusBehavior('no-focus-change'):
        // el foco se queda en esta pestaña (la del oficio). Solo aplica a
        // pestañas/ventanas (no a "pantalla completa") y no existe en todos
        // los navegadores, así que se usa solo si está disponible.
        // window.focus() queda como refuerzo, aunque los navegadores suelen
        // ignorarlo cuando la pestaña no tiene el foco.
        var opciones = { video: { cursor: 'never' }, audio: false };
        var controlador = null;
        if (typeof CaptureController !== 'undefined') {
            try {
                controlador = new CaptureController();
                controlador.setFocusBehavior('no-focus-change');
                opciones.controller = controlador;
            } catch (e) {
                controlador = null;
                delete opciones.controller;
            }
        }

        function volverAlOficio() {
            try { window.focus(); } catch (e) {}
        }

        navigator.mediaDevices.getDisplayMedia(opciones)
            .then(function(stream) {
                // Solo se puede fijar UNA vez, inmediatamente después de que
                // la promesa se cumple (si el usuario compartió la pantalla
                // completa lanza InvalidStateError: se ignora).
                if (controlador) {
                    try { controlador.setFocusBehavior('no-focus-change'); } catch (e) {}
                }
                setTimeout(volverAlOficio, 0);
                setTimeout(volverAlOficio, 120);
                setTimeout(volverAlOficio, 400);

                var video = document.createElement('video');
                video.muted = true;
                video.srcObject = stream;
                return video.play().then(function() {
                    return new Promise(function(resolve) {
                        (function esperar() {
                            if (video.videoWidth > 0 && video.videoHeight > 0) {
                                resolve({ video: video, stream: stream });
                            } else {
                                requestAnimationFrame(esperar);
                            }
                        })();
                    });
                });
            })
            .then(function(obj) {
                var video = obj.video, stream = obj.stream;
                var canvas = document.createElement('canvas');
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                // Ya se tiene el fotograma: se detiene la transmisión de
                // inmediato, solo hacía falta una captura fija.
                stream.getTracks().forEach(function(t) { t.stop(); });
                volverAlOficio();
                mostrarSelectorRecorte(canvas);
            })
            .catch(function(err) {
                volverAlOficio();
                if (err && err.name === 'NotAllowedError') {
                    mostrarEstado('Permiso de captura de pantalla cancelado.', true);
                } else {
                    mostrarEstado('No se pudo capturar la pantalla.', true);
                }
            });
    }

    // Función que escala una imagen a un máximo de 1200px (o cualquier dimensión configurable)
    function escalarImagen(canvasOriginal, maxDim) {
        maxDim = maxDim || 1200;
        var width = canvasOriginal.width;
        var height = canvasOriginal.height;
        if (width <= maxDim && height <= maxDim) {
            return canvasOriginal;
        }
        var scale = Math.min(maxDim / width, maxDim / height);
        var newWidth = Math.round(width * scale);
        var newHeight = Math.round(height * scale);
        var canvasEscalado = document.createElement('canvas');
        canvasEscalado.width = newWidth;
        canvasEscalado.height = newHeight;
        canvasEscalado.getContext('2d').drawImage(canvasOriginal, 0, 0, newWidth, newHeight);
        return canvasEscalado;
    }

    // Función que sube la imagen directamente sin mostrar el recuadro rojo
    function subirCapturaEscalada(canvasOriginal) {
        var canvasEscalado = escalarImagen(canvasOriginal, 1200);
        canvasEscalado.toBlob(function(blob) {
            if (!blob) {
                mostrarEstado('No se pudo procesar la imagen.', true);
                return;
            }
            // Si el blob aún es muy grande (> 8 MB), lo comprimimos más
            if (blob.size > 8 * 1024 * 1024) {
                // Reintentar con calidad 0.7
                canvasEscalado.toBlob(function(blob2) {
                    if (blob2) subirCapturaBlob(blob2);
                    else mostrarEstado('No se pudo comprimir la imagen.', true);
                }, 'image/jpeg', 0.7);
            } else {
                subirCapturaBlob(blob);
            }
        }, 'image/png', 0.9);
    }

    function iniciarPegarCaptura() {
        if (!ticketId) {
            mostrarEstado('No se pudo identificar el ticket.', true);
            return;
        }
        if (!navigator.clipboard || !navigator.clipboard.read) {
            mostrarEstado('Este navegador no soporta la lectura del portapapeles. Usa "Capturar pantalla" o sube manualmente.', true);
            return;
        }

        // Mostrar un mensaje temporal de "procesando"
        mostrarEstado('📋 Leyendo portapapeles y escalando imagen...', false);

        navigator.clipboard.read()
            .then(function(items) {
                var imagenEncontrada = false;
                for (var i = 0; i < items.length; i++) {
                    var item = items[i];
                    var tipoImagen = item.types.find(function(tipo) {
                        return tipo.startsWith('image/');
                    });
                    if (tipoImagen) {
                        imagenEncontrada = true;
                        return item.getType(tipoImagen);
                    }
                }
                if (!imagenEncontrada) {
                    throw new Error('No se encontró ninguna imagen en el portapapeles. Copia una captura de pantalla primero (PrtSc o Ctrl+C sobre una imagen).');
                }
            })
            .then(function(blob) {
                return createImageBitmap(blob);
            })
            .then(function(imageBitmap) {
                var canvas = document.createElement('canvas');
                canvas.width = imageBitmap.width;
                canvas.height = imageBitmap.height;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(imageBitmap, 0, 0);
                // Subir directamente sin recuadro
                subirCapturaEscalada(canvas);
            })
            .catch(function(err) {
                if (err.message && err.message.includes('No se encontró ninguna imagen')) {
                    mostrarEstado(err.message, true);
                } else {
                    mostrarEstado('No se pudo leer el portapapeles. Asegúrate de haber copiado una imagen (PrtSc o Ctrl+C) y de dar permiso al navegador.', true);
                    console.error('Clipboard error:', err);
                }
            });
    }

    function mostrarSelectorRecorte(canvasOriginal) {
        estadoCaptura.canvasOriginal = canvasOriginal;
        estadoCaptura.sel = null;
        habilitarBotonConfirmar(false);

        imgCaptura.onload = function() {
            estadoCaptura.naturalWidth = canvasOriginal.width;
            estadoCaptura.naturalHeight = canvasOriginal.height;
            ajustarTamanoImagenOverlay();
            rectSeleccion.style.display = 'none';
            overlayCaptura.style.display = 'flex';
        };
        imgCaptura.src = canvasOriginal.toDataURL('image/png');
    }

    function ajustarTamanoImagenOverlay() {
        var maxW = window.innerWidth * 0.9;
        var maxH = window.innerHeight * 0.72;
        var escala = Math.min(maxW / estadoCaptura.naturalWidth, maxH / estadoCaptura.naturalHeight, 1);
        estadoCaptura.scale = escala;
        var dispW = Math.round(estadoCaptura.naturalWidth * escala);
        var dispH = Math.round(estadoCaptura.naturalHeight * escala);
        imgCaptura.style.width = dispW + 'px';
        imgCaptura.style.height = dispH + 'px';
        wrapCaptura.style.width = dispW + 'px';
        wrapCaptura.style.height = dispH + 'px';
    }

    function cerrarOverlayCaptura() {
        overlayCaptura.style.display = 'none';
        imgCaptura.src = '';
        rectSeleccion.style.display = 'none';
        estadoCaptura = { canvasOriginal: null, naturalWidth: 0, naturalHeight: 0, scale: 1, sel: null, arrastrando: false, inicioX: 0, inicioY: 0, reemplazarRuta: null };
    }

    // ------------------------------------------------------------
    // "✂️ Recortar de nuevo": botón de la barra de herramientas de una
    // captura ya anexada (visible solo en modo "✏️ Editar", ver
    // .anexo-foto-toolbar en oficio.css). En vez de pedir una captura de
    // pantalla nueva, carga la imagen YA subida, deja recortarla otra vez
    // con el mismo recuadro rojo, y al confirmar la reemplaza en el mismo
    // lugar (mismo tamaño, misma posición en el anexo) vía
    // subir_captura.php con "reemplazar_ruta".
    // ------------------------------------------------------------
    function iniciarRecorteExistente(ruta) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() {
            var canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            canvas.getContext('2d').drawImage(img, 0, 0);
            mostrarSelectorRecorte(canvas);
            estadoCaptura.reemplazarRuta = ruta;
        };
        img.onerror = function() {
            mostrarEstado('No se pudo cargar la captura para recortarla de nuevo.', true);
        };
        img.src = '/' + ruta.replace(/^\/+/, '') + '?t=' + Date.now();
    }

    wrapCaptura.addEventListener('mousedown', function(e) {
        var rect = wrapCaptura.getBoundingClientRect();
        estadoCaptura.arrastrando = true;
        estadoCaptura.inicioX = e.clientX - rect.left;
        estadoCaptura.inicioY = e.clientY - rect.top;
        rectSeleccion.style.left = estadoCaptura.inicioX + 'px';
        rectSeleccion.style.top = estadoCaptura.inicioY + 'px';
        rectSeleccion.style.width = '0px';
        rectSeleccion.style.height = '0px';
        rectSeleccion.style.display = 'block';
        habilitarBotonConfirmar(false);
        e.preventDefault();
    });

    window.addEventListener('mousemove', function(e) {
        if (!estadoCaptura.arrastrando) return;
        var rect = wrapCaptura.getBoundingClientRect();
        var x = Math.max(0, Math.min(e.clientX - rect.left, rect.width));
        var y = Math.max(0, Math.min(e.clientY - rect.top, rect.height));
        var left = Math.min(x, estadoCaptura.inicioX);
        var top = Math.min(y, estadoCaptura.inicioY);
        var w = Math.abs(x - estadoCaptura.inicioX);
        var h = Math.abs(y - estadoCaptura.inicioY);
        rectSeleccion.style.left = left + 'px';
        rectSeleccion.style.top = top + 'px';
        rectSeleccion.style.width = w + 'px';
        rectSeleccion.style.height = h + 'px';
        estadoCaptura.sel = { left: left, top: top, w: w, h: h };
    });

    window.addEventListener('mouseup', function() {
        if (!estadoCaptura.arrastrando) return;
        estadoCaptura.arrastrando = false;
        var sel = estadoCaptura.sel;
        habilitarBotonConfirmar(!!(sel && sel.w > 5 && sel.h > 5));
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlayCaptura.style.display !== 'none') {
            cerrarOverlayCaptura();
        }
    });

    btnCancelarCaptura.addEventListener('click', cerrarOverlayCaptura);
    btnCapturarPantalla.addEventListener('click', iniciarCapturaPantalla);
    btnPegarCaptura.addEventListener('click', iniciarPegarCaptura);

    btnConfirmarRecorte.addEventListener('click', function() {
        var sel = estadoCaptura.sel;
        if (!sel || !estadoCaptura.canvasOriginal) return;

        var escala = estadoCaptura.scale;
        var sx = Math.round(sel.left / escala);
        var sy = Math.round(sel.top / escala);
        var sw = Math.round(sel.w / escala);
        var sh = Math.round(sel.h / escala);

        var canvasRecorte = document.createElement('canvas');
        canvasRecorte.width = sw;
        canvasRecorte.height = sh;
        canvasRecorte.getContext('2d').drawImage(estadoCaptura.canvasOriginal, sx, sy, sw, sh, 0, 0, sw, sh);

        canvasRecorte.toBlob(function(blob) {
            if (!blob) {
                mostrarEstado('No se pudo procesar el recorte.', true);
                return;
            }
            subirCapturaBlob(blob);
        }, 'image/png');
    });

    function subirCapturaBlob(blob) {
        var textoOriginal = btnConfirmarRecorte.textContent;
        btnConfirmarRecorte.disabled = true;
        btnConfirmarRecorte.textContent = '⏳ Subiendo...';

        var datosFormulario = new FormData();
        datosFormulario.append('ticket_id', ticketId);
        datosFormulario.append('captura', blob, 'captura_chat.png');
        if (estadoCaptura.reemplazarRuta) {
            datosFormulario.append('reemplazar_ruta', estadoCaptura.reemplazarRuta);
        }

        fetch('subir_captura.php', {
            method: 'POST',
            body: datosFormulario
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success) {
                mostrarEstado(estadoCaptura.reemplazarRuta ? 'Captura recortada ✔' : 'Captura adjuntada ✔', false);
                cerrarOverlayCaptura();
                // Se recarga la vista para mostrar el anexo actualizado
                // con la nueva captura (nueva o recortada en el mismo
                // lugar).
                setTimeout(function() { window.location.reload(); }, 600);
            } else {
                btnConfirmarRecorte.disabled = false;
                btnConfirmarRecorte.textContent = textoOriginal;
                mostrarEstado('No se pudo adjuntar: ' + (data && data.error ? data.error : 'error desconocido'), true);
            }
        })
        .catch(function() {
            btnConfirmarRecorte.disabled = false;
            btnConfirmarRecorte.textContent = textoOriginal;
            mostrarEstado('No se pudo adjuntar (sin conexión).', true);
        });
    }

    // ------------------------------------------------------------
    // Barra de herramientas por captura del anexo (✂️ recortar de nuevo,
    // ➖ achicar, ➕ agrandar, 🗑️ borrar). Solo es visible cuando
    // #anexo-evidencia tiene la clase "anexo-modo-edicion" (se agrega
    // junto con "✏️ Editar", ver activarEdicion() más arriba), pero el
    // listener se registra siempre por delegación de eventos, ya que los
    // botones vienen ya en el HTML que devuelve reportes/generar.php.
    // ------------------------------------------------------------
    var ORDEN_TAMANOS = ['pequena', 'mediana', 'grande'];

    if (anexoContenedor) {
        anexoContenedor.addEventListener('click', function(e) {
            var boton = e.target.closest('.anexo-btn-herramienta');
            if (!boton || !anexoContenedor.contains(boton)) return;

            var caja = boton.closest('.anexo-foto-caja');
            if (!caja) return;
            var ruta = caja.getAttribute('data-ruta');
            var origen = caja.getAttribute('data-origen') || 'chat';
            if (!ruta) return;

            if (boton.classList.contains('anexo-btn-centrar')) {
                // Persiste tanto para capturas del chat como para fotos de WhatsApp.
                cambiarCentradoImagen(caja, ruta, origen, !caja.classList.contains('anexo-foto-centrada'));
            } else if (boton.classList.contains('anexo-btn-recortar')) {
                if (origen === 'chat') iniciarRecorteExistente(ruta);
            } else if (boton.classList.contains('anexo-btn-achicar')) {
                cambiarTamanoImagen(caja, ruta, origen, -1);
            } else if (boton.classList.contains('anexo-btn-agrandar')) {
                cambiarTamanoImagen(caja, ruta, origen, 1);
            } else if (boton.classList.contains('anexo-btn-borrar')) {
                if (origen === 'chat') borrarCapturaAnexo(caja, ruta);
                else                   borrarFotoWhatsApp(caja, ruta);
            }
        });

        // ------------------------------------------------------------
        // ARRASTRAR PARA INTERCAMBIAR POSICIONES
        //
        // En modo "✏️ Editar" se arrastra una imagen con el mouse: queda
        // semitransparente y la celda sobre la que está el cursor se
        // resalta. Al soltarla sobre otra imagen, las dos intercambian
        // lugar. El orden se guarda en reportes/anexo_orden.php y, si el
        // servidor confirma, se reordena el DOM SIN recargar la página:
        // así el modo edición sigue activo y no se pierde nada sin guardar.
        // ------------------------------------------------------------
        anexoContenedor.addEventListener('mousedown', function(e) {
            if (!editando) return;
            if (e.button !== 0) return; // solo botón izquierdo
            // No iniciar arrastre sobre la barra de herramientas ni sobre el
            // rótulo editable (ahí el clic es para escribir).
            if (e.target.closest('.anexo-foto-toolbar')) return;
            if (e.target.closest('.anexo-foto-num')) return;
            var img = e.target.closest('.anexo-foto-caja img');
            if (!img) return;
            var cajaOrigen = img.closest('.anexo-foto-caja');
            var rutaOrigen = cajaOrigen.getAttribute('data-ruta');
            var origenA = cajaOrigen.getAttribute('data-origen') || 'chat';
            if (!rutaOrigen) return;

            e.preventDefault();
            e.stopPropagation();

            cajaOrigen.classList.add('anexo-arrastrando');
            var celdaResaltada = null;

            function limpiarResaltado() {
                if (celdaResaltada) {
                    celdaResaltada.classList.remove('anexo-drop-target');
                    celdaResaltada = null;
                }
            }

            function onMove(ev) {
                var bajoCursor = document.elementFromPoint(ev.clientX, ev.clientY);
                var cajaBajo = bajoCursor ? bajoCursor.closest('.anexo-foto-caja') : null;
                if (cajaBajo && cajaBajo !== cajaOrigen) {
                    if (celdaResaltada !== cajaBajo) {
                        limpiarResaltado();
                        cajaBajo.classList.add('anexo-drop-target');
                        celdaResaltada = cajaBajo;
                    }
                } else {
                    limpiarResaltado();
                }
            }

            function onUp(ev) {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                cajaOrigen.classList.remove('anexo-arrastrando');

                var bajoCursor = document.elementFromPoint(ev.clientX, ev.clientY);
                var cajaDestino = bajoCursor ? bajoCursor.closest('.anexo-foto-caja') : null;
                limpiarResaltado();

                if (!cajaDestino || cajaDestino === cajaOrigen) return; // soltó sobre sí misma o fuera
                var rutaDestino = cajaDestino.getAttribute('data-ruta');
                var origenB = cajaDestino.getAttribute('data-origen') || 'chat';
                if (!rutaDestino) return;

                fetch('anexo_orden.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        ticket_id: ticketId,
                        a: { origen: origenA, ruta: rutaOrigen },
                        b: { origen: origenB, ruta: rutaDestino }
                    })
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.success) {
                        mostrarEstado('No se pudo intercambiar: ' + (data && data.error ? data.error : 'error desconocido'), true);
                        return;
                    }

                    // 1) Intercambiar las dos celdas COMPLETAS en el DOM
                    //    (imagen, barra y rótulo van juntos), estén en la
                    //    misma hoja o en hojas distintas.
                    var padreA = cajaOrigen.parentNode;
                    var padreB = cajaDestino.parentNode;
                    var marcador = document.createElement('div');
                    padreA.insertBefore(marcador, cajaOrigen);
                    padreB.insertBefore(cajaOrigen, cajaDestino);
                    padreA.insertBefore(cajaDestino, marcador);
                    padreA.removeChild(marcador);

                    // 2) Reacomodar hojas (por si cambió el reparto según los
                    //    tamaños) y renumerar los rótulos NO personalizados.
                    reacomodarAnexo();

                    mostrarEstado('Posiciones intercambiadas ✔', false);
                })
                .catch(function() {
                    mostrarEstado('No se pudo intercambiar (sin conexión).', true);
                });
            }

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // Rótulos "Fotografía N" / "Captura N" editables: se registran como
        // "editados" para enviarlos al pulsar "✅ Terminar edición".
        anexoContenedor.addEventListener('input', function(e) {
            var etiqueta = e.target.closest ? e.target.closest('.anexo-foto-num') : null;
            if (etiqueta) {
                etiqueta.setAttribute('data-editado', '1');
                marcarCambio();
            }
        });
        // Enter en un rótulo = terminar de escribir (sin saltos de línea).
        anexoContenedor.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.closest && e.target.closest('.anexo-foto-num')) {
                e.preventDefault();
                e.target.blur();
            }
        });
    }

    // Endpoint a usar según el origen de la imagen (whatsapp vs captura del
    // chat). Guardan en sitios distintos pero devuelven el mismo JSON.
    function endpointAjuste(origen) {
        return (origen === 'chat') ? 'redimensionar_captura.php' : 'ajustar_foto_ticket.php';
    }

    function cambiarTamanoImagen(caja, ruta, origen, direccion) {
        var actual = caja.getAttribute('data-tamano') || 'mediana';
        var indice = ORDEN_TAMANOS.indexOf(actual);
        if (indice === -1) indice = 1;
        var nuevoIndice = indice + direccion;
        if (nuevoIndice < 0 || nuevoIndice >= ORDEN_TAMANOS.length) return; // ya está en el extremo
        var nuevoTamano = ORDEN_TAMANOS[nuevoIndice];

        fetch(endpointAjuste(origen), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, ruta: ruta, tamano: nuevoTamano })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success) {
                caja.classList.remove('anexo-tam-' + actual);
                caja.classList.add('anexo-tam-' + nuevoTamano);
                caja.setAttribute('data-tamano', nuevoTamano);
                mostrarEstado('Tamaño actualizado ✔', false);
                reacomodarAnexo();
            } else {
                mostrarEstado('No se pudo cambiar el tamaño: ' + (data && data.error ? data.error : 'error desconocido'), true);
            }
        })
        .catch(function() {
            mostrarEstado('No se pudo cambiar el tamaño (sin conexión).', true);
        });
    }

    function cambiarCentradoImagen(caja, ruta, origen, centrar) {
        fetch(endpointAjuste(origen), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, ruta: ruta, centrada: centrar })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success) {
                caja.classList.toggle('anexo-foto-centrada', centrar);
                caja.setAttribute('data-centrada', centrar ? '1' : '0');
                mostrarEstado(centrar ? 'Imagen centrada ✔' : 'Imagen a tamaño completo ✔', false);
            } else {
                mostrarEstado('No se pudo cambiar: ' + (data && data.error ? data.error : 'error desconocido'), true);
            }
        })
        .catch(function() {
            mostrarEstado('No se pudo cambiar (sin conexión).', true);
        });
    }

    function borrarCapturaAnexo(caja, ruta) {
        if (!window.confirm('¿Eliminar esta captura del anexo? Esta acción no se puede deshacer.')) return;

        fetch('borrar_captura.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, ruta: ruta })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success) {
                caja.remove();
                mostrarEstado('Captura eliminada ✔', false);
                reacomodarAnexo();
            } else {
                mostrarEstado('No se pudo eliminar: ' + (data && data.error ? data.error : 'error desconocido'), true);
            }
        })
        .catch(function() {
            mostrarEstado('No se pudo eliminar (sin conexión).', true);
        });
    }

    // Tras eliminar o cambiar de tamaño una imagen del anexo (foto o
    // captura): se reacomodan TODAS las imágenes en hojas con la misma
    // regla que renderAnexoEvidencia() en oficio_html.php (grid de 2
    // columnas; grande = fila completa; máximo 4 imágenes y ~21.3 cm de
    // alto por hoja, o sea 4 medianas/pequeñas u 2 grandes). Se crean o
    // quitan hojas según haga falta, se renumera (Fotografía N / Captura N,
    // por tipo) y se actualiza el rótulo "(página X de Y · N imágenes en
    // total)". El encabezado adaptativo se recalcula al recargar la página.
    // Si cambias las alturas de abajo, cámbialas también en el PHP y en
    // oficio.css.
    // Alturas en cm (imagen + padding + rótulo), en sincronía con oficio.css
    // (alto de imagen + 0.8). Ver también la variable altoCelda en oficio_html.php.
    var ALTO_CELDA = { pequena: 5.3, mediana: 10.3, grande: 10.5 };
    var PRESUPUESTO_HOJA = 21.3;
    var GAP_FILAS = 0.22;
    var MAX_POR_HOJA = 4;

    function tamanoDeCaja(caja) {
        for (var i = 0; i < ORDEN_TAMANOS.length; i++) {
            if (caja.classList.contains('anexo-tam-' + ORDEN_TAMANOS[i])) return ORDEN_TAMANOS[i];
        }
        return 'mediana';
    }

    function colocarEnFilas(filas, caja) {
        var nuevas = filas.map(function(f) { return { h: f.h, llena: f.llena }; });
        var tam = tamanoDeCaja(caja);
        var h = ALTO_CELDA[tam] || ALTO_CELDA.mediana;
        var n = nuevas.length;
        if (tam !== 'grande' && n > 0 && !nuevas[n - 1].llena) {
            nuevas[n - 1].llena = true;
            nuevas[n - 1].h = Math.max(nuevas[n - 1].h, h);
        } else {
            nuevas.push({ h: h, llena: tam === 'grande' });
        }
        return nuevas;
    }

    function altoDeFilas(filas) {
        var total = 0;
        filas.forEach(function(f) { total += f.h; });
        return total + GAP_FILAS * Math.max(0, filas.length - 1);
    }

    // Título del anexo editable (solo en modo "✏️ Editar"). Como el título
    // se repite en cada hoja, al teclear en una se copia a las demás.
    function alEditarTituloAnexo(ev) {
        var texto = ev.target.textContent;
        if (anexoContenedor) {
            [].slice.call(anexoContenedor.querySelectorAll('.anexo-titulo-texto')).forEach(function(el) {
                if (el !== ev.target && el.textContent !== texto) el.textContent = texto;
            });
        }
        marcarCambio();
    }

    function prepararTituloEditable(el) {
        el.setAttribute('contenteditable', 'true');
        el.style.outline = '2px dashed #667eea';
        el.style.outlineOffset = '2px';
        el.addEventListener('input', alEditarTituloAnexo);
    }

    function crearPaginaAnexo(base) {
        var pagina = document.createElement('div');
        pagina.className = 'carta-pagina anexo-pagina';
        // Membrete institucional: se copia del de la primera hoja.
        var membreteBase = base ? base.querySelector('.anexo-membrete') : null;
        if (membreteBase) {
            pagina.appendChild(membreteBase.cloneNode(true));
            var regla = document.createElement('div');
            regla.className = 'anexo-membrete-regla';
            pagina.appendChild(regla);
        }
        var titulo = document.createElement('div');
        titulo.className = 'anexo-titulo';
        var texto = document.createElement('span');
        texto.className = 'anexo-titulo-texto';
        var textoBase = base ? base.querySelector('.anexo-titulo-texto') : null;
        texto.textContent = textoBase ? textoBase.textContent : '';
        if (editando) prepararTituloEditable(texto);
        titulo.appendChild(texto);
        var grid = document.createElement('div');
        grid.className = 'anexo-grid';
        pagina.appendChild(titulo);
        pagina.appendChild(grid);
        return pagina;
    }

    function reacomodarAnexo() {
        if (!anexoContenedor) return;
        var paginas = [].slice.call(anexoContenedor.querySelectorAll('.anexo-pagina'));
        if (!paginas.length) return;
        var cajas = [].slice.call(anexoContenedor.querySelectorAll('.anexo-foto-caja'));

        // Sin imágenes: se quitan todas las hojas.
        if (!cajas.length) {
            paginas.forEach(function(p) { p.remove(); });
            return;
        }

        // Renumerar por tipo (respetando rótulos personalizados).
        renumerarCapturasAnexo();

        // Repartir las imágenes en hojas según su tamaño.
        var grupos = [];
        var actual = [];
        var filas = [];
        cajas.forEach(function(caja) {
            var probables = colocarEnFilas(filas, caja);
            var excede = actual.length > 0 &&
                (altoDeFilas(probables) > PRESUPUESTO_HOJA || actual.length + 1 > MAX_POR_HOJA);
            if (excede) {
                grupos.push(actual);
                actual = [];
                probables = colocarEnFilas([], caja);
            }
            actual.push(caja);
            filas = probables;
        });
        if (actual.length) grupos.push(actual);

        // Crear las hojas que falten y quitar las que sobren.
        while (paginas.length < grupos.length) {
            var ultima = paginas[paginas.length - 1];
            var nueva = crearPaginaAnexo(paginas[0]);
            ultima.parentNode.insertBefore(nueva, ultima.nextSibling);
            paginas.push(nueva);
        }
        while (paginas.length > grupos.length) {
            paginas.pop().remove();
        }

        // Mover cada imagen a su hoja y refrescar clases y rótulos.
        paginas.forEach(function(pagina, k) {
            var grid = pagina.querySelector('.anexo-grid');
            grupos[k].forEach(function(caja) { grid.appendChild(caja); });

            pagina.classList.toggle('anexo-pagina-continuacion', k > 0);
            pagina.setAttribute('data-pagina-anexo', String(k + 1));

            // Ya no se muestra "(página X de Y · N imágenes en total)"; se
            // quita por si viniera de una versión anterior.
            var meta = pagina.querySelector('.anexo-titulo-meta');
            if (meta) meta.remove();
        });
    }

    // Fotografías de WhatsApp: se eliminan del ticket (tickets.json) y del
    // disco vía borrar_foto_ticket.php. Ojo: este código vive dentro de un
    // heredoc de PHP, por eso los saltos de línea se escriben con \\n.
    function borrarFotoWhatsApp(caja, ruta) {
        if (!window.confirm('¿Eliminar esta fotografía del expediente?\\n\\nEl archivo también se borra del disco. Esta acción no se puede deshacer.')) return;

        fetch('borrar_foto_ticket.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ticket_id: ticketId, ruta: ruta })
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data && data.success) {
                caja.remove();
                mostrarEstado('Fotografía eliminada ✔', false);
                reacomodarAnexo();
            } else {
                mostrarEstado('No se pudo eliminar: ' + (data && data.error ? data.error : 'error desconocido'), true);
            }
        })
        .catch(function() {
            mostrarEstado('No se pudo eliminar (sin conexión).', true);
        });
    }

    // ------------------------------------------------------------
    // Botón "⤢ Centrar" en las CAPTURAS DEL CHAT (viven fuera del HTML
    // editable, así que su estado se guarda en capturas_chat.json vía
    // redimensionar_captura.php).
    // ------------------------------------------------------------
    // "🖼️ Agregar imagen": sube una imagen nueva al anexo de capturas del
    // chat (mismo endpoint que "Pegar captura") y recarga la página para
    // que el anexo la muestre paginada junto a las demás.
    // El selector de archivo se abre directamente en el clic (los
    // navegadores bloquean input.click() si no viene de un gesto del
    // usuario); si había cambios sin guardar, se guardan ANTES de subir,
    // porque la recarga los perdería.
    // ------------------------------------------------------------
    btnAgregarImagen.addEventListener('click', function() {
        inputAgregarImagen.click();
    });

    inputAgregarImagen.addEventListener('change', function() {
        var archivo = this.files && this.files[0];
        this.value = '';
        if (!archivo) return;

        function subir() {
            var datos = new FormData();
            datos.append('ticket_id', ticketId);
            datos.append('captura', archivo, archivo.name || 'imagen.png');
            datos.append('tamano', 'mediana');

            btnAgregarImagen.disabled = true;
            var textoOriginal = btnAgregarImagen.textContent;
            btnAgregarImagen.textContent = '⏳ Subiendo...';

            fetch('subir_captura.php', { method: 'POST', body: datos })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.success) {
                        mostrarEstado('Imagen agregada ✔', false);
                        setTimeout(function() { window.location.reload(); }, 500);
                    } else {
                        btnAgregarImagen.disabled = false;
                        btnAgregarImagen.textContent = textoOriginal;
                        mostrarEstado('No se pudo agregar: ' + (data && data.error ? data.error : 'error desconocido'), true);
                    }
                })
                .catch(function() {
                    btnAgregarImagen.disabled = false;
                    btnAgregarImagen.textContent = textoOriginal;
                    mostrarEstado('No se pudo agregar (sin conexión).', true);
                });
        }

        if (huboCambios) {
            guardarEdicion(function() {
                if (huboCambios) {
                    mostrarEstado('No se pudieron guardar tus cambios; no se agregó la imagen para no perderlos.', true);
                    return;
                }
                subir();
            });
        } else {
            subir();
        }
    });
})();
</script>
HTML;

$reporteHtml = str_replace('</body>', $controlesEdicion . '</body>', $reporteHtml);

header('Content-Type: text/html; charset=utf-8');
echo $reporteHtml;
?>