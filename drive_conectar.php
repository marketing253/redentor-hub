<?php
/**
 * drive_conectar.php — liga o portal ao Google Drive.
 *
 * Guarda client_id, client_secret, refresh_token e a pasta em portal_config,
 * para ninguém precisar editar arquivo no servidor. Só admin entra.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/db_config.php';
if (is_file(__DIR__ . '/auxilio/drive.php')) require_once __DIR__ . '/auxilio/drive.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true,
                              'secure' => $secure, 'samesite' => 'Lax']);
    session_start();
}
$db = portal_db();
if (!$db) exit('Sem conexão com o banco.');
$db->set_charset('utf8mb4');
$db->query("CREATE TABLE IF NOT EXISTS portal_config (chave VARCHAR(60) PRIMARY KEY, valor TEXT)
            ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function cfgLer(mysqli $db, string $k, string $pad = ''): string {
    $r = $db->query("SELECT valor FROM portal_config WHERE chave='" . $db->real_escape_string($k) . "'");
    return ($r && $r->num_rows) ? (string)$r->fetch_assoc()['valor'] : $pad;
}
function cfgGravar(mysqli $db, string $k, string $v): void {
    $k = $db->real_escape_string($k); $v = $db->real_escape_string($v);
    $db->query("INSERT INTO portal_config (chave,valor) VALUES ('$k','$v')
                ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
}

$eu = null;
if (!empty($_SESSION['uid'])) {
    $r = $db->query('SELECT username, name, role FROM portal_usuarios WHERE id=' . (int)$_SESSION['uid']);
    $u = ($r && $r->num_rows) ? $r->fetch_assoc() : null;
    if ($u && strtolower((string)$u['role']) === 'admin') $eu = $u;
}
if (!$eu) {
    http_response_code(403);
    exit('<meta charset="utf-8"><p style="font:15px system-ui;padding:24px">Área restrita aos '
       . 'administradores do Redentor Hub. <a href="index.html">Voltar ao portal</a></p>');
}

$redirect = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST'] . strtok((string)$_SERVER['REQUEST_URI'], '?');
$msg = ''; $erro = '';

/* ---------- salva as credenciais do app ---------- */
if (($_POST['acao'] ?? '') === 'credenciais') {
    cfgGravar($db, 'drive_client_id',     trim((string)($_POST['client_id'] ?? '')));
    cfgGravar($db, 'drive_client_secret', trim((string)($_POST['client_secret'] ?? '')));
    cfgGravar($db, 'drive_pasta',         trim((string)($_POST['pasta'] ?? '')));
    $msg = 'Credenciais guardadas. Agora clique em Autorizar no Google.';
}
if (($_POST['acao'] ?? '') === 'desligar') {
    cfgGravar($db, 'drive_refresh_token', '');
    cfgGravar($db, 'drive_ativo', '0');
    $msg = 'Conexão com o Drive desligada. As credenciais foram mantidas.';
}

$id     = cfgLer($db, 'drive_client_id');
$secret = cfgLer($db, 'drive_client_secret');
$pasta  = cfgLer($db, 'drive_pasta');
$token  = cfgLer($db, 'drive_refresh_token');

/* ---------- volta do Google com o código ---------- */
if (!empty($_GET['code']) && $id && $secret) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $_GET['code'], 'client_id' => $id, 'client_secret' => $secret,
            'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        ]),
    ]);
    $r = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$r, true);
    if (!empty($j['refresh_token'])) {
        cfgGravar($db, 'drive_refresh_token', (string)$j['refresh_token']);
        cfgGravar($db, 'drive_ativo', '1');
        $token = (string)$j['refresh_token'];
        $msg = 'Drive conectado. Os backups passam a subir para a pasta configurada.';
    } else {
        $erro = 'O Google não devolveu a autorização definitiva. Se você já tinha autorizado antes, '
              . 'remova o acesso em myaccount.google.com/permissions e tente de novo. '
              . 'Resposta: ' . substr((string)$r, 0, 200);
    }
}

/* ---------- teste de envio ---------- */
if (($_GET['testar'] ?? '') === '1' && $token) {
    $cfg = ['drive' => ['ativo' => true, 'modo' => 'oauth', 'client_id' => $id,
            'client_secret' => $secret, 'refresh_token' => $token, 'pasta_id' => $pasta,
            'escopo' => 'https://www.googleapis.com/auth/drive.file']];
    $arq = sys_get_temp_dir() . '/hub-teste-drive.txt';
    file_put_contents($arq, "Teste de conexão do Redentor Hub — " . date('c'));
    [$ok, $res] = driveEnvia($cfg, $arq, 'hub-teste-' . date('Ymd-His') . '.txt', 'text/plain');
    @unlink($arq);
    if ($ok) $msg = 'Arquivo de teste enviado para a pasta do Drive (id ' . $res . ').';
    else     $erro = 'Falha no envio: ' . $res;
}

$linkGoogle = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $id, 'redirect_uri' => $redirect, 'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/drive.file',
    'access_type' => 'offline', 'prompt' => 'consent',
]);
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conectar o Google Drive</title>
<style>
body{margin:0;background:#0f1117;color:#e2e8f0;font:15px/1.6 "Segoe UI",system-ui,Arial,sans-serif}
.marca{background:#1b2145;border-bottom:3px solid #fbbf24;padding:14px 22px;display:flex;align-items:center;gap:12px}
.marca b{font-size:16px}.marca span{font-size:12px;color:#8f97c9}
.marca a{margin-left:auto;color:#dfe4ff;text-decoration:none;border:1px solid #3a4170;border-radius:999px;padding:6px 14px;font-size:13px}
.wrap{max-width:760px;margin:0 auto;padding:22px 18px 70px}
.card{background:#141a2e;border:1px solid #2a3050;border-radius:14px;padding:20px;margin-bottom:16px}
h2{font-size:16px;margin:0 0 8px}
p.sub{color:#94a3b8;font-size:13px;margin:0 0 14px}
label{display:block;font-size:12px;color:#94a3b8;margin:12px 0 4px}
input{width:100%;background:#0f1523;border:1px solid #2a3050;color:#e2e8f0;border-radius:9px;
  padding:10px 12px;font:inherit;box-sizing:border-box}
.btn{display:inline-block;background:#3b82f6;color:#fff;border:0;border-radius:9px;padding:11px 20px;
  font:inherit;cursor:pointer;text-decoration:none;margin-top:14px}
.btn.gh{background:transparent;border:1px solid #2a3050;color:#e2e8f0}
.btn.no{border-color:#7f2233;color:#fca5a5}
.ok{border-left:3px solid #22c55e;background:#22c55e1f;padding:12px 14px;border-radius:8px;margin-bottom:14px}
.err{border-left:3px solid #ef4444;background:#ef44441f;padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
.aviso{border-left:3px solid #f59e0b;background:#f59e0b1a;padding:12px 14px;border-radius:8px;font-size:13px}
code{background:#0f1523;border-radius:5px;padding:2px 8px;font-size:12.5px;word-break:break-all}
ol{padding-left:20px;color:#c8cfda;font-size:14px}li{margin:7px 0}
.passo{display:inline-block;background:#3b82f6;color:#fff;border-radius:999px;width:22px;height:22px;
  line-height:22px;text-align:center;font-size:12px;font-weight:700;margin-right:8px}
</style></head><body>
<div class="marca"><div><b>Redentor Hub</b><br><span>Conectar o Google Drive · <?= htmlspecialchars($eu['name']) ?></span></div>
  <a href="index.html">Voltar ao portal</a></div>
<div class="wrap">

<?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<?php if ($token): ?>
  <div class="card">
    <h2>&#9989; Drive conectado</h2>
    <p class="sub">Os backups do portal sobem para a pasta configurada. Nada mais a fazer aqui.</p>
    <?php if ($pasta): ?>
      <p>Pasta: <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($pasta) ?>"
        target="_blank" style="color:#60a5fa">abrir no Drive</a></p>
    <?php endif; ?>
    <a class="btn" href="?testar=1">Enviar arquivo de teste</a>
    <form method="post" style="display:inline">
      <input type="hidden" name="acao" value="desligar">
      <button class="btn gh no" type="submit">Desconectar</button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h2><span class="passo">1</span>No Google Cloud Console</h2>
  <p class="sub">Uma vez só. É onde o Google cria a identidade do seu aplicativo.</p>
  <ol>
    <li>Acesse <b>console.cloud.google.com</b> e crie um projeto (nome livre, ex.: Redentor Hub).</li>
    <li>Em <b>APIs e serviços &rarr; Biblioteca</b>, procure <b>Google Drive API</b> e clique em Ativar.</li>
    <li>Em <b>Tela de permissão OAuth</b>: tipo <b>Externo</b>, preencha nome e e-mail de contato,
      e adicione o seu e-mail em <b>Usuários de teste</b>.</li>
    <li>Em <b>Credenciais &rarr; Criar credenciais &rarr; ID do cliente OAuth</b>, tipo
      <b>Aplicativo da Web</b>. Em <b>URIs de redirecionamento autorizados</b>, cole exatamente:
      <br><code><?= htmlspecialchars($redirect) ?></code></li>
    <li>Salve. O Google mostra o <b>Client ID</b> e o <b>Client Secret</b>.</li>
  </ol>
</div>

<form class="card" method="post">
  <h2><span class="passo">2</span>Cole as credenciais aqui</h2>
  <p class="sub">Ficam guardadas no banco do portal, não em arquivo.</p>
  <input type="hidden" name="acao" value="credenciais">
  <label>Client ID</label>
  <input name="client_id" value="<?= htmlspecialchars($id) ?>" autocomplete="off">
  <label>Client Secret</label>
  <input name="client_secret" value="<?= htmlspecialchars($secret) ?>" autocomplete="off">
  <label>ID da pasta no Drive <span style="color:#64748b">(o trecho final da URL da pasta)</span></label>
  <input name="pasta" value="<?= htmlspecialchars($pasta) ?>" autocomplete="off"
    placeholder="19ZiANi4pNrGIVv-TNAJ4h12fhMQUU7bY">
  <button class="btn" type="submit">Guardar</button>
</form>

<?php if ($id && $secret): ?>
  <div class="card">
    <h2><span class="passo">3</span>Autorize com a conta dona da pasta</h2>
    <p class="sub">O Google vai avisar que o app não foi verificado — é o seu próprio app.
      Clique em <b>Avançado</b> e depois em <b>Acessar</b>.</p>
    <a class="btn" href="<?= htmlspecialchars($linkGoogle) ?>">Autorizar no Google</a>
  </div>
<?php else: ?>
  <div class="aviso">Preencha o Client ID e o Client Secret acima para liberar o passo 3.</div>
<?php endif; ?>

<div class="aviso" style="margin-top:16px">O backup carrega CPF, chave Pix e boletos.
  Deixe a pasta do Drive compartilhada apenas com quem precisa — nunca com "qualquer pessoa com o link".</div>
</div></body></html>
