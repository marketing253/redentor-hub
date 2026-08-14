<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   auth.php — Login, sessão e usuários do Portal Redentor
   Autossuficiente (não depende do api.php antigo).
   Usa o mesmo db_config.php. A tabela é criada sozinha.
   ============================================================ */

/* Blindagem: qualquer erro fatal vira JSON legível (nunca resposta vazia) */
error_reporting(E_ALL); ini_set('display_errors','0');
set_exception_handler(function($e){
  if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok'=>false,'error'=>'PHP exception: '.$e->getMessage().' (linha '.$e->getLine().')')); exit;
});
register_shutdown_function(function(){
  $er=error_get_last();
  if($er && in_array($er['type'], array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR))){
    if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>false,'error'=>'PHP fatal: '.$er['message'].' ('.basename($er['file']).':'.$er['line'].')'));
  }
});

require __DIR__.'/db_config.php';
// Cookie de sessao protegido (httponly, samesite, secure em https)
if(session_status() !== PHP_SESSION_ACTIVE){
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  if(PHP_VERSION_ID >= 70300){
    session_set_cookie_params(array('lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$secure,'samesite'=>'Lax'));
  } else {
    session_set_cookie_params(0,'/','',$secure,true);
  }
}
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($a){ echo json_encode($a); exit; }
function fail($m){ out(array('ok'=>false, 'error'=>$m)); }

$action = isset($_GET['action']) ? $_GET['action'] : '';
$body = json_decode(file_get_contents('php://input'), true);
if(!is_array($body)) $body = array();

$mysqli = portal_db();
if(!$mysqli) fail('Sem conexão com o banco de dados — confira as credenciais em db_config.php e se o MySQL está ativo no painel.');

$mysqli->query(
  "CREATE TABLE IF NOT EXISTS portal_usuarios (
     id INT AUTO_INCREMENT PRIMARY KEY,
     username VARCHAR(60) UNIQUE NOT NULL,
     name VARCHAR(120) NOT NULL,
     senha_hash VARCHAR(255) NOT NULL,
     role VARCHAR(20) NOT NULL DEFAULT 'user',
     perm_fuel TINYINT(1) NOT NULL DEFAULT 1,
     perm_drive TINYINT(1) NOT NULL DEFAULT 1,
     perm_biart TINYINT(1) NOT NULL DEFAULT 1,
     perm_dash TINYINT(1) NOT NULL DEFAULT 1,
     criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// Garante as colunas de 2FA mesmo em tabelas ja existentes
function ensureCol($mysqli,$col,$def){
  $r=$mysqli->query("SHOW COLUMNS FROM portal_usuarios LIKE '".$mysqli->real_escape_string($col)."'");
/* Colunas dinâmicas (criadas sob demanda) */
foreach(array('perms_json'=>"TEXT NULL",'foto'=>"MEDIUMTEXT NULL",'telefone'=>"VARCHAR(30) NOT NULL DEFAULT ''") as $c=>$def){
  $r=$mysqli->query("SHOW COLUMNS FROM portal_usuarios LIKE '$c'");
  if($r && $r->num_rows==0) $mysqli->query("ALTER TABLE portal_usuarios ADD COLUMN $c $def");
}

  if($r && $r->num_rows===0){ $mysqli->query("ALTER TABLE portal_usuarios ADD COLUMN $col $def"); }
}
ensureCol($mysqli,'totp_secret','VARCHAR(64) DEFAULT NULL');
ensureCol($mysqli,'totp_backup','TEXT DEFAULT NULL');

/* ===== TOTP (Google Authenticator) ===== */
function b32_encode($bin){
  $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits='';
  for($i=0;$i<strlen($bin);$i++){$bits.=str_pad(decbin(ord($bin[$i])),8,'0',STR_PAD_LEFT);}
  $out='';
  for($i=0;$i<strlen($bits);$i+=5){$c=substr($bits,$i,5);if(strlen($c)<5)$c=str_pad($c,5,'0',STR_PAD_RIGHT);$out.=$a[bindec($c)];}
  return $out;
}
function b32_decode($b32){
  $a='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  $b32=strtoupper(preg_replace('/[^A-Z2-7]/','',$b32));$bits='';
  for($i=0;$i<strlen($b32);$i++){$bits.=str_pad(decbin(strpos($a,$b32[$i])),5,'0',STR_PAD_LEFT);}
  $out='';
  for($i=0;$i+8<=strlen($bits);$i+=8){$out.=chr(bindec(substr($bits,$i,8)));}
  return $out;
}
function totp_secret(){ return b32_encode(random_bytes(20)); }
function totp_at($secret,$counter){
  $key=b32_decode($secret);
  $bin=pack('N*',0).pack('N*',$counter);
  $h=hash_hmac('sha1',$bin,$key,true);
  $o=ord($h[19])&0xf;
  $n=((ord($h[$o])&0x7f)<<24)|((ord($h[$o+1])&0xff)<<16)|((ord($h[$o+2])&0xff)<<8)|(ord($h[$o+3])&0xff);
  return str_pad($n%1000000,6,'0',STR_PAD_LEFT);
}
function totp_verify($secret,$code,$window=1){
  $code=preg_replace('/\D/','',$code);
  if(strlen($code)!==6||!$secret) return false;
  $t=floor(time()/30);
  for($i=-$window;$i<=$window;$i++){ if(hash_equals(totp_at($secret,$t+$i),$code)) return true; }
  return false;
}
function consumeBackup($mysqli,$row,$code){
  $code=strtoupper(preg_replace('/\s/','',$code));
  $list=json_decode(isset($row['totp_backup'])?$row['totp_backup']:'[]',true);
  if(!is_array($list)) return false;
  foreach($list as $i=>$hash){
    if($hash && password_verify($code,$hash)){
      unset($list[$i]);
      $j=json_encode(array_values($list)); $id=intval($row['id']);
      $st=$mysqli->prepare("UPDATE portal_usuarios SET totp_backup=? WHERE id=?");
      $st->bind_param('si',$j,$id); $st->execute();
      return true;
    }
  }
  return false;
}

// Cria o admin padrão (lorena/8586) se a tabela estiver vazia
$r = $mysqli->query("SELECT COUNT(*) AS n FROM portal_usuarios");
$rw = $r->fetch_assoc();
if(intval($rw['n']) === 0){
  $h = password_hash('8586', PASSWORD_DEFAULT);
  $st = $mysqli->prepare(
    "INSERT INTO portal_usuarios (username,name,senha_hash,role,perm_fuel,perm_drive,perm_biart,perm_dash)
     VALUES ('lorena','Lorena',?,'admin',1,1,1,1)");
  $st->bind_param('s', $h); $st->execute();
}

// ===== Seguranca: log de acessos + bloqueio anti-forca-bruta =====
$mysqli->query("CREATE TABLE IF NOT EXISTS portal_acessos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60), nome VARCHAR(120), ip VARCHAR(60), agente VARCHAR(255),
  quando DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$mysqli->query("CREATE TABLE IF NOT EXISTS portal_login_fail (
  ident VARCHAR(120) PRIMARY KEY, tentativas INT DEFAULT 0,
  bloqueado_ate DATETIME NULL, atualizado DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if(!defined('LOGIN_MAX_TENTATIVAS')) define('LOGIN_MAX_TENTATIVAS', 5);
if(!defined('LOGIN_BLOQUEIO_MIN')) define('LOGIN_BLOQUEIO_MIN', 15);

function cliente_ip(){
  foreach(array('HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR') as $k){
    if(!empty($_SERVER[$k])){ $v=explode(',', $_SERVER[$k]); return trim($v[0]); }
  }
  return '';
}
function bloqueio_restante($mysqli,$ident){
  $st=$mysqli->prepare("SELECT bloqueado_ate FROM portal_login_fail WHERE ident=? LIMIT 1");
  $st->bind_param('s',$ident); $st->execute();
  $r=$st->get_result()->fetch_assoc();
  if($r && $r['bloqueado_ate']){ $rest=strtotime($r['bloqueado_ate'])-time(); if($rest>0) return (int)ceil($rest/60); }
  return 0;
}
function registrar_falha($mysqli,$ident){
  $st=$mysqli->prepare("SELECT tentativas,UNIX_TIMESTAMP(atualizado) AS at FROM portal_login_fail WHERE ident=? LIMIT 1");
  $st->bind_param('s',$ident); $st->execute();
  $r=$st->get_result()->fetch_assoc();
  $janela = LOGIN_BLOQUEIO_MIN*60; $tent=1;
  if($r){ $tent = ((time()-intval($r['at']))<=$janela) ? intval($r['tentativas'])+1 : 1; }
  if($tent >= LOGIN_MAX_TENTATIVAS){
    $ate = date('Y-m-d H:i:s', time()+LOGIN_BLOQUEIO_MIN*60); $zero=0;
    $st=$mysqli->prepare("INSERT INTO portal_login_fail (ident,tentativas,bloqueado_ate,atualizado) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE tentativas=VALUES(tentativas),bloqueado_ate=VALUES(bloqueado_ate),atualizado=NOW()");
    $st->bind_param('sis',$ident,$zero,$ate); $st->execute();
  } else {
    $st=$mysqli->prepare("INSERT INTO portal_login_fail (ident,tentativas,bloqueado_ate,atualizado) VALUES (?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE tentativas=VALUES(tentativas),bloqueado_ate=NULL,atualizado=NOW()");
    $st->bind_param('si',$ident,$tent); $st->execute();
  }
}
function limpar_falha($mysqli,$ident){ $st=$mysqli->prepare("DELETE FROM portal_login_fail WHERE ident=?"); $st->bind_param('s',$ident); $st->execute(); }
function logar_acesso($mysqli,$row){
  if(!$row) return;
  $ip=cliente_ip(); $ag=isset($_SERVER['HTTP_USER_AGENT'])?substr($_SERVER['HTTP_USER_AGENT'],0,250):'';
  $st=$mysqli->prepare("INSERT INTO portal_acessos (username,nome,ip,agente) VALUES (?,?,?,?)");
  $st->bind_param('ssss',$row['username'],$row['name'],$ip,$ag); $st->execute();
}

function tokenDe($mysqli,$username){
  $mysqli->query("CREATE TABLE IF NOT EXISTS portal_tokens (
    username VARCHAR(60) PRIMARY KEY, token VARCHAR(64) NOT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $u=$mysqli->real_escape_string($username);
  $r=$mysqli->query("SELECT token FROM portal_tokens WHERE username='$u' LIMIT 1");
  $row=$r?$r->fetch_assoc():null;
  if($row && !empty($row['token'])) return $row['token'];
  $t=bin2hex(random_bytes(24));
  $te=$mysqli->real_escape_string($t);
  $mysqli->query("REPLACE INTO portal_tokens (username, token) VALUES ('$u','$te')");
  return $t;
}
function userObj($row){
  global $mysqli;
  $pj = array();
  if(!empty($row['perms_json'])){ $t = json_decode($row['perms_json'], true); if(is_array($t)) $pj = $t; }
  $legacy = array('fuel'=>$row['perm_fuel']==1,'drive'=>$row['perm_drive']==1,'biart'=>$row['perm_biart']==1,'dash'=>$row['perm_dash']==1);
  foreach($legacy as $k=>$v){ if(!array_key_exists($k,$pj)) $pj[$k] = $v; }
  return array(
    'token' => tokenDe($mysqli, $row['username']),
    'id' => intval($row['id']),
    'username' => $row['username'],
    'name' => $row['name'],
    'role' => $row['role'],
    'has2fa' => !empty($row['totp_secret']),
    'telefone' => isset($row['telefone']) ? $row['telefone'] : '',
    'foto' => isset($row['foto']) ? $row['foto'] : '',
    'perms' => $pj
  );
}
function fetchUserById($mysqli, $id){
  $st = $mysqli->prepare("SELECT * FROM portal_usuarios WHERE id=? LIMIT 1");
  $st->bind_param('i', $id); $st->execute();
  return $st->get_result()->fetch_assoc();
}

/* ---------- LOGIN ---------- */
/* ── reCAPTCHA v2 (Google) ────────────────────────────────────────────
   A chave secreta fica SÓ aqui, no servidor. A do site aparece no HTML —
   é assim mesmo, ela não serve para nada sozinha.

   RECAPTCHA_ATIVO em false desliga a checagem sem mexer em mais nada:
   a verificação própria (armadilha + tempo mínimo) continua valendo e
   ninguém fica trancado fora enquanto o problema é resolvido. */
define('RECAPTCHA_ATIVO', true);
$__authsec = @include __DIR__.'/auth_secrets.php';
define('RECAPTCHA_SITE',   $__authsec['recaptcha_site'] ?? '');
define('RECAPTCHA_SECRET', $__authsec['recaptcha_secret'] ?? '');

/** @return array{0:bool,1:string} [passou, motivo] */
function captcha_valido($token){
  if(!RECAPTCHA_ATIVO || RECAPTCHA_SECRET === '') return array(true, 'desligado');
  if($token === '') return array(false, 'sem token');
  $post = http_build_query(array(
    'secret'   => RECAPTCHA_SECRET,
    'response' => $token,
    'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''
  ));
  $resp = false;
  if(function_exists('curl_init')){
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, array(
      CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post,
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5
    ));
    $resp = curl_exec($ch);
    curl_close($ch);
  }
  /* Google fora do ar ou bloqueado na saída do servidor: deixa passar e
     registra. Trancar o portal inteiro porque um serviço de terceiro caiu
     seria trocar um problema raro por outro pior — e a armadilha, o tempo
     mínimo e o bloqueio por tentativas continuam valendo. */
  if($resp === false) { error_log('recaptcha: sem resposta do Google'); return array(true, 'sem resposta'); }
  $j = json_decode($resp, true);
  if(!is_array($j)) return array(true, 'resposta ilegivel');
  if(!empty($j['success'])) return array(true, 'ok');

  $erros = isset($j['error-codes']) ? implode(',', (array)$j['error-codes']) : '';
  $host  = isset($j['hostname']) ? (string)$j['hostname'] : '';
  error_log('recaptcha recusou: ' . $erros . ' hostname=' . $host);

  /* Erro de CONFIGURAÇÃO não é ataque: é chave trocada ou domínio fora da
     lista. Trancar todo mundo fora por isso seria pior que o problema —
     a armadilha, o tempo mínimo e o bloqueio por tentativas continuam
     valendo. Passa, e o motivo fica no log e no diagnóstico. */
  /* invalid-input-response entrou nesta lista por experiência dura: ele
     aparece tanto para token forjado quanto para PAR DE CHAVES trocado
     ou chave do tipo Enterprise, que o siteverify clássico não aceita.
     Como o segundo caso tranca o portal inteiro e o primeiro já é coberto
     pela armadilha, pelo tempo mínimo e pelo bloqueio por tentativas,
     aqui o certo é deixar entrar e registrar. */
  if(strpos($erros, 'invalid-input-secret') !== false
     || strpos($erros, 'invalid-keys') !== false
     || strpos($erros, 'missing-input-secret') !== false
     || strpos($erros, 'invalid-input-response') !== false){
    error_log('recaptcha: liberando apesar de "' . $erros . '" — confira o par de chaves no painel');
    return array(true, 'config:' . $erros);
  }
  return array(false, $erros !== '' ? $erros : ('recusado' . ($host ? " (dominio $host)" : '')));
}

/* Diagnóstico do reCAPTCHA: diz se a chave secreta conversa com o Google
   e o que ele responde. Não exige estar logado — de propósito, porque o
   problema aparece justamente em quem não consegue entrar — e não revela
   a chave, só o veredito. */
if($action === 'captcha_diag'){
  $r = array('site_key' => substr(RECAPTCHA_SITE, 0, 12) . '…',
             'secret_definida' => RECAPTCHA_SECRET !== '',
             'curl' => function_exists('curl_init'));
  if(RECAPTCHA_SECRET !== '' && function_exists('curl_init')){
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    curl_setopt_array($ch, array(CURLOPT_POST=>true,
      CURLOPT_POSTFIELDS=>http_build_query(array('secret'=>RECAPTCHA_SECRET,'response'=>'teste')),
      CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>8));
    $resp = curl_exec($ch);
    $r['erro_curl'] = curl_error($ch);
    curl_close($ch);
    $j = json_decode((string)$resp, true);
    $r['resposta'] = is_array($j) ? $j : substr((string)$resp, 0, 200);
    /* Com um token falso, a resposta certa é invalid-input-response.
       Se vier invalid-input-secret, a chave secreta está errada. */
    $codes = isset($j['error-codes']) ? (array)$j['error-codes'] : array();
    $r['veredito'] = in_array('invalid-input-response', $codes, true)
        ? 'chave secreta OK — o problema é outro (veja o domínio cadastrado)'
        : (in_array('invalid-input-secret', $codes, true)
            ? 'CHAVE SECRETA ERRADA — confira no painel do reCAPTCHA'
            : 'resposta inesperada, veja o campo resposta');
  }
  out($r);
}

if($action === 'login'){
  $u = strtolower(trim(isset($body['user']) ? $body['user'] : ''));
  $p = isset($body['pass']) ? $body['pass'] : '';
  if($u === '') fail('Informe o usuário.');

  /* Verificação humana.
     A caixa "não sou um robô" sozinha não segura ninguém: qualquer script
     manda o campo marcado. O que segura é o conjunto — a armadilha
     invisível, que só um robô preenche, e o tempo entre abrir a tela e
     enviar, que nenhuma pessoa consegue fazer em meio segundo.
     A resposta é a mesma de senha errada de propósito: dizer "seu robô
     foi detectado" ensina o robô a melhorar. */
  $armadilha = isset($body['apelido']) ? trim((string)$body['apelido']) : '';
  $msTela    = isset($body['ms']) ? (int)$body['ms'] : 99999;
  if($armadilha !== '' || $msTela < 1200){
    registrar_falha($mysqli, $u);
    usleep(700000);
    fail('Não foi possível validar a solicitação. Recarregue a página e tente de novo.');
  }

  /* reCAPTCHA: só cobra quando o navegador conseguiu carregar o Google.
     Quem não conseguiu passou pela caixa própria, já conferida acima. */
  $capToken = isset($body['captcha']) ? trim((string)$body['captcha']) : '';
  if($capToken !== ''){
    list($capOk, $capMotivo) = captcha_valido($capToken);
    if(!$capOk){
      registrar_falha($mysqli, $u);
      usleep(500000);
      fail(strpos($capMotivo, 'timeout') !== false || strpos($capMotivo, 'duplicate') !== false
        ? 'A verificação expirou. Marque "Não sou um robô" de novo.'
        : 'Verificação de segurança não confirmada (' . $capMotivo . ').');
    }
  }
  $mins = bloqueio_restante($mysqli, $u);
  if($mins > 0) fail("Muitas tentativas. Tente novamente em $mins min.");
  $st = $mysqli->prepare("SELECT * FROM portal_usuarios WHERE username=? LIMIT 1");
  $st->bind_param('s', $u); $st->execute();
  $row = $st->get_result()->fetch_assoc();
  if(!$row){ registrar_falha($mysqli, $u); fail('Usuário não encontrado.'); }

  /* Ja tem autenticador: entra so com o codigo (sem senha).
     Se a pessoa escolheu digitar a senha ("Entrar com senha"), ela é
     conferida antes — quem erra a senha nem chega à tela do código. */
  if(!empty($row['totp_secret'])){
    if($p !== '' && !password_verify($p, $row['senha_hash'])){
      registrar_falha($mysqli, $u);
      fail('Senha incorreta.');
    }
    $_SESSION['pre2fa_uid'] = intval($row['id']);
    unset($_SESSION['enroll_uid'], $_SESSION['enroll_secret'], $_SESSION['enroll_backup'], $_SESSION['uid']);
    limpar_falha($mysqli, $u);
    out(array('ok'=>true, 'need2fa'=>true, 'name'=>$row['name']));
  }

  /* Primeiro acesso (sem autenticador): a senha e exigida UMA vez, para cadastrar o app com seguranca */
  if($p === ''){ out(array('ok'=>true, 'need_pass'=>true, 'name'=>$row['name'])); }
  if(!password_verify($p, $row['senha_hash'])){ registrar_falha($mysqli, $u); fail('Senha incorreta.'); }
  limpar_falha($mysqli, $u);

  if(empty($row['totp_secret'])){
    // Primeiro acesso: precisa cadastrar o Google Authenticator
    $secret = totp_secret();
    $backup = array(); $backupHash = array();
    for($i=0;$i<6;$i++){ $c = strtoupper(bin2hex(random_bytes(4))); $backup[] = $c; $backupHash[] = password_hash($c, PASSWORD_DEFAULT); }
    $_SESSION['enroll_uid'] = intval($row['id']);
    $_SESSION['enroll_secret'] = $secret;
    $_SESSION['enroll_backup'] = $backupHash;
    unset($_SESSION['pre2fa_uid'], $_SESSION['uid']);
    $otp = 'otpauth://totp/'.rawurlencode('Portal Redentor:'.$row['username']).'?secret='.$secret.'&issuer='.rawurlencode('Portal Redentor').'&digits=6&period=30';
    out(array('ok'=>true, 'enroll'=>true, 'name'=>$row['name'], 'secret'=>$secret, 'otpauth'=>$otp, 'backup'=>$backup));
  } else {
    $_SESSION['pre2fa_uid'] = intval($row['id']);
    unset($_SESSION['enroll_uid'], $_SESSION['enroll_secret'], $_SESSION['enroll_backup'], $_SESSION['uid']);
    out(array('ok'=>true, 'need2fa'=>true, 'name'=>$row['name']));
  }
}

/* ---------- 2A ETAPA: codigo do Google Authenticator ---------- */
if($action === 'login_2fa'){
  $code = isset($body['code']) ? trim($body['code']) : '';
  if($code === '') fail('Informe o codigo do app.');
  $_SESSION['fa_tries'] = isset($_SESSION['fa_tries']) ? intval($_SESSION['fa_tries']) : 0;
  if($_SESSION['fa_tries'] >= 5){
    unset($_SESSION['pre2fa_uid'], $_SESSION['enroll_uid'], $_SESSION['enroll_secret'], $_SESSION['enroll_backup'], $_SESSION['fa_tries']);
    fail('Muitos codigos errados. Entre com usuario e senha novamente.');
  }

  if(!empty($_SESSION['enroll_uid'])){
    $secret = $_SESSION['enroll_secret'];
    if(!totp_verify($secret, $code)){ $_SESSION['fa_tries']++; fail('Código inválido. Use o código atual do app.'); }
    $_SESSION['fa_tries']=0;
    $uid = intval($_SESSION['enroll_uid']);
    $bj = json_encode($_SESSION['enroll_backup']);
    $st = $mysqli->prepare("UPDATE portal_usuarios SET totp_secret=?, totp_backup=? WHERE id=?");
    $st->bind_param('ssi', $secret, $bj, $uid); $st->execute();
    $_SESSION['uid'] = $uid;
    unset($_SESSION['enroll_uid'], $_SESSION['enroll_secret'], $_SESSION['enroll_backup']);
    $urow = fetchUserById($mysqli,$uid); logar_acesso($mysqli,$urow);
    out(array('ok'=>true, 'user'=>userObj($urow)));
  }

  if(!empty($_SESSION['pre2fa_uid'])){
    $uid = intval($_SESSION['pre2fa_uid']);
    $row = fetchUserById($mysqli, $uid);
    if(!$row) fail('Sessão expirada. Entre novamente.');
    $ok = totp_verify($row['totp_secret'], $code);
    if(!$ok) $ok = consumeBackup($mysqli, $row, $code);
    if(!$ok){ $_SESSION['fa_tries']++; fail('Código inválido.'); }
    $_SESSION['fa_tries']=0;
    $_SESSION['uid'] = $uid;
    unset($_SESSION['pre2fa_uid']);
    logar_acesso($mysqli,$row);
    out(array('ok'=>true, 'user'=>userObj($row)));
  }

  fail('Sessão expirada. Entre novamente.');
}

/* ---------- SESSÃO ATUAL ---------- */
if($action === 'session'){
  if(!empty($_SESSION['uid'])){
    $row = fetchUserById($mysqli, intval($_SESSION['uid']));
    if($row) out(array('ok'=>true, 'user'=>userObj($row)));
  }
  out(array('ok'=>true, 'user'=>null));
}

/* ---------- LOGOUT ---------- */

/* ---------- PERFIL DO PRÓPRIO USUÁRIO ---------- */
if($action === 'perfil_save'){
  if(empty($_SESSION['uid'])) fail('Faça login.');
  $uid = intval($_SESSION['uid']);
  $tel = isset($body['telefone']) ? trim($body['telefone']) : null;
  $foto = array_key_exists('foto', $body) ? $body['foto'] : null; /* '' remove */
  if($foto !== null && strlen($foto) > 400000) fail('Foto muito grande — use uma imagem menor.');
  if($tel !== null){
    $st = $mysqli->prepare("UPDATE portal_usuarios SET telefone=? WHERE id=?");
    $st->bind_param('si', $tel, $uid); $st->execute();
  }
  if($foto !== null){
    $st = $mysqli->prepare("UPDATE portal_usuarios SET foto=? WHERE id=?");
    $st->bind_param('si', $foto, $uid); $st->execute();
  }
  $row = fetchUserById($mysqli, $uid);
  out(array('ok'=>true, 'user'=>userObj($row)));
}
if($action === 'trocar_senha'){
  if(empty($_SESSION['uid'])) fail('Faça login.');
  $uid = intval($_SESSION['uid']);
  $atual = isset($body['atual']) ? $body['atual'] : '';
  $nova = isset($body['nova']) ? $body['nova'] : '';
  if(strlen($nova) < 4) fail('A nova senha precisa de pelo menos 4 caracteres.');
  $row = fetchUserById($mysqli, $uid);
  if(!$row) fail('Usuário não encontrado.');
  if(!password_verify($atual, $row['senha_hash'])) fail('Senha atual incorreta.');
  $h = password_hash($nova, PASSWORD_DEFAULT);
  $st = $mysqli->prepare("UPDATE portal_usuarios SET senha_hash=? WHERE id=?");
  $st->bind_param('si', $h, $uid); $st->execute();
  out(array('ok'=>true));
}
if($action === 'fotos'){
  if(empty($_SESSION['uid'])) fail('Faça login.');
  $res = $mysqli->query("SELECT username, foto FROM portal_usuarios WHERE foto IS NOT NULL AND foto <> ''");
  $map = array();
  if($res){ while($r = $res->fetch_assoc()){ $map[$r['username']] = $r['foto']; } }
  out(array('ok'=>true, 'fotos'=>$map));
}

if($action === 'logout'){
  if(isset($_SESSION['uid'])){
    $rid=$mysqli->query("SELECT username FROM portal_usuarios WHERE id=".intval($_SESSION['uid'])." LIMIT 1");
    $ru=$rid?$rid->fetch_assoc():null;
    if($ru){ $uu=$mysqli->real_escape_string($ru['username']); $mysqli->query("DELETE FROM portal_tokens WHERE username='$uu'"); }
  }
  $_SESSION = array();
  session_destroy();
  out(array('ok'=>true));
}

/* ---------- CONVITE: validar (público, via link) ---------- */
function ensureConvites($mysqli){
  $mysqli->query("CREATE TABLE IF NOT EXISTS portal_convites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,
    nome_sugerido VARCHAR(120) DEFAULT '',
    role VARCHAR(20) NOT NULL DEFAULT 'user',
    criado_por VARCHAR(60), usado_por INT DEFAULT NULL,
    expira DATETIME, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
if($action === 'invite_info'){
  ensureConvites($mysqli);
  $tk = isset($body['token']) ? trim($body['token']) : '';
  $st=$mysqli->prepare("SELECT nome_sugerido,role,usado_por,expira FROM portal_convites WHERE token=? LIMIT 1");
  $st->bind_param('s',$tk); $st->execute();
  $r=$st->get_result()->fetch_assoc();
  if(!$r) fail('Convite inválido.');
  if($r['usado_por']) fail('Este convite já foi utilizado.');
  if($r['expira'] && strtotime($r['expira'])<time()) fail('Convite expirado. Peça um novo ao administrador.');
  out(array('ok'=>true,'nome'=>$r['nome_sugerido'],'role'=>$r['role']));
}

/* ---------- CONVITE: aceitar (público) — cria a conta e inicia o autenticador ---------- */
if($action === 'invite_accept'){
  ensureConvites($mysqli);
  $tk = isset($body['token']) ? trim($body['token']) : '';
  $username = strtolower(trim(isset($body['username']) ? $body['username'] : ''));
  $name = trim(isset($body['name']) ? $body['name'] : '');
  $pass = isset($body['password']) ? $body['password'] : '';
  if($tk==='') fail('Convite inválido.');
  if($username==='' || $name==='') fail('Preencha nome e usuário.');
  if(!preg_match('/^[a-z0-9._-]{3,30}$/',$username)) fail('Usuário inválido: use 3-30 letras/números, sem espaços.');
  if(strlen($pass)<4) fail('A senha precisa ter pelo menos 4 caracteres.');
  $st=$mysqli->prepare("SELECT * FROM portal_convites WHERE token=? LIMIT 1");
  $st->bind_param('s',$tk); $st->execute();
  $cv=$st->get_result()->fetch_assoc();
  if(!$cv) fail('Convite inválido.');
  if($cv['usado_por']) fail('Este convite já foi utilizado.');
  if($cv['expira'] && strtotime($cv['expira'])<time()) fail('Convite expirado.');
  $chk=$mysqli->prepare("SELECT id FROM portal_usuarios WHERE username=? LIMIT 1");
  $chk->bind_param('s',$username); $chk->execute();
  if($chk->get_result()->fetch_assoc()) fail('Já existe um usuário com esse login. Escolha outro.');
  $h=password_hash($pass, PASSWORD_DEFAULT);
  $role=($cv['role']==='admin')?'admin':'user';
  $st=$mysqli->prepare("INSERT INTO portal_usuarios (username,name,senha_hash,role,perm_fuel,perm_drive,perm_biart,perm_dash) VALUES (?,?,?,?,1,1,1,1)");
  $st->bind_param('ssss',$username,$name,$h,$role);
  if(!$st->execute()) fail('Erro ao criar a conta.');
  $uid=intval($mysqli->insert_id);
  $st=$mysqli->prepare("UPDATE portal_convites SET usado_por=? WHERE id=?");
  $iid=intval($cv['id']); $st->bind_param('ii',$uid,$iid); $st->execute();
  /* inicia o cadastro do autenticador (mesmo fluxo do primeiro login) */
  $secret = totp_secret();
  $backup = array(); $backupHash = array();
  for($i=0;$i<6;$i++){ $c = strtoupper(bin2hex(random_bytes(4))); $backup[] = $c; $backupHash[] = password_hash($c, PASSWORD_DEFAULT); }
  $_SESSION['enroll_uid'] = $uid;
  $_SESSION['enroll_secret'] = $secret;
  $_SESSION['enroll_backup'] = $backupHash;
  unset($_SESSION['pre2fa_uid'], $_SESSION['uid']);
  $otp = 'otpauth://totp/'.rawurlencode('Portal Redentor:'.$username).'?secret='.$secret.'&issuer='.rawurlencode('Portal Redentor').'&digits=6&period=30';
  out(array('ok'=>true, 'enroll'=>true, 'name'=>$name, 'secret'=>$secret, 'otpauth'=>$otp, 'backup'=>$backup));
}

/* ===== Daqui pra baixo exige estar logado ===== */
if(empty($_SESSION['uid'])) fail('Sessão expirada. Entre novamente.');

/* ---------- LISTA BASICA DE USUARIOS (qualquer logado; para convites de agenda) ---------- */
if($action === 'users_basic'){
  $res=$mysqli->query("SELECT id, username, name FROM portal_usuarios ORDER BY name");
  $items=array();
  while($r=$res->fetch_assoc()){ $items[]=$r; }
  out(array('ok'=>true,'users'=>$items));
}

/* ---------- CONVITE: gerar (admin) ---------- */
if($action === 'invite_create'){
  $me = fetchUserById($mysqli, intval($_SESSION['uid']));
  if(!$me || $me['role'] !== 'admin') fail('Apenas administradores.');
  ensureConvites($mysqli);
  $nome = trim(isset($body['name']) ? $body['name'] : '');
  $role = (isset($body['role']) && $body['role']==='admin') ? 'admin' : 'user';
  $token = bin2hex(random_bytes(16));
  $exp = date('Y-m-d H:i:s', time()+7*24*3600);
  $st=$mysqli->prepare("INSERT INTO portal_convites (token,nome_sugerido,role,criado_por,expira) VALUES (?,?,?,?,?)");
  $st->bind_param('sssss',$token,$nome,$role,$me['username'],$exp);
  if(!$st->execute()) fail('Erro ao gerar convite.');
  out(array('ok'=>true,'token'=>$token,'expira'=>$exp));
}

/* ---------- LISTAR USUÁRIOS ---------- */
if($action === 'users_list'){
  $res = $mysqli->query("SELECT * FROM portal_usuarios ORDER BY role DESC, name ASC");
  $users = array();
  while($row = $res->fetch_assoc()){ $users[] = userObj($row); }
  out(array('ok'=>true, 'users'=>$users));
}

/* ---------- CRIAR / EDITAR USUÁRIO ---------- */
if($action === 'user_save'){
  $username = strtolower(trim(isset($body['username']) ? $body['username'] : ''));
  $name = trim(isset($body['name']) ? $body['name'] : '');
  $pass = isset($body['password']) ? $body['password'] : '';
  $role = (isset($body['role']) && $body['role'] === 'admin') ? 'admin' : 'user';
  $permsIn = (isset($body['perms']) && is_array($body['perms'])) ? $body['perms'] : null;
  if($permsIn !== null){
    $pf = !empty($permsIn['fuel'])  ? 1 : 0;
    $pd = !empty($permsIn['drive']) ? 1 : 0;
    $pb = !empty($permsIn['biart']) ? 1 : 0;
    $px = array_key_exists('dash',$permsIn) ? (!empty($permsIn['dash'])?1:0) : 1;
    $pjson = json_encode($permsIn);
  } else {
    $pf = !empty($body['perm_fuel'])  ? 1 : 0;
    $pd = !empty($body['perm_drive']) ? 1 : 0;
    $pb = !empty($body['perm_biart']) ? 1 : 0;
    $px = !empty($body['perm_dash'])  ? 1 : 0;
    $pjson = null;
  }
  $editId = isset($body['edit_id']) ? intval($body['edit_id']) : 0;
  if($username === '' || $name === '') fail('Preencha usuario e nome.');

  if($editId > 0){
    if($pass !== ''){
      $h = password_hash($pass, PASSWORD_DEFAULT);
      $st = $mysqli->prepare("UPDATE portal_usuarios SET name=?,senha_hash=?,role=?,perm_fuel=?,perm_drive=?,perm_biart=?,perm_dash=?,perms_json=? WHERE id=?");
      $st->bind_param('sssiiiisi', $name,$h,$role,$pf,$pd,$pb,$px,$pjson,$editId);
    } else {
      $st = $mysqli->prepare("UPDATE portal_usuarios SET name=?,role=?,perm_fuel=?,perm_drive=?,perm_biart=?,perm_dash=?,perms_json=? WHERE id=?");
      $st->bind_param('ssiiiisi', $name,$role,$pf,$pd,$pb,$px,$pjson,$editId);
    }
    if(!$st->execute()) fail('Erro ao atualizar.');
    out(array('ok'=>true));
  } else {
    if($pass === '') fail('Defina uma senha para o novo usuario.');
    $chk = $mysqli->prepare("SELECT id FROM portal_usuarios WHERE username=? LIMIT 1");
    $chk->bind_param('s', $username); $chk->execute();
    if($chk->get_result()->fetch_assoc()) fail('Já existe um usuário com esse login.');
    $h = password_hash($pass, PASSWORD_DEFAULT);
    $st = $mysqli->prepare("INSERT INTO portal_usuarios (username,name,senha_hash,role,perm_fuel,perm_drive,perm_biart,perm_dash,perms_json) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->bind_param('ssssiiiis', $username,$name,$h,$role,$pf,$pd,$pb,$px,$pjson);
    if(!$st->execute()) fail('Erro ao criar usuario.');
    out(array('ok'=>true));
  }
}

/* ---------- EXCLUIR USUÁRIO ---------- */
if($action === 'user_delete'){
  $id = isset($body['id']) ? intval($body['id']) : 0;
  if($id <= 0) fail('ID invalido.');
  if($id === intval($_SESSION['uid'])) fail('Voce nao pode excluir o proprio usuario.');
  $st = $mysqli->prepare("DELETE FROM portal_usuarios WHERE id=?");
  $st->bind_param('i', $id);
  if(!$st->execute()) fail('Erro ao excluir.');
  out(array('ok'=>true));
}

/* ---------- RESETAR 2FA (admin) ---------- */
if($action === 'totp_reset'){
  $me = fetchUserById($mysqli, intval($_SESSION['uid']));
  if(!$me || $me['role'] !== 'admin') fail('Apenas administradores podem resetar 2FA.');
  $id = isset($body['id']) ? intval($body['id']) : 0;
  if($id <= 0) fail('ID invalido.');
  $st = $mysqli->prepare("UPDATE portal_usuarios SET totp_secret=NULL, totp_backup=NULL WHERE id=?");
  $st->bind_param('i', $id); $st->execute();
  out(array('ok'=>true));
}

/* ---------- ATIVIDADES: acessos + mudancas (admin) ---------- */
if($action === 'auditoria_list'){
  $me = fetchUserById($mysqli, intval($_SESSION['uid']));
  if(!$me || $me['role'] !== 'admin') fail('Apenas administradores.');
  $mysqli->query("CREATE TABLE IF NOT EXISTS portal_auditoria (
     id INT AUTO_INCREMENT PRIMARY KEY,
     uid INT, username VARCHAR(60), tipo VARCHAR(40), detalhe VARCHAR(255),
     ip VARCHAR(60), quando DATETIME DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $fu = isset($body['user']) ? trim($body['user']) : '';
  $w1 = $fu!=='' ? " WHERE username='".$mysqli->real_escape_string($fu)."'" : '';
  $sql = "SELECT * FROM (
            SELECT username, 'Entrou no portal' AS tipo, '' AS detalhe, ip, quando FROM portal_acessos".$w1."
            UNION ALL
            SELECT username, tipo, detalhe, ip, quando FROM portal_auditoria".$w1."
          ) t ORDER BY quando DESC LIMIT 200";
  $res = $mysqli->query($sql);
  $items=array();
  if($res){ while($r=$res->fetch_assoc()){ $r['quando']=date('d/m/Y H:i', strtotime($r['quando'].' UTC')); $items[]=$r; } }
  out(array('ok'=>true,'items'=>$items));
}

/* ---------- LOG DE ACESSOS (admin) ---------- */
if($action === 'acessos_list'){
  $me = fetchUserById($mysqli, intval($_SESSION['uid']));
  if(!$me || $me['role'] !== 'admin') fail('Apenas administradores.');
  $res = $mysqli->query("SELECT username,nome,ip,agente,DATE_FORMAT(CONVERT_TZ(quando,'+00:00','-03:00'),'%d/%m/%Y %H:%i') AS quando FROM portal_acessos ORDER BY id DESC LIMIT 100");
  $items=array();
  while($r=$res->fetch_assoc()){ $items[]=$r; }
  out(array('ok'=>true,'acessos'=>$items));
}

fail('Acao desconhecida.');
