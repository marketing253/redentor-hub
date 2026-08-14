<?php
/**
 * versoes.php — lista as versões guardadas e devolve uma delas ao lugar.
 *
 * Existe para o portal poder mostrar as últimas atualizações direto na
 * tela de Configurações, sem abrir outra página. Quando algo quebra, a
 * pessoa já está ali procurando o que fazer — mandar ela para outra tela
 * é justamente o que ninguém quer no momento do aperto.
 *
 * Responde JSON. Só admin do Redentor Hub.
 *   ?acao=listar              devolve as cópias guardadas
 *   ?acao=restaurar&copia=X   devolve os arquivos daquela cópia
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');
/* Sem esta linha o servidor usa UTC e os horários aparecem 3 horas
   adiantados: um envio das 15h01 vira 18h01 no cartão.
   Precisa vir DEPOIS do declare(strict_types), que por regra do PHP
   tem de ser a primeira instrução do arquivo. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(300);
session_start();

const RAIZ    = __DIR__ . '/..';
const DIR_BKP = __DIR__ . '/backups';

/* Não são devolvidos nunca: configuração e dados mudaram depois da
   atualização, e voltar um config.php antigo derruba a conexão. */
const PROTEGIDOS = ['auxilio/config.php', 'db_config.php', 'config.php',
                    '.htaccess', '.user.ini', 'auxilio/.backup_estado.json'];

function sai(array $d): void { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function falha(string $m, int $c = 400): void { http_response_code($c); sai(['ok' => false, 'erro' => $m]); }

/* ---------- quem pode ---------- */
$cfg = @require __DIR__ . '/config.php';
if (!is_array($cfg)) falha('Configuração do auxílio não encontrada.', 500);

$eu = null;
try {
    if (!empty($_SESSION['uid'])) {
        $c = $cfg['db'];
        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4",
            $c['usuario'], $c['senha'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $st = $pdo->prepare("SELECT id, username, role FROM portal_usuarios WHERE id=? LIMIT 1");
        $st->execute([(int)$_SESSION['uid']]);
        $eu = $st->fetch() ?: null;
    }
} catch (Throwable $e) { $eu = null; }

if (!$eu || ($eu['role'] ?? '') !== 'admin') falha('Só administradores.', 403);

$acao = $_GET['acao'] ?? 'listar';

/* ---------- listar ---------- */
if ($acao === 'listar') {
    $out = [];
    if (is_dir(DIR_BKP)) {
        foreach (scandir(DIR_BKP) ?: [] as $d) {
            if ($d === '.' || $d === '..') continue;
            $cam = DIR_BKP . '/' . $d;
            if (!is_dir($cam)) continue;

            $n = 0; $bytes = 0; $amostra = [];
            try {
                $it = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($cam, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile()) continue;
                    $n++; $bytes += $f->getSize();
                    if (count($amostra) < 6) {
                        $amostra[] = str_replace('\\', '/', substr($f->getPathname(), strlen($cam) + 1));
                    }
                }
            } catch (Throwable $e) { continue; }
            if (!$n) continue;

            /* AAAA-MM-DD_HHMMSS vira data legível. O sufixo
               "_antes-de-restaurar" marca as cópias que o próprio
               restaurador criou, para não se confundirem com envios. */
            $rest = strpos($d, '_antes-de-restaurar') !== false;
            $base = str_replace('_antes-de-restaurar', '', $d);
            $quando = preg_match('/^(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})$/', $base, $m)
                ? "{$m[3]}/{$m[2]}/{$m[1]} às {$m[4]}h{$m[5]}" : $d;

            $out[] = ['id' => $d, 'quando' => $quando, 'total' => $n,
                      'bytes' => $bytes, 'amostra' => $amostra,
                      'de_restauracao' => $rest];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['id'], $a['id']));
    sai(['ok' => true, 'copias' => array_slice($out, 0, 6)]);
}

/* ---------- restaurar ---------- */
if ($acao === 'restaurar') {
    $id = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($_GET['copia'] ?? ''));
    $dir = DIR_BKP . '/' . $id;
    if ($id === '' || !is_dir($dir)) falha('Cópia não encontrada.');

    /* Guarda o estado atual ANTES de sobrescrever. Sem isso, restaurar
       seria via de mão única: se a versão antiga também estivesse
       quebrada, não haveria como voltar. */
    $carimbo = date('Y-m-d_His') . '_antes-de-restaurar';
    $dirAgora = DIR_BKP . '/' . $carimbo;

    $devolvidos = []; $pulados = []; $falhas = [];
    try {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile()) continue;
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));

            if (in_array($rel, PROTEGIDOS, true)) { $pulados[] = $rel; continue; }
            if (strpos($rel, '..') !== false) { $pulados[] = $rel; continue; }

            $alvo = RAIZ . '/' . $rel;
            if (is_file($alvo)) {
                $g = $dirAgora . '/' . $rel;
                if (!is_dir(dirname($g))) @mkdir(dirname($g), 0750, true);
                @copy($alvo, $g);
            }
            if (!is_dir(dirname($alvo))) @mkdir(dirname($alvo), 0755, true);
            if (@copy($f->getPathname(), $alvo)) $devolvidos[] = $rel;
            else $falhas[] = $rel;
        }
    } catch (Throwable $e) {
        falha('Falhou no meio: ' . $e->getMessage(), 500);
    }

    sai(['ok' => true, 'de' => $id, 'devolvidos' => count($devolvidos),
         'pulados' => count($pulados), 'falhas' => count($falhas),
         'guardado' => $carimbo, 'lista' => array_slice($devolvidos, 0, 40)]);
}

falha('Ação desconhecida.');
