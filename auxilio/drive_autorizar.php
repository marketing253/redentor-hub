<?php
/**
 * Autorização do Google Drive — gera o refresh_token.
 *
 * Use uma vez só, e depois APAGUE este arquivo do servidor.
 * Acesse: drive_autorizar.php?chave=SUA_CHAVE_TESTE
 */
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

$cfg = require __DIR__ . '/config.php';
if (!hash_equals((string)$cfg['chave_teste'], (string)($_GET['chave'] ?? $_SESSION['dr_chave'] ?? ''))) {
    http_response_code(403);
    exit('Chave inválida. Use drive_autorizar.php?chave=SUA_CHAVE_TESTE');
}
$_SESSION['dr_chave'] = $cfg['chave_teste'];

$redirect = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST'] . strtok((string)$_SERVER['REQUEST_URI'], '?');
$escopo   = (string)(($cfg['drive']['escopo']) ?? 'https://www.googleapis.com/auth/drive.file');
$id       = trim((string)($_POST['client_id'] ?? $_SESSION['dr_id'] ?? ($cfg['drive']['client_id'] ?? '')));
$secret   = trim((string)($_POST['client_secret'] ?? $_SESSION['dr_secret'] ?? ($cfg['drive']['client_secret'] ?? '')));
if ($id)     $_SESSION['dr_id'] = $id;
if ($secret) $_SESSION['dr_secret'] = $secret;

$token = $erro = '';

/* volta do Google com o código */
if (!empty($_GET['code']) && $id && $secret) {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $_GET['code'], 'client_id' => $id, 'client_secret' => $secret,
            'redirect_uri' => $redirect, 'grant_type' => 'authorization_code',
        ]),
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$r, true);
    if (!empty($j['refresh_token'])) $token = $j['refresh_token'];
    else $erro = 'O Google não devolveu refresh_token. Resposta: ' . substr((string)$r, 0, 300)
               . ' — se já autorizou antes, remova o acesso em myaccount.google.com/permissions e repita.';
}

$linkGoogle = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $id, 'redirect_uri' => $redirect, 'response_type' => 'code',
    'scope' => $escopo, 'access_type' => 'offline', 'prompt' => 'consent',
]);
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>Autorizar Google Drive</title>
<style>
body{margin:0;background:#1b1e24;color:#e8ecf2;font:15px/1.6 "Segoe UI",system-ui,Arial,sans-serif}
.marca{background:#3B4192;border-bottom:3px solid #D9A93F;padding:14px 20px;font-weight:600}
.wrap{max-width:720px;margin:0 auto;padding:22px 18px 60px}
.card{background:#23272f;border:1px solid #333a45;border-radius:12px;padding:18px;margin-bottom:14px}
label{display:block;font-size:12px;color:#9aa4b2;margin:12px 0 4px}
input{width:100%;background:#1b1e24;border:1px solid #333a45;color:#e8ecf2;border-radius:8px;
  padding:9px 11px;font:inherit;box-sizing:border-box}
.btn{display:inline-block;background:#3B4192;color:#fff;text-decoration:none;border:0;padding:11px 18px;
  border-radius:8px;font:inherit;cursor:pointer;margin-top:16px}
code{background:#1b1e24;padding:2px 6px;border-radius:5px;font-size:13px;word-break:break-all}
.ok{border-left:3px solid #2fbf71;background:rgba(47,191,113,.12);padding:12px 14px;border-radius:6px}
.no{border-left:3px solid #f4623a;background:rgba(244,98,58,.12);padding:12px 14px;border-radius:6px;
  font-size:13px}
ol{padding-left:20px} li{margin:6px 0}
</style></head><body>
<div class="marca">Autorizar o Google Drive</div>
<div class="wrap">

<?php if ($token): ?>
  <div class="card"><div class="ok"><b>Pronto.</b> Copie o valor abaixo para o <code>config.php</code>,
    no bloco <code>drive</code>, em <code>refresh_token</code>, e mude <code>ativo</code> para
    <code>true</code>.</div>
    <p><code><?= htmlspecialchars($token) ?></code></p>
    <p style="color:#9aa4b2;font-size:13px">Depois disso, apague este arquivo do servidor e teste
      pelo <a href="instalar.php" style="color:#8f98ee">instalar.php</a>.</p>
  </div>
<?php else: ?>
  <?php if ($erro): ?><div class="card"><div class="no"><?= htmlspecialchars($erro) ?></div></div><?php endif; ?>

  <div class="card">
    <b>Antes de continuar, no Google Cloud Console</b>
    <ol style="color:#c8cfda;font-size:14px">
      <li>Crie um projeto e ative a <b>Google Drive API</b>.</li>
      <li>Em <b>Credenciais</b>, crie um <b>ID do cliente OAuth</b> do tipo <b>Aplicativo da Web</b>.</li>
      <li>Em <b>URIs de redirecionamento autorizados</b>, cole exatamente:<br>
        <code><?= htmlspecialchars($redirect) ?></code></li>
      <li>Na tela de consentimento, adicione o seu e-mail como <b>usuário de teste</b>.</li>
    </ol>
  </div>

  <form class="card" method="post">
    <b>Credenciais do OAuth</b>
    <label>Client ID</label>
    <input name="client_id" value="<?= htmlspecialchars($id) ?>" autocomplete="off">
    <label>Client Secret</label>
    <input name="client_secret" value="<?= htmlspecialchars($secret) ?>" autocomplete="off">
    <button class="btn" type="submit">Guardar</button>
  </form>

  <?php if ($id && $secret): ?>
    <div class="card">
      <b>Agora autorize com a conta dona da pasta</b>
      <p style="color:#9aa4b2;font-size:13px">Entre com a conta Google que tem acesso à pasta do backup.</p>
      <a class="btn" href="<?= htmlspecialchars($linkGoogle) ?>">Autorizar no Google</a>
    </div>
  <?php endif; ?>
<?php endif; ?>

</div></body></html>
