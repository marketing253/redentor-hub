<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   dados.php — Sincroniza os dados de TODOS os sistemas do portal
   (Abastecimento, Direção, Biarticulado, LNT) com o banco MySQL.
   Coloque na MESMA PASTA do index.html. A tabela é criada sozinha.
   ============================================================ */

require __DIR__.'/db_config.php';

// ===== Protecao: exige usuario logado (sessao do auth.php) =====
if(session_status() !== PHP_SESSION_ACTIVE){
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  if(PHP_VERSION_ID >= 70300){
    session_set_cookie_params(array('lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$secure,'samesite'=>'Lax'));
  } else {
    session_set_cookie_params(0,'/','',$secure,true);
  }
}
session_start();
if(empty($_SESSION['uid'])){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok'=>false,'error'=>'Sessão expirada. Entre novamente.','erro'=>'Sessão expirada. Entre novamente.'));
  exit;
}


header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($a){ echo json_encode($a); exit; }
function fail($m){ out(array('ok'=>false, 'erro'=>$m)); }

$action = isset($_GET['action']) ? $_GET['action'] : '';
$body = json_decode(file_get_contents('php://input'), true);
if(!is_array($body)) $body = array();

$mysqli = portal_db();

$mysqli->query(
  "CREATE TABLE IF NOT EXISTS portal_dados (
     chave VARCHAR(190) PRIMARY KEY,
     valor LONGTEXT,
     atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

$mysqli->query(
  "CREATE TABLE IF NOT EXISTS portal_auditoria (
     id INT AUTO_INCREMENT PRIMARY KEY,
     uid INT, username VARCHAR(60), tipo VARCHAR(40), detalhe VARCHAR(255),
     ip VARCHAR(60), quando DATETIME DEFAULT CURRENT_TIMESTAMP
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
function aud_ip(){ foreach(array('HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR') as $k){ if(!empty($_SERVER[$k])){ $v=explode(',', $_SERVER[$k]); return trim($v[0]); } } return ''; }
function audit($mysqli,$tipo,$detalhe){
  $uid=intval($_SESSION['uid']);
  $un='';
  $r=$mysqli->query("SELECT username FROM portal_usuarios WHERE id=".$uid." LIMIT 1");
  if($r && ($x=$r->fetch_assoc())) $un=$x['username'];
  $ip=aud_ip(); $detalhe=substr($detalhe,0,250);
  $st=$mysqli->prepare("INSERT INTO portal_auditoria (uid,username,tipo,detalhe,ip) VALUES (?,?,?,?,?)");
  $st->bind_param('issss',$uid,$un,$tipo,$detalhe,$ip); $st->execute();
}

/* ---------- LER um conjunto de dados ---------- */
if($action === 'get'){
  $key = isset($_GET['key']) ? $_GET['key'] : '';
  if($key === '') fail('Chave nao informada.');
  $stmt = $mysqli->prepare("SELECT valor FROM portal_dados WHERE chave=? LIMIT 1");
  $stmt->bind_param('s', $key);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  out(array('ok'=>true, 'value'=> $row ? $row['valor'] : null));
}

/* ---------- GRAVAR / ATUALIZAR ---------- */
if($action === 'set'){
  $key = isset($body['key'])   ? $body['key']   : '';
  $val = isset($body['value']) ? $body['value'] : '';
  if($key === '') fail('Chave nao informada.');
  /* Agendamento de salas: gravação restrita ao setor de Treinamento (flag salas_editar) ou admin */
  if($key === 'salas_agendamentos_v1'){
    $rme = $mysqli->query("SELECT role, perms_json FROM portal_usuarios WHERE id=".intval($_SESSION['uid'])." LIMIT 1");
    $me  = $rme ? $rme->fetch_assoc() : null;
    $pode = ($me && $me['role'] === 'admin');
    if(!$pode && $me && !empty($me['perms_json'])){
      $pj = json_decode($me['perms_json'], true);
      $pode = is_array($pj) && !empty($pj['salas_editar']);
    }
    if(!$pode){
      audit($mysqli, 'Bloqueado (salas)', 'Tentativa de gravar agendamentos sem permissão salas_editar');
      fail('Somente o setor de Treinamento pode criar ou alterar agendamentos de sala.');
    }
    /* Anti-conflito no servidor: rejeita sobreposição de horário na mesma sala
       (somente reservas de hoje em diante — histórico não bloqueia) */
    $arrS = json_decode($val, true);
    if(!is_array($arrS)) fail('Formato inválido dos agendamentos de sala.');
    $t2mF = function($t){
      if(!preg_match('~^(\d{1,2}):(\d{2})$~', trim((string)$t), $mm)) return -1;
      return intval($mm[1])*60 + intval($mm[2]);
    };
    $hojeS = date('Y-m-d');
    $porKS = array();
    foreach($arrS as $ag){
      if(!is_array($ag)) continue;
      $dt = isset($ag['data']) ? (string)$ag['data'] : '';
      if($dt === '' || $dt < $hojeS) continue;
      $i = $t2mF(isset($ag['inicio'])?$ag['inicio']:''); $f = $t2mF(isset($ag['fim'])?$ag['fim']:'');
      if($i < 0 || $f < 0 || $f <= $i) continue;
      $k = (isset($ag['salaId'])?(string)$ag['salaId']:'').'|'.$dt;
      if(!isset($porKS[$k])) $porKS[$k] = array();
      foreach($porKS[$k] as $o){
        if($i < $o[1] && $f > $o[0]){
          $ev1 = isset($ag['evento']) ? mb_substr((string)$ag['evento'],0,40) : 'reserva';
          fail('Conflito de horário na sala em '.$dt.': "'.$ev1.'" sobrepõe "'.mb_substr($o[2],0,40).'". Atualize a tela e tente novamente.');
        }
      }
      $porKS[$k][] = array($i, $f, isset($ag['evento'])?(string)$ag['evento']:'reserva');
    }
  }
  $stmt = $mysqli->prepare(
    "INSERT INTO portal_dados (chave, valor) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
  $stmt->bind_param('ss', $key, $val);
  if(!$stmt->execute()) fail('Erro ao salvar: '.$stmt->error);
  audit($mysqli, 'Alterou dados', $key);
  out(array('ok'=>true));
}

/* ---------- LISTAR (diagnóstico) ---------- */
if($action === 'list'){
  $res = $mysqli->query("SELECT chave, LENGTH(valor) AS tam, atualizado_em FROM portal_dados ORDER BY chave");
  $items = array();
  while($row = $res->fetch_assoc()){
    $items[] = array('key'=>$row['chave'], 'tamanho'=>intval($row['tam']), 'atualizado_em'=>$row['atualizado_em']);
  }
  out(array('ok'=>true, 'items'=>$items));
}

/* ---------- EVENTO (ex.: abriu um sistema) ---------- */
if($action === 'evento'){
  $sis = isset($body['sistema']) ? trim($body['sistema']) : '';
  if($sis!==''){ audit($mysqli, 'Abriu sistema', $sis); }
  out(array('ok'=>true));
}

/* ---------- BACKUP COMPLETO (admin) ---------- */
if($action === 'dump'){
  $r=$mysqli->query("SELECT role FROM portal_usuarios WHERE id=".intval($_SESSION['uid'])." LIMIT 1");
  $me=$r?$r->fetch_assoc():null;
  if(!$me || $me['role']!=='admin') fail('Apenas administradores.');
  $res=$mysqli->query("SELECT chave, valor, atualizado_em FROM portal_dados");
  $items=array();
  while($row=$res->fetch_assoc()){ $items[]=$row; }
  audit($mysqli, 'Baixou backup', count($items).' conjuntos de dados');
  out(array('ok'=>true, 'gerado_em'=>date('Y-m-d H:i:s'), 'items'=>$items));
}

/* ---------- ENVIAR AO N8N (webhook configurado em n8n_webhook_url) ---------- */
if($action === 'n8n_send'){
  $st=$mysqli->prepare("SELECT valor FROM portal_dados WHERE chave='n8n_webhook_url' LIMIT 1");
  $st->execute(); $r=$st->get_result()->fetch_assoc();
  $url = $r ? trim($r['valor']) : '';
  if($url==='' || !preg_match('~^https?://~i',$url)){ out(array('ok'=>true,'enviado'=>false)); }
  $payload = isset($body['payload']) ? $body['payload'] : array();
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
  $http = 0;
  if(function_exists('curl_init')){
    $chx = curl_init($url);
    curl_setopt_array($chx, array(
      CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$json,
      CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),
      CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>true
    ));
    $rbody = curl_exec($chx);
    $http = intval(curl_getinfo($chx, CURLINFO_HTTP_CODE));
    $cerr = curl_error($chx);
    curl_close($chx);
    if(is_string($rbody) && $rbody!==''){ $cerr = trim(($cerr?$cerr.' | ':'').substr(strip_tags($rbody),0,300)); }
  } else {
    $ctx = stream_context_create(array('http'=>array('method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$json,'timeout'=>10)));
    $resp = @file_get_contents($url, false, $ctx);
    $http = ($resp!==false) ? 200 : 0;
  }
  if(!isset($cerr)) $cerr='';
  audit($mysqli, 'Disparo n8n', substr((isset($payload['tipo'])?$payload['tipo']:'evento').' HTTP '.$http.($cerr?' ERR: '.$cerr:''),0,200));
  out(array('ok'=>true,'enviado'=>($http>=200 && $http<300),'http'=>$http,'detalhe'=>$cerr));
}

fail('Acao desconhecida.');
