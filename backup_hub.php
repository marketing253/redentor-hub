<?php
/**
 * backup_hub.php — backup completo do Redentor Hub.
 *
 * O que entra no pacote:
 *   - dump SQL de TODAS as tabelas do banco do portal
 *   - os arquivos anexados que ficam em pasta (uploads do auxílio)
 *
 * Para onde vai:
 *   - fica uma cópia no servidor (as N mais recentes, configurável)
 *   - sobe para a pasta do Google Drive configurada
 *
 * Como roda:
 *   - pela tela: Configurações → Backup → "Fazer backup agora" (admin)
 *   - por cron, uma vez por dia:
 *       php /home/uXXXX/domains/SEUDOMINIO/public_html/backup_hub.php
 *     O script confere o agendamento e só executa na hora marcada.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
set_time_limit(900);

$CLI = (PHP_SAPI === 'cli');
require __DIR__ . '/db_config.php';

/* credenciais do Drive e chave de teste vêm do config do módulo auxílio,
   para não haver duas configurações dizendo a mesma coisa */
$cfgAux = is_file(__DIR__ . '/auxilio/config.php') ? require __DIR__ . '/auxilio/config.php' : [];
if (is_file(__DIR__ . '/auxilio/drive.php')) require_once __DIR__ . '/auxilio/drive.php';

const DIR_BACKUP  = __DIR__ . '/backups_hub';
const MANTER_PAD  = 7;

$db = portal_db();
if (!$db) { $CLI ? print("Sem conexão com o banco.\n") : http_response_code(500); exit; }
$db->set_charset('utf8mb4');

/* ---------- tabelas de apoio ---------- */
$db->query("CREATE TABLE IF NOT EXISTS portal_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    arquivo VARCHAR(190) NOT NULL,
    tamanho BIGINT NOT NULL DEFAULT 0,
    tabelas INT NOT NULL DEFAULT 0,
    registros INT NOT NULL DEFAULT 0,
    anexos INT NOT NULL DEFAULT 0,
    origem VARCHAR(20) NOT NULL DEFAULT 'manual',
    quem VARCHAR(60) DEFAULT NULL,
    drive_id VARCHAR(120) DEFAULT NULL,
    ok TINYINT(1) NOT NULL DEFAULT 1,
    erro VARCHAR(400) DEFAULT NULL,
    segundos DECIMAL(6,1) NOT NULL DEFAULT 0,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_bkp_data (criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS portal_config (
    chave VARCHAR(60) PRIMARY KEY,
    valor TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/** Credenciais do Drive: o que estiver no banco (tela Conectar) manda;
    o config.php do módulo serve de reserva. */
function driveCfg(mysqli $db, array $cfgAux): array {
    $d = (array)($cfgAux['drive'] ?? []);
    foreach (['drive_client_id' => 'client_id', 'drive_client_secret' => 'client_secret',
              'drive_pasta' => 'pasta_id'] as $chave => $campo) {
        $v = cfgLer($db, $chave, '');
        if ($v !== '') $d[$campo] = $v;
    }
    $tok = cfgLer($db, 'drive_refresh_token', '');
    if ($tok !== '') {
        $d['modo'] = 'oauth';
        $d['refresh_token'] = $tok;
        $d['ativo'] = cfgLer($db, 'drive_ativo', '1') === '1';
    }
    if (empty($d['escopo'])) $d['escopo'] = 'https://www.googleapis.com/auth/drive.file';
    $cfgAux['drive'] = $d;
    return $cfgAux;
}

function cfgLer(mysqli $db, string $k, string $pad = ''): string {
    $r = $db->query("SELECT valor FROM portal_config WHERE chave='" . $db->real_escape_string($k) . "'");
    return ($r && $r->num_rows) ? (string)$r->fetch_assoc()['valor'] : $pad;
}
function cfgGravar(mysqli $db, string $k, string $v): void {
    $k = $db->real_escape_string($k); $v = $db->real_escape_string($v);
    $db->query("INSERT INTO portal_config (chave,valor) VALUES ('$k','$v')
                ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
}
function agendamento(mysqli $db): array {
    return [
        'ativo'      => cfgLer($db, 'backup_ativo', '0') === '1',
        'hora'       => cfgLer($db, 'backup_hora', '03:00'),
        'frequencia' => cfgLer($db, 'backup_freq', 'diario'),   // diario | semanal
        'dia_semana' => (int)cfgLer($db, 'backup_dia', '1'),    // 1=segunda
        'manter'     => max(2, min(30, (int)cfgLer($db, 'backup_manter', (string)MANTER_PAD))),
    ];
}

/* ---------- o backup em si ---------- */
function fazerBackup(mysqli $db, array $cfgAux, string $origem, string $quem = ''): array {
    $t0 = microtime(true);
    $cfgAux = driveCfg($db, $cfgAux);
    if (!is_dir(DIR_BACKUP)) mkdir(DIR_BACKUP, 0750, true);
    $carimbo = date('Y-m-d_His');
    $nomeZip = "hub-$carimbo.zip";
    $caminho = DIR_BACKUP . "/$nomeZip";
    $sqlTmp  = DIR_BACKUP . "/.dump-$carimbo.sql";

    /* 1. dump de todas as tabelas, gravando direto em arquivo */
    $fh = fopen($sqlTmp, 'w');
    fwrite($fh, "-- Redentor Hub — backup de " . date('d/m/Y H:i') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
    $tabelas = 0; $registros = 0;
    $r = $db->query('SHOW TABLES');
    while ($t = $r->fetch_array()) {
        $tab = $t[0];
        $c = $db->query("SHOW CREATE TABLE `$tab`")->fetch_assoc();
        fwrite($fh, "DROP TABLE IF EXISTS `$tab`;\n" . $c['Create Table'] . ";\n");
        $res = $db->query("SELECT * FROM `$tab`", MYSQLI_USE_RESULT);
        $n = 0;
        while ($linha = $res->fetch_assoc()) {
            $vals = [];
            foreach ($linha as $v) {
                $vals[] = $v === null ? 'NULL' : "'" . $db->real_escape_string((string)$v) . "'";
            }
            fwrite($fh, "INSERT INTO `$tab` VALUES (" . implode(',', $vals) . ");\n");
            $n++;
        }
        $res->free();
        fwrite($fh, "\n");
        $tabelas++; $registros += $n;
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);

    /* 2. zip com o dump e os arquivos anexados */
    if (!class_exists('ZipArchive')) {
        @unlink($sqlTmp);
        return ['ok' => false, 'erro' => 'Extensão zip indisponível no servidor.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($caminho, ZipArchive::CREATE) !== true) {
        @unlink($sqlTmp);
        return ['ok' => false, 'erro' => 'Não consegui criar o arquivo do backup.'];
    }
    $zip->addFile($sqlTmp, "banco/hub-$carimbo.sql");

    $anexos = 0;
    $pastas = [__DIR__ . '/auxilio/uploads_auxilio' => 'auxilio'];
    if (!empty($cfgAux['dir_uploads'])) $pastas[rtrim((string)$cfgAux['dir_uploads'], '/')] = 'auxilio';
    foreach ($pastas as $dir => $rotulo) {
        if (!is_dir($dir)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            if (in_array($f->getFilename(), ['.htaccess', 'htaccess.txt', 'LEIA.txt'], true)) continue;
            $zip->addFile($f->getPathname(), "arquivos/$rotulo/" . ltrim(str_replace($dir, '', $f->getPathname()), '/\\'));
            $anexos++;
        }
    }
    $zip->close();
    @unlink($sqlTmp);
    $tam = (int)filesize($caminho);

    /* 3. envia ao Drive */
    $driveId = null; $erro = '';
    if (function_exists('driveEnvia') && !empty($cfgAux['drive']['ativo'])
        && !empty($cfgAux['drive']['refresh_token'])) {
        [$ok, $res] = driveEnvia($cfgAux, $caminho, $nomeZip);
        if ($ok) $driveId = $res; else $erro = 'Backup gerado, mas o envio ao Drive falhou: ' . $res;
    } else {
        $erro = 'Backup gerado só no servidor: o Google Drive ainda não foi conectado '
              . '(Configurações → Backup → Conectar o Google Drive).';
    }

    /* 4. registra e limpa os antigos */
    $seg = round(microtime(true) - $t0, 1);
    $ok = $erro === '' ? 1 : ($driveId ? 1 : 0);
    $st = $db->prepare("INSERT INTO portal_backups
        (arquivo,tamanho,tabelas,registros,anexos,origem,quem,drive_id,ok,erro,segundos)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $st->bind_param('siiiisssisd', $nomeZip, $tam, $tabelas, $registros, $anexos,
                    $origem, $quem, $driveId, $ok, $erro, $seg);
    $st->execute();

    limparAntigos($db);
    cfgGravar($db, 'backup_ultimo', date('Y-m-d H:i:s'));

    return ['ok' => (bool)$ok, 'arquivo' => $nomeZip, 'tamanho' => $tam, 'tabelas' => $tabelas,
            'registros' => $registros, 'anexos' => $anexos, 'drive_id' => $driveId,
            'erro' => $erro, 'segundos' => $seg];
}

function limparAntigos(mysqli $db): void {
    $manter = agendamento($db)['manter'];
    $r = $db->query("SELECT id, arquivo FROM portal_backups ORDER BY criado_em DESC");
    $i = 0;
    while ($b = $r->fetch_assoc()) {
        $i++;
        if ($i <= $manter) continue;
        @unlink(DIR_BACKUP . '/' . $b['arquivo']);   // some do servidor; no Drive continua
    }
}

/* ---------- quem está pedindo ---------- */
function souAdmin(mysqli $db): ?array {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
                                  'secure' => $secure, 'samesite' => 'Lax']);
        session_start();
    }
    if (empty($_SESSION['uid'])) return null;
    $r = $db->query('SELECT username, name, role FROM portal_usuarios WHERE id=' . (int)$_SESSION['uid']);
    $u = ($r && $r->num_rows) ? $r->fetch_assoc() : null;
    return ($u && strtolower((string)$u['role']) === 'admin') ? $u : null;
}

/* ---------- modo cron ---------- */
if ($CLI || isset($_GET['cron'])) {
    $ag = agendamento($db);
    $forcar = in_array('--agora', $argv ?? [], true) || isset($_GET['agora']);
    if (!$forcar) {
        if (!$ag['ativo']) { echo "Backup automático desligado.\n"; exit; }
        if ($ag['frequencia'] === 'semanal' && (int)date('N') !== $ag['dia_semana']) {
            echo "Hoje não é o dia agendado.\n"; exit;
        }
        if (date('H:i') < $ag['hora']) { echo "Ainda não deu a hora ({$ag['hora']}).\n"; exit; }
        $ultimo = cfgLer($db, 'backup_ultimo', '');
        if ($ultimo && date('Y-m-d', strtotime($ultimo)) === date('Y-m-d')) {
            echo "Já foi feito hoje ($ultimo).\n"; exit;
        }
    }
    $r = fazerBackup($db, $cfgAux, 'agendado');
    echo ($r['ok'] ? 'Backup concluído: ' : 'Backup com problema: ')
       . ($r['arquivo'] ?? '-') . ' — ' . ($r['erro'] ?: 'enviado ao Drive') . "\n";
    exit;
}

/* ---------- API para a tela ---------- */
header('Content-Type: application/json; charset=utf-8');
$eu = souAdmin($db);
if (!$eu) { echo json_encode(['ok' => false, 'erro' => 'Área restrita aos administradores.']); exit; }

$a = $_GET['a'] ?? 'lista';

if ($a === 'lista') {
    $ag = agendamento($db);
    $lista = [];
    $r = $db->query("SELECT * FROM portal_backups ORDER BY criado_em DESC LIMIT 30");
    while ($b = $r->fetch_assoc()) {
        $b['existe_local'] = is_file(DIR_BACKUP . '/' . $b['arquivo']);
        $lista[] = $b;
    }
    $cfgD  = driveCfg($db, $cfgAux);
    $pasta = (string)(($cfgD['drive']['pasta_id']) ?? '');
    echo json_encode(['ok' => true, 'backups' => $lista, 'agenda' => $ag,
        'ultimo' => cfgLer($db, 'backup_ultimo', ''),
        'drive' => [
            'ativo' => !empty($cfgD['drive']['ativo']) && !empty($cfgD['drive']['refresh_token']),
            'pasta' => $pasta,
            'link'  => $pasta ? "https://drive.google.com/drive/folders/$pasta" : '',
        ]], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($a === 'salvar_agenda') {
    $b = json_decode((string)file_get_contents('php://input'), true) ?: [];
    cfgGravar($db, 'backup_ativo',  !empty($b['ativo']) ? '1' : '0');
    cfgGravar($db, 'backup_hora',   preg_match('/^\d{2}:\d{2}$/', (string)($b['hora'] ?? '')) ? $b['hora'] : '03:00');
    cfgGravar($db, 'backup_freq',   ($b['frequencia'] ?? '') === 'semanal' ? 'semanal' : 'diario');
    cfgGravar($db, 'backup_dia',    (string)max(1, min(7, (int)($b['dia_semana'] ?? 1))));
    cfgGravar($db, 'backup_manter', (string)max(2, min(30, (int)($b['manter'] ?? MANTER_PAD))));
    echo json_encode(['ok' => true]);
    exit;
}

if ($a === 'executar') {
    $r = fazerBackup($db, $cfgAux, 'manual', (string)$eu['username']);
    echo json_encode(['ok' => true, 'resultado' => $r], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($a === 'baixar') {
    $arq = basename((string)($_GET['arquivo'] ?? ''));
    $caminho = DIR_BACKUP . "/$arq";
    if (!$arq || !is_file($caminho)) { echo json_encode(['ok' => false, 'erro' => 'Arquivo não está mais no servidor.']); exit; }
    header_remove('Content-Type');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $arq . '"');
    header('Content-Length: ' . filesize($caminho));
    readfile($caminho);
    exit;
}

echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
