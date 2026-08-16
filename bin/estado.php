<?php
/**
 * AlastreSystem - estado del pipeline de un vistazo.
 *
 *   php bin/estado.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("Este script es de linea de comandos.\n");
}

$recuento = recuento();
$total    = array_sum($recuento);
$maximo   = max(1, max($recuento));

echo "\n  PIPELINE\n";
echo "  " . str_repeat('-', 46) . "\n";

foreach ($recuento as $etapa => $n) {
    $barra = str_repeat('#', (int) round($n / $maximo * 22));
    printf("  %-16s %4d  %s\n", $etapa, $n, $barra);
}

echo "  " . str_repeat('-', 46) . "\n";
printf("  %-16s %4d\n\n", 'total', $total);

if ($recuento['40-por-aprobar'] > 0) {
    printf(
        "  >> %d lead(s) esperando tu aprobacion.\n     Abre http://localhost/AlastreSystem/\n\n",
        $recuento['40-por-aprobar']
    );
}
