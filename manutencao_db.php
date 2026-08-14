<?php
/**
 * manutencao_db.php — saúde do banco do portal.
 *
 * O que faz:
 *   - mostra o tamanho de cada tabela e o que mais pesa;
 *   - aponta tabelas fora do padrão de acentuação (collation), que é o que
 *     provoca o erro "Illegal mix of collations" ao comparar duas tabelas;
 *   - padroniza tudo em utf8mb4_unicode_ci, tirando um backup antes.
 *
 * Só admin. Respostas em JSON, consumidas pela tela de Configurações.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(600);

require __DIR__ . '/db_config.php';
header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
                              'secure' => $secure, 'samesite' => 'Lax']);
    session_start();
}
function out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$db = portal_db();
if (!$db) out(['ok' => false, 'erro' => 'Sem conexão com o banco.']);
$db->set_charset('utf8mb4');

$eu = null;
if (!empty($_SESSION['uid'])) {
    $r = $db->query('SELECT username, name, role FROM portal_usuarios WHERE id=' . (int)$_SESSION['uid']);
    $u = ($r && $r->num_rows) ? $r->fetch_assoc() : null;
    if ($u && strtolower((string)$u['role']) === 'admin') $eu = $u;
}
if (!$eu) out(['ok' => false, 'erro' => 'Área restrita aos administradores.']);

const PADRAO = 'utf8mb4_unicode_ci';
$banco = $db->query('SELECT DATABASE() d')->fetch_assoc()['d'];

function diagnostico(mysqli $db, string $banco): array {
    $tabelas = [];
    $r = $db->query("SELECT TABLE_NAME t, TABLE_COLLATION c, TABLE_ROWS n,
                       (DATA_LENGTH + INDEX_LENGTH) tam
                     FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = '" . $db->real_escape_string($banco) . "'
                     ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC");
    $foraPadrao = 0; $total = 0;
    while ($l = $r->fetch_assoc()) {
        $ok = ((string)$l['c'] === PADRAO);
        if (!$ok) $foraPadrao++;
        $total += (int)$l['tam'];
        $tabelas[] = ['tabela' => $l['t'], 'collation' => (string)$l['c'],
                      'padrao' => $ok, 'linhas' => (int)$l['n'], 'tamanho' => (int)$l['tam']];
    }
    /* colunas com collation própria diferente do padrão */
    $cols = [];
    $r = $db->query("SELECT TABLE_NAME t, COLUMN_NAME c, COLLATION_NAME cl
                     FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = '" . $db->real_escape_string($banco) . "'
                       AND COLLATION_NAME IS NOT NULL AND COLLATION_NAME <> '" . PADRAO . "'");
    while ($l = $r->fetch_assoc()) $cols[] = $l['t'] . '.' . $l['c'] . ' (' . $l['cl'] . ')';

    return ['tabelas' => $tabelas, 'fora_padrao' => $foraPadrao,
            'colunas_fora' => $cols, 'total' => $total, 'padrao' => PADRAO];
}

$a = $_GET['a'] ?? 'diagnostico';

if ($a === 'diagnostico') {
    out(['ok' => true, 'banco' => $banco] + diagnostico($db, $banco));
}

if ($a === 'padronizar') {
    /* Segurança: tira um backup antes de mexer na estrutura. */
    $backup = 'não gerado';
    try {
        if (is_file(__DIR__ . '/backup_hub.php')) {
            $saida = [];
            @exec('php ' . escapeshellarg(__DIR__ . '/backup_hub.php') . ' --agora 2>&1', $saida);
            $backup = trim(implode(' ', $saida)) ?: 'executado';
        }
    } catch (Throwable $e) { $backup = 'falhou: ' . $e->getMessage(); }

    $d = diagnostico($db, $banco);
    $feitas = []; $erros = [];
    foreach ($d['tabelas'] as $t) {
        if ($t['padrao']) continue;
        $nome = $t['tabela'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $nome)) continue;
        if (!$db->query("ALTER TABLE `$nome` CONVERT TO CHARACTER SET utf8mb4 COLLATE " . PADRAO)) {
            $erros[] = "$nome: " . $db->error;
        } else {
            $feitas[] = $nome;
        }
    }
    $depois = diagnostico($db, $banco);
    out(['ok' => empty($erros), 'convertidas' => $feitas, 'erros' => $erros,
         'backup' => $backup, 'fora_padrao' => $depois['fora_padrao']]);
}

out(['ok' => false, 'erro' => 'Ação desconhecida.']);
