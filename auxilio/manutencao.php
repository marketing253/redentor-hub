<?php
/**
 * Modo manutenção — usado pelo atualizador.
 *
 * Enquanto está ligado, o portal mostra um aviso para todo mundo, menos
 * para quem está aplicando a atualização (esse vê a barra de progresso).
 *
 * O estado fica num arquivo, não no banco: se a atualização mexer no banco,
 * o aviso continua funcionando.
 */
declare(strict_types=1);

const ARQ_MANUTENCAO = __DIR__ . '/.manutencao.json';

function manutencaoEstado(): array {
    if (!is_file(ARQ_MANUTENCAO)) return ['ativa' => false];
    $j = json_decode((string)file_get_contents(ARQ_MANUTENCAO), true);
    if (!is_array($j) || empty($j['ativa'])) return ['ativa' => false];
    // trava de segurança: some sozinho depois de 30 min, para o portal
    // não ficar preso se a atualização morrer no meio
    if (time() - (int)($j['inicio'] ?? 0) > 1800) { @unlink(ARQ_MANUTENCAO); return ['ativa' => false]; }
    return $j;
}

function manutencaoLigar(string $quem, int $total): void {
    file_put_contents(ARQ_MANUTENCAO, json_encode([
        'ativa'  => true,
        'quem'   => $quem,
        'inicio' => time(),
        'total'  => $total,
        'feito'  => 0,
        'pct'    => 0,
        'etapa'  => 'Preparando os arquivos',
    ], JSON_UNESCAPED_UNICODE));
}

function manutencaoProgresso(int $feito, int $total, string $etapa): void {
    $e = manutencaoEstado();
    if (empty($e['ativa'])) return;
    $e['feito'] = $feito;
    $e['total'] = $total;
    $e['pct']   = $total > 0 ? min(99, (int)round($feito / $total * 100)) : 0;
    $e['etapa'] = $etapa;
    file_put_contents(ARQ_MANUTENCAO, json_encode($e, JSON_UNESCAPED_UNICODE));
}

function manutencaoDesligar(): void {
    @unlink(ARQ_MANUTENCAO);
}
