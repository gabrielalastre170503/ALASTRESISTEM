<?php
/**
 * AlastreSystem - prueba de la clave de Places.
 *
 *   php bin/probar-places.php
 *
 * Hace UNA peticion minima y cuenta que ha pasado. Sirve para confirmar que la
 * clave, la facturacion y las restricciones estan bien antes de lanzar nada en
 * volumen, y para ver con tus propios ojos que devuelve --completo.
 *
 * Coste: una peticion de busqueda mas los campos pedidos. Es la forma mas
 * barata de salir de dudas.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

$clave = env('GOOGLE_PLACES_API_KEY');

if ($clave === null) {
    exit(
        "\n  Falta GOOGLE_PLACES_API_KEY en el .env.\n" .
        "  Rellenala y vuelve a ejecutar esto.\n\n"
    );
}

printf("Clave en .env: …%s (%d caracteres)\n\n", substr($clave, -6), strlen($clave));

$campos = [
    'places.id',
    'places.displayName',
    'places.rating',
    'places.userRatingCount',
    'places.reviews',
    'places.photos',
];

echo "Pidiendo 1 resultado con campos completos (resenas y fotos incluidas)…\n\n";

$res = http_post_json(
    'https://places.googleapis.com/v1/places:searchText',
    ['textQuery' => 'psicologo Benimaclet Valencia', 'languageCode' => 'es', 'maxResultCount' => 1],
    ['X-Goog-Api-Key' => $clave, 'X-Goog-FieldMask' => implode(',', $campos)]
);

$datos = json_o_null($res['cuerpo']);

if ($res['estado'] !== 200) {
    $motivo = $datos['error']['message'] ?? substr($res['cuerpo'], 0, 400);
    echo "  HTTP {$res['estado']}\n\n  {$motivo}\n\n";
    echo diagnostico($res['estado'], $motivo);
    exit(1);
}

$lugar = $datos['places'][0] ?? null;

if ($lugar === null) {
    exit("  Respondio 200 pero sin resultados. La clave funciona; prueba otra consulta.\n");
}

echo "  TODO CORRECTO\n\n";
printf("  Negocio      %s\n", $lugar['displayName']['text'] ?? '?');
printf("  Valoracion   %s con %s resenas\n",
    $lugar['rating'] ?? '—', $lugar['userRatingCount'] ?? '—');
printf("  Resenas      %d con texto\n", count($lugar['reviews'] ?? []));
printf("  Fotos        %d referencias\n", count($lugar['photos'] ?? []));

if (!empty($lugar['reviews'][0])) {
    $r = $lugar['reviews'][0];
    printf("\n  Ejemplo de resena (%s, %s estrellas):\n    \"%s…\"\n",
        $r['authorAttribution']['displayName'] ?? '?',
        $r['rating'] ?? '?',
        mb_substr($r['originalText']['text'] ?? ($r['text']['text'] ?? ''), 0, 90)
    );
}

echo "\n  Ya puedes lanzar:\n";
echo "    php bin/scout.php \"psicologia\" \"Valencia, España\" --max=10 --completo\n\n";

// ---------------------------------------------------------------------------

function diagnostico(int $estado, string $motivo): string
{
    if (str_contains($motivo, 'has not been used') || str_contains($motivo, 'is disabled')) {
        return "  QUE PASA: la Places API (New) no esta habilitada en ese proyecto.\n" .
               "  QUE HACER: habilitala y espera un par de minutos a que propague.\n\n";
    }
    if (str_contains($motivo, 'billing') || str_contains($motivo, 'BILLING')) {
        return "  QUE PASA: el proyecto no tiene facturacion activa.\n" .
               "  QUE HACER: vincula una cuenta de facturacion al proyecto.\n\n";
    }
    if ($estado === 403 && str_contains($motivo, 'referer')) {
        return "  QUE PASA: la clave esta restringida a sitios web (HTTP referer), y esto\n" .
               "  son llamadas desde PHP, que no envian esa cabecera.\n" .
               "  QUE HACER: en Restricciones de aplicaciones, pon Ninguno o Direcciones IP.\n\n";
    }
    if ($estado === 403) {
        return "  QUE PASA: la clave no tiene permiso para esta API.\n" .
               "  QUE HACER: revisa Restricciones de API y que incluya Places API (New).\n\n";
    }
    if ($estado === 400) {
        return "  QUE PASA: la peticion no le gusta a la API, no es problema de la clave.\n\n";
    }

    return "  Revisa el mensaje de arriba: suele decir exactamente que falta.\n\n";
}
