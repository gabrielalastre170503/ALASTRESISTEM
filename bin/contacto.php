<?php
/**
 * AlastreSystem - extraccion de datos de contacto.
 *
 *   php bin/contacto.php [--etapa=20-auditado] [--limite=20] [--id=...]
 *
 * Lee la web del propio negocio y saca correo, redes y telefonos. Places nunca
 * da el email: la unica via legitima es la pagina que ellos publican, donde
 * esos datos estan precisamente para que les escriban.
 *
 * Ojo con la asimetria: los mejores leads son los que NO tienen web, y para
 * esos no hay de donde sacar correo. Su contacto es el telefono, que Places si
 * devuelve. Este script sirve sobre todo para los leads de rediseno.
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

$opciones = getopt('', ['etapa::', 'limite::', 'id::']);
$etapa    = $opciones['etapa'] ?? '20-auditado';
$limite   = (int) ($opciones['limite'] ?? 20);
$soloId   = $opciones['id'] ?? null;

$ids = $soloId !== null ? [$soloId] : leads_en($etapa);

if ($ids === []) {
    exit("No hay leads en {$etapa}.\n");
}

$conEmail = $conRedes = $sinWeb = $revisados = 0;

foreach (array_slice($ids, 0, $limite) as $id) {
    $donde = $soloId !== null ? (etapa_de($id) ?? $etapa) : $etapa;
    $lead  = cargar_lead($donde, $id);

    echo "\n· " . mb_substr($lead['nombre'], 0, 50) . "\n";

    // La web puede venir del scout o habersela encontrado el verificador.
    $web = $lead['web'] ?? ($lead['verificacion_dominio']['web'] ?? null);

    if (empty($web)) {
        echo "  sin web: su contacto es el telefono "
            . (!empty($lead['telefono']) ? $lead['telefono'] : '(tampoco hay)') . "\n";
        $sinWeb++;
        continue;
    }

    $revisados++;
    $c = contacto_de_web($web);

    $lead['contacto'] = $c + ['fecha' => date('c'), 'fuente' => $web];
    guardar_lead($donde, $id, $lead);

    if ($c['email'] !== []) {
        echo '  correo: ' . implode(', ', $c['email']) . "\n";
        $conEmail++;
    }
    if ($c['redes'] !== []) {
        echo '  redes:  ' . implode(', ', array_keys($c['redes'])) . "\n";
        $conRedes++;
    }
    if ($c['telefonos'] !== []) {
        echo '  tel:    ' . implode(', ', $c['telefonos']) . "\n";
    }
    if ($c['email'] === [] && $c['redes'] === []) {
        echo "  nada publicado en " . count($c['revisado']) . " pagina(s)\n";
    }
}

echo "\n" . str_repeat('-', 46) . "\n";
printf("Con web revisada: %d   Con correo: %d   Con redes: %d   Sin web: %d\n",
    $revisados, $conEmail, $conRedes, $sinWeb);

if ($sinWeb > 0) {
    echo "\nLos que no tienen web son tus mejores leads y su via es el telefono.\n";
    echo "No es una carencia del script: es que no hay pagina que leer.\n";
}
