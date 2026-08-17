<?php
/**
 * AlastreSystem - Verificador de dominios.
 *
 *   php bin/verificar.php [--etapa=20-auditado] [--limite=10] [--id=...]
 *
 * Resuelve el fallo mas caro del pipeline: que Google Maps no enlace una web
 * NO prueba que el negocio no la tenga. En el primer lote real, 4 de cada 10
 * leads marcados como "sin web" si la tenian. Escribirles diciendoles que no
 * tienen algo que si tienen quema el lead en la primera frase.
 *
 * Como lo hace, sin API de busqueda ni coste: deduce dominios candidatos del
 * nombre del negocio, los prueba, y para los que responden comprueba que sean
 * suyos de verdad buscando su telefono o su calle dentro de la pagina. Sin esa
 * segunda comprobacion, "clinicadental.es" daria positivo para cualquier
 * clinica dental del pais.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

/** Palabras que describen el oficio, no identifican al negocio. */
const RELLENO = [
    'psicologia','psicologo','psicologa','psicologos','psicologas','psicoterapeuta',
    'psicoterapia','terapia','terapias','centro','gabinete','consulta','clinica',
    'instituto','espai','espacio','salud','integral','de','del','la','el','los','las',
    'y','en','para','sl','sa','slu','cb',
];

const TLDS = ['.es', '.com'];

$opciones = getopt('', ['etapa::', 'limite::', 'id::']);
$etapa    = $opciones['etapa']  ?? '20-auditado';
$limite   = (int) ($opciones['limite'] ?? 10);
$soloId   = $opciones['id'] ?? null;

$ids = $soloId !== null ? [$soloId] : leads_en($etapa);

if ($ids === []) {
    exit("No hay leads en {$etapa}.\n");
}

$conWeb = $sinWeb = $omitidos = 0;
$revisados = 0;

foreach ($ids as $id) {
    if ($revisados >= $limite) {
        break;
    }

    $lead = cargar_lead($etapa, $id);

    // Solo interesa lo que esta marcado como "deducido de una ausencia".
    $porVerificar = array_filter(
        $lead['hallazgos'] ?? [],
        static fn(array $h): bool => !empty($h['verificar'])
    );

    if ($porVerificar === []) {
        $omitidos++;
        continue;
    }

    $revisados++;
    echo "\n· " . mb_substr($lead['nombre'], 0, 52) . "\n";

    $candidatos = candidatos_dominio($lead['nombre'], (string) ($lead['zona'] ?? ''));
    $nombres    = array_keys($candidatos);
    echo '  candidatos: ' . implode(', ', array_slice($nombres, 0, 6))
        . (count($nombres) > 6 ? ' …' : '') . "\n";

    $hallada = buscar_web($candidatos, $lead);

    $lead['verificacion_dominio'] = [
        'fecha'      => date('c'),
        'candidatos' => count($candidatos),
        'resultado'  => $hallada === null ? 'sin_indicios' : 'encontrada',
        'web'        => $hallada['url']      ?? null,
        'prueba'     => $hallada['prueba']   ?? null,
        'confianza'  => $hallada['confianza'] ?? null,
    ];

    if ($hallada !== null) {
        // Se distingue lo probado de lo meramente plausible: dar por hecho
        // algo con prueba debil es como se cuelan los errores caros.
        $rotulo = $hallada['confianza'] === 'baja' ? 'POSIBLE, revisar' : 'CONFIRMADO';
        printf("  >> %s: %s\n     %s\n", $rotulo, $hallada['url'], $hallada['prueba']);
        $conWeb++;
    } else {
        echo "  sin indicios de web propia\n";
        $sinWeb++;
    }

    guardar_lead($etapa, $id, $lead);
}

echo "\n" . str_repeat('-', 46) . "\n";
printf("Revisados: %d   Con web: %d   Sin indicios: %d   Omitidos: %d\n",
    $revisados, $conWeb, $sinWeb, $omitidos);

if ($conWeb > 0) {
    echo "\nLos que tienen web no son leads de 'no tienes web': son de rediseno.\n";
    echo "Revisa su hallazgo antes de redactar nada.\n";
}

// ---------------------------------------------------------------------------

/**
 * Deduce dominios plausibles a partir del nombre del negocio.
 *
 * @return list<string>
 */
function candidatos_dominio(string $nombre, string $zona): array
{
    $palabras = palabras_utiles($nombre, $zona);

    if ($palabras === []) {
        return [];
    }

    $todas   = palabras_utiles($nombre, $zona, false); // incluye el oficio
    $bases   = [];

    $bases[] = implode('', $palabras);                       // nataliajorrin
    $bases[] = implode('', $todas);                          // nataliajorrinpsicologa
    $bases[] = implode('', array_slice($palabras, 0, 2));    // nataliajorrin
    $bases[] = implode('', array_slice($todas, 0, 2));

    if (mb_strlen($palabras[0]) >= 5) {
        $bases[] = $palabras[0];                             // sarahbel
    }

    $bases = array_values(array_unique(array_filter(
        $bases,
        static fn(string $b): bool => mb_strlen($b) >= 5 && mb_strlen($b) <= 40
    )));

    /* Cuantas palabras del nombre compone cada base. Importa: un dominio de
       una sola palabra ("mercedes", "piensa", "pablo") coincide con marcas y
       palabras comunes, y no puede aceptarse con prueba debil. */
    $dominios = [];
    foreach ($bases as $base) {
        $n = 1;
        foreach ([$palabras, $todas] as $juego) {
            if (count($juego) >= 2 && $base === implode('', array_slice($juego, 0, 2))) { $n = 2; }
            if (count($juego) >= 2 && $base === implode('', $juego)) { $n = count($juego); }
        }
        foreach (TLDS as $tld) {
            $dominios[$base . $tld] = $n;
        }
    }

    return $dominios;
}

/**
 * Normaliza el nombre a palabras utiles: sin acentos, sin puntuacion y, por
 * defecto, sin las palabras que describen el oficio ni la ciudad.
 *
 * @return list<string>
 */
function palabras_utiles(string $nombre, string $zona, bool $quitarRelleno = true): array
{
    $texto = mb_strtolower(trim($nombre));
    $texto = strtr($texto, [
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'à'=>'a','è'=>'e','ò'=>'o','ï'=>'i','ç'=>'c',
    ]);
    $texto = preg_replace('/[^a-z0-9\s]/', ' ', $texto) ?? $texto;

    $ciudad = mb_strtolower(trim(explode(',', $zona)[0] ?? ''));
    $ciudad = strtr($ciudad, ['à'=>'a','è'=>'e','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n']);

    $palabras = preg_split('/\s+/', trim($texto)) ?: [];

    return array_values(array_filter($palabras, static function (string $p) use ($quitarRelleno, $ciudad): bool {
        if (mb_strlen($p) < 3) {
            return false;
        }
        if ($p === $ciudad || $p === 'valencia' || $p === 'madrid' || $p === 'sevilla') {
            return false;
        }
        return !$quitarRelleno || !in_array($p, RELLENO, true);
    }));
}

/**
 * Prueba los candidatos y devuelve el primero que ademas resulte ser suyo.
 *
 * @param list<string> $dominios
 * @return array{url:string, prueba:string, confianza:string}|null
 */
function buscar_web(array $dominios, array $lead): ?array
{
    foreach ($dominios as $dominio => $nPalabras) {
        // Muchos sitios solo resuelven en www: probar solo el dominio raiz
        // dejaba fuera casos reales como www.sarahbel.es.
        $hosts = array_values(array_filter(
            [$dominio, 'www.' . $dominio],
            static fn(string $h): bool => gethostbyname($h) !== $h
        ));

        if ($hosts === []) {
            continue;
        }

        foreach ($hosts as $host) {
            foreach (['https://', 'http://'] as $esquema) {
                $res = http_get($esquema . $host, [], 12);

                if ($res['estado'] < 200 || $res['estado'] >= 400 || $res['cuerpo'] === '') {
                    continue;
                }

                $prueba = identificar($res['cuerpo'], $lead);

                /* La prueba debil solo vale si el dominio compone dos o mas
                   palabras del nombre. Con una sola ("mercedes", "piensa")
                   coincide con marcas y palabras corrientes: en la prueba real
                   dio Mercedes-Benz como web de Mercedes Diaz. */
                if ($prueba !== null && $prueba['confianza'] === 'baja' && $nPalabras < 2) {
                    $prueba = null;
                }

                if ($prueba !== null) {
                    return [
                        'url'       => $esquema . $host,
                        'prueba'    => $prueba['texto'],
                        'confianza' => $prueba['confianza'],
                    ];
                }

                // Responde pero no se pudo atribuir: no se prueba el otro
                // esquema del mismo host, daria lo mismo.
                break;
            }
        }
    }

    return null;
}

/**
 * Comprueba que la pagina sea del negocio y no de un homonimo.
 * El telefono es la prueba fuerte; el nombre completo, media.
 *
 * @return array{texto:string, confianza:string}|null
 */
function identificar(string $html, array $lead): ?array
{
    $texto  = mb_strtolower(strip_tags($html));
    $digitos = preg_replace('/\D+/', '', $html) ?? '';

    if (!empty($lead['telefono'])) {
        $tel = preg_replace('/\D+/', '', (string) $lead['telefono']) ?? '';
        $tel = ltrim($tel, '0');
        if (str_starts_with($tel, '34') && mb_strlen($tel) > 9) {
            $tel = substr($tel, 2);
        }
        if (mb_strlen($tel) === 9 && str_contains($digitos, $tel)) {
            return ['texto' => "aparece su telefono {$tel}", 'confianza' => 'alta'];
        }
    }

    // Calle: descarta el tipo de via y busca el nombre propio.
    if (!empty($lead['direccion'])) {
        $calle = mb_strtolower((string) $lead['direccion']);
        if (preg_match('/(?:c\/|calle|carrer|av|avinguda|avenida|pl|plaza|placa)\.?\s*(?:de\s+|del\s+|les\s+)?([a-zñáéíóúàèòï\s]{6,30}?)\s*,/u', $calle, $m)) {
            $trozo = trim($m[1]);
            if (mb_strlen($trozo) >= 6 && str_contains($texto, $trozo)) {
                return ['texto' => "aparece su direccion ({$trozo})", 'confianza' => 'alta'];
            }
        }
    }

    // Nombre completo del negocio dentro de la pagina.
    $nombre = mb_strtolower(trim((string) $lead['nombre']));
    if (mb_strlen($nombre) >= 8 && str_contains($texto, $nombre)) {
        return ['texto' => 'aparece el nombre completo del negocio', 'confianza' => 'media'];
    }

    /* Ultimo escalon: el dominio se dedujo de su nombre y la pagina contiene
       esa palabra distintiva. Es mas debil que el telefono, pero exigir siempre
       telefono o direccion daba falsos negativos en portadas que no los
       muestran (le paso a isepclinic.es). Sale marcado como confianza baja
       para que lo confirme una persona: el objetivo no es decidir solo, es
       evitar afirmar "no tienes web" a quien si la tiene. */
    $distintivas = palabras_utiles((string) $lead['nombre'], (string) ($lead['zona'] ?? ''));
    foreach ($distintivas as $palabra) {
        if (mb_strlen($palabra) >= 4 && str_contains($texto, $palabra)) {
            return [
                'texto'     => "el dominio sale de su nombre y la pagina menciona \"{$palabra}\"",
                'confianza' => 'baja',
            ];
        }
    }

    return null;
}
