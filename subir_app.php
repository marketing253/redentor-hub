<?php
/**
 * subir_app.php — envia um sistema (arquivo .html) para a pasta apps/.
 *
 * Existia um vazio no portal: dava para cadastrar o card do sistema pela
 * tela, mas o arquivo tinha que chegar em apps/ por FTP. Resultado: todo
 * painel novo dependia de alguém com acesso ao servidor.
 *
 * Só admin. Guarda a versão anterior antes de substituir.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/db_config.php';
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
                              'secure' => $secure, 'samesite' => 'Lax']);
    session_start();
}
function out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function erro(string $m): void { out(['ok' => false, 'erro' => $m]); }

$db = portal_db();
if (!$db) erro('Sem conexão com o banco.');

$eu = null;
if (!empty($_SESSION['uid'])) {
    $r = $db->query('SELECT username, role FROM portal_usuarios WHERE id=' . (int)$_SESSION['uid']);
    $u = ($r && $r->num_rows) ? $r->fetch_assoc() : null;
    if ($u && strtolower((string)$u['role']) === 'admin') $eu = $u;
}
if (!$eu) erro('Área restrita aos administradores.');

$acao = $_GET['a'] ?? 'enviar';

/* Diz à tela quanto o servidor aceita por envio. Hospedagem compartilhada
   costuma limitar em poucos MB, e um painel com dados dentro passa disso
   fácil — por isso o envio é feito em pedaços. */
function limiteBytes(string $ini): int {
    $v = trim((string)ini_get($ini));
    if ($v === '') return 0;
    $u = strtolower(substr($v, -1));
    $n = (int)$v;
    if ($u === 'g') $n *= 1024 * 1024 * 1024;
    elseif ($u === 'm') $n *= 1024 * 1024;
    elseif ($u === 'k') $n *= 1024;
    return $n;
}
if ($acao === 'limites') {
    out(['ok' => true,
         'upload' => limiteBytes('upload_max_filesize'),
         'post'   => limiteBytes('post_max_size')]);
}

/* ---------- envio em pedaços ----------
   Cada pedaço é um POST pequeno, que passa em qualquer limite. O servidor
   vai juntando num arquivo temporário e só publica no fim. */
if ($acao === 'pedaco') {
    $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_POST['id'] ?? '')));
    $n  = (int)($_POST['n'] ?? -1);
    if ($id === '' || $n < 0) erro('Pedaço sem identificação.');
    if (!isset($_FILES['parte']) || $_FILES['parte']['error'] !== UPLOAD_ERR_OK) {
        erro('Pedaço ' . $n . ' não chegou (código ' . ($_FILES['parte']['error'] ?? -1) . ').');
    }
    $dir = sys_get_temp_dir() . '/hub-envio';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $tmp = $dir . '/' . $id . '.parcial';

    /* Pedaço 0 começa do zero: se um envio anterior travou no meio, o
       resto não pode grudar no novo. */
    $modo = ($n === 0) ? 'wb' : 'ab';
    $fh = fopen($tmp, $modo);
    if (!$fh) erro('Não consegui gravar o arquivo temporário.');
    fwrite($fh, (string)file_get_contents($_FILES['parte']['tmp_name']));
    fclose($fh);

    /* Limpa restos de envios abandonados há mais de uma hora. */
    foreach (glob($dir . '/*.parcial') ?: [] as $velho) {
        if (filemtime($velho) < time() - 3600) @unlink($velho);
    }
    out(['ok' => true, 'recebido' => (int)filesize($tmp)]);
}

/* Lista o que já existe em apps/, para a tela avisar antes de substituir. */
if ($acao === 'lista') {
    $out = [];
    foreach (glob(__DIR__ . '/apps/*.html') ?: [] as $f) {
        $out[] = ['nome' => basename($f, '.html'),
                  'tamanho' => (int)filesize($f),
                  'quando' => date('Y-m-d H:i', (int)filemtime($f))];
    }
    usort($out, fn($a, $b) => strcmp($a['nome'], $b['nome']));
    out(['ok' => true, 'arquivos' => $out]);
}

if ($acao !== 'enviar' && $acao !== 'finalizar') erro('Ação desconhecida.');

/* Fim do envio em pedaços: junta tudo e publica. */
$dePedacos = false; $tmpPedacos = '';
if ($acao === 'finalizar') {
    $id = preg_replace('/[^a-z0-9]/', '', strtolower((string)($_POST['id'] ?? '')));
    $tmpPedacos = sys_get_temp_dir() . '/hub-envio/' . $id . '.parcial';
    if ($id === '' || !is_file($tmpPedacos)) erro('Não encontrei o arquivo enviado. Tente de novo.');
    $dePedacos = true;
    $f = ['name' => (string)($_POST['nome_original'] ?? 'sistema.html'),
          'size' => (int)filesize($tmpPedacos), 'tmp_name' => $tmpPedacos, 'error' => 0];
}

if (!$dePedacos && (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK)) {
    $cod = $_FILES['arquivo']['error'] ?? -1;
    erro($cod === UPLOAD_ERR_INI_SIZE || $cod === UPLOAD_ERR_FORM_SIZE
        ? 'Arquivo maior que o limite do servidor. Peça para aumentar upload_max_filesize.'
        : 'Não recebi o arquivo (código ' . $cod . ').');
}
if (!$dePedacos) $f = $_FILES['arquivo'];

if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'html') {
    erro('Envie um arquivo .html — é o formato que o portal abre dentro do menu.');
}
if ($f['size'] > 30 * 1024 * 1024) erro('Arquivo acima de 30 MB.');

/* Nome do arquivo: o que o usuário digitou ou o nome original, sempre
   saneado. Sem isso, um nome com barra escreveria fora de apps/. */
$nome = (string)($_POST['nome'] ?? pathinfo($f['name'], PATHINFO_FILENAME));
$nome = strtolower(trim($nome));
$nome = preg_replace('/\.html$/', '', $nome);
$nome = str_replace([' ', '.'], '-', $nome);
$nome = preg_replace('/[^a-z0-9_\-]/', '', $nome);
$nome = trim((string)$nome, '-_');
if (!preg_match('/^[a-z0-9_\-]{2,40}$/', $nome)) {
    erro('Nome inválido. Use letras minúsculas, números e hífen (ex.: painel-frota).');
}

/* Não deixar sobrescrever os sistemas que vêm com o portal por engano. */
$doPortal = ['fuel','combustivel','iak','acidentes','comparativo','aderencia','drive','biart',
             'lnt','salas','agenda','plano','tvindoor','auxilio','chamados','reunioes',
             'media-operacao'];
$substituir = ($_POST['substituir'] ?? '') === '1';
$destino = __DIR__ . '/apps/' . $nome . '.html';

if (in_array($nome, $doPortal, true) && !$substituir) {
    erro('Já existe um sistema do portal com esse nome (' . $nome . '). '
       . 'Escolha outro nome ou marque a opção de substituir.');
}
if (is_file($destino) && !$substituir) {
    out(['ok' => false, 'existe' => true, 'nome' => $nome,
         'erro' => 'Já existe apps/' . $nome . '.html (enviado em '
                 . date('d/m/Y H:i', (int)filemtime($destino)) . ').']);
}

if (!is_dir(__DIR__ . '/apps')) erro('A pasta apps/ não existe no servidor.');
if (!is_writable(__DIR__ . '/apps')) erro('A pasta apps/ está sem permissão de escrita (use 755).');

/* Guarda a versão anterior: um envio errado não pode apagar o que
   funcionava. Mantém as 3 últimas de cada sistema. */
$backup = '';
if (is_file($destino)) {
    $dirBk = __DIR__ . '/apps/_versoes';
    if (!is_dir($dirBk)) @mkdir($dirBk, 0755, true);
    $backup = $dirBk . '/' . $nome . '-' . date('Ymd-His') . '.html';
    @copy($destino, $backup);
    $velhos = glob($dirBk . '/' . $nome . '-*.html') ?: [];
    rsort($velhos);
    foreach (array_slice($velhos, 3) as $v) @unlink($v);
}

$gravou = $dePedacos
    ? @rename($f['tmp_name'], $destino)
    : move_uploaded_file($f['tmp_name'], $destino);
if (!$gravou) erro('Falha ao gravar o arquivo em apps/.');
@chmod($destino, 0644);

/* Aviso, não bloqueio: um HTML sem <html> costuma ser arquivo errado,
   mas quem manda é quem está enviando. */
$aviso = '';
$inicio = strtolower((string)file_get_contents($destino, false, null, 0, 800));
if (strpos($inicio, '<html') === false && strpos($inicio, '<!doctype') === false) {
    $aviso = 'O arquivo não parece uma página HTML completa. Confira se é o arquivo certo.';
}

out(['ok' => true, 'nome' => $nome, 'tamanho' => (int)filesize($destino),
     'substituiu' => $backup !== '', 'aviso' => $aviso]);
