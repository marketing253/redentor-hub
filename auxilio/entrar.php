<?php
/**
 * Entrada do aluno com login e senha próprios.
 * (A contabilidade continua entrando pela sessão do Hub.)
 */
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();
if (isset($_GET['sair'])) { $_SESSION = []; session_destroy(); header('Location: entrar.php'); exit; }

$cfg  = require __DIR__ . '/config.php';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string)($_POST['usuario'] ?? ''));
    $senha = (string)($_POST['senha'] ?? '');
    usleep(300000);                                   // freia tentativa em massa
    try {
        $c   = $cfg['db'];
        $pdo = new PDO("mysql:host={$c['host']};dbname={$c['base']};charset=utf8mb4",
            $c['usuario'], $c['senha'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        try { garanteEstrutura($pdo); } catch (Throwable $e) {}
        $st = $pdo->prepare('SELECT usuario, senha_hash, precisa_trocar, status, acesso_enviado_em
                             FROM aux_alunos WHERE usuario = ? LIMIT 1');
        $st->execute([$login]);
        $a = $st->fetch();

        /* A senha do convite é padrão e conhecida. Enquanto ela vale, quem
           souber o login entra na conta alheia — e vê CPF, chave Pix e
           boletos. Por isso ela expira: passado o prazo, só a contabilidade
           reabre, com o botão de reenviar que já existe. */
        $horas = (int)($cfg['horas_senha_padrao'] ?? 48);
        $expirou = false;
        if ($a && !empty($a['precisa_trocar']) && !empty($a['acesso_enviado_em']) && $horas > 0) {
            $expirou = (strtotime((string)$a['acesso_enviado_em']) + $horas * 3600) < time();
        }

        if (!$a || empty($a['senha_hash']) || !password_verify($senha, $a['senha_hash'])) {
            $erro = 'Login ou senha incorretos.';
        } elseif ($expirou) {
            $erro = 'A senha do convite venceu (vale ' . $horas . ' horas). '
                  . 'Peça à contabilidade para reenviar o acesso.';
        } elseif ($a['status'] === 'encerrado') {
            $erro = 'Este cadastro está encerrado. Procure a contabilidade.';
        } else {
            $pdo->prepare('UPDATE aux_alunos SET ultimo_acesso=NOW() WHERE usuario=?')->execute([$login]);
            $_SESSION['aux_usuario']       = $a['usuario'];
            $_SESSION['aux_contabilidade'] = false;
            $_SESSION['aux_trocar_senha']  = (bool)$a['precisa_trocar'];
            header('Location: apps/auxilio.html');
            exit;
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if (stripos($msg, 'senha_hash') !== false || stripos($msg, 'Unknown column') !== false) {
            $erro = 'O banco ainda não tem as colunas de senha. Abra o instalar.php e clique em '
                  . '"Atualizar colunas (Pix e senha)".';
        } elseif (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Base table') !== false) {
            $erro = 'As tabelas ainda não foram criadas. Abra o instalar.php e clique em "Criar tabelas".';
        } else {
            $erro = 'Falha ao conectar ao banco.';
            // detalhe só para quem tem a chave: entrar.php?debug=SUA_CHAVE
            if (hash_equals((string)$cfg['chave_teste'], (string)($_GET['debug'] ?? ''))) {
                $erro .= ' — ' . $msg;
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Auxílio Graduação — entrar</title>
<style>
body{margin:0;background:#1b1e24;color:#e8ecf2;font:15px/1.5 "Segoe UI",system-ui,Arial,sans-serif;
  display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.box{width:min(400px,100%);background:#23272f;border:1px solid #333a45;border-radius:12px;overflow:hidden}
.faixa{background:#3B4192;border-bottom:3px solid #D9A93F;padding:16px 20px}
.faixa b{display:block;font-size:16px;letter-spacing:.4px}
.faixa span{font-size:12px;color:#c6cbee}
.corpo{padding:20px}
label{display:block;font-size:12px;color:#9aa4b2;margin:14px 0 4px}
input{width:100%;background:#1b1e24;border:1px solid #333a45;color:#e8ecf2;border-radius:8px;
  padding:10px 12px;font:inherit;box-sizing:border-box}
button{width:100%;margin-top:20px;background:#3B4192;color:#fff;border:0;border-radius:8px;
  padding:11px;font:inherit;cursor:pointer}
button:hover{background:#474fad}
.erro{background:rgba(244,98,58,.14);border-left:3px solid #f4623a;padding:9px 12px;border-radius:6px;
  font-size:13px;margin-top:14px}
.nota{margin-top:16px;font-size:12px;color:#9aa4b2;border-top:1px solid #333a45;padding-top:12px;line-height:1.6}
</style></head><body>
<form class="box" method="post">
  <div class="faixa"><b>Auto Viação Redentor</b><span>Auxílio Graduação</span></div>
  <div class="corpo">
    <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
    <label>Login</label>
    <input type="text" name="usuario" autocomplete="username" autofocus
           value="<?= htmlspecialchars((string)($_POST['usuario'] ?? '')) ?>">
    <label>Senha</label>
    <input type="password" name="senha" autocomplete="current-password">
    <button type="submit">Entrar</button>
    <div class="nota">O login e a senha chegaram no seu e-mail quando a contabilidade
      cadastrou o seu curso. Perdeu ou não recebeu? Peça à contabilidade para reenviar.</div>
  </div>
</form>
</body></html>
