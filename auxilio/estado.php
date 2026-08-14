<?php
/** Estado do portal — consultado pelo Hub a cada poucos segundos. */
declare(strict_types=1);
require_once __DIR__ . '/manutencao.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$e = manutencaoEstado();
echo json_encode(empty($e['ativa'])
    ? ['manutencao' => false]
    : ['manutencao' => true, 'quem' => $e['quem'] ?? '', 'pct' => (int)($e['pct'] ?? 0),
       'etapa' => $e['etapa'] ?? '', 'desde' => (int)($e['inicio'] ?? 0)],
    JSON_UNESCAPED_UNICODE);
