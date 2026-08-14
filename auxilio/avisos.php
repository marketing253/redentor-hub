<?php
/**
 * Auxílio Graduação — lembretes por e-mail.
 *
 * Rode uma vez por dia por cron (painel da Hostinger → Cron Jobs):
 *     php /home/uXXXXXX/domains/SEUDOMINIO/public_html/avisos.php
 *
 * Ou abra no navegador com a chave:  avisos.php?chave=SUA_CHAVE_TESTE
 *
 * O que ele manda:
 *  - dia 1º: avisa cada aluno que a janela do mês abriu
 *  - véspera do prazo: lembrete de quem ainda não enviou
 *  - dia seguinte ao prazo: aviso de atraso ao aluno e à contabilidade
 *  - toda segunda: resumo do que está parado esperando a contabilidade
 */
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/email.php';
$cli = PHP_SAPI === 'cli';
if (!$cli && !hash_equals((string)$cfg['chave_teste'], (string)($_GET['chave'] ?? ''))) {
    http_response_code(403);
    exit('Chave inválida.');
}
if (!$cli) header('Content-Type: text/plain; charset=utf-8');

$c   = $cfg['db'];
$pdo = new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4", $c['usuario'], $c['senha'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$remetente  = (string)(($cfg['smtp']['usuario']) ?? '');
$contabeis  = (array)($cfg['email_contabilidade'] ?? []);
$url        = rtrim((string)($cfg['url_sistema'] ?? ''), '/');
$hoje       = date('Y-m-d');
$comp       = date('Y-m');
$enviados   = 0;

function manda(string $para, string $assunto, string $corpo, string $de): bool {
    global $cfg;
    $html = moldeEmail($assunto, '<p>' . nl2br(htmlspecialchars($corpo)) . '</p>',
                       (string)($cfg['url_sistema'] ?? ''));
    [$ok, $erro] = enviaEmail($cfg, $para, $assunto, $corpo, $html);
    if (!$ok) error_log("avisos.php: falha para $para — $erro");
    return $ok;
}

/* prazo do mês: dia fixo, para dar tempo de processar o pagamento */
$DIA_PRAZO = (int)($cfg['dia_prazo'] ?? 3);
$prazo = $comp . '-' . str_pad((string)$DIA_PRAZO, 2, '0', STR_PAD_LEFT);

$vespera   = date('Y-m-d', strtotime($prazo . ' -1 day'));
$diaSeguinte = date('Y-m-d', strtotime($prazo . ' +1 day'));

/* ---- avisos ao aluno ---- */
$pend = $pdo->prepare('SELECT a.nome, a.email, a.curso, m.competencia
                       FROM aux_mensalidades m JOIN aux_alunos a ON a.id = m.aluno_id
                       WHERE m.competencia = ? AND m.status IN ("aguardando_boleto","rejeitado")
                         AND a.status = "ativo" AND a.email IS NOT NULL');
$pend->execute([$comp]);
$pendentes = $pend->fetchAll();

$mesBR = date('m/Y', strtotime($comp . '-01'));
foreach ($pendentes as $a) {
    $prazoBR = date('d/m/Y', strtotime($prazo));
    $texto = null;
    if (date('j') === '1') {
        $texto = "Olá, {$a['nome']}.\n\nO envio do boleto de $mesBR do curso {$a['curso']} está liberado.\n"
               . "Prazo: até $prazoBR (dia $DIA_PRAZO), para dar tempo de processar o pagamento.\n";
    } elseif ($hoje === $vespera) {
        $texto = "Olá, {$a['nome']}.\n\nO prazo para enviar o boleto de $mesBR termina amanhã ($prazoBR).\n"
               . "Depois disso a parcela fica registrada como EM ATRASO.\n";
    } elseif ($hoje === $prazo) {
        // dia do prazo, de manhã: quem não mandou ainda recebe o toque
        $texto = "Olá, {$a['nome']}.\n\nVocê ainda não encaminhou o boleto referente a $mesBR "
               . "do curso {$a['curso']}.\n\nO prazo termina HOJE ($prazoBR). Envie pelo portal para "
               . "o pagamento ser processado neste mês.\n";
    } elseif ($hoje === $diaSeguinte) {
        $texto = "Olá, {$a['nome']}.\n\nO prazo de $prazoBR passou e o boleto de $mesBR não foi enviado.\n"
               . "O envio continua liberado, mas a parcela já consta como EM ATRASO.\n";
    }
    if ($texto === null) continue;
    if ($url !== '') $texto .= "\nAcesse: $url\n";
    if (manda((string)$a['email'], "Auxílio Graduação — boleto de $mesBR", $texto, $remetente)) $enviados++;
}

/* ---- resumo para a contabilidade ---- */
$querResumo = (date('N') === '1') || ($hoje === $prazo) || ($hoje === $diaSeguinte);
if ($querResumo && $contabeis) {
    $q = $pdo->prepare('SELECT status, COUNT(*) q FROM aux_mensalidades WHERE competencia=? GROUP BY status');
    $q->execute([$comp]);
    $m = [];
    foreach ($q->fetchAll() as $r) $m[$r['status']] = (int)$r['q'];
    $atr = (int)$pdo->query("SELECT COUNT(*) FROM aux_mensalidades
                             WHERE competencia='$comp' AND boleto_atrasado=1")->fetchColumn();
    $semPix = (int)$pdo->query('SELECT COUNT(DISTINCT usuario) FROM aux_alunos
                                WHERE status="ativo" AND (pix_chave IS NULL OR CHAR_LENGTH(pix_chave)=0)')
                       ->fetchColumn();
    $corpo = "Situação do auxílio graduação em $mesBR:\n\n"
        . "- Boletos aguardando conferência: " . ($m['em_analise'] ?? 0) . "\n"
        . "- Alunos sem boleto enviado: "      . ($m['aguardando_boleto'] ?? 0) . "\n"
        . "- Aprovados aguardando repasse: "   . ($m['aprovado'] ?? 0) . "\n"
        . "- Repasses concluídos: "             . ($m['pago'] ?? 0) . "\n"
        . "- Envios em atraso: $atr\n"
        . "- Alunos sem chave Pix cadastrada: $semPix\n";
    if ($url !== '') $corpo .= "\nAcesse: $url\n";
    foreach ($contabeis as $e) {
        if (manda((string)$e, "Auxílio Graduação — resumo de $mesBR", $corpo, $remetente)) $enviados++;
    }
}

echo "Data: $hoje | prazo do mês: $prazo | pendentes: " . count($pendentes)
   . " | e-mails enviados: $enviados\n";
