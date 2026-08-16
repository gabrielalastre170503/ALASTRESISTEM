<?php
/**
 * AlastreSystem - cliente HTTP minimo sobre cURL.
 *
 * Solo lo que necesita el pipeline: un GET y un POST de JSON, con timeout
 * y sin excepciones por codigo de estado (el llamador decide que hacer).
 */

declare(strict_types=1);

/**
 * @param array<string,string> $cabeceras
 * @return array{estado:int, cuerpo:string, error:?string}
 */
function http_get(string $url, array $cabeceras = [], int $timeout = 20): array
{
    return http_peticion('GET', $url, null, $cabeceras, $timeout);
}

/**
 * @param array<string,string> $cabeceras
 * @return array{estado:int, cuerpo:string, error:?string}
 */
function http_post_json(string $url, array $datos, array $cabeceras = [], int $timeout = 30): array
{
    $cabeceras['Content-Type'] = 'application/json';
    $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    return http_peticion('POST', $url, $cuerpo, $cabeceras, $timeout);
}

/**
 * @param array<string,string> $cabeceras
 * @return array{estado:int, cuerpo:string, error:?string}
 */
function http_peticion(
    string $metodo,
    string $url,
    ?string $cuerpo,
    array $cabeceras,
    int $timeout
): array {
    $ch = curl_init($url);

    $lista = [];
    foreach ($cabeceras as $clave => $valor) {
        $lista[] = "{$clave}: {$valor}";
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $metodo,
        CURLOPT_HTTPHEADER     => $lista,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        // Identificarse es lo correcto y ademas evita bloqueos por bot anonimo.
        CURLOPT_USERAGENT      => 'AlastreSystem/1.0 (auditoria web; +contacto en el sitio)',
    ]);

    if ($cuerpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
    }

    $respuesta = curl_exec($ch);
    $estado    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error     = curl_errno($ch) !== 0 ? curl_error($ch) : null;

    curl_close($ch);

    return [
        'estado' => $estado,
        'cuerpo' => $respuesta === false ? '' : (string) $respuesta,
        'error'  => $error,
    ];
}

/**
 * Decodifica JSON devolviendo null en vez de lanzar, que es lo comodo aqui.
 */
function json_o_null(string $texto): ?array
{
    try {
        $datos = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
        return is_array($datos) ? $datos : null;
    } catch (JsonException) {
        return null;
    }
}
