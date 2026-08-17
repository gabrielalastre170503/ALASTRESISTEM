<?php
/**
 * AlastreSystem - Places y extraccion de contacto.
 *
 * Estas funciones las usan tanto los scripts de bin/ como la consola web, para
 * que la logica viva en un solo sitio. La consola NO ejecuta comandos de shell:
 * llama a estas funciones directamente, y asi no hay nada que inyectar.
 */

declare(strict_types=1);

const PLACES_BUSCAR = 'https://places.googleapis.com/v1/places:searchText';

/** Portales donde el negocio esta listado pero la web no es suya. */
const PLACES_DIRECTORIOS = [
    'doctoralia', 'wa.me', 'whatsapp', 'facebook', 'instagram', 'linkedin',
    'eholo', 'topdoctors', 'paginasamarillas', 'milanuncios', 'sites.google',
    'business.site', 'linktr.ee', 'booksy',
];

/**
 * Campos que se piden a Places. El tramo de facturacion lo marca el campo mas
 * caro de la lista, asi que resenas y fotos van aparte: pedirlas en un barrido
 * de cientos de negocios cuesta bastante mas que pedir solo lo basico.
 *
 * @return list<string>
 */
function places_campos(bool $completo): array
{
    $basicos = [
        'places.id',
        'places.displayName',
        'places.formattedAddress',
        'places.websiteUri',
        'places.nationalPhoneNumber',
        'places.internationalPhoneNumber',
        'places.rating',
        'places.userRatingCount',
        'places.primaryTypeDisplayName',
        'nextPageToken',
    ];

    if (!$completo) {
        return $basicos;
    }

    return array_merge($basicos, [
        'places.reviews',
        'places.photos',
        'places.regularOpeningHours',
        'places.businessStatus',
        'places.googleMapsUri',
    ]);
}

/**
 * Una pagina de resultados de Places.
 *
 * @param array{lat?:float, lng?:float, radio?:int} $geo
 * @return array{ok:bool, lugares:list<array>, token:?string, error:?string}
 */
function places_buscar(
    string $consulta,
    string $clave,
    bool $completo = false,
    ?string $token = null,
    array $geo = []
): array {
    $cuerpo = [
        'textQuery'      => $consulta,
        'languageCode'   => 'es',
        'maxResultCount' => 20,
    ];

    if ($token !== null) {
        $cuerpo['pageToken'] = $token;
    }

    // Sesgo por coordenadas: acota la busqueda a un radio concreto, que es lo
    // que de verdad saca consultas pequenas en vez de las grandes de la ciudad.
    if (isset($geo['lat'], $geo['lng'])) {
        $cuerpo['locationBias'] = [
            'circle' => [
                'center' => ['latitude' => $geo['lat'], 'longitude' => $geo['lng']],
                'radius' => (float) max(100, min(50000, $geo['radio'] ?? 3000)),
            ],
        ];
    }

    $res = http_post_json(PLACES_BUSCAR, $cuerpo, [
        'X-Goog-Api-Key'   => $clave,
        'X-Goog-FieldMask' => implode(',', places_campos($completo)),
    ]);

    if ($res['error'] !== null) {
        return ['ok' => false, 'lugares' => [], 'token' => null, 'error' => $res['error']];
    }

    $datos = json_o_null($res['cuerpo']);

    if ($res['estado'] !== 200) {
        return [
            'ok'      => false,
            'lugares' => [],
            'token'   => null,
            'error'   => $datos['error']['message'] ?? ('HTTP ' . $res['estado']),
        ];
    }

    return [
        'ok'      => true,
        'lugares' => $datos['places'] ?? [],
        'token'   => $datos['nextPageToken'] ?? null,
        'error'   => null,
    ];
}

/**
 * Convierte un lugar de Places en un lead del pipeline.
 *
 * @param array<string,mixed> $lugar
 * @return array<string,mixed>
 */
function places_a_lead(array $lugar, string $vertical, string $zona): array
{
    $web = $lugar['websiteUri'] ?? null;
    $tipo = 'ninguna';

    if ($web !== null) {
        $tipo = 'propia';
        foreach (PLACES_DIRECTORIOS as $portal) {
            if (str_contains(mb_strtolower($web), $portal)) {
                $tipo = 'directorio';
                break;
            }
        }
    }

    return [
        'place_id'    => $lugar['id'],
        'nombre'      => $lugar['displayName']['text'] ?? '(sin nombre)',
        'direccion'   => $lugar['formattedAddress'] ?? null,
        'web'         => $web,
        'web_tipo'    => $tipo,
        'telefono'    => $lugar['nationalPhoneNumber'] ?? ($lugar['internationalPhoneNumber'] ?? null),
        'valoracion'  => $lugar['rating'] ?? null,
        'resenas'     => $lugar['userRatingCount'] ?? null,
        'categoria'   => $lugar['primaryTypeDisplayName']['text'] ?? null,
        'vertical'    => $vertical,
        'zona'        => $zona,
        'origen'      => 'places',
        'estado_negocio' => $lugar['businessStatus'] ?? null,
        'maps_url'    => $lugar['googleMapsUri'] ?? null,
        'horario'     => $lugar['regularOpeningHours']['weekdayDescriptions'] ?? null,
        'fotos'       => array_map(
            static fn(array $f): array => [
                'nombre'     => $f['name'] ?? null,
                'atribucion' => $f['authorAttributions'][0]['displayName'] ?? null,
            ],
            $lugar['photos'] ?? []
        ),
        'resenas_texto' => array_map(
            static fn(array $r): array => [
                'autor'      => $r['authorAttribution']['displayName'] ?? null,
                'puntuacion' => $r['rating'] ?? null,
                'texto'      => $r['originalText']['text'] ?? ($r['text']['text'] ?? null),
                'fecha'      => $r['publishTime'] ?? null,
            ],
            $lugar['reviews'] ?? []
        ),
        'contacto'    => null,
        'descubierto' => date('c'),
        'hallazgos'   => [],
        'landing'     => null,
        'borrador'    => null,
        'historial'   => [
            ['etapa' => '00-descubierto', 'fecha' => date('c'), 'nota' => 'scout places'],
        ],
    ];
}

/**
 * Descarga fotos de un lead a landings/<slug>/img/.
 *
 * Las fotos son una API aparte que se factura por peticion, por eso no van en
 * la busqueda. Ademas las suben usuarios y llevan atribucion de autor, que se
 * guarda junto a las imagenes: sin ese archivo, en una semana nadie sabe de
 * quien son.
 *
 * @return array{descargadas:int, carpeta:string, archivos:list<string>}
 */
function places_fotos(array $lead, string $clave, int $maximo = 6, int $ancho = 1600): array
{
    $slug    = places_slug($lead['nombre'] ?? 'lead');
    $rel     = 'landings/' . $slug . '/img';
    $carpeta = BASE_PATH . '/' . $rel;

    if (!is_dir($carpeta)) {
        mkdir($carpeta, 0775, true);
    }

    $atribuciones = [];
    $archivos     = [];

    foreach (array_slice($lead['fotos'] ?? [], 0, $maximo) as $i => $foto) {
        if (empty($foto['nombre'])) {
            continue;
        }

        $url = sprintf(
            'https://places.googleapis.com/v1/%s/media?maxWidthPx=%d&key=%s',
            $foto['nombre'],
            $ancho,
            urlencode($clave)
        );

        $res = http_get($url, [], 45);

        if ($res['estado'] !== 200 || strlen($res['cuerpo']) < 1024) {
            continue;
        }

        $archivo = sprintf('places-%02d.jpg', $i + 1);
        file_put_contents($carpeta . '/' . $archivo, $res['cuerpo']);

        $archivos[]     = $rel . '/' . $archivo;
        $atribuciones[] = [
            'archivo'    => $archivo,
            'atribucion' => $foto['atribucion'] ?? null,
            'origen'     => 'Google Places',
            'descargada' => date('c'),
        ];

        usleep(250000);
    }

    if ($atribuciones !== []) {
        file_put_contents(
            $carpeta . '/fotos.json',
            json_encode($atribuciones, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    return ['descargadas' => count($archivos), 'carpeta' => $rel, 'archivos' => $archivos];
}

/**
 * Busca datos de contacto en la web del propio negocio.
 *
 * Places nunca da el correo ni las redes. La unica via legitima es leer la web
 * que el negocio publica, donde esos datos estan precisamente para que le
 * escriban. Si el lead no tiene web, no hay de donde sacarlo: para esos el
 * contacto es el telefono, que Places si da.
 *
 * @return array{email:list<string>, redes:array<string,string>, revisado:list<string>, telefonos:list<string>}
 */
function contacto_de_web(?string $web, int $maxPaginas = 3): array
{
    $vacio = ['email' => [], 'redes' => [], 'revisado' => [], 'telefonos' => []];

    if ($web === null || $web === '') {
        return $vacio;
    }

    $base = rtrim($web, '/');
    // La portada no siempre lleva el correo; la pagina de contacto casi siempre.
    $rutas = ['', '/contacto', '/contact', '/aviso-legal', '/legal'];

    $emails = $redes = $telefonos = $revisado = [];
    $vistas = 0;

    foreach ($rutas as $ruta) {
        if ($vistas >= $maxPaginas) {
            break;
        }

        $res = http_get($base . $ruta, [], 15);

        if ($res['estado'] !== 200 || $res['cuerpo'] === '') {
            continue;
        }

        $vistas++;
        $revisado[] = $base . $ruta;
        $html       = $res['cuerpo'];

        // Correos: tanto en mailto: como sueltos en el texto.
        if (preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $html, $m)) {
            foreach ($m[0] as $correo) {
                $correo = mb_strtolower($correo);
                // Fuera los de plantilla, imagenes y ejemplos.
                if (preg_match('/\.(png|jpe?g|gif|webp|svg)$/i', $correo)) { continue; }
                if (preg_match('/(example|dominio|tudominio|sentry|wixpress|@2x)/i', $correo)) { continue; }
                $emails[$correo] = true;
            }
        }

        // Redes sociales: solo el perfil, sin parametros de seguimiento.
        $portales = [
            'facebook'  => '#https?://(?:www\.)?facebook\.com/[A-Za-z0-9._%-]+#i',
            'instagram' => '#https?://(?:www\.)?instagram\.com/[A-Za-z0-9._]+#i',
            'linkedin'  => '#https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:in|company)/[A-Za-z0-9._-]+#i',
            'tiktok'    => '#https?://(?:www\.)?tiktok\.com/@[A-Za-z0-9._]+#i',
            'youtube'   => '#https?://(?:www\.)?youtube\.com/(?:@|c/|channel/)[A-Za-z0-9._-]+#i',
            'whatsapp'  => '#https?://(?:api\.whatsapp\.com/send\?phone=|wa\.me/)[0-9]+#i',
        ];

        foreach ($portales as $nombre => $patron) {
            if (!isset($redes[$nombre]) && preg_match($patron, $html, $mm)) {
                $redes[$nombre] = $mm[0];
            }
        }

        // Telefonos espanoles en enlaces tel:
        if (preg_match_all('/tel:\+?([0-9\s().-]{9,20})/i', $html, $mt)) {
            foreach ($mt[1] as $t) {
                $limpio = preg_replace('/\D+/', '', $t) ?? '';
                if (strlen($limpio) >= 9) {
                    $telefonos[substr($limpio, -9)] = true;
                }
            }
        }
    }

    return [
        'email'     => array_slice(array_keys($emails), 0, 5),
        'redes'     => $redes,
        'telefonos' => array_slice(array_keys($telefonos), 0, 3),
        'revisado'  => $revisado,
    ];
}

function places_slug(string $texto): string
{
    $t = mb_strtolower(trim($texto));
    $t = strtr($t, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'à'=>'a','è'=>'e','ò'=>'o','ï'=>'i','ç'=>'c',
    ]);
    $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? $t;

    return trim($t, '-') ?: 'lead';
}
