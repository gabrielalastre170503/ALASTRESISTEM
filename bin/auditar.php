<?php
/**
 * AlastreSystem - Auditor. Convierte un lead descubierto en hallazgos objetivos.
 *
 *   php bin/auditar.php [--limite=10] [--id=ChIJ...]
 *
 * Cada hallazgo lleva un campo "citable": ESE es el unico texto que el
 * Redactor tiene permitido usar en un mensaje. Si un dato no esta aqui,
 * no existe. Es lo que impide que el borrador se invente cosas del negocio.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

$opciones = getopt('', ['limite::', 'id::']);
$limite   = (int) ($opciones['limite'] ?? 10);
$soloId   = $opciones['id'] ?? null;

$pendientes = $soloId !== null ? [$soloId] : leads_en('00-descubierto');

if ($pendientes === []) {
    exit("No hay nada en 00-descubierto. Ejecuta primero bin/scout.php\n");
}

$pendientes = array_slice($pendientes, 0, $limite);
$clavePsi   = env('GOOGLE_PSI_API_KEY');

echo "Auditando " . count($pendientes) . " lead(s)...\n\n";

foreach ($pendientes as $id) {
    $reclamado = reclamar('00-descubierto', $id, 'auditor');

    if ($reclamado === null) {
        echo "  ~ {$id} lo tiene otro proceso, salto\n";
        continue;
    }

    $lead = json_decode((string) file_get_contents($reclamado), true, 512, JSON_THROW_ON_ERROR);
    echo "  · " . mb_substr($lead['nombre'], 0, 50) . "\n";

    $lead['hallazgos'] = auditar($lead, $clavePsi);
    $lead['auditado']  = date('c');

    file_put_contents(
        $reclamado,
        json_encode($lead, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    foreach ($lead['hallazgos'] as $h) {
        printf("      [%s] %s\n", strtoupper($h['gravedad']), $h['citable']);
    }

    soltar($id, 'auditor', '20-auditado', 'auditor');
    echo "\n";
}

echo "Listo. Revisa: php bin/estado.php\n";

// ---------------------------------------------------------------------------

/**
 * @return list<array{codigo:string, gravedad:string, citable:string}>
 */
function auditar(array $lead, ?string $clavePsi): array
{
    $hallazgos = [];

    if (empty($lead['web'])) {
        $hallazgos[] = [
            'codigo'   => 'sin_web',
            'gravedad' => 'alta',
            'citable'  => 'El negocio no tiene sitio web listado en su ficha de Google.',
        ];

        // Sin web no hay nada mas que medir, pero la ficha si dice cosas.
        return array_merge($hallazgos, auditar_ficha($lead));
    }

    $url = $lead['web'];
    $res = http_get($url);

    if ($res['error'] !== null || $res['estado'] >= 400) {
        $hallazgos[] = [
            'codigo'   => 'web_caida',
            'gravedad' => 'alta',
            'citable'  => sprintf(
                'Su web (%s) no responde correctamente: %s.',
                $url,
                $res['error'] ?? "codigo HTTP {$res['estado']}"
            ),
        ];

        return array_merge($hallazgos, auditar_ficha($lead));
    }

    $html = $res['cuerpo'];

    if (!str_starts_with(strtolower($url), 'https://')) {
        $hallazgos[] = [
            'codigo'   => 'sin_https',
            'gravedad' => 'alta',
            'citable'  => 'Su web no usa HTTPS, y los navegadores la marcan como "no segura".',
        ];
    }

    if (!preg_match('/<meta[^>]+name=["\']viewport["\']/i', $html)) {
        $hallazgos[] = [
            'codigo'   => 'sin_viewport',
            'gravedad' => 'alta',
            'citable'  => 'Su web no declara viewport, asi que no se adapta a pantallas de movil.',
        ];
    }

    // Un copyright viejo en el pie es la senal mas legible de abandono.
    if (preg_match_all('/(?:©|&copy;|copyright)[^0-9]{0,15}(19|20)(\d{2})/i', $html, $m)) {
        $anios = array_map(static fn($a, $b) => (int) ($a . $b), $m[1], $m[2]);
        $ultimo = max($anios);
        $actual = (int) date('Y');

        if ($ultimo < $actual - 1) {
            $hallazgos[] = [
                'codigo'   => 'desactualizada',
                'gravedad' => 'media',
                'citable'  => "El pie de su web sigue marcando {$ultimo}, " .
                              ($actual - $ultimo) . ' anios sin actualizar.',
            ];
        }
    }

    if ($clavePsi !== null) {
        $psi = medir_pagespeed($url, $clavePsi);

        if ($psi !== null) {
            $puntos = (int) round($psi * 100);

            if ($puntos < 50) {
                $hallazgos[] = [
                    'codigo'   => 'lento_movil',
                    'gravedad' => $puntos < 30 ? 'alta' : 'media',
                    'citable'  => "Su web puntua {$puntos}/100 en rendimiento movil segun " .
                                  'PageSpeed Insights de Google.',
                ];
            }
        }
    }

    return array_merge($hallazgos, auditar_ficha($lead));
}

/**
 * Hallazgos que salen de la propia ficha de Google, sin tocar la web.
 *
 * @return list<array{codigo:string, gravedad:string, citable:string}>
 */
function auditar_ficha(array $lead): array
{
    $hallazgos = [];

    if (($lead['resenas'] ?? 0) >= 20 && ($lead['valoracion'] ?? 0) >= 4.3) {
        $hallazgos[] = [
            'codigo'   => 'buena_reputacion',
            'gravedad' => 'oportunidad',
            'citable'  => sprintf(
                'Tiene %s en Google con %d resenas: la reputacion ya esta, falta la web que la acompane.',
                number_format((float) $lead['valoracion'], 1, ',', '.'),
                (int) $lead['resenas']
            ),
        ];
    }

    if (empty($lead['telefono'])) {
        $hallazgos[] = [
            'codigo'   => 'ficha_incompleta',
            'gravedad' => 'baja',
            'citable'  => 'Su ficha de Google no muestra telefono de contacto.',
        ];
    }

    return $hallazgos;
}

/**
 * Puntuacion de rendimiento movil (0..1) segun PageSpeed Insights, o null.
 * La API es gratuita; la clave solo sube la cuota diaria.
 */
function medir_pagespeed(string $url, string $clave): ?float
{
    $consulta = http_build_query([
        'url'      => $url,
        'strategy' => 'mobile',
        'category' => 'performance',
        'key'      => $clave,
    ]);

    // PSI tarda: analiza la pagina de verdad, no consulta un indice.
    $res = http_get(
        "https://www.googleapis.com/pagespeedonline/v5/runPagespeed?{$consulta}",
        [],
        90
    );

    if ($res['estado'] !== 200) {
        return null;
    }

    $datos = json_o_null($res['cuerpo']);
    $score = $datos['lighthouseResult']['categories']['performance']['score'] ?? null;

    return is_numeric($score) ? (float) $score : null;
}
