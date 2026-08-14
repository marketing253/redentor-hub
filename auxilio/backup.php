<?php
/**
 * Auxílio Graduação — backup automático para o Google Drive.
 *
 * O que vai no pacote:
 *   - dump SQL das tabelas aux_alunos, aux_mensalidades e aux_log
 *   - os boletos, comprovantes e contratos gravados desde o último backup
 *
 * Cron diário (painel da Hostinger → Cron Jobs), de madrugada:
 *     php /home/uXXXXXX/domains/SEUDOMINIO/public_html/backup.php
 *
 * Manual, pelo navegador:  backup.php?chave=SUA_CHAVE_TESTE
 * Backup cheio (todos os arquivos):  backup.php?chave=...&tudo=1
 */
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(600);

$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/drive.php';

$cli = PHP_SAPI === 'cli';
if (!$cli && !hash_equals((string)$cfg['chave_teste'], (string)($_GET['chave'] ?? ''))) {
    http_response_code(403);
    exit('Chave inválida.');
}
if (!$cli) header('Content-Type: text/plain; charset=utf-8');

$linhas = [];
function log_(string $t) { global $linhas, $cli; $linhas[] = $t; echo $t, "\n"; if (!$cli) flush(); }

if (empty($cfg['drive']['ativo'])) { log_('Backup desligado no config.php (drive.ativo = false).'); exit; }
if (!class_exists('ZipArchive')) { log_('ERRO: extensão zip indisponível no servidor.'); exit(1); }

$tudo    = ($_GET['tudo'] ?? '') === '1' || in_array('--tudo', $argv ?? [], true);
$estado  = __DIR__ . '/.backup_estado.json';
$desde   = 0;
if (!$tudo && is_file($estado)) {
    $e = json_decode((string)file_get_contents($estado), true);
    $desde = (int)($e['ultimo'] ?? 0);
}
$agora = time();
$tmp   = sys_get_temp_dir() . '/auxilio-backup-' . date('Ymd-His') . '.zip';

/* ---------- 1. dump das tabelas ---------- */
$c   = $cfg['db'];
$pdo = new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4", $c['usuario'], $c['senha'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$sql = "-- Auxílio Graduação — backup de " . date('d/m/Y H:i') . "\n"
     . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach (['aux_alunos', 'aux_mensalidades', 'aux_log'] as $t) {
    $cria = $pdo->query("SHOW CREATE TABLE `$t`")->fetch();
    $sql .= "DROP TABLE IF EXISTS `$t`;\n" . ($cria['Create Table'] ?? '') . ";\n";
    $n = 0;
    foreach ($pdo->query("SELECT * FROM `$t`") as $linha) {
        $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($linha));
        $sql .= "INSERT INTO `$t` VALUES (" . implode(',', $vals) . ");\n";
        $n++;
    }
    $sql .= "\n";
    log_("Tabela $t: $n registro(s).");
}
$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

/* ---------- 2. monta o zip ---------- */
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::CREATE) !== true) { log_('ERRO: não consegui criar o zip.'); exit(1); }
$zip->addFromString('banco/auxilio-' . date('Y-m-d') . '.sql', $sql);

$dirUp = !empty($cfg['dir_uploads']) ? rtrim((string)$cfg['dir_uploads'], '/')
                                     : __DIR__ . '/uploads_auxilio';
$arqs = 0; $bytes = 0;
if (is_dir($dirUp)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dirUp, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        if (in_array($f->getFilename(), ['.htaccess', 'htaccess.txt', 'LEIA.txt'], true)) continue;
        if (!$tudo && $f->getMTime() <= $desde) continue;
        $rel = 'arquivos/' . ltrim(str_replace($dirUp, '', $f->getPathname()), '/\\');
        $zip->addFile($f->getPathname(), $rel);
        $arqs++; $bytes += $f->getSize();
    }
}
$zip->close();
log_(($tudo ? 'Backup cheio' : 'Backup incremental') . ": $arqs arquivo(s), "
   . round($bytes / 1048576, 2) . ' MB de anexos.');

/* ---------- 3. envia ao Drive ---------- */
$nome = 'auxilio-' . ($tudo ? 'completo-' : '') . date('Y-m-d-His') . '.zip';
[$ok, $res] = driveEnvia($cfg, $tmp, $nome);
if ($ok) {
    log_("Enviado ao Google Drive: $nome (id $res)");
    file_put_contents($estado, json_encode(['ultimo' => $agora, 'nome' => $nome]));
} else {
    log_("FALHA no envio: $res");
}
@unlink($tmp);

/* ---------- 4. avisa a contabilidade se falhar ---------- */
if (!$ok && !empty($cfg['email_contabilidade'])) {
    require_once __DIR__ . '/email.php';
    enviaEmail($cfg, (string)$cfg['email_contabilidade'][0],
        'Auxílio Graduação — falha no backup',
        "O backup automático falhou em " . date('d/m/Y H:i') . ":\n\n$res\n");
}
log_('Fim.');
