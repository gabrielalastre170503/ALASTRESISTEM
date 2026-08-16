<?php
/**
 * AlastreSystem - maquina de estados del pipeline.
 *
 * Un lead es un archivo JSON. La etapa es la carpeta donde vive.
 * Cambiar de etapa = mover el archivo. No hay base de datos ni bloqueos.
 */

declare(strict_types=1);

const ETAPAS = [
    '00-descubierto',
    '10-calificado',
    '20-auditado',
    '30-construido',
    '40-por-aprobar',
    '50-enviado',
    '60-respondido',
    '70-reunion',
    '90-cerrado',
    '99-descartado',
];

const SUPRESION  = '_supresion';
const TRABAJANDO = '_trabajando';

function ruta_pipeline(string $carpeta = ''): string
{
    $base = BASE_PATH . '/pipeline';
    return $carpeta === '' ? $base : $base . '/' . $carpeta;
}

/**
 * IDs de los leads que hay en una etapa.
 *
 * @return list<string>
 */
function leads_en(string $etapa): array
{
    $archivos = glob(ruta_pipeline($etapa) . '/*.json') ?: [];
    return array_map(static fn(string $r): string => basename($r, '.json'), $archivos);
}

/**
 * Localiza en que etapa esta un lead, o null si no esta en el pipeline.
 */
function etapa_de(string $id): ?string
{
    foreach (array_merge(ETAPAS, [SUPRESION]) as $etapa) {
        if (is_file(ruta_pipeline($etapa) . "/{$id}.json")) {
            return $etapa;
        }
    }
    return null;
}

function cargar_lead(string $etapa, string $id): array
{
    $ruta = ruta_pipeline($etapa) . "/{$id}.json";
    $json = file_get_contents($ruta);

    if ($json === false) {
        throw new RuntimeException("No se pudo leer {$ruta}");
    }

    return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Escritura atomica: se vuelca en un temporal y se renombra encima.
 * Asi un fallo a medias nunca deja un JSON truncado.
 */
function guardar_lead(string $etapa, string $id, array $lead): void
{
    $destino = ruta_pipeline($etapa) . "/{$id}.json";
    $temp    = $destino . '.tmp';

    $json = json_encode(
        $lead,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    if (file_put_contents($temp, $json) === false) {
        throw new RuntimeException("No se pudo escribir {$temp}");
    }

    // Windows no sobrescribe con rename(): hay que quitar el destino antes.
    if (is_file($destino)) {
        unlink($destino);
    }

    if (!rename($temp, $destino)) {
        unlink($temp);
        throw new RuntimeException("No se pudo renombrar {$temp} -> {$destino}");
    }
}

/**
 * Mueve un lead de una etapa a otra y deja constancia en su historial.
 * Devuelve false si el lead no estaba donde se dijo.
 */
function promover(string $id, string $desde, string $hacia, string $nota = ''): bool
{
    $origen = ruta_pipeline($desde) . "/{$id}.json";

    if (!is_file($origen)) {
        return false;
    }

    $lead = cargar_lead($desde, $id);
    $lead['historial'][] = [
        'etapa' => $hacia,
        'fecha' => date('c'),
        'nota'  => $nota,
    ];

    guardar_lead($desde, $id, $lead);

    $destino = ruta_pipeline($hacia) . "/{$id}.json";
    if (is_file($destino)) {
        unlink($destino);
    }

    return rename($origen, $destino);
}

/**
 * Reclama un lead moviendolo a _trabajando/<agente>/ para que ningun otro
 * proceso lo toque. Devuelve la ruta reclamada o null si ya no estaba.
 */
function reclamar(string $etapa, string $id, string $agente): ?string
{
    $origen  = ruta_pipeline($etapa) . "/{$id}.json";
    $carpeta = ruta_pipeline(TRABAJANDO) . '/' . $agente;

    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0775, true);
    }

    $destino = $carpeta . "/{$id}.json";

    // rename() falla si otro proceso se lo llevo primero: eso es el candado.
    return @rename($origen, $destino) ? $destino : null;
}

/**
 * Devuelve un lead reclamado a una etapa del pipeline.
 */
function soltar(string $id, string $agente, string $hacia, string $nota = ''): bool
{
    $origen = ruta_pipeline(TRABAJANDO) . "/{$agente}/{$id}.json";

    if (!is_file($origen)) {
        return false;
    }

    $lead = json_decode((string) file_get_contents($origen), true, 512, JSON_THROW_ON_ERROR);
    $lead['historial'][] = ['etapa' => $hacia, 'fecha' => date('c'), 'nota' => $nota];

    file_put_contents(
        $origen,
        json_encode($lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $destino = ruta_pipeline($hacia) . "/{$id}.json";
    if (is_file($destino)) {
        unlink($destino);
    }

    return rename($origen, $destino);
}

/**
 * Lista de no-contactar. Se consulta ANTES de cualquier envio.
 */
function en_supresion(string $id): bool
{
    return is_file(ruta_pipeline(SUPRESION) . "/{$id}.json");
}

/**
 * Recuento de leads por etapa, para el panel y para bin/estado.php.
 *
 * @return array<string,int>
 */
function recuento(): array
{
    $total = [];
    foreach (array_merge(ETAPAS, [SUPRESION]) as $etapa) {
        $total[$etapa] = count(leads_en($etapa));
    }
    return $total;
}

/**
 * Crea las carpetas del pipeline si faltan. Idempotente.
 */
function preparar_pipeline(): void
{
    foreach (array_merge(ETAPAS, [SUPRESION, TRABAJANDO]) as $carpeta) {
        $ruta = ruta_pipeline($carpeta);
        if (!is_dir($ruta)) {
            mkdir($ruta, 0775, true);
        }
    }
}
