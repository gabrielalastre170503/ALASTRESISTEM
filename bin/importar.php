<?php
/**
 * AlastreSystem - importador manual de leads.
 *
 *   php bin/importar.php leads.csv "psicologia" "Valencia, Espana"
 *
 * Para la Fase 0: buscas en Google Maps con el navegador, copias 10 negocios
 * a un CSV, y esto los mete en el pipeline. Sin Places API y sin facturacion.
 *
 * Formato del CSV (separador ; o ,). La cabecera es opcional:
 *
 *   nombre;web;telefono
 *   Centro de Psicologia Ejemplo;https://ejemplo.es;963000000
 *   Consulta Sin Web;;963111111
 *
 * Solo "nombre" es obligatorio. Dejar "web" vacio es informacion, no un hueco:
 * un negocio sin web es el mejor lead que hay.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

$archivo  = $argv[1] ?? '';
$vertical = $argv[2] ?? 'sin-clasificar';
$zona     = $argv[3] ?? 'sin-zona';

if ($archivo === '' || !is_readable($archivo)) {
    exit(
        "Uso: php bin/importar.php <archivo.csv> \"<vertical>\" \"<zona>\"\n\n" .
        "Ejemplo:\n" .
        "  php bin/importar.php leads.csv \"psicologia\" \"Valencia, Espana\"\n"
    );
}

$manejador = fopen($archivo, 'r');

if ($manejador === false) {
    exit("No se pudo abrir {$archivo}\n");
}

// Excel en espanol exporta con ';'. Detectamos cual usa la primera linea.
$primera   = (string) fgets($manejador);
$separador = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
rewind($manejador);

$nuevos     = 0;
$repetidos  = 0;
$saltados   = 0;
$primeraFila = true;

while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
    $nombre = trim((string) ($fila[0] ?? ''));

    if ($nombre === '') {
        continue;
    }

    // Si la primera fila parece cabecera, la ignoramos.
    if ($primeraFila) {
        $primeraFila = false;
        if (strcasecmp($nombre, 'nombre') === 0) {
            continue;
        }
    }

    $web      = trim((string) ($fila[1] ?? ''));
    $telefono = trim((string) ($fila[2] ?? ''));

    if ($web !== '' && !preg_match('#^https?://#i', $web)) {
        $web = 'https://' . $web;
    }

    if ($web !== '' && filter_var($web, FILTER_VALIDATE_URL) === false) {
        echo "  ! web no valida en \"{$nombre}\", la dejo vacia\n";
        $web = '';
    }

    // Sin place_id necesitamos un ID propio, y estable: reimportar el mismo
    // archivo no debe duplicar leads.
    $id = 'manual_' . substr(hash('sha256', mb_strtolower($nombre) . '|' . $zona), 0, 24);

    if (($etapa = etapa_de($id)) !== null) {
        echo "  = ya estaba ({$etapa}): {$nombre}\n";
        $repetidos++;
        continue;
    }

    guardar_lead('00-descubierto', $id, [
        'place_id'    => $id,
        'nombre'      => $nombre,
        'direccion'   => null,
        'web'         => $web !== '' ? $web : null,
        'telefono'    => $telefono !== '' ? $telefono : null,
        'valoracion'  => null,
        'resenas'     => null,
        'vertical'    => $vertical,
        'zona'        => $zona,
        'origen'      => 'manual',
        'descubierto' => date('c'),
        'hallazgos'   => [],
        'landing'     => null,
        'borrador'    => null,
        'historial'   => [
            ['etapa' => '00-descubierto', 'fecha' => date('c'), 'nota' => 'importado a mano'],
        ],
    ]);

    $nuevos++;
    printf("  + %-45s %s\n", mb_substr($nombre, 0, 45), $web === '' ? '[SIN WEB]' : '');
}

fclose($manejador);

echo "\n";
echo "Nuevos:    {$nuevos}\n";
echo "Repetidos: {$repetidos}\n";

if ($nuevos > 0) {
    echo "\nSiguiente paso: php bin/auditar.php --limite={$nuevos}\n";
}
