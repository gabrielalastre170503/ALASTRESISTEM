<?php
/**
 * AlastreSystem - Scout. Descubre negocios via Google Places API.
 *
 *   php bin/scout.php "dentista" "Valencia, Espana" [--max=40]
 *
 * Escribe un JSON por negocio en pipeline/00-descubierto/.
 *
 * Nota sobre cache: de todo lo que devuelve Places, solo el place_id se puede
 * almacenar indefinidamente. El resto (nombre, telefono, valoracion) tiene
 * limite de conservacion, asi que se refresca antes de usarlo, no se archiva.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

$argumentos = array_slice($argv, 1);
$opciones   = [];

foreach ($argumentos as $i => $arg) {
    if (str_starts_with($arg, '--')) {
        [$k, $v] = array_pad(explode('=', substr($arg, 2), 2), 2, '1');
        $opciones[$k] = $v;
        unset($argumentos[$i]);
    }
}

$argumentos = array_values($argumentos);
$vertical   = $argumentos[0] ?? '';
$zona       = $argumentos[1] ?? '';
$maximo     = (int) ($opciones['max'] ?? 20);
$completo   = isset($opciones['completo']);
$soloSinWeb = isset($opciones['sin-web']);
$minResenas = (int) ($opciones['min-resenas'] ?? 0);
$maxResenas = (int) ($opciones['max-resenas'] ?? 0);

if ($vertical === '' || $zona === '') {
    exit(
        "Uso: php bin/scout.php \"<vertical>\" \"<zona>\" [opciones]\n\n" .
        "  --max=N          cuantos guardar (por defecto 20)\n" .
        "  --completo       pide ademas resenas, fotos, horario y estado.\n" .
        "                   Sube el tramo de precio de la Places API: uselo en el\n" .
        "                   lote corto que vayas a trabajar, no en el barrido amplio.\n" .
        "  --sin-web        solo guarda los que no enlazan web en su ficha\n" .
        "  --max-resenas=N  descarta los muy establecidos, que suelen tener agencia\n" .
        "  --min-resenas=N  descarta los que casi no tienen recorrido\n\n" .
        "Sobre la consulta: Google ordena por prominencia, asi que un termino\n" .
        "generico devuelve primero facultades y clinicas grandes. Busca por barrio\n" .
        "y por especialidad para llegar a las consultas pequenas, que son el lead\n" .
        "que interesa:\n\n" .
        "  php bin/scout.php \"psicologo\" \"Benimaclet, Valencia\" --sin-web\n" .
        "  php bin/scout.php \"terapia de pareja\" \"Ruzafa, Valencia\" --sin-web\n"
    );
}

/**
 * Campos que se piden a Places. El tramo de facturacion lo marca el campo mas
 * caro de la lista, asi que pedir resenas y fotos en un barrido de cientos de
 * negocios cuesta bastante mas que pedir solo lo basico. Por eso van aparte.
 *
 * @return list<string>
 */
function campos(bool $completo): array
{
    $basicos = [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.websiteUri',
        'places.nationalPhoneNumber',
        'places.rating',
        'places.userRatingCount',
        'nextPageToken',
    ];

    if (!$completo) {
        return $basicos;
    }

    return array_merge($basicos, [
        'places.reviews',                 // hasta 5, con texto y autor
        'places.photos',                  // referencias; la imagen se pide aparte
        'places.regularOpeningHours',
        'places.businessStatus',
        'places.googleMapsUri',
        'places.primaryTypeDisplayName',
    ]);
}

$clave = env_obligatoria('GOOGLE_PLACES_API_KEY');

$consulta      = "{$vertical} en {$zona}";
$encontrados   = 0;
$filtrados     = 0;
$nuevos        = 0;
$yaConocidos   = 0;
$tokenSiguiente = null;

echo "Buscando: {$consulta}\n";

do {
    $cuerpo = [
        'textQuery'      => $consulta,
        'languageCode'   => env('PLACES_IDIOMA', 'es'),
        'maxResultCount' => 20,
    ];

    if ($tokenSiguiente !== null) {
        $cuerpo['pageToken'] = $tokenSiguiente;
    }

    $respuesta = http_post_json(
        'https://places.googleapis.com/v1/places:searchText',
        $cuerpo,
        [
            'X-Goog-Api-Key'   => $clave,
            'X-Goog-FieldMask' => implode(',', campos($completo)),
        ]
    );

    if ($respuesta['error'] !== null) {
        exit("Error de red: {$respuesta['error']}\n");
    }

    $datos = json_o_null($respuesta['cuerpo']);

    if ($respuesta['estado'] !== 200) {
        $motivo = $datos['error']['message'] ?? substr($respuesta['cuerpo'], 0, 300);
        exit("Places API devolvio {$respuesta['estado']}: {$motivo}\n");
    }

    foreach ($datos['places'] ?? [] as $lugar) {
        $id = $lugar['id'] ?? null;

        if ($id === null) {
            continue;
        }

        $encontrados++;

        // Ya lo tenemos en alguna etapa, o esta en la lista de no-contactar.
        if (etapa_de($id) !== null) {
            $yaConocidos++;
            continue;
        }

        /* Filtros de calidad. Google ordena por prominencia, asi que un termino
           generico devuelve primero los mas establecidos: los que ya tienen web
           y agencia. Sin filtrar, se paga por leads que no se van a usar. */
        $resenas = (int) ($lugar['userRatingCount'] ?? 0);

        if ($soloSinWeb && !empty($lugar['websiteUri'])) {
            $filtrados++;
            continue;
        }
        if ($minResenas > 0 && $resenas < $minResenas) {
            $filtrados++;
            continue;
        }
        if ($maxResenas > 0 && $resenas > $maxResenas) {
            $filtrados++;
            continue;
        }

        $lead = [
            'place_id'    => $id,
            'nombre'      => $lugar['displayName']['text'] ?? '(sin nombre)',
            'direccion'   => $lugar['formattedAddress'] ?? null,
            'web'         => $lugar['websiteUri'] ?? null,
            'telefono'    => $lugar['nationalPhoneNumber'] ?? null,
            'valoracion'  => $lugar['rating'] ?? null,
            'resenas'     => $lugar['userRatingCount'] ?? null,
            'vertical'    => $vertical,
            'zona'        => $zona,
            'estado_negocio' => $lugar['businessStatus'] ?? null,
            'maps_url'    => $lugar['googleMapsUri'] ?? null,
            'horario'     => $lugar['regularOpeningHours']['weekdayDescriptions'] ?? null,
            // Referencias de foto, no imagenes: se descargan con bin/fotos.php
            'fotos'       => array_map(
                static fn(array $f): array => [
                    'nombre'      => $f['name'] ?? null,
                    'ancho'       => $f['widthPx'] ?? null,
                    'alto'        => $f['heightPx'] ?? null,
                    'atribucion'  => $f['authorAttributions'][0]['displayName'] ?? null,
                ],
                $lugar['photos'] ?? []
            ),
            // Hasta 5 resenas con texto. Sirven para dos cosas: citar la
            // reputacion con datos y saber que valora su clientela, que es
            // material directo para el copy de la landing.
            'resenas_texto' => array_map(
                static fn(array $r): array => [
                    'autor'      => $r['authorAttribution']['displayName'] ?? null,
                    'puntuacion' => $r['rating'] ?? null,
                    'texto'      => $r['originalText']['text'] ?? ($r['text']['text'] ?? null),
                    'fecha'      => $r['publishTime'] ?? null,
                ],
                $lugar['reviews'] ?? []
            ),
            'descubierto' => date('c'),
            'hallazgos'   => [],
            'landing'     => null,
            'borrador'    => null,
            'historial'   => [
                ['etapa' => '00-descubierto', 'fecha' => date('c'), 'nota' => 'scout'],
            ],
        ];

        guardar_lead('00-descubierto', $id, $lead);
        $nuevos++;

        $marca = $lead['web'] === null ? '[SIN WEB]' : '';
        printf("  + %-45s %s\n", mb_substr($lead['nombre'], 0, 45), $marca);

        if ($nuevos >= $maximo) {
            break 2;
        }
    }

    $tokenSiguiente = $datos['nextPageToken'] ?? null;

    // El token tarda un instante en activarse del lado de Google.
    if ($tokenSiguiente !== null) {
        sleep(2);
    }
} while ($tokenSiguiente !== null);

echo "\n";
echo "Vistos:      {$encontrados}\n";
echo "Nuevos:      {$nuevos}\n";
echo "Ya conocidos: {$yaConocidos}\n";
echo "Filtrados:    {$filtrados}\n";

if ($nuevos === 0 && $filtrados > 0) {
    echo "\nTodos los resultados cayeron por los filtros. Prueba por barrio y por\n";
    echo "especialidad: los terminos genericos devuelven primero los mas grandes.\n";
}
echo "\nSiguiente paso: php bin/auditar.php\n";
