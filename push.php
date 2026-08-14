<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   PUSH NOTIFICATIONS — Redentor Hub
   Endpoints:
     push.php?action=subscribe   (POST JSON: {subscription, username})
     push.php?action=send        (POST JSON: {username, title, body, tag, url})
     push.php?action=send_all    (POST JSON: {title, body, tag, url})
   ============================================================ */
$__vapid = require __DIR__.'/push_secrets.php';
define('VAPID_PUBLIC',  $__vapid['public']);
define('VAPID_PRIVATE', $__vapid['private']);
define('VAPID_SUBJECT', $__vapid['subject']);

require __DIR__.'/db_config.php';

$mysqli = portal_db();
if($mysqli) $mysqli->set_charset('utf8mb4');

/* Tabela de assinaturas push */
if($mysqli) $mysqli->query("CREATE TABLE IF NOT EXISTS portal_push_subs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh TEXT NOT NULL,
  auth_key VARCHAR(100) NOT NULL,
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ep (username, endpoint(500))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Envia para todos os assinantes com role=admin. Usada tanto pelo
   action=send_all (um admin logado clicando em "avisar todo mundo") quanto
   por scripts internos de servidor, como tvi_saude.php, que não têm sessão
   de usuário nenhuma — por isso não depende de token/login, só do cargo
   já cadastrado no banco. */
if(!function_exists('push_enviar_admins')){
  function push_enviar_admins($mysqli, $titulo, $corpo, $tag, $url){
    if(!$mysqli) return array('ok'=>0,'falhas'=>0);
    $payload = json_encode(array(
      'title'=>$titulo, 'body'=>$corpo, 'tag'=>$tag, 'url'=>$url, 'icon'=>'/icon-192.png'
    ), JSON_UNESCAPED_UNICODE);
    $sql = "SELECT DISTINCT s.endpoint, s.p256dh, s.auth_key
              FROM portal_push_subs s
              JOIN portal_usuarios u ON u.username = s.username
             WHERE u.role='admin'";
    $res = $mysqli->query($sql);
    $ok=0; $err=0;
    while($res && $r = $res->fetch_assoc()){
      if(send_push($r['endpoint'], $r['p256dh'], $r['auth_key'], $payload)) $ok++; else $err++;
    }
    return array('ok'=>$ok,'falhas'=>$err);
  }
}

/* A partir daqui é só o roteamento HTTP normal (subscribe/send/send_all).
   Scripts internos que só querem reaproveitar send_push()/push_enviar_admins()
   fazem `define('PUSH_LIB_ONLY', true);` antes do require e pulam tudo isto. */
if(defined('PUSH_LIB_ONLY')) return;

header('Content-Type: application/json; charset=utf-8');
function out($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m){ out(array('ok'=>false,'error'=>$m)); }

if(!$mysqli) fail('Sem conexão com o banco.');

$body = json_decode(file_get_contents('php://input'), true);
if(!$body) $body = array();
$action = isset($_GET['action']) ? $_GET['action'] : (isset($body['action'])?$body['action']:'');

/* ── SUBSCRIBE ── */
function tokenOk($db,$u,$t){
  if(!$u||!$t)return false;
  $ue=$db->real_escape_string($u);
  $r=$db->query("SELECT token FROM portal_tokens WHERE username='$ue' LIMIT 1");
  $row=$r?$r->fetch_assoc():null;
  return $row && hash_equals($row['token'],$t);
}
$__u = isset($body['auth_user']) && $body['auth_user'] !== '' ? trim($body['auth_user']) : (isset($body['username']) ? trim($body['username']) : '');
$__t = isset($body['token']) ? trim($body['token']) : '';
if(!tokenOk($mysqli, $__u, $__t)) fail('Sessão inválida. Faça logout e login novamente.');

if($action === 'subscribe'){
  $sub = isset($body['subscription']) ? $body['subscription'] : null;
  $user = isset($body['username']) ? trim($body['username']) : '';
  if(!$sub || !$user || empty($sub['endpoint'])) fail('Dados incompletos.');
  $ep = $sub['endpoint'];
  $p256dh = isset($sub['keys']['p256dh']) ? $sub['keys']['p256dh'] : '';
  $auth = isset($sub['keys']['auth']) ? $sub['keys']['auth'] : '';
  if(!$p256dh || !$auth) fail('Chaves de push ausentes.');
  /* upsert */
  $st = $mysqli->prepare("REPLACE INTO portal_push_subs (username, endpoint, p256dh, auth_key) VALUES (?,?,?,?)");
  $st->bind_param('ssss', $user, $ep, $p256dh, $auth);
  $st->execute();
  out(array('ok'=>true));
}

/* ── SEND (para um usuário) ── */
if($action === 'send'){
  $user = isset($body['username']) ? trim($body['username']) : '';
  if(!$user) fail('Username obrigatório.');
  $payload = json_encode(array(
    'title' => isset($body['title'])?$body['title']:'Redentor Hub',
    'body'  => isset($body['body'])?$body['body']:'Nova notificação',
    'tag'   => isset($body['tag'])?$body['tag']:'hub-'.time(),
    'url'   => isset($body['url'])?$body['url']:'/',
    'icon'  => '/icon-192.png'
  ), JSON_UNESCAPED_UNICODE);
  $st = $mysqli->prepare("SELECT endpoint, p256dh, auth_key FROM portal_push_subs WHERE username=?");
  $st->bind_param('s', $user); $st->execute();
  $res = $st->get_result();
  $ok=0; $err=0;
  while($r = $res->fetch_assoc()){
    $ret = send_push($r['endpoint'], $r['p256dh'], $r['auth_key'], $payload);
    if($ret) $ok++; else $err++;
  }
  out(array('ok'=>true,'enviados'=>$ok,'falhas'=>$err));
}

/* ── SEND_ALL ── */
if($action === 'send_all'){
  // Antes, qualquer usuário com sessão válida podia notificar TODA a base.
  // Agora exige role=admin, igual à checagem já usada em upload.php.
  $stR = $mysqli->prepare("SELECT role FROM portal_usuarios WHERE username=? LIMIT 1");
  $stR->bind_param('s', $__u); $stR->execute();
  $meRow = $stR->get_result()->fetch_assoc();
  if(!$meRow || $meRow['role'] !== 'admin') fail('Apenas administradores podem enviar para todos.');

  $payload = json_encode(array(
    'title' => isset($body['title'])?$body['title']:'Redentor Hub',
    'body'  => isset($body['body'])?$body['body']:'',
    'tag'   => isset($body['tag'])?$body['tag']:'hub-'.time(),
    'url'   => isset($body['url'])?$body['url']:'/',
    'icon'  => '/icon-192.png'
  ), JSON_UNESCAPED_UNICODE);
  $res = $mysqli->query("SELECT DISTINCT endpoint, p256dh, auth_key FROM portal_push_subs");
  $ok=0; $err=0;
  while($r = $res->fetch_assoc()){
    $ret = send_push($r['endpoint'], $r['p256dh'], $r['auth_key'], $payload);
    if($ret) $ok++; else $err++;
  }
  out(array('ok'=>true,'enviados'=>$ok,'falhas'=>$err));
}

fail('Ação desconhecida.');

/* ══════════════════════════════════════════════════════════
   Web Push: envio com VAPID (JWT) + payload criptografado
   Implementação mínima em PHP puro (sem Composer)
   ══════════════════════════════════════════════════════════ */
function b64url_decode($s){ return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s)%4)%4)); }
function b64url_encode($d){ return rtrim(strtr(base64_encode($d), '+/', '-_'), '='); }

function send_push($endpoint, $userPubB64, $authB64, $payload){
  /* 1. VAPID JWT */
  $aud = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);
  $header = b64url_encode(json_encode(array('typ'=>'JWT','alg'=>'ES256')));
  $claims = b64url_encode(json_encode(array('aud'=>$aud,'exp'=>time()+86400,'sub'=>VAPID_SUBJECT)));
  $unsig = $header.'.'.$claims;

  $privDer = b64url_decode(VAPID_PRIVATE);
  /* Construir chave PEM DER para EC P-256 */
  $pem = "-----BEGIN EC PRIVATE KEY-----\n".
    chunk_split(base64_encode(
      hex2bin('30770201010420').str_pad($privDer,32,"\0",STR_PAD_LEFT).
      hex2bin('a00a06082a8648ce3d030107a14403420004').b64url_decode(VAPID_PUBLIC)
    ), 64, "\n").
    "-----END EC PRIVATE KEY-----\n";
  $key = openssl_pkey_get_private($pem);
  if(!$key) return false;
  $sig = '';
  if(!openssl_sign($unsig, $sig, $key, OPENSSL_ALGO_SHA256)) return false;
  /* DER → raw r||s */
  $raw = der_to_raw($sig);
  $jwt = $unsig.'.'.b64url_encode($raw);

  /* 2. Payload encryption (aes128gcm) */
  $userPub = b64url_decode($userPubB64);
  $authSecret = b64url_decode($authB64);

  $localEC = openssl_pkey_new(array('curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC));
  $localD = openssl_pkey_get_details($localEC);
  $localPub = hex2bin('04').$localD['ec']['x'].$localD['ec']['y'];

  $sharedSecret = '';
  if(!openssl_pkey_derive($localEC, openssl_pkey_get_public(
    "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(
      hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$userPub
    ),64,"\n")."-----END PUBLIC KEY-----\n"
  ), $sharedSecret, 256)){
    return false;
  }

  /* HKDF */
  $prkAuth = hash_hmac('sha256', $sharedSecret, $authSecret, true);
  $ikm = hkdf($prkAuth, "WebPush: info\x00".$userPub.$localPub, 32);
  $salt = random_bytes(16);
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $cek = hkdf($prk, "Content-Encoding: aes128gcm\x00", 16);
  $nonce = hkdf($prk, "Content-Encoding: nonce\x00", 12);

  $padded = "\x00\x00".$payload;  /* 2-byte padding */
  $encrypted = openssl_encrypt($padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
  if($encrypted === false) return false;

  $recordSize = 4096;
  $body = $salt.pack('N', $recordSize).chr(strlen($localPub)).$localPub.$encrypted.$tag;

  /* 3. HTTP POST */
  $ch = curl_init($endpoint);
  curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => array(
      'Content-Type: application/octet-stream',
      'Content-Encoding: aes128gcm',
      'TTL: 86400',
      'Authorization: vapid t='.$jwt.', k='.VAPID_PUBLIC
    ),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true
  ));
  curl_exec($ch);
  $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
  curl_close($ch);
  if($http === 410 || $http === 404){
    /* assinatura expirada — limpar */
    global $mysqli;
    $st = $mysqli->prepare("DELETE FROM portal_push_subs WHERE endpoint=?");
    $st->bind_param('s', $endpoint); $st->execute();
  }
  return ($http >= 200 && $http < 300);
}

function hkdf($ikm, $info, $len){
  $t = '';
  $okm = '';
  for($i=1; strlen($okm)<$len; $i++){
    $t = hash_hmac('sha256', $t.$info.chr($i), $ikm, true);
    $okm .= $t;
  }
  return substr($okm, 0, $len);
}

function der_to_raw($der){
  /* Extrai r e s do DER SEQUENCE e converte para 32+32 bytes */
  $pos = 2; /* skip SEQUENCE tag+len */
  if(ord($der[$pos])===0x02){
    $rLen = ord($der[$pos+1]); $r = substr($der, $pos+2, $rLen); $pos += 2+$rLen;
  } else return $der;
  if(ord($der[$pos])===0x02){
    $sLen = ord($der[$pos+1]); $s = substr($der, $pos+2, $sLen);
  } else return $der;
  $r = str_pad(ltrim($r,"\x00"), 32, "\x00", STR_PAD_LEFT);
  $s = str_pad(ltrim($s,"\x00"), 32, "\x00", STR_PAD_LEFT);
  return $r.$s;
}
