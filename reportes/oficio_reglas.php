<?php
/**
 * reportes/oficio_reglas.php
 *
 * Reglas centralizadas de redacción para los oficios formales.
 * Todo lo que se puede ajustar sin tocar la lógica vive acá:
 *   • Errores de ortografía típicos de WhatsApp.
 *   • Modismos coloquiales → lenguaje institucional.
 *   • Sustantivos genéricos (para no confundir "la calle" con una dirección).
 *   • Tipos de lugar (con el artículo que se les antepone).
 *   • Marco por categoría ("problemática", "requerimiento", "trámite"...).
 *   • Palabras que NUNCA deben estar en un oficio.
 *
 * Para agregar una categoría o un tipo nuevo, agregá una entrada acá y listo.
 * Se usa define() dentro de if(!defined()) para que se cargue una sola vez.
 */

// 1. ORTOGRAFÍA: patrón regex => reemplazo (aplicadas en orden)
if (!defined('OFICIO_REGLAS_ORTOGRAFIA')) {
    define('OFICIO_REGLAS_ORTOGRAFIA', [

        // --- Verbos mal escritos o conjugados incorrectamente ---
        '/\bse\s+call[oó]\b/iu'         => 'se cayó',
        '/\bse\s+cayo\b/iu'             => 'se cayó',
        '/\bse\s+gue\b/iu'              => 'se fue',
        '/\ben\s+d[ií]a\s+de\s+ayer\b/iu'  => 'ayer',
        '/\bse\s+corto\b/iu'            => 'se cortó',
        '/\bcall[oó]\b/iu'              => 'cayó',
        '/\bcayo\b/iu'                  => 'cayó',
        '/\bca[ií]do\b/iu'              => 'caído',
        '/\bca[ií]da\b/iu'              => 'caída',
        '/\bcalleron\b/iu'              => 'cayeron',
        '/\bcaieron\b/iu'               => 'cayeron',
        '/\btopo\b/iu'                  => 'topó',
        '/\bexploto\b/iu'               => 'explotó',
        '/\bestallo\b/iu'               => 'estalló',
        '/\bdejo\b/iu'                  => 'dejó',
        '/\bquedo\b/iu'                 => 'quedó',
        '/\bobstruyo\b/iu'              => 'obstruyó',
        '/\bprovoco\b/iu'               => 'provocó',
        '/\bquemao\b/iu'                => 'quemado',
        '/\bpuedo\b/iu'                 => 'puede',
        '/\binscribirme\b/iu'           => 'inscribirse',
        '/\bson\s+alumbrado\b/iu'       => 'sin alumbrado',

        // --- Acentos frecuentemente omitidos ---
        '/\btambien\b/iu'               => 'también',
        '/\bcafe\b/iu'                  => 'café',
        '/\bmadre\s+mia\b/iu'           => 'Madre Mía',
        '/\bflor\s+de\s+caf[eé]\b/iu'   => 'Flor del Café',

        // --- Expresiones de lugar mal escritas ---
        '/\b(?:al|a)\s+l?apar\b/iu'     => 'a la par',
        '/\ben\s+frente\b/iu'           => 'enfrente',
        '/\bfrente\s+de\s+la\b/iu'      => 'frente a la',
        '/\bfrente\s+de\s+las\b/iu'     => 'frente a las',
        '/\bfrente\s+de\s+los\b/iu'     => 'frente a los',
        '/\bfrente\s+de\s+el\b/iu'      => 'frente al',
        '/\bfrente\s+de\s+un\b/iu'      => 'frente a un',
        '/\bfrente\s+de\s+una\b/iu'     => 'frente a una',

        // --- Tiempo relativo (las más específicas primero) ---
        '/\bhace\s+mucho\s+rato\b/iu'     => 'hace bastante tiempo',
        '/\bhace\s+un\s+buen\s+rato\b/iu' => 'hace bastante tiempo',
        '/\bhace\s+ratos?\b/iu'           => 'hace varias horas',
        '/\bhace\s+un\s+rato\b/iu'        => 'hace unos minutos',
        '/\bhace\s+un\s+momento\b/iu'     => 'hace unos momentos',
        '/\bhace\s+poco(?!\s+tiempo)\b/iu' => 'recientemente',
        '/\bal\s+ratito\b/iu'             => 'hace unos momentos',
        '/\bno\s+hace\s+mucho\b/iu'       => 'recientemente',

        // --- Acentos omitidos en palabras muy frecuentes ---
        '/\barbol\b/iu'                                 => 'árbol',
        '/\barboles\b/iu'                               => 'árboles',

        // --- Verbos y palabras mal escritas ---
        '/\bbiene\b/iu'              => 'viene',
        '/\bbienen\b/iu'             => 'vienen',
        '/\bllendo\b/iu'             => 'yendo',
        '/\bhaiga\b/iu'              => 'haya',
        '/\btapo\b/iu'               => 'tapó',
        '/\bconducia\b/iu'           => 'conducía',
        '/\bbierte\b/iu'             => 'vierte',
        '/\bbierten\b/iu'            => 'vierten',
        '/\bagua\s+botad[oa]\b/iu'   => 'agua desechada',
        '/\bluces\s+fundidas\b/iu'   => 'luminarias fuera de servicio',
        '/\blampara\b/iu'            => 'lámpara',
        '/\blamparas\b/iu'           => 'lámparas',

        // --- "esta/estan" sin tilde cuando es el verbo + gerundio ---
        // ("esta sacando" → "está sacando"). No se corrige "esta que" porque
        // puede ser el demostrativo ("esta que ves").
        '/\besta\s+(\p{L}+(?:ando|iendo|yendo))\b/iu'   => 'está $1',
        '/\bestan\s+(\p{L}+(?:ando|iendo|yendo))\b/iu'  => 'están $1',

        // --- Ampliación: verbos con tilde omitida en pretérito (3.ª persona) ---
        '/\bmurio\b/iu'               => 'murió',
        '/\bprendio\b/iu'             => 'prendió',
        '/\bperdio\b/iu'              => 'perdió',
        '/\bsalio\b/iu'               => 'salió',
        '/\bsubio\b/iu'               => 'subió',
        '/\bempezo\b/iu'              => 'empezó',
        '/\bcomenzo\b/iu'             => 'comenzó',
        '/\bllego\b/iu'               => 'llegó',
        '/\bentro\s+agua\b/iu'        => 'entró agua',
        '/\bfalto\b/iu'               => 'faltó',

        // --- Ampliación: concordancia verbal "se ha/n" + participio ---
        '/\bse\s+han\s+ca[ií]do\b/iu'            => 'se han caído',
        '/\bse\s+han\s+roto\b/iu'                => 'se han roto',
        '/\bse\s+han\s+reventado\b/iu'           => 'se han reventado',
        '/\bse\s+han\s+descompuesto\b/iu'        => 'se han descompuesto',

        // --- Ampliación: números ordinales abreviados ---
        '/\b1\s*ro\.?\b/iu'   => '1ro',
        '/\b2\s*do\.?\b/iu'   => '2do',
        '/\b3\s*ro\.?\b/iu'   => '3ro',
        '/\b4\s*to\.?\b/iu'   => '4to',

        // --- Ampliación: expresiones de tiempo relativas ---
        '/\ben\s+la\s+ma[ñn]ana\b/iu'         => 'durante la mañana',
        '/\ben\s+la\s+tarde\b/iu'             => 'durante la tarde',
        '/\ben\s+la\s+noche\b/iu'             => 'durante la noche',
        '/\ben\s+la\s+madrugada\b/iu'         => 'durante la madrugada',
        '/\ba\s+medianoche\b/iu'              => 'a medianoche',
        '/\bm[aá]s\s+temprano\b/iu'           => 'previamente',
        '/\bm[aá]s\s+tarde\b/iu'              => 'posteriormente',

        // --- Ampliación: gramática básica ---
        // "enfrente a el/la/los/las" es incorrecto: se usa "enfrente del /
        // de la / de los / de las". Caso real: oficio 04.
        '/\benfrente\s+a\s+el\b/iu'    => 'enfrente del',
        '/\benfrente\s+a\s+la\b/iu'    => 'enfrente de la',
        '/\benfrente\s+a\s+los\b/iu'   => 'enfrente de los',
        '/\benfrente\s+a\s+las\b/iu'   => 'enfrente de las',
        '/\bfrente\s+a\s+el\b/iu'      => 'frente al',

        // "Y" mayúscula en medio de una oración (casos reales: oficios 06 y
        // 07, "...moscas verdes Y con el paso de los días..."). Solo actúa si
        // la "Y" va entre palabras en minúscula; no toca "Juan Y María".
        '/([a-záéíóúñ0-9,])\s+Y\s+([a-záéíóúñ])/u' => '$1 y $2',

        // --- Ampliación: pretéritos tras "vecino/a que ..." o "quien ..." ---
        // Casos reales: oficios 02, 06 y 07. Se limita a estas construcciones
        // (y no a cualquier "que notifico") para no tocar frases en primera
        // persona como "la información que solicito" o "la denuncia que presento".
        '/\b(vecin[oa]|ciudadan[oa]|persona)\s+(que|quien)\s+(notific|report|solicit|inform|comunic|manifest|indic|expres|present)o\b/iu' => '$1 $2 $3ó',
        '/\bquien\s+(notific|report|solicit|inform|comunic|manifest|indic|expres|present)o\b/iu' => 'quien $1ó',

        // --- Ampliación: nombres propios de negocios y personas ---
        // Casos reales: oficios 01, 04, 05, 06, 07, 08 y 09. Los reemplazos
        // conservan el "vecina/sector/don" tal como venía escrito.
        '/\bhelados\s+sarita\b/iu'      => 'Helados Sarita',
        '/\bhelader[ií]a\s+sarita\b/iu' => 'Heladería Sarita',
        '/\bian\s+tarrales\b/iu'        => 'IAN Tarrales',
        '/\bian\s+casitas\b/iu'         => 'IAN Casitas',
        '/\b(sector)\s+toronjal\b/iu'   => '$1 Toronjal',
        '/\btoronjal(es)?\b/iu'         => 'Toronjal$1',
        '/\bhamilton\s+s[aá]nchez\b/iu' => 'Hamilton Sánchez',
        '/\b(don)\s+max\b/iu'           => '$1 Max',
        '/\b(vecin[oa])\s+mercedes\b/iu' => '$1 Mercedes',

        // --- Ampliación: topónimos y siglas del municipio ---
        '/\bchocola\b/iu'                     => 'Chocolá',
        '/\bixcoj\b/iu'                       => 'Ixcoj',
        '/\bpacacoj\b/iu'                     => 'Pacacoj',
        '/\blolemi\b/iu'                      => 'Lolemi',
        '/\bixtacapa\b/iu'                    => 'Ixtacapa',
        '/\bixcanalero\b/iu'                  => 'Ixcanalero',
        '/\bnanzal\b/iu'                      => 'Nanzal',
        '/\binsodi\b/iu'                      => 'INSODI',
        '/\binebo\b/iu'                       => 'INEBO',
        '/\bpnc\b/iu'                         => 'PNC',
        '/\bcomred\b/iu'                      => 'COMRED',
        '/\bcocode\b/iu'                      => 'COCODE',
        '/\brenap\b/iu'                       => 'RENAP',
        '/\bmarn\b/iu'                        => 'MARN',
        '/\biusi\b/iu'                        => 'IUSI',

        // --- Ampliación: abreviaturas comunes de WhatsApp ---
        '/\bxq\b/iu'                          => 'porque',
        '/\bporq\b/iu'                        => 'porque',
        '/\bq\b/iu'                           => 'que',
        '/\btmb\b/iu'                         => 'también',
        '/\btbn\b/iu'                         => 'también',
        '/\bpq\b/iu'                          => 'porque',
        '/\bexcelente\s+dia\b/iu'             => 'excelente día',
        '/\bbuen\s+dia\b/iu'                  => 'buenos días',
        '/\bbuenas\s+tarde\b/iu'              => 'buenas tardes',
        '/\bbuena\s+noche\b/iu'               => 'buenas noches',
    ]);
}

// 2. FORMALIZACIÓN: modismos del chat → lenguaje institucional
if (!defined('OFICIO_REGLAS_FORMALIZACION')) {
    define('OFICIO_REGLAS_FORMALIZACION', [

        // --- Obstrucciones ---
        '/\bse\s+tap[oó]\s+con\b/iu'                  => 'se encuentra obstruido con',
        '/\bse\s+tap[oó]\b/iu'                        => 'se encuentra obstruido',
        '/\btapadas\b/iu'                             => 'obstruidas',
        '/\btapados\b/iu'                             => 'obstruidos',
        '/\btapada\b/iu'                              => 'obstruida',
        '/\btapado\b/iu'                              => 'obstruido',
        '/\btaponad[oa]s?\b/iu'                       => 'obstruido',
        '/\breventad[oa]s?\b/iu'                      => 'reventado',

        // --- Chispas / cables eléctricos ---
        '/\best[áa]n?\s+sacando\s+chispas\b/iu'       => 'desprenden chispas',
        '/\bsacando\s+chispas\b/iu'                   => 'desprendiendo chispas',
        '/\bhaciendo\s+chispas\b/iu'                  => 'desprendiendo chispas',
        '/\bunos\s+cables\b/iu'                       => 'cables',
        '/\bcables?\s+pelados?\b/iu'                  => 'cables expuestos',

        // --- Agua / anegamiento ---
        '/\bse\s+est[áa]\s+llenando\s+de\s+agua\b/iu' => 'se encuentra anegada',
        '/\bse\s+est[áa]n\s+llenando\s+de\s+agua\b/iu' => 'se encuentran anegadas',
        '/\bya\s+se\s+est[áa]\s+llenando\b/iu'        => 'se encuentra anegada',
        '/\best[áa]\s+inundad[oa]s?\b/iu'             => 'se encuentra anegada',

        // --- Imperativos coloquiales dirigidos a la Municipalidad ---
        '/\bvengan\s+a\s+mover\b/iu'                  => 'se remueva',
        '/\bvengan\s+a\s+revisar\b/iu'                => 'se inspeccione',
        '/\bvengan\s+a\s+arreglar\b/iu'               => 'se repare',
        '/\bvengan\s+a\s+quitar\b/iu'                 => 'se retire',
        '/\bvengan\s+a\s+ver\b/iu'                    => 'se inspeccione',
        '/\bvengan\s+a\s+limpiar\b/iu'                => 'se proceda a la limpieza',
        '/\bvengan\s+pronto\b/iu'                     => 'se atienda a la brevedad',
        '/\bdetenganlo\s+lo\s+antes\s+posible\b/iu'   => 'se tomen las medidas pertinentes de inmediato',
        '/\bhagan\s+algo\b/iu'                        => 'se tomen las medidas correspondientes',
        '/\bay[uú]dennos?\b/iu'                       => 'se atienda el presente requerimiento',

        // --- Artículos indefinidos ---
        '/\bunos\s+[áa]rboles\b/iu'                   => 'árboles',
        '/\bunos\s+veh[ií]culos\b/iu'                 => 'vehículos',
        '/\bunos\s+animales\b/iu'                     => 'animales',
        '/\bunos\s+postes\b/iu'                       => 'postes',
        '/\bunos\s+focos\b/iu'                        => 'focos',
        '/\bunos\s+vecinos\b/iu'                      => 'vecinos',

        // --- Redundancias comunes ---
        // "la vecina llamada Mercedes" → "la vecina Mercedes" (oficios 06 y
        // 07). Se limita a "vecino/ciudadano/persona/señor(a) + llamado/de
        // nombre" para no borrar "llamada" o "de nombre" en otros contextos
        // ("una llamada telefónica", "llamado de atención").
        '/\b(vecin[oa]s?|ciudadan[oa]s?|persona|se[ñn]or[a]?)\s+(?:llamad[oa]s?|de\s+nombre)\s+/iu' => '$1 ',

        // --- Muletillas del chat ---
        '/\bpor\s+favor\b/iu'                         => '',
        '/\bporfa(vor)?\b/iu'                         => '',
        '/\bdisculpe(n)?\b/iu'                        => '',
        '/\bf[ií]jese\s+que\b/iu'                     => '',
        '/\bfijese\s+que\b/iu'                        => '',
        '/\bresulta\s+que\b/iu'                       => '',
        '/\ble\s+cuento\s+que\b/iu'                   => '',
        '/\ble\s+escribo\s+porque\b/iu'               => '',
        '/\bquer[ií]a\s+avisar\s+que\b/iu'            => '',
        '/\bquer[ií]a\s+reportar\s+que\b/iu'          => '',

        // --- Verbos de percepción personal (dejan el relato en 1ª persona) ---
        '/\bveo\s+que\s+/iu'                     => '',
        '/\bo[ií]\s+que\s+/iu'                   => '',
        '/\bme\s+dijeron\s+que\s+/iu'            => '',
        '/\bme\s+dicen\s+que\s+/iu'              => '',
        '/\bme\s+comentan\s+que\s+/iu'           => '',
        '/\bme\s+contaron\s+que\s+/iu'           => '',
        '/\bpuedo\s+ver\s+que\s+/iu'             => '',
        '/\bse\s+puede\s+ver\s+que\s+/iu'        => '',

        // --- Exceso de velocidad (singular y plural por separado) ---
        '/\bandan\s+a\s+toda\s+velocidad\b/iu'   => 'circulan a exceso de velocidad',
        '/\banda\s+a\s+toda\s+velocidad\b/iu'    => 'circula a exceso de velocidad',
        '/\bviene\s+a\s+toda\s+velocidad\b/iu'   => 'circula a exceso de velocidad',
        '/\bvienen\s+a\s+toda\s+velocidad\b/iu'  => 'circulan a exceso de velocidad',
        '/\bva\s+a\s+toda\s+velocidad\b/iu'      => 'circula a exceso de velocidad',
        '/\bvan\s+a\s+toda\s+velocidad\b/iu'     => 'circulan a exceso de velocidad',
        '/\bpasa\s+a\s+toda\s+velocidad\b/iu'    => 'circula a exceso de velocidad',
        '/\bpasan\s+a\s+toda\s+velocidad\b/iu'   => 'circulan a exceso de velocidad',
        '/\bpas[oó]\s+a\s+toda\s+velocidad\b/iu' => 'circuló a exceso de velocidad',

        // --- Estado de ebriedad al conducir ---
        '/\bque\s+est[aá]\s+bol[oa]s?\b/iu'         => 'que se encuentra en estado de ebriedad',
        '/\bque\s+est[aá]\s+borrach[oa]s?\b/iu'     => 'que se encuentra en estado de ebriedad',
        '/\bque\s+anda\s+bol[oa]s?\b/iu'            => 'que se encuentra en estado de ebriedad',
        '/\bque\s+va\s+bol[oa]s?\b/iu'              => 'que se encuentra en estado de ebriedad',

        // --- Imperativos coloquiales que llegan pegados al relato ---
        '/\bvengan?\s+a\s+verl[oa]s?\b/iu'          => 'se inspeccione',
        '/\bvengan?\s+a\s+revisarl[oa]s?\b/iu'      => 'se revise',
        '/\bvengan?\s+a\s+quitarl[oa]s?\b/iu'       => 'se retire',
        '/\bvengan?\s+a\s+taparl[oa]s?\b/iu'        => 'se reponga',
        '/\bvengan?\s+a\s+arreglarl[oa]s?\b/iu'     => 'se repare',
        '/\bvengan?\s+a\s+limpiarl[oa]s?\b/iu'      => 'se proceda a la limpieza',
        '/\bvengan?\s+pronto\b/iu'                  => 'se atienda a la brevedad',
        '/\bmanden\s+a\s+alguien\b/iu'              => 'se envíe personal municipal',
        '/\bmanden\s+personal\b/iu'                 => 'se envíe personal municipal',

        // --- Consecuencias coloquiales dentro de la misma frase ---
        '/\bcuando\s+llueve\s+el\s+agua\s+se\s+acumula\b/iu' => 'cuando llueve, el agua se acumula',
        '/\bcuando\s+llueve\s+se\s+inunda\b/iu'              => 'cuando llueve, se inunda',
        '/\bcuando\s+hace\s+aire\b/iu'                       => 'cuando se presentan ráfagas de viento',
        '/\bcuando\s+hay\s+viento\b/iu'                      => 'cuando se presentan ráfagas de viento',

        // --- Cortes e interrupciones de SERVICIOS BÁSICOS ---
        // "se fue la luz", "no hay agua", "se cortó el agua" → tercera persona
        // institucional. Van al final porque las reglas se aplican en orden.
        // OJO: NO se formalizan "sin luz" / "no hay luz": en una calle suelen
        // referirse al alumbrado público (ver 'patrones_oscuridad' más abajo)
        // y reescribirlos como "servicio de energía eléctrica" cambiaría el sentido.
        '/\bse\s+(?:fue|fué|cort[oó]|apag[oó])\s+(?:la\s+)?(?:luz|energ[ií]a(?:\s+el[eé]ctrica)?)\b/iu' => 'se interrumpió el servicio de energía eléctrica',
        '/\bse\s+va\s+(?:la\s+)?(?:luz|energ[ií]a(?:\s+el[eé]ctrica)?)\b/iu'                          => 'se interrumpe el servicio de energía eléctrica',
        '/\bse\s+(?:fue|fué|cort[oó])\s+el\s+agua(?:\s+potable)?\b/iu'                               => 'se interrumpió el servicio de agua potable',
        '/\bno\s+hay\s+agua(?:\s+potable)?\b/iu'                                                    => 'no hay servicio de agua potable',
        '/\bsin\s+agua(?:\s+potable)?\b/iu'                                                         => 'sin servicio de agua potable',
        '/\bno\s+(?:pasa|ha\s+pasado)\s+el\s+cami[oó]n\s+(?:de\s+la\s+)?basura\b/iu'                 => 'no ha pasado el camión recolector de basura',
        '/\bno\s+(?:pasa|ha\s+pasado)\s+el\s+tren\s+de\s+aseo\b/iu'                                  => 'no ha pasado el tren de aseo',

        // --- Ampliación: ruidos y molestias ---
        '/\bhacen\s+mucho\s+ruido\b/iu'         => 'generan niveles de ruido que afectan la tranquilidad',
        '/\bhacen\s+esc[aá]ndalo\b/iu'          => 'provocan escándalo en la vía pública',
        '/\bponen\s+la\s+m[uú]sica\s+muy\s+alta\b/iu' => 'reproducen música a un volumen que excede los límites permitidos',
        '/\bno\s+dejan\s+dormir\b/iu'           => 'impiden el descanso de los vecinos',
        '/\bhasta\s+la\s+madrugada\b/iu'        => 'hasta horas de la madrugada',
        '/\bno\s+dejan\s+descansar\b/iu'        => 'impiden el descanso de la comunidad',

        // --- Ampliación: expuesto a la intemperie ---
        '/\ba\s+la\s+intemperie\b/iu'           => 'expuesto a la intemperie',
        '/\bsin\s+protecci[oó]n\b/iu'           => 'sin la protección adecuada',
        '/\bexpuestos?\s+al\s+peligro\b/iu'     => 'en situación de riesgo',
        '/\ben\s+peligro\s+constante\b/iu'      => 'en riesgo constante',

        // --- Ampliación: vehículos con términos genéricos ---
        '/\bmal\s+parqueado\b/iu'               => 'estacionado en un lugar no autorizado',
        '/\bmal\s+estacionado\b/iu'             => 'estacionado en un lugar no autorizado',
        '/\bmal\s+parqueados\b/iu'              => 'estacionados en lugares no autorizados',
        '/\bmal\s+estacionados\b/iu'            => 'estacionados en lugares no autorizados',
        '/\btapando\s+la\s+entrada\b/iu'        => 'obstruyendo el acceso',
        '/\btapando\s+el\s+paso\b/iu'           => 'obstruyendo el paso',
        '/\btapando\s+la\s+calle\b/iu'          => 'obstruyendo la vía pública',
        '/\btapando\s+la\s+banqueta\b/iu'       => 'obstruyendo la acera',
        '/\btapando\s+la\s+acera\b/iu'          => 'obstruyendo la acera',
        '/\bno\s+deja\s+pasar\b/iu'             => 'impide el paso',
        '/\bno\s+dejan\s+pasar\b/iu'            => 'impiden el paso',
        '/\bno\s+deja\s+salir\b/iu'             => 'impide la salida',
        '/\bno\s+dejan\s+salir\b/iu'            => 'impiden la salida',
        '/\bno\s+deja\s+circular\b/iu'          => 'impide la circulación',
        '/\bno\s+dejan\s+circular\b/iu'         => 'impiden la circulación',
        '/\ben\s+doble\s+fila\b/iu'             => 'en doble fila',
        '/\ben\s+zona\s+prohibida\b/iu'         => 'en zona de estacionamiento prohibido',
        '/\ben\s+zona\s+peatonal\b/iu'          => 'en zona peatonal',

        // --- Ampliación: higiene y salud pública ---
        '/\bhay\s+muchas\s+moscas\b/iu'         => 'se ha detectado proliferación de moscas',
        '/\bhay\s+muchos\s+zancudos\b/iu'       => 'se ha detectado proliferación de zancudos',
        '/\bhay\s+muchas\s+ratas\b/iu'          => 'se ha detectado presencia de roedores',
        '/\bhay\s+muchos\s+zopes\b/iu'          => 'se ha detectado presencia de aves carroñeras',
        '/\bhay\s+malos\s+olores\b/iu'          => 'se perciben olores desagradables',
        '/\bhuele\s+muy\s+feo\b/iu'             => 'se percibe un olor desagradable',
        '/\bhuele\s+horrible\b/iu'              => 'se percibe un olor ofensivo',
        '/\bno\s+se\s+puede\s+respirar\b/iu'    => 'el aire resulta irrespirable',
        '/\bno\s+se\s+puede\s+vivir\s+as[ií]\b/iu' => 'la situación afecta seriamente la calidad de vida',

        // --- Ampliación: impacto comunitario ---
        '/\ba\s+todos\s+nos\s+afecta\b/iu'      => 'afecta a toda la comunidad',
        '/\bnos\s+afecta\s+a\s+todos\b/iu'      => 'afecta a toda la comunidad',
        '/\ba\s+los\s+vecinos\s+del\s+sector\b/iu' => 'a los vecinos del sector',
        '/\bya\s+no\s+aguantamos\b/iu'          => 'la situación se ha vuelto insostenible',
        '/\bya\s+no\s+podemos\s+m[aá]s\b/iu'    => 'la situación se ha vuelto insostenible',
        '/\bestamos\s+desesperados\b/iu'        => 'la comunidad se encuentra preocupada',
        '/\bestamos\s+preocupados\b/iu'         => 'la comunidad se encuentra preocupada',

        // --- Ampliación: peticiones de intervención ---
        '/\bque\s+vengan\s+a\s+arreglar\b/iu'   => 'se solicita la reparación correspondiente',
        '/\bque\s+vengan\s+a\s+revisar\b/iu'    => 'se solicita la inspección correspondiente',
        '/\bque\s+manden\s+a\s+alguien\b/iu'    => 'se solicita el envío de personal municipal',
        '/\bque\s+hagan\s+algo\b/iu'            => 'se solicita la intervención de la autoridad competente',
        '/\bque\s+lo\s+arreglen\b/iu'           => 'se solicita su reparación',
        '/\bque\s+lo\s+revisen\b/iu'            => 'se solicita su revisión',
        '/\bque\s+lo\s+quiten\b/iu'             => 'se solicita su retiro',
        '/\bque\s+lo\s+tapen\b/iu'              => 'se solicita su reposición',

        // --- Estacionamiento: concordancia del verbo con sujeto plural ---
        // Caso real: "para que no se estacione los carros" (el verbo
        // quedaba en singular a pesar del sujeto plural).
        '/\bpara\s+que\s+no\s+se\s+estacione\s+(los|las)\b/iu' => 'para que no se estacionen $1',
        '/\bque\s+no\s+se\s+estacione\s+(los|las)\b/iu'        => 'que no se estacionen $1',
        '/\bno\s+se\s+estacione\s+(los|las)\b/iu'              => 'no se estacionen $1',
        '/\bse\s+estacione\s+(los|las)\b/iu'                   => 'se estacionen $1',
        '/\bse\s+estaciona\s+(los|las)\b/iu'                   => 'se estacionan $1',

        // --- "Poner rojo en <algo>" → "pintar de rojo" (redacción formal) ---
        '/\bhayan\s+puesto\s+rojo\s+en\s+(la|las|el|los)\s+/iu' => 'hayan pintado de rojo $1 ',
        '/\bhan\s+puesto\s+rojo\s+en\s+(la|las|el|los)\s+/iu'    => 'han pintado de rojo $1 ',
        '/\bpusieron\s+rojo\s+en\s+(la|las|el|los)\s+/iu'        => 'pintaron de rojo $1 ',
        '/\bpuso\s+rojo\s+en\s+(la|las|el|los)\s+/iu'            => 'pintó de rojo $1 ',
        '/\bpintaron?\s+rojo\s+en\s+(la|las|el|los)\s+/iu'      => 'pintaron de rojo $1 ',
        '/\bpintado\s+rojo\s+en\s+(la|las|el|los)\s+/iu'        => 'pintado de rojo $1 ',
        // Fallback: cualquier "rojo en las banquetas/aceras/calles" que
        // se haya escapado de los patrones anteriores.
        '/\brojo\s+en\s+(la|las)\s+(banqueta|banquetas|acera|aceras|calle|calles)\b/iu' => 'de rojo $1 $2',

        // --- Quitar cortesías finales del relato ---
        '/\bde\s+antemano\s+(?:muchas?\s+|mil\s+|un\s+mont[oó]n\s+de\s+)?gracias\b[^.\n]*/iu' => '',
        '/\bde\s+antemano\s+(?:muy\s+)?agradecid[oa]s?\b[^.\n]*/iu' => '',
        '/\bde\s+antemano\b[^.\n]*/iu' => '',
        '/\bgracias\s+por\s+(?:su|la)\s+(?:apoyo|atenci[oó]n|ayuda|tiempo)\b[^.\n]*/iu' => '',
    ]);
}

// 3. SUSTANTIVOS COMUNES (direcciones genéricas). Minúsculas, sin tilde.
if (!defined('OFICIO_SUSTANTIVOS_GENERICOS')) {
    define('OFICIO_SUSTANTIVOS_GENERICOS', [
        'calle', 'calles', 'camino', 'caminos', 'carretera', 'carreteras',
        'avenida', 'avenidas', 'calzada', 'calzadas', 'callejon', 'callejones',
        'pasaje', 'pasajes', 'bulevar', 'bulevares', 'zona', 'zonas',
        'cuadra', 'cuadras', 'esquina', 'esquinas', 'sector', 'sectores',
        'colonia', 'colonias', 'barrio', 'barrios', 'aldea', 'aldeas',
        'caserio', 'caserios', 'canton', 'cantones', 'comunidad', 'comunidades',
        'paraje', 'parajes',
        'casa', 'casas', 'casita', 'casitas', 'vivienda', 'viviendas',
        'residencia', 'residencias', 'edificio', 'edificios',
        'apartamento', 'apartamentos', 'local', 'locales', 'negocio', 'negocios',
        'tienda', 'tiendas', 'terreno', 'terrenos', 'lote', 'lotes',
        'propiedad', 'propiedades', 'patio', 'patios',
        'lugar', 'lugares', 'vecindario', 'vecindarios',
        'aqui', 'aca', 'ahi', 'alli',
        'todo', 'todos', 'toda', 'todas',
    ]);
}

// 4. TIPOS DE LUGAR => artículo
if (!defined('OFICIO_TIPOS_LUGAR')) {
    define('OFICIO_TIPOS_LUGAR', [
        // Masculinos
        'canton' => 'el', 'caserio' => 'el', 'barrio' => 'el', 'sector' => 'el',
        'callejon' => 'el', 'camino' => 'el', 'parcelamiento' => 'el',
        'residencial' => 'el', 'casco' => 'el', 'centro' => 'el', 'mercado' => 'el',
        'cementerio' => 'el', 'salon' => 'el', 'parque' => 'el', 'puente' => 'el',
        'rio' => 'el', 'paraje' => 'el', 'cerro' => 'el', 'kilometro' => 'el',
        'pasaje' => 'el', 'bulevar' => 'el', 'edificio' => 'el',
        // Femeninos
        'aldea' => 'la', 'colonia' => 'la', 'zona' => 'la', 'calle' => 'la',
        'avenida' => 'la', 'carretera' => 'la', 'finca' => 'la',
        'lotificacion' => 'la', 'escuela' => 'la', 'iglesia' => 'la',
        'cancha' => 'la', 'comunidad' => 'la', 'calzada' => 'la', 'cuadra' => 'la',
        // Plurales
        'residenciales' => 'los', 'condominios' => 'los',
    ]);
}

// 5. MARCO POR CATEGORÍA: problema | requerimiento | tramite | emergencia | consulta
// Categoría nueva sin mapear => '_default'.
if (!defined('OFICIO_MARCO_POR_CATEGORIA')) {
    define('OFICIO_MARCO_POR_CATEGORIA', [
        // Obras y servicios
        'bacheo'                    => 'problema',
        'alumbrado_publico'         => 'problema',
        'fuga_agua'                 => 'problema',
        'recoleccion_basura'        => 'problema',
        'gestion_ambiental'         => 'problema',
        // Riesgos y tránsito
        'catastro'                  => 'emergencia',
        'via_publica'               => 'requerimiento',
        'incidentes_seguridad'      => 'emergencia',
        // CAMBIO: las denuncias entre particulares usan marco propio.
        'conflictos_particulares'   => 'denuncia',
        // Trámites y consultas
        'administrativo'            => 'tramite',
        'informacion_ciudadana'     => 'consulta',
        'informacion_actividades'   => 'consulta',
        'informacion_cursos'        => 'consulta',
        'informacion_general'       => 'consulta',
        // Fallback
        '_default'                  => 'problema',
    ]);
}

// 6. FRASES DE CIERRE DEL MARCO (una por tipo de marco)
if (!defined('OFICIO_FRASE_CIERRE_MARCO')) {
    define('OFICIO_FRASE_CIERRE_MARCO', [
        // Terminan en ":" (no en ".") para que la descripción que sigue
        // quede enlazada en una sola oración.
        'problema'      => 'a fin de solicitar su apoyo en la resolución de la siguiente problemática:',
        'requerimiento' => 'a fin de solicitar su apoyo en la atención del siguiente requerimiento:',
        'tramite'       => 'a fin de solicitar su apoyo en la gestión del siguiente trámite:',
        'emergencia'    => 'a fin de solicitar su apoyo en la atención de la siguiente emergencia:',
        'consulta'      => 'a fin de solicitar su apoyo en la atención del siguiente requerimiento informativo:',
        // NUEVO: marco para DENUNCIAS ENTRE PARTICULARES (categoría
        // 'conflictos_particulares'). Casos reales: oficios 06 y 07.
        'denuncia'      => 'a fin de solicitar su apoyo en la atención de la siguiente denuncia entre particulares:',
        '_default'      => 'a fin de solicitar su apoyo en la atención del siguiente requerimiento:',
    ]);
}

// 7. PALABRAS QUE NUNCA DEBEN ESTAR EN UN OFICIO
if (!defined('OFICIO_PROHIBIDAS')) {
    define('OFICIO_PROHIBIDAS', [
        'pendejo', 'pendeja', 'pendejos', 'pendejas',
        'puta', 'puto', 'putas', 'putos',
        'mierda', 'verga', 'cabron', 'cabrona',
        'maricon', 'marica', 'culero', 'culera',
        'idiota', 'imbecil', 'estupido', 'estupida',
        'corrupto', 'corrupta', 'ladron', 'ladrona',
        'inutil', 'inutiles', 'basura de gobierno',

        // Ampliación: insultos y descalificaciones
        'maldito', 'maldita', 'malditos', 'malditas',
        'maldito sea', 'maldita sea',
        'desgraciado', 'desgraciada', 'desgraciados', 'desgraciadas',
        'sinvergüenza', 'sinverguenza', 'sinvergüenzas',
        'caradura', 'caraduras',
        'haragán', 'haragana', 'haraganes', 'haraganas',
        'mentiroso', 'mentirosa', 'mentirosos', 'mentirosas',
        'hipócrita', 'hipocrita', 'hipócritas', 'hipocritas',
        'arrastrado', 'arrastrada', 'arrastrados', 'arrastradas',
        'ratero', 'ratera', 'rateros', 'rateras',
        'basura de persona', 'basura de alcalde',
        'no sirve el alcalde',
        // Ampliación: descalificaciones institucionales
        'municipalidad de porquería', 'municipalidad de porqueria',
        'gobierno de porquería', 'gobierno de porqueria',
        'alcalde de porquería', 'alcalde de porqueria',
        'empleados de porquería', 'empleados de porqueria',
        'solo roban', 'solo estafan', 'puros ladrones',
        'puros corruptos', 'pura corrupción', 'pura corrupcion',
        // Ampliación: vulgaridades
        'chinga', 'chingón', 'chingon', 'chingona',
        'culiao', 'culiada',
        'cagada', 'cagadas', 'cagón', 'cagon',
        'pedo', 'pedos',
        'mamada', 'mamadas', 'mamón', 'mamon',
        'joto', 'jotos',
        // Ampliación: amenazas
        'te voy a demandar', 'los voy a demandar',
        'los voy a quemar', 'te voy a quemar',
        'los voy a golpear', 'te voy a golpear',
        'me las van a pagar', 'me las vas a pagar',
    ]);
}

// ============================================================================
// 8. VOCABULARIO FORMAL (coloquial → institucional)
// ----------------------------------------------------------------------------
// Se aplica ANTES de la reestructuración causa→efecto (sección 10).
// IMPORTANTE: las reglas ESPECÍFICAS van antes que las genéricas (el orden
// del arreglo es el orden de aplicación). Singular y plural van en reglas
// separadas para no depender de grupos de captura.
// ============================================================================
if (!defined('OFICIO_REGLAS_VOCABULARIO_FORMAL')) {
    define('OFICIO_REGLAS_VOCABULARIO_FORMAL', [

        // --- TENDIDO ELÉCTRICO: contacto con cables ---
        '/\bencima\s+de\s+los?\s+cables?\b/iu'          => 'sobre el tendido eléctrico',
        '/\bencima\s+de\s+cables?\b/iu'                 => 'sobre el tendido eléctrico',
        '/\bsobre\s+los?\s+cables?(?:\s+(?:de\s+luz|el[eé]ctricos?))?\b/iu' => 'sobre el tendido eléctrico',
        '/\bsobre\s+cables?(?:\s+(?:de\s+luz|el[eé]ctricos?))?\b/iu'        => 'sobre el tendido eléctrico',
        '/\bencima\s+de\s+los?\s+alambres?\b/iu'        => 'sobre el tendido eléctrico',
        '/\bsobre\s+los?\s+alambres?\b/iu'              => 'sobre el tendido eléctrico',
        '/\bencima\s+de\s+los?\s+postes?\s+de\s+luz\b/iu' => 'sobre los postes del alumbrado público',
        '/\bencima\s+del?\s+transformador\b/iu'         => 'sobre el transformador',

        // --- HUMO / CHISPAS / LLAMAS: verbos de emisión ---
        '/\b(?:sacando|echando|tirando)\s+(?:mucho|bastante)\s+humo\b/iu' => 'emitiendo abundante humo',
        '/\b(?:sacando|echando|tirando|haciendo)\s+humo\b/iu'             => 'emitiendo humo',
        '/\b(?:sacando|echando|tirando)\s+muchas\s+chispas\b/iu'         => 'desprendiendo abundantes chispas',
        '/\b(?:sacando|echando|tirando)\s+chispas?\b/iu'                  => 'desprendiendo chispas',
        '/\b(?:sacando|echando)\s+llamas?\b/iu'                           => 'emitiendo llamas',
        '/\b(?:sacando|echando)\s+fuego\b/iu'                             => 'emitiendo fuego',
        '/\b(?:mucho|bastante)\s+humo\b/iu'                               => 'abundante humo',

        // --- COLINAS / COLONIAS (topónimos) ---
        '/\bcolina\s+(?:de\s+)?chocola\b/iu'             => 'Colonia Chocola',
        '/\bcolonias?\s+de\s+chocola\b/iu'              => 'Colonia Chocola',
        '/\bcolina\s+de\s+flor\s+del?\s+caf[eé]\b/iu'  => 'Colonia Flor del Café',
        '/\bcolina\s+(?:de\s+)?la\s+cumbre\b/iu'        => 'Colonia La Cumbre',
        '/\bcolina\s+ixcoj\b/iu'                         => 'Colonia Ixcoj',
        '/\bcolina\s+rumalinda\b/iu'                     => 'Colonia Rumalinda',
        '/\bcolina\s+vista\s+hermosa\b/iu'              => 'Colonia Vista Hermosa',
        '/\bcolina\s+valle\s+alegre\b/iu'               => 'Colonia Valle Alegre',
        '/\bcolina\s+linda\s+vista\b/iu'                => 'Colonia Linda Vista',

        // --- Agua: turbiedad, coloración, lodo, contaminación ---
        // ("biene" ya llega corregido a "viene" por OFICIO_REGLAS_ORTOGRAFIA)
        '/\bel\s+agua\s+(?:biene|viene)\s+(?:totalmente\s+|muy\s+|bien\s+)?(?:negra|oscura|negruzca)\b/iu' => 'el agua presenta una coloración oscura',
        '/\bel\s+agua\s+(?:biene|viene)\s+(?:totalmente\s+|muy\s+|bien\s+)?lodosa\b/iu' => 'el agua presenta alto contenido de lodo',
        '/\bel\s+agua\s+(?:biene|viene)\s+(?:totalmente\s+|muy\s+|bien\s+)?turbia\b/iu' => 'el agua presenta turbiedad',
        '/\by\s+lodosa\b/iu'                                         => 'y con alto contenido de lodo',
        '/\bagua\s+(?:muy\s+)?(?:negra|oscur[ao]s?|negruzca)\b/iu'   => 'agua con presencia de sedimentos oscuros',
        '/\bagua\s+(?:muy\s+)?lodosa\b/iu'                           => 'agua con alto contenido de lodo',
        '/\bagua\s+(?:muy\s+)?sucia\b/iu'                            => 'agua contaminada',
        '/\bagua\s+con\s+(?:mucho\s+)?lodo\b/iu'                     => 'agua con alto contenido de lodo',
        '/\bagua\s+con\s+(?:mucha\s+)?tierra\b/iu'                   => 'agua con presencia de sedimentos',

        // --- Alumbrado: focos, lámparas, faroles → "luminaria" (femenino: se
        //     reescribe también el artículo, porque la concordancia corre ANTES
        //     del vocabulario). Primero las frases con "está/están". ---
        '/\b(?:el|la|este|esta|ese|esa)\s+(?:foco|l[aá]mpara|farol)\s+est[aá]\s+(?:quemad|fundid|apagad)[oa]\b/iu' => 'la luminaria se encuentra fuera de servicio',
        '/\b(?:un|una)\s+(?:foco|l[aá]mpara|farol)\s+est[aá]\s+(?:quemad|fundid|apagad)[oa]\b/iu'                  => 'una luminaria se encuentra fuera de servicio',
        '/\b(?:foco|l[aá]mpara|farol)\s+est[aá]\s+(?:quemad|fundid|apagad)[oa]\b/iu'                               => 'luminaria se encuentra fuera de servicio',
        '/\b(?:los|las|estos|estas|esos|esas)\s+(?:focos|l[aá]mparas|faroles)\s+est[aá]n\s+(?:quemad|fundid|apagad)[oa]s\b/iu' => 'las luminarias se encuentran fuera de servicio',
        '/\b(?:unos|unas)\s+(?:focos|l[aá]mparas|faroles)\s+est[aá]n\s+(?:quemad|fundid|apagad)[oa]s\b/iu'         => 'unas luminarias se encuentran fuera de servicio',
        '/\b(?:focos|l[aá]mparas|faroles)\s+est[aá]n\s+(?:quemad|fundid|apagad)[oa]s\b/iu'                         => 'luminarias se encuentran fuera de servicio',
        '/\b(?:el|la|este|esta|ese|esa)\s+(?:foco|l[aá]mpara|farol)\s+(?:quemad|fundid|apagad)[oa]\b/iu'            => 'la luminaria fuera de servicio',
        '/\b(?:un|una)\s+(?:foco|l[aá]mpara|farol)\s+(?:quemad|fundid|apagad)[oa]\b/iu'                             => 'una luminaria fuera de servicio',
        '/\b(?:foco|l[aá]mpara|farol)\s+(?:quemad|fundid|apagad)[oa]\b/iu'                                          => 'luminaria fuera de servicio',
        '/\b(?:los|las|estos|estas|esos|esas)\s+(?:focos|l[aá]mparas|faroles)\s+(?:quemad|fundid|apagad)[oa]s\b/iu' => 'las luminarias fuera de servicio',
        '/\b(?:unos|unas)\s+(?:focos|l[aá]mparas|faroles)\s+(?:quemad|fundid|apagad)[oa]s\b/iu'                    => 'unas luminarias fuera de servicio',
        '/\b(?:focos|l[aá]mparas|faroles)\s+(?:quemad|fundid|apagad)[oa]s\b/iu'                                     => 'luminarias fuera de servicio',

        // --- Drenaje / alcantarillado ("se tapó" ya lo cubre la formalización) ---
        '/\bdrenaje\s+(?:tapad[oa]|obstruid[oa])\b/iu'  => 'drenaje obstruido',
        '/\bse\s+tap[oó]\s+el\s+drenaje\b/iu'           => 'el drenaje se encuentra obstruido',
        '/\bdrenaje\s+de\s+la\s+(?:calle|v[ií]a)\b(?!\s+(?:p[uú]blica|\d|principal|real|central|nueva|vieja))/iu' => 'drenaje de la vía pública',

        // --- Tapa de aguas negras fuera de sitio (va ANTES del genérico "tapa de
        //     aguas negras" de la sección de aguas, que ya existe) ---
        '/\btapa\s+(?:de\s+)?aguas?\s+negras?\s+(?:que\s+est[aá]\s+)?fuera\s+de\s+su\s+(?:agujero|lugar|sitio)\b/iu' => 'tapa del sistema de aguas residuales desplazada de su sitio',
        '/\bfuera\s+de\s+su\s+agujero\b/iu'             => 'fuera de su sitio',

        // --- Vía pública: boquete grande ("boquete"/"bachón" ya existen abajo) ---
        '/\bboquete\s+(?:grande|enorme)\b/iu'  => 'bache de gran tamaño',

        // --- Árboles y objetos sobre la vía ---
        '/\bencima\s+de\s+la\s+calle\b/iu'      => 'sobre la vía pública',
        '/\bencima\s+de\s+la\s+v[ií]a\b/iu'     => 'sobre la vía',
        '/\bencima\s+del?\s+camino\b/iu'        => 'sobre el camino',
        '/\barriba\s+de\s+la\s+calle\b/iu'      => 'sobre la vía pública',

        // --- Anegamiento ("se está llenando de agua" ya existe en formalización) ---
        '/\bse\s+llena\s+de\s+agua\b/iu'                  => 'se anega',
        '/\bacumul[aá]ndose\s+el\s+agua\b/iu'             => 'acumulándose agua',
        '/\bfrente\s+a\s+las?\s+casas?\b/iu'              => 'frente a las viviendas',
        '/\bfrente\s+a\s+mi\s+casa\b/iu'                  => 'frente a su vivienda',

        // --- Calles y vías ---
        '/\bboquete(s)?\b/iu'              => 'bache$1',
        '/\bbachal(es)?\b/iu'              => 'bache$1',
        '/\bbach[oó]n(es)?\b/iu'           => 'bache$1',
        '/\bhueco\s+en\s+la\s+calle\b(?!\s+(?:\d|principal|real|central|nueva|vieja))/iu'  => 'bache en la vía pública',
        '/\bhoyo\s+en\s+la\s+calle\b(?!\s+(?:\d|principal|real|central|nueva|vieja))/iu'   => 'bache en la vía pública',
        '/\bhoyo\s+en\s+el\s+camino\b/iu'  => 'bache en el camino',
        '/\bcalle\s+rota\b/iu'             => 'vía pública en mal estado',
        '/\bcalles\s+da[nñ]adas\b/iu'      => 'vías públicas deterioradas',
        '/\bcalle\s+da[nñ]ada\b/iu'        => 'vía pública deteriorada',
        // No toca "en la calle 5", "en la calle principal": ahí "calle" es dirección.
        '/\ben\s+la\s+calle\b(?!\s+(?:\d|principal|real|central|nueva|vieja))/iu' => 'en la vía pública',

        // --- Aguas y drenajes (específicas primero) ---
        '/\bcharcos?\s+de\s+aguas?\s+negras?\b/iu'     => 'encharcamiento de aguas residuales',
        '/\btapa\s+de\s+aguas?\s+negras?\b/iu'         => 'tapa del sistema de aguas residuales',
        '/\btuber[ií]a\s+de\s+aguas?\s+negras?\b/iu'   => 'tubería de aguas residuales',
        '/\btapa\s+del?\s+alcantarillado\b/iu'         => 'tapa del alcantarillado',
        '/\balcantarilla\s+(?:tapada|obstruida)\b/iu'  => 'alcantarilla obstruida',
        '/\balcantarillado\s+(?:tapado|obstruido)\b/iu' => 'alcantarillado obstruido',
        '/\baguas?\s+negras?\b/iu'                     => 'aguas residuales',
        '/\bdesag[uü]e\b/iu'                           => 'sistema de drenaje',

        // --- Vehículos ---
        '/\bmotos\b/iu'                         => 'motocicletas',
        '/\bmoto\b/iu'                          => 'motocicleta',
        '/(?<!tipo\s)\bpicops\b/iu'                        => 'vehículos tipo picop',
        '/(?<!tipo\s)\bpicop\b/iu'                         => 'vehículo tipo picop',
        '/\btrocas\b/iu'                        => 'camiones',
        '/\btroca\b/iu'                         => 'camión',
        '/\btuc\s*tuc(?:s|es)?\b/iu'            => 'mototaxi',
        '/\btuctuques?\b/iu'                    => 'mototaxis',

        // --- Animales ---
        '/\bchuchos\b/iu'                       => 'perros',
        '/\bchucho\b/iu'                        => 'perro',
        '/\bchuchas\b/iu'                       => 'perras',
        '/\bchucha\b/iu'                        => 'perra',
        '/\bburros\b/iu'                        => 'asnos',
        '/\bburro\b/iu'                         => 'asno',
        '/\bburras\b/iu'                        => 'asnas',
        '/\bburra\b/iu'                         => 'asna',
        '/\b(?:marranos|chanchos|puercos)\b/iu' => 'cerdos',
        '/\b(?:marrano|chancho|puerco)\b/iu'    => 'cerdo',

        // --- Personas ---
        '/\b(?:chavos|chavas|patojos|patojas)\b/iu' => 'jóvenes',
        '/\b(?:chavo|chava|patojo|patoja)\b/iu'     => 'joven',
        '/\bcipotes\b/iu'                       => 'menores de edad',
        '/\bcipote\b/iu'                        => 'menor de edad',
        '/\bplaticar\b/iu'                      => 'conversar',
        '/\bplaticando\b/iu'                    => 'conversando',
        '/\bplaticaron\b/iu'                    => 'conversaron',

        // --- Cantidades coloquiales ---
        '/\bun\s+chingo\s+de\b/iu'              => 'gran cantidad de',
        '/\bun\s+mont[oó]n\s+de\b/iu'           => 'gran cantidad de',
        '/\bmuch[ií]simos\b/iu'                 => 'numerosos',
        '/\bmuch[ií]simas\b/iu'                 => 'numerosas',
        '/\bhartos\b/iu'                        => 'numerosos',
        '/\bhartas\b/iu'                        => 'numerosas',

        // General: "Colina <Nombre Propio>" → "Colonia <Nombre Propio>" (solo si sigue mayúscula).
        '/\bcolina\s+(?=\p{Lu})/u'                       => 'Colonia ',

        // --- Ampliación: vías y superficies ---
        '/\bel\s+piso\s+est[aá]\s+roto\b/iu'       => 'la superficie de la vía se encuentra deteriorada',
        '/\bel\s+piso\s+est[aá]\s+levantado\b/iu'  => 'la superficie de la vía presenta levantamiento',
        '/\bpavimento\s+levantado\b/iu'            => 'pavimento con levantamiento',
        '/\basfalto\s+levantado\b/iu'              => 'asfalto con levantamiento',
        '/\basfalto\s+roto\b/iu'                   => 'asfalto deteriorado',
        '/\badoqu[ií]n\s+roto\b/iu'                => 'adoquín deteriorado',
        '/\badoquines\s+rotos\b/iu'                => 'adoquines deteriorados',
        '/\bempedrado\s+en\s+mal\s+estado\b/iu'    => 'empedrado deteriorado',
        '/\bterracer[ií]a\s+en\s+mal\s+estado\b/iu' => 'vía de terracería en mal estado',

        // --- Ampliación: agua y saneamiento ---
        '/\bagua\s+apestosa\b/iu'                  => 'agua con olores desagradables',
        '/\bagua\s+podrida\b/iu'                   => 'agua en estado de descomposición',
        '/\bcharco\s+de\s+agua\s+sucia\b/iu'       => 'encharcamiento de agua contaminada',
        '/\bcharcas\s+de\s+agua\s+sucia\b/iu'      => 'encharcamientos de agua contaminada',
        '/\bpozo\s+de\s+agua\s+negras?\b/iu'       => 'pozo del sistema de aguas residuales',
        '/\bpozo\s+ciego\b/iu'                     => 'pozo de absorción',
        '/\bsistema\s+de\s+alcantarillado\b(?!\s+sanitario)/iu' => 'sistema de alcantarillado sanitario',
        '/\bplanta\s+de\s+tratamiento\b(?!\s+de\s+aguas)/iu'    => 'planta de tratamiento de aguas residuales',

        // --- Ampliación: árboles y vegetación ---
        '/\bpalo\s+ca[ií]do\b/iu'                  => 'árbol caído',
        '/\bpalos\s+ca[ií]dos\b/iu'                => 'árboles caídos',
        '/\bpalo\s+seco\b/iu'                      => 'árbol seco',
        '/\bpalos\s+secos\b/iu'                    => 'árboles secos',
        '/\bmonte\s+alto\b/iu'                     => 'vegetación de gran altura',
        '/\bmaleza\s+alta\b/iu'                    => 'maleza de gran altura',
        '/\bmaleza\s+crecida\b/iu'                 => 'maleza sin control',
        '/\bpasto\s+alto\b/iu'                     => 'pasto de gran altura',

        // --- Ampliación: vehículos y tránsito ---
        '/\bcarro\s+fantasma\b/iu'                 => 'vehículo abandonado',
        '/\bcarros\s+fantasmas\b/iu'               => 'vehículos abandonados',
        '/\bcarro\s+chatarra\b/iu'                 => 'vehículo en estado de chatarra',
        '/\bcarro\s+desmantelado\b/iu'             => 'vehículo desmantelado',
        '/\bcarro\s+sin\s+llantas\b/iu'            => 'vehículo sin neumáticos',
        '/\bcarro\s+quemado\b/iu'                  => 'vehículo siniestrado',
        '/\bcarro\s+chocado\b/iu'                  => 'vehículo colisionado',
        '/\bmoto\s+tirada\b/iu'                    => 'motocicleta abandonada',
        '/\bmotos\s+tiradas\b/iu'                  => 'motocicletas abandonadas',

        // --- Ampliación: personas ---
        '/\blos\s+chavos\b/iu'                     => 'los jóvenes',
        '/\blas\s+chavas\b/iu'                     => 'las jóvenes',
        '/\bel\s+bolo\b/iu'                        => 'la persona en estado de ebriedad',
        '/\blos\s+bolos\b/iu'                      => 'las personas en estado de ebriedad',
        '/\bel\s+borracho\b/iu'                    => 'la persona en estado de ebriedad',
        '/\blos\s+borrachos\b/iu'                  => 'las personas en estado de ebriedad',
        '/\blos\s+muchachos\b/iu'                  => 'los jóvenes',
        '/\blos\s+patojos\b/iu'                    => 'los menores de edad',
        '/\blos\s+cipotes\b/iu'                    => 'los menores de edad',

        // --- Ampliación: lugar y ubicación ---
        '/\bpor\s+aqu[ií]\b/iu'                    => 'en este sector',
        '/\bpor\s+ac[aá]\b/iu'                     => 'en este sector',
        '/\bpor\s+all[aá]\b/iu'                    => 'en ese sector',
        '/\bm[aá]s\s+adelante\b/iu'                => 'a poca distancia',
        '/\bm[aá]s\s+arriba\b/iu'                  => 'en la parte alta del sector',
        '/\bm[aá]s\s+abajo\b/iu'                   => 'en la parte baja del sector',
        '/\bde\s+este\s+lado\b/iu'                 => 'por este sector',
        '/\bde\s+ese\s+lado\b/iu'                  => 'por ese sector',
        '/\ben\s+la\s+entrada\s+de\b/iu'           => 'en el acceso a',
        '/\bal\s+fondo\s+de\b/iu'                  => 'en la parte posterior de',

        // --- Ampliación: estado de cosas ---
        '/\bya\s+no\s+sirve\b/iu'                  => 'se encuentra fuera de servicio',
        '/\bya\s+no\s+funciona\b/iu'               => 'se encuentra fuera de servicio',
        '/\bya\s+no\s+da\b/iu'                     => 'ya no presta el servicio',
        '/\best[aá]\s+malo\b/iu'                   => 'se encuentra deteriorado',
        '/\best[aá]\s+mala\b/iu'                   => 'se encuentra deteriorada',
        '/\best[aá]n\s+malos\b/iu'                 => 'se encuentran deteriorados',
        '/\best[aá]n\s+malas\b/iu'                 => 'se encuentran deterioradas',
        '/\best[aá]\s+en\s+mal\s+estado\b/iu'      => 'se encuentra en mal estado',
        '/\best[aá]n\s+en\s+mal\s+estado\b/iu'     => 'se encuentran en mal estado',
        '/\best[aá]\s+destruid[oa]\b/iu'           => 'se encuentra destruido',
        '/\best[aá]n\s+destruid[oa]s\b/iu'         => 'se encuentran destruidos',
        '/\best[aá]\s+da[ñn]ad[oa]\b/iu'           => 'se encuentra dañado',
        '/\best[aá]n\s+da[ñn]ad[oa]s\b/iu'         => 'se encuentran dañados',

        // --- Ampliación: cantidades y frecuencia ---
        '/\ba\s+cada\s+rato\b/iu'                  => 'con frecuencia',
        '/\btodo\s+el\s+tiempo\b/iu'               => 'de manera constante',
        '/(?<!durante\s)\btodo\s+el\s+d[ií]a\b/iu' => 'durante todo el día',
        '/(?<!durante\s)\btoda\s+la\s+noche\b/iu'  => 'durante toda la noche',
        '/\btodos\s+los\s+d[ií]as\b/iu'            => 'diariamente',
        '/\bcada\s+d[ií]a\b/iu'                    => 'diariamente',
        '/\btodas\s+las\s+semanas\b/iu'            => 'semanalmente',
        '/\btodos\s+los\s+meses\b/iu'              => 'mensualmente',
        '/\bmuch[ií]simas\s+veces\b/iu'            => 'en numerosas ocasiones',
        '/\bhartas\s+veces\b/iu'                   => 'en numerosas ocasiones',
        '/\bun\s+mont[oó]n\s+de\s+veces\b/iu'      => 'en numerosas ocasiones',
    ]);
}

// ============================================================================
// 9. REDUNDANCIAS
// ----------------------------------------------------------------------------
// Solo repeticiones inequívocas del lenguaje oral. NO se incluyen "y ... y" ni
// "que ... que" separados por palabras: son construcciones legítimas
// ("calles y avenidas y colonias", "el problema que hay que resolver") y
// borrarlas dañaba el texto.
// ============================================================================
if (!defined('OFICIO_REGLAS_REDUNDANCIA')) {
    define('OFICIO_REGLAS_REDUNDANCIA', [
        // "ya ... ya" dentro de la misma cláusula corta
        '/\bya\s+([^.,;:!?]{1,40}?)\s+ya\s+/iu'  => 'ya $1 ',
        // palabra idéntica repetida seguida: "se se", "muy muy", "de de"
        '/\b(se|muy|mucho|mucha|muchos|muchas|de|la|el|los|las|que|en)\s+\1\b/iu' => '$1',
    ]);
}

// ============================================================================
// 10. CAUSA → EFECTO
// ----------------------------------------------------------------------------
// "X y varias motos se cayeron" → "X, el cual ha provocado la caída de
// varias motocicletas". Las listas son fragmentos regex SIN grupos de
// captura (usar (?:...) si hace falta agrupar).
// ============================================================================
if (!defined('OFICIO_REGLAS_CAUSA_EFECTO')) {
    define('OFICIO_REGLAS_CAUSA_EFECTO', [
        'verbos_dano' => 'cayeron|cay[oó]|calleron|call[oó]|chocaron|choc[oó]|volcaron|volc[oó]|golpearon|golpe[oó]|lastimaron|lastim[oó]|accidentaron|accident[oó]|da[nñ]aron|da[nñ][oó]|averiaron|averi[oó]|destrozaron|destroz[oó]|rompieron|rompi[oó]|pincharon|pinch[oó]|reventaron|revent[oó]|quebraron|quebr[oó]|partieron|parti[oó]|tumbaron|tumb[oó]|derribaron|derrib[oó]|arrastraron|arrastr[oó]|impact[oó]|atropell[oó]|atropellaron|arrollaron|arroll[oó]',

        'sujetos' => 'motos?|motocicletas?|motociclistas?|motoristas?|carros?|veh[ií]culos?|autom[oó]viles?|camiones|cami[oó]n|buses|bus|bicicletas?|ciclistas?|peatones|peat[oó]n|personas?|ni[nñ]os?|ancianos?|vecinos?|conductores|conductor|animales?|perros?|gatos?|caballos?|vacas?|toros?|reses?|asnos?|asnas?|burros?|burras?|cerdos?|gallinas?|pollos?',

        // Cuantificadores opcionales delante del sujeto ("varias motos").
        'cuantificadores' => 'varias?|varios|muchas?|muchos|algunas?|algunos|unas|unos|dos|tres|cuatro|cinco|numerosas?|numerosos|gran\s+cantidad\s+de',

        // Clave (regex, se compara contra el verbo sin tildes) => frase nominal.
        // Emisión continua sobre el tendido: "X cayó sobre Y y está emitiendo Z".
        // Todos los patrones comparten grupos: 1 sujeto, 2 receptor,
        // 3 verbo de emisión, 4 qué emite, 5 resto (ubicación, etc.).
        'patrones_emision_electrica' => [
            // "Una rama de un árbol cayó sobre <receptor> y está emitiendo humo ..."
            '/^(.+?)\s+(?:cay[oó]|se\s+cay[oó]|call[oó]|se\s+call[oó])\s+'
                . '(?:encima\s+de|sobre)\s+'
                . '(el\s+tendido\s+el[eé]ctrico|los?\s+cables?(?:\s+del\s+tendido)?|el\s+transformador|las?\s+l[ií]neas?\s+de\s+alta\s+tensi[oó]n|los?\s+postes?\s+del?\s+alumbrado\s+p[uú]blico)'
                . '\s*,?\s*y\s+est[aá]\s+(emitiendo|desprendiendo|produciendo|generando|provocando|causando)\s+'
                . '((?:(?:abundantes?|mucho|muchas|bastante)\s+)?(?:humo|chispas|llamas|fuego))\b(.*)$/iu',
            // "Se cayó un árbol sobre <receptor> y está emitiendo humo ..."
            '/^(?:se\s+)?(?:cay[oó]|call[oó])\s+((?:un|una|el|la)\s+.+?)\s+'
                . '(?:encima\s+de|sobre)\s+'
                . '(el\s+tendido\s+el[eé]ctrico|los?\s+cables?(?:\s+del\s+tendido)?|el\s+transformador|las?\s+l[ií]neas?\s+de\s+alta\s+tensi[oó]n|los?\s+postes?\s+del?\s+alumbrado\s+p[uú]blico)'
                . '\s*,?\s*y\s+est[aá]\s+(emitiendo|desprendiendo|produciendo|generando|provocando|causando)\s+'
                . '((?:(?:abundantes?|mucho|muchas|bastante)\s+)?(?:humo|chispas|llamas|fuego))\b(.*)$/iu',
        ],

        // ── Obstrucción de la vía: "X y (no) pueden pasar" ───────────────────
        // Caso real que motivó esto: "hay un árbol caído encima de la calle y no
        // podemos pasar" quedaba en el oficio como "un árbol caído encima de la
        // calle y no pueden pasar", como si fueran dos hechos separados en vez de
        // un solo problema con su consecuencia. Estas reglas convierten esa
        // segunda parte en una frase nominal ("el cual impide el paso") para que
        // la carta lea institucionalmente.
        //
        // El patrón general es:  <hecho> y (ya) (no) (se) puede/pueden/dejan/
        // permiten/impiden  pasar/transitar/circular/el paso/el tránsito/salir/
        // entrar  <cola opcional>.
        'patrones_obstruccion_via' => [
            '/^(.+?)\s*,?\s+y\s+(?:ya\s+)?(?:no\s+)?(?:se\s+)?'
                . '(?:puede|pueden|podemos|deja|dejan|dejaba|dejaban|'
                . 'permiten|permite|permit[ií]a|permit[ií]an|impiden|impide)\s+'
                . '(?:pasar|transitar|circular|el\s+paso|el\s+tr[aá]nsito|salir|entrar)'
                . '\b(.*)$/iu',
            '/^(.+?)\s*,?\s+y\s+(?:est[aá]n?\s+)?'
                . '(?:bloquea|bloquean|bloqueando|obstruye|obstruyen|obstruyendo|'
                . 'tapa|tapan|tapando|impidiendo)\s+'
                . '(?:el\s+paso|el\s+tr[aá]nsito|el\s+acceso|la\s+v[ií]a|la\s+calle)'
                . '\b(.*)$/iu',
        ],

        // ── Anegamiento: "fuga de agua y ya se está llenando de agua la calle" ──
        // Convierte la segunda parte (la consecuencia) en frase nominal:
        //   "una fuga de agua desde esta mañana y ya se está llenando de agua la calle"
        //   → "una fuga de agua desde esta mañana, la cual ha comenzado a anegar la vía pública"
        // OJO: OFICIO_REGLAS_FORMALIZACION ya convierte "se está llenando de agua"
        // en "se encuentra anegada" ANTES de llegar aquí, así que el primer patrón
        // acepta las dos formas. Grupos con nombre: hecho, receptor, cola (y
        // "lluvia" solo en el segundo patrón).
        'patrones_anegamiento' => [
            '/^(?P<hecho>.+?)\s*,?\s+y\s+(?:ya\s+)?'
                . '(?:(?:se\s+)?(?:est[aá]n?\s+)?(?:llenando|inundando|anegando)\s+(?:de\s+agua\s+)?'
                . '|se\s+encuentra[n]?\s+anegad[oa]s?\s+)'
                . '(?P<receptor>la\s+calle|el\s+camino|la\s+v[ií]a(?:\s+p[uú]blica)?|el\s+sector|la\s+colonia|el\s+barrio|'
                . 'frente\s+a\s+(?:las?\s+(?:casas?|viviendas?)|mi\s+casa|su\s+vivienda)|'
                . 'las?\s+(?:casas?|viviendas?))'
                . '\b(?P<cola>.*)$/iu',
            // "el drenaje se tapó y cuando llueve el agua se acumula frente a las casas"
            '/^(?P<hecho>.+?)\s*,?\s+y\s+(?P<lluvia>cuando\s+llueve)\s*,?\s+(?:el\s+)?agua\s+se\s+acumula\s+'
                . '(?P<receptor>frente\s+a\s+(?:las?\s+(?:casas?|viviendas?)|mi\s+casa|su\s+vivienda)|'
                . 'en\s+la\s+calle|en\s+la\s+v[ií]a(?:\s+p[uú]blica)?|en\s+el\s+sector)'
                . '\b(?P<cola>.*)$/iu',
        ],

        // ── Oscuridad: "lámpara quemada y por las noches queda bastante oscuro" ──
        //   → "una lámpara está quemada, lo cual mantiene el sector a oscuras durante la noche"
        // (el adjetivo va en grupo NO capturante para que "cola" sea el único resto)
        'patrones_oscuridad' => [
            '/^(?P<hecho>.+?)\s*,?\s+y\s+'
                . '(?:en\s+las\s+noches|por\s+las\s+noches|durante\s+la\s+noche|en\s+la\s+noche|de\s+noche)'
                . '\s+(?:queda|quedan|est[aá]n?|se\s+mantiene[n]?|permanece[n]?|se\s+ve)\s+'
                . '(?:bastante\s+|muy\s+|totalmente\s+|demasiado\s+)?'
                . '(?:oscuro|oscura|a\s+oscuras|sin\s+iluminaci[oó]n|sin\s+luz)'
                . '\b(?P<cola>.*)$/iu',
        ],

        'mapa_verbos' => [
            'cayeron|cayo|calleron|callo' => 'la caída de',
            'chocaron|choco'              => 'la colisión de',
            'volcaron|volco'              => 'el vuelco de',
            'golpearon|golpeo'            => 'el impacto contra',
            'lastimaron|lastimo'          => 'las lesiones de',
            'accidentaron|accidento'      => 'el accidente de',
            'danaron|dano|averiaron|averio|rompieron|rompio' => 'los daños de',
            'destrozaron|destrozo'        => 'la destrucción de',
            'pincharon|pincho'            => 'el pinchazo de',
        ],
    ]);
}

// ============================================================================
// 14. CONCORDANCIA DE GÉNERO Y NÚMERO
// ----------------------------------------------------------------------------
// Corrige artículos que no concuerdan con el sustantivo ("la problema" →
// "el problema", "un emergencia" → "una emergencia").
// ============================================================================
if (!defined('OFICIO_REGLAS_CONCORDANCIA')) {
    define('OFICIO_REGLAS_CONCORDANCIA', [
        // Masculinos terminados en -a
        '/\bla\s+(problema|sistema|tema|clima|idioma|mapa|drama|planeta|esquema|diagrama|programa|tel[eé]fono|d[ií]a)\b/iu' => 'el $1',
        '/\buna\s+(problema|sistema|tema|clima|idioma|mapa|planeta|programa|d[ií]a)\b/iu' => 'un $1',
        '/\blas\s+(problemas|sistemas|temas|mapas|programas)\b/iu'   => 'los $1',
        '/\bunas\s+(problemas|sistemas|temas|mapas|programas)\b/iu'  => 'unos $1',

        // Femeninos mal concordados
        '/\bel\s+(emergencia|urgencia|importancia|necesidad|comunidad|colonia|calle|avenida|zona|aldea|casa|vivienda)\b/iu' => 'la $1',
        '/\bun\s+(emergencia|urgencia|importancia|necesidad|comunidad|colonia|calle|avenida|zona|aldea|casa|vivienda)\b/iu' => 'una $1',
        '/\blos\s+(emergencias|urgencias|necesidades|comunidades|colonias|calles|avenidas|zonas|aldeas|casas|viviendas)\b/iu' => 'las $1',
        '/\bunos\s+(emergencias|urgencias|necesidades|comunidades|colonias|calles|avenidas|zonas|aldeas|casas|viviendas)\b/iu' => 'unas $1',

        // "un agua fugándose"
        '/\bun\s+agua\s+fug[aá]ndose\b/iu'          => 'una fuga de agua',
        '/\bun\s+agua\s+est[aá]\s+corriendo\b/iu'  => 'una fuga de agua',

        // "mucho problema" → "muchos problemas"
        '/\bmucho\s+(problema|da[nñ]o|bache|hueco|hoyo|poste|cable)\b/iu' => 'muchos $1s',

        // Artículo con número equivocado
        '/\blos\s+agua\b/iu'                         => 'las aguas',
        '/\blas\s+caminos?\b/iu'                     => 'los caminos',
        '/\blos\s+calles?\b/iu'                      => 'las calles',
        '/\blos\s+colonias?\b/iu'                    => 'las colonias',
        '/\blos\s+casas?\b/iu'                       => 'las casas',
        '/\blas\s+problemas?\b/iu'                   => 'los problemas',
        '/\blas\s+veh[ií]culos?\b/iu'                => 'los vehículos',
        '/\blos\s+personas?\b/iu'                    => 'las personas',
        '/\blas\s+vecinos?\b/iu'                     => 'los vecinos',

        // Ampliación: concordancia de artículos y adjetivos comunes
        '/\blas\s+faroles?\s+apagad[oa]s?\b/iu'    => 'los faroles apagados',
        '/\blos\s+l[aá]mparas?\s+apagad[oa]s?\b/iu' => 'las lámparas apagadas',
        '/\blas\s+focos?\s+quemad[oa]s?\b/iu'      => 'los focos quemados',
        '/\blos\s+luminarias?\s+fundid[oa]s?\b/iu' => 'las luminarias fundidas',
        '/\bun\s+poste\s+ca[ií]d[oa]\b/iu'         => 'un poste caído',
        '/\buna\s+poste\s+ca[ií]d[oa]\b/iu'        => 'un poste caído',
        '/\bel\s+calle\s+est[aá]\s+rota\b/iu'      => 'la calle está rota',
        '/\bla\s+cami[oó]n\s+no\s+pasa\b/iu'       => 'el camión no pasa',
        '/\bel\s+tuber[ií]a\s+est[aá]\s+rota\b/iu' => 'la tubería está rota',
        '/\bla\s+problema\b/iu'                    => 'el problema',
        '/\bel\s+emergencia\b/iu'                  => 'la emergencia',
        '/\bun\s+emergencia\b/iu'                  => 'una emergencia',
        '/\bel\s+urgencia\b/iu'                    => 'la urgencia',
        '/\bun\s+urgencia\b/iu'                    => 'una urgencia',
        '/\bmucho\s+basura\b/iu'                   => 'mucha basura',
        '/\bmucha\s+ruido\b/iu'                    => 'mucho ruido',
        '/\bmucha\s+humo\b/iu'                     => 'mucho humo',
        '/\bla\s+agua\s+est[aá]\s+negra\b/iu'      => 'el agua está negra',
    ]);
}

// ============================================================================
// 15. COHERENCIA DE TIEMPO VERBAL / PERSONA
// ----------------------------------------------------------------------------
// Solo reglas que NO alteran el hecho narrado: pasan la primera persona del
// peticionario a forma impersonal. No se convierten "hay" → "había" ni
// "se está cayendo" → "se ha caído": cambiarían el estado real reportado.
// ============================================================================
if (!defined('OFICIO_REGLAS_TIEMPO_VERBAL')) {
    define('OFICIO_REGLAS_TIEMPO_VERBAL', [
        '/\byo\s+reporto\s+que\b/iu'            => 'se reporta que',
        '/\byo\s+informo\s+que\b/iu'            => 'se informa que',
        '/\byo\s+comunico\s+que\b/iu'           => 'se comunica que',
        '/\byo\s+denuncio\s+que\b/iu'           => 'se denuncia que',
        '/\byo\s+manifiesto\s+que\b/iu'         => 'se manifiesta que',
        '/\bnosotros\s+reportamos\s+que\b/iu'   => 'se reporta que',
        '/\bnosotros\s+informamos\s+que\b/iu'   => 'se informa que',
    ]);
}

// ============================================================================
// 16. NÚMEROS EN LETRAS Y ORDINALES
// ----------------------------------------------------------------------------
// Ordinales de vía ("5ta calle" → "Quinta Calle") y cantidades 1-10 delante
// de sustantivos contables ("3 días" → "tres días", "1 casa" → "una casa").
// No toca teléfonos, DPI, decimales ni direcciones numéricas.
// ============================================================================
if (!defined('OFICIO_REGLAS_NUMEROS')) {
    define('OFICIO_REGLAS_NUMEROS', [
        '/\b1ra\.?\s+calle\b/iu'              => 'Primera Calle',
        '/\b2da\.?\s+calle\b/iu'              => 'Segunda Calle',
        '/\b3ra\.?\s+calle\b/iu'              => 'Tercera Calle',
        '/\b4ta\.?\s+calle\b/iu'              => 'Cuarta Calle',
        '/\b5ta\.?\s+calle\b/iu'              => 'Quinta Calle',
        '/\b6ta\.?\s+calle\b/iu'              => 'Sexta Calle',
        '/\b7ma\.?\s+calle\b/iu'              => 'Séptima Calle',
        '/\b8va\.?\s+calle\b/iu'              => 'Octava Calle',
        '/\b9na\.?\s+calle\b/iu'              => 'Novena Calle',
        '/\b10ma\.?\s+calle\b/iu'             => 'Décima Calle',
        '/\b1ra\.?\s+avenida\b/iu'            => 'Primera Avenida',
        '/\b2da\.?\s+avenida\b/iu'            => 'Segunda Avenida',
        '/\b3ra\.?\s+avenida\b/iu'            => 'Tercera Avenida',
        '/\b4ta\.?\s+avenida\b/iu'            => 'Cuarta Avenida',
        '/\b5ta\.?\s+avenida\b/iu'            => 'Quinta Avenida',
        '/\b6ta\.?\s+avenida\b/iu'            => 'Sexta Avenida',
        '/\b7ma\.?\s+avenida\b/iu'            => 'Séptima Avenida',
        '/\b8va\.?\s+avenida\b/iu'            => 'Octava Avenida',
        '/\b9na\.?\s+avenida\b/iu'            => 'Novena Avenida',
        '/\b10ma\.?\s+avenida\b/iu'           => 'Décima Avenida',
        '/\b1\s+calle\b/iu'                   => 'Primera Calle',
        '/\b2\s+calle\b/iu'                   => 'Segunda Calle',
        '/\b3\s+calle\b/iu'                   => 'Tercera Calle',
        '/\b4\s+calle\b/iu'                   => 'Cuarta Calle',
        '/\b5\s+calle\b/iu'                   => 'Quinta Calle',
        '/\b6\s+calle\b/iu'                   => 'Sexta Calle',
        '/\b7\s+calle\b/iu'                   => 'Séptima Calle',
        '/\b8\s+calle\b/iu'                   => 'Octava Calle',
        '/\b9\s+calle\b/iu'                   => 'Novena Calle',
        '/\b10\s+calle\b/iu'                  => 'Décima Calle',
        '/\b1\s+avenida\b/iu'                 => 'Primera Avenida',
        '/\b2\s+avenida\b/iu'                 => 'Segunda Avenida',
        '/\b3\s+avenida\b/iu'                 => 'Tercera Avenida',
        '/\b4\s+avenida\b/iu'                 => 'Cuarta Avenida',
        '/\b5\s+avenida\b/iu'                 => 'Quinta Avenida',
        '/\b6\s+avenida\b/iu'                 => 'Sexta Avenida',
        '/\b7\s+avenida\b/iu'                 => 'Séptima Avenida',
        '/\b8\s+avenida\b/iu'                 => 'Octava Avenida',
        '/\b9\s+avenida\b/iu'                 => 'Novena Avenida',
        '/\b10\s+avenida\b/iu'                => 'Décima Avenida',
        '/\bzona\s+uno\b/iu'                  => 'Zona 1',
        '/\bzona\s+dos\b/iu'                  => 'Zona 2',
        '/\bzona\s+tres\b/iu'                 => 'Zona 3',
        '/\bzona\s+cuatro\b/iu'               => 'Zona 4',
        '/\bzona\s+cinco\b/iu'                => 'Zona 5',
        '/\bzona\s+seis\b/iu'                 => 'Zona 6',
        '/(?<![\d.,])\b1\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'un $1',
        '/(?<![\d.,])\b1\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'una $1',
        '/(?<![\d.,])\b2\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'dos $1',
        '/(?<![\d.,])\b2\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'dos $1',
        '/(?<![\d.,])\b3\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'tres $1',
        '/(?<![\d.,])\b3\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'tres $1',
        '/(?<![\d.,])\b4\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'cuatro $1',
        '/(?<![\d.,])\b4\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'cuatro $1',
        '/(?<![\d.,])\b5\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'cinco $1',
        '/(?<![\d.,])\b5\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'cinco $1',
        '/(?<![\d.,])\b6\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'seis $1',
        '/(?<![\d.,])\b6\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'seis $1',
        '/(?<![\d.,])\b7\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'siete $1',
        '/(?<![\d.,])\b7\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'siete $1',
        '/(?<![\d.,])\b8\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'ocho $1',
        '/(?<![\d.,])\b8\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'ocho $1',
        '/(?<![\d.,])\b9\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'nueve $1',
        '/(?<![\d.,])\b9\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'nueve $1',
        '/(?<![\d.,])\b10\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?)\b/iu' => 'diez $1',
        '/(?<![\d.,])\b10\s+(semanas?|horas?|personas?|familias?|viviendas?|casas?|motocicletas?|calles?|lámparas?)\b/iu' => 'diez $1',

        // Ampliación: más ordinales de vía con notación con punto o letra
        '/\b1a\.?\s+calle\b/iu' => 'Primera Calle',
        '/\b2a\.?\s+calle\b/iu' => 'Segunda Calle',
        '/\b3a\.?\s+calle\b/iu' => 'Tercera Calle',
        '/\b4a\.?\s+calle\b/iu' => 'Cuarta Calle',
        '/\b5a\.?\s+calle\b/iu' => 'Quinta Calle',
        '/\b6a\.?\s+calle\b/iu' => 'Sexta Calle',
        '/\b7a\.?\s+calle\b/iu' => 'Séptima Calle',
        '/\b8a\.?\s+calle\b/iu' => 'Octava Calle',
        '/\b9a\.?\s+calle\b/iu' => 'Novena Calle',
        '/\b10a\.?\s+calle\b/iu' => 'Décima Calle',
        '/\b1era\.?\s+calle\b/iu' => 'Primera Calle',
        '/\b2da\.?\s+calle\b/iu' => 'Segunda Calle',
        '/\b1ra\.?\s+avenida\b/iu' => 'Primera Avenida',
        '/\b2da\.?\s+avenida\b/iu' => 'Segunda Avenida',
        '/\b3ra\.?\s+avenida\b/iu' => 'Tercera Avenida',
        '/\b4ta\.?\s+avenida\b/iu' => 'Cuarta Avenida',
        '/\b5ta\.?\s+avenida\b/iu' => 'Quinta Avenida',
        '/\b6ta\.?\s+avenida\b/iu' => 'Sexta Avenida',
        '/\b7ma\.?\s+avenida\b/iu' => 'Séptima Avenida',
        '/\b8va\.?\s+avenida\b/iu' => 'Octava Avenida',
        '/\b9na\.?\s+avenida\b/iu' => 'Novena Avenida',
        '/\b10ma\.?\s+avenida\b/iu' => 'Décima Avenida',
        '/\b11\s+calle\b/iu' => 'Onceava Calle',
        '/\b12\s+calle\b/iu' => 'Doceava Calle',
        '/\b13\s+calle\b/iu' => 'Treceava Calle',
        '/\b14\s+calle\b/iu' => 'Catorceava Calle',
        '/\b15\s+calle\b/iu' => 'Quinceava Calle',
        '/\b11\s+avenida\b/iu' => 'Onceava Avenida',
        '/\b12\s+avenida\b/iu' => 'Doceava Avenida',
        '/\b13\s+avenida\b/iu' => 'Treceava Avenida',
        '/\b14\s+avenida\b/iu' => 'Catorceava Avenida',
        '/\b15\s+avenida\b/iu' => 'Quinceava Avenida',
        // Ampliación: números 11-15 delante de sustantivos contables
        '/(?<![\d.,])\b11\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?|familias?|viviendas?|casas?|calles?|l[aá]mparas?)\b/iu' => 'once $1',
        '/(?<![\d.,])\b12\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?|familias?|viviendas?|casas?|calles?|l[aá]mparas?)\b/iu' => 'doce $1',
        '/(?<![\d.,])\b13\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?|familias?|viviendas?|casas?|calles?|l[aá]mparas?)\b/iu' => 'trece $1',
        '/(?<![\d.,])\b14\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?|familias?|viviendas?|casas?|calles?|l[aá]mparas?)\b/iu' => 'catorce $1',
        '/(?<![\d.,])\b15\s+(d[ií]as?|meses|mes|a[nñ]os?|veh[ií]culos?|carros?|baches?|postes?|cables?|[aá]rboles|[aá]rbol|ni[nñ]os?|vecinos?|camiones|hoyos?|familias?|viviendas?|casas?|calles?|l[aá]mparas?)\b/iu' => 'quince $1',
    ]);
}

// ============================================================================
// 17. MULETILLAS Y OPINIONES PERSONALES
// ============================================================================
if (!defined('OFICIO_REGLAS_MULETILLAS')) {
    define('OFICIO_REGLAS_MULETILLAS', [
        // Eliminar al inicio del relato
        '/^a\s+mi\s+parecer\s*,?\s*/iu'                 => '',
        '/^en\s+mi\s+opini[oó]n\s*,?\s*/iu'             => '',
        '/^desde\s+mi\s+punto\s+de\s+vista\s*,?\s*/iu' => '',
        '/^la\s+verdad\s+es\s+que\s+/iu'                => '',
        '/^la\s+verdad\s*,\s*/iu'                        => '',
        '/^sinceramente\s*,?\s*/iu'                       => '',
        '/^francamente\s*,?\s*/iu'                        => '',
        '/^honestamente\s*,?\s*/iu'                       => '',
        '/^a\s+decir\s+verdad\s*,?\s*/iu'               => '',
        '/^para\s+ser\s+honestos?\s*,?\s*/iu'           => '',
        '/^como\s+usted\s+sabe\s*,?\s*/iu'              => '',
        '/^como\s+es\s+sabido\s*,?\s*/iu'               => '',
        '/^como\s+todos\s+saben\s*,?\s*/iu'             => '',
        '/^en\s+serio\s*,\s*/iu'                         => '',
        '/^de\s+verdad\s*,\s*/iu'                        => '',
        '/^obviamente\s*,?\s*/iu'                         => '',
        '/^por\s+supuesto\s*,?\s*/iu'                    => '',
        '/^claro\s+que\s+s[ií]\s*,?\s*/iu'              => '',
        '/^l[oó]gicamente\s*,?\s*/iu'                     => '',
        '/^naturalmente\s*,?\s*/iu'                       => '',
        '/^como\s+sea\s*,?\s*/iu'                        => '',
        '/^de\s+todas?\s+formas?\s*,?\s*/iu'            => '',
        '/^de\s+todos?\s+modos?\s*,?\s*/iu'             => '',
        '/^en\s+fin\s*,\s*/iu'                           => '',

        // Opiniones → impersonal
        '/\byo\s+creo\s+que\b/iu'                       => 'se considera que',
        '/\bcreo\s+que\b/iu'                             => 'se considera que',
        '/\byo\s+pienso\s+que\b/iu'                     => 'se estima que',
        '/\bpienso\s+que\b/iu'                           => 'se estima que',
        '/\byo\s+considero\s+que\b/iu'                  => 'se considera que',
        '/\bconsidero\s+que\b/iu'                        => 'se considera que',
        '/\ba\s+m[ií]\s+me\s+parece\s+que\b/iu'       => 'se estima que',
        '/\bme\s+parece\s+que\b/iu'                     => 'se estima que',
        '/\bme\s+da\s+la\s+impresi[oó]n\s+de\s+que\b/iu' => 'se advierte que',
        '/\bseg[uú]n\s+yo\b/iu'                          => 'según lo observado',
        '/\bseg[uú]n\s+mi\s+parecer\b/iu'               => 'según lo observado',
        '/\bhasta\s+donde\s+yo\s+s[eé]\b/iu'           => 'según lo observado',
        '/\bque\s+yo\s+sepa\b/iu'                       => 'según lo observado',
        '/\bpor\s+lo\s+que\s+yo\s+veo\b/iu'           => 'según lo observado',

        // Ampliación: muletillas al inicio del relato (de la más larga a la más corta)
        '/^mire\s+le\s+cuento\s+que\s+/iu'             => '',
        '/^f[ií]jese\s+que\s+le\s+cuento\s+que\s+/iu'  => '',
        '/^mire\s+f[ií]jese\s+que\s+/iu'               => '',
        '/^f[ií]jese\s+usted\s+que\s+/iu'              => '',
        '/^mire\s+usted\s+que\s+/iu'                   => '',
        '/^es\s+que\s+resulta\s+que\s+/iu'             => '',
        '/^le\s+comento\s+que\s+/iu'                   => '',
        '/^le\s+cuento\s+que\s+/iu'                    => '',
        '/^mire\s+que\s+/iu'                           => '',
        '/^vea\s+que\s+/iu'                            => '',
        '/^oiga\s+que\s+/iu'                           => '',
        '/^vea\s+pues\s*,?\s*/iu'                      => '',
        '/^bueno\s+pues\s*,?\s*/iu'                    => '',
        '/^oigame\s*,?\s*/iu'                          => '',
        '/^mir[aá]\s*,?\s*/iu'                         => '',
        '/^mire\s+(usted\s+)?/iu'                      => '',
        '/^vea\s+(usted\s+)?/iu'                       => '',
        '/^oiga\s+(usted\s+)?/iu'                      => '',
        '/^es\s+que\s+/iu'                             => '',
        '/^le\s+comento\s+/iu'                         => '',
        '/^le\s+cuento\s+/iu'                          => '',
        // Ampliación: opiniones y valoraciones subjetivas
        '/\bes\s+una\s+l[aá]stima\s+que\b/iu'          => 'se observa que',
        '/\bes\s+una\s+pena\s+que\b/iu'                => 'se observa que',
        '/\bes\s+una\s+verg[uü]enza\s+que\b/iu'        => 'resulta inaceptable que',
        '/\bes\s+inaceptable\s+que\b/iu'               => 'resulta inaceptable que',
        '/\bes\s+un\s+abuso\s+que\b/iu'                => 'resulta abusivo que',
        '/\bno\s+es\s+justo\s+que\b/iu'                => 'se considera injusto que',
        '/\bda\s+mucha\s+pena\b/iu'                    => 'resulta lamentable',
        '/\bda\s+l[aá]stima\b/iu'                      => 'resulta lamentable',
        '/\bda\s+mucha\s+rabia\b/iu'                   => 'resulta preocupante',
        '/\bes\s+una\s+falta\s+de\s+respeto\b/iu'      => 'constituye una falta de respeto a la comunidad',
        '/\bno\s+nos\s+toman\s+en\s+cuenta\b/iu'       => 'la comunidad no ha sido tomada en cuenta',
        '/\bnos\s+tienen\s+abandonados\b/iu'           => 'el sector se encuentra en situación de abandono',
        '/\bnos\s+tienen\s+olvidados\b/iu'             => 'el sector se encuentra en situación de abandono',
    ]);
}

// ============================================================================
// 18. CITAS TEXTUALES
// ----------------------------------------------------------------------------
// Convertir discurso directo a indirecto exige cambiar personas y verbos
// ("no te metas" → "que no se meta"), algo que una regex no puede hacer con
// seguridad. Por eso la cita se conserva literal, entre comillas latinas,
// introducida en forma impersonal. Así el oficio no queda con frases
// gramaticalmente rotas.
// ============================================================================
if (!defined('OFICIO_REGLAS_CITAS')) {
    define('OFICIO_REGLAS_CITAS', [
        '/\ble\s+dije\s*:?\s*["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/iu' => 'se le manifestó lo siguiente: «$1»',
        '/\bles\s+dije\s*:?\s*["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/iu' => 'se les manifestó lo siguiente: «$1»',
        '/\bme\s+dijo\s*:?\s*["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/iu' => 'se manifestó lo siguiente: «$1»',
        '/\bnos\s+dijo\s*:?\s*["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/iu' => 'se manifestó lo siguiente: «$1»',
        '/\bdijo\s*:?\s*["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/iu' => 'manifestó lo siguiente: «$1»',
        '/\bcoment[oó]\s*:?\s*["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/iu' => 'comentó lo siguiente: «$1»',
    ]);
}

// ============================================================================
// 19. PUNTUACIÓN FINAL (se aplica después de todas las sustituciones)
// ============================================================================
if (!defined('OFICIO_REGLAS_PUNTUACION_FINAL')) {
    define('OFICIO_REGLAS_PUNTUACION_FINAL', [
        '/\.{2,}/u'                     => '.',
        '/…/u'                           => '.',
        '/,{2,}/u'                       => ',',
        '/!{2,}/u'                       => '!',
        '/\?{2,}/u'                      => '?',
        '/[!?]{2,}/u'                    => '.',
        '/[.!,;:]{2,}/u'                 => '.',
        '/\s+([,.;:!?])/u'               => '$1',
        // Espacio tras , ; : solo antes de letra (no rompe "10:30" ni "2,5")
        '/(?<!\d)([,;:])(?=\p{L})/u'     => '$1 ',
        '/,\s*\./u'                     => '.',
        '/\.\s*\./u'                    => '.',
        '/[ \t]{2,}/u'                  => ' ',
        '/[ \t]+\n/u'                  => "\n",
        '/\n[ \t]+/u'                  => "\n",
        '/\n{2,}/u'                     => "\n",
        '/\s+([)}\]])/u'                => '$1',
        '/([(\[{])\s+/u'               => '$1',
        // Comilla de apertura seguida de espacio (quedó tras eliminar una muletilla)
        '/(?<=[\s:(])"\s+(?=\p{L})/u'   => '"',
    ]);
}

// ============================================================================
// 20. ENRIQUECIMIENTO DE LA REDACCIÓN
// ----------------------------------------------------------------------------
// Conectores y frases que dan fluidez al cuerpo del oficio (ver
// cuerpoCartaOficio() en oficio_html.php). La elección entre variantes es
// DETERMINISTA por número de solicitud: el mismo expediente siempre genera el
// mismo texto, aunque se reimprima o se vuelva a abrir.
// ============================================================================
if (!defined('OFICIO_REGLAS_ENRIQUECIMIENTO')) {
    define('OFICIO_REGLAS_ENRIQUECIMIENTO', [
        // Conectores que van seguidos de coma ("Además, se sugiere ...")
        'conectores_transicion' => [
            'además', 'asimismo', 'de igual manera', 'en ese mismo sentido',
        ],
        // Introductores que ya traen "que" (no llevan coma después). Reservados
        // para usos futuros; hoy no se usan en el párrafo 2.
        'conectores_con_que' => [
            'cabe mencionar que', 'es importante señalar que',
            'cabe destacar que', 'resulta pertinente indicar que',
        ],
        // Verbos de reporte formales. Solo se usan los 5 primeros porque
        // funcionan tanto con "que + oración" como con un sintagma nominal.
        'verbos_reporte' => [
            'reporta', 'manifiesta', 'señala', 'indica', 'expone',
            'informa', 'comunica', 'da a conocer', 'hace saber',
        ],
        // Cierres de contexto comunitario (solo para reportes de interés general)
        'cierres_contexto' => [
            'situación que afecta directamente a los vecinos del sector',
            'situación que vulnera los derechos de la comunidad',
            'situación que atenta contra la calidad de vida de los habitantes',
            'situación que representa un riesgo latente para la población',
            'situación que requiere la intervención inmediata de la autoridad',
        ],
        // Fórmulas de cierre del oficio
        'cierres_formales' => [
            'Sin otro particular, me suscribo de usted de la manera más atenta.',
            'Sin otro particular por el momento, me suscribo de usted con las muestras de mi consideración y estima.',
            'Agradeciendo de antemano su atención a la presente, me suscribo de usted de la manera más atenta.',
            'Sin otro particular, agradezco su atención y me suscribo de usted con las muestras de mi respeto.',
            'En espera de su pronta intervención, me suscribo de usted de la manera más atenta.',
        ],
    ]);
}

// ============================================================================
// 21. FORMALIZACIÓN DEL TEXTO DEL CIUDADANO
// ----------------------------------------------------------------------------
// formalizarTextoCiudadano(): limpieza final y ligera del texto que se inserta
// después de "a fin de solicitar su apoyo en ...:". Solo se aplica a la
// descripción formal (III-A); la transcripción textual (III-B) NO se toca.
//   • Colapsa espacios.
//   • Quita comillas rectas/tipográficas/simples (conserva «...» que generan
//     las reglas de OFICIO_REGLAS_CITAS para el discurso directo).
//   • Corrige tildes frecuentes.
//   • Capitaliza el inicio de cada oración.
//   • Asegura punto final.
// ============================================================================
if (!function_exists('formalizarTextoCiudadano')) {
    function formalizarTextoCiudadano(string $texto): string
    {
        $t = preg_replace('/\s+/u', ' ', trim($texto));
        if ($t === null || $t === '') {
            return '';
        }

        // Comillas internas (se conservan las latinas « » de las citas).
        $t = str_replace(['"', "'", '“', '”', '‘', '’'], '', $t);

        $correcciones = [
            '/\brevento\b/iu'  => 'reventó',
            '/\brebento\b/iu'  => 'reventó',
            '/\bcayo\b/iu'     => 'cayó',
            '/\bestan\b/iu'    => 'están',
            '/\besta\b(?=\s+(?:muy|lleno|llena|tapad|roto|rota|dañad|inundad|reventad|caíd))/iu' => 'está',
            '/\bmas\b(?=\s+(?:de|que|grande|pequeñ))/iu' => 'más',
            '/\baun\b(?=\s)/iu'      => 'aún',
            '/\btambien\b/iu'        => 'también',
            '/\bdespues\b/iu'        => 'después',
            '/\bquizas\b/iu'         => 'quizás',
        ];
        foreach ($correcciones as $patron => $reemplazo) {
            $t = preg_replace($patron, $reemplazo, $t) ?? $t;
        }

        // Mayúscula inicial de cada oración.
        $t = preg_replace_callback(
            '/(^|[.!?]\s+)([a-záéíóúüñ])/u',
            static function ($m) { return $m[1] . mb_strtoupper($m[2], 'UTF-8'); },
            $t
        ) ?? $t;

        // Punto final (también tras una cita «...»).
        if (!preg_match('/[.!?]$/u', $t)) {
            $t .= '.';
        }

        return $t;
    }
}
