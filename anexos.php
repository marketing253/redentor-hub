<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   anexos.php  —  Armazenamento de PDFs no banco (Portal Redentor)

   COMO INSTALAR:
   1) Coloque este arquivo na MESMA PASTA do index.html do portal
      (na Hostinger, geralmente public_html/ ou a pasta do portal).
   2) Preencha abaixo os dados do seu MySQL (painel da Hostinger >
      Bancos de Dados MySQL). O nome do banco já vem preenchido.
   3) Pronto. A tabela "lnt_anexos" é criada automaticamente.
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
   // credenciais centralizadas

// (Opcional) Token simples para evitar uso externo. Deixe '' para desativar.
// Se preencher aqui, preencha igual no LNT (constante ANEXO_TOKEN) — veja o LEIAME.
$ANEXO_TOKEN = '';

// ------------------------------------------------------------
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function out($arr){ echo json_encode($arr); exit; }
function fail($msg){ out(array('ok'=>false, 'erro'=>$msg)); }

$action = isset($_GET['action']) ? $_GET['action'] : '';
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if(!is_array($body)) $body = array();

// Token opcional
if($ANEXO_TOKEN !== ''){
  $tok = isset($_GET['token']) ? $_GET['token'] : (isset($body['token']) ? $body['token'] : '');
  if($tok !== $ANEXO_TOKEN) fail('Acesso negado.');
}

// Conexão
$mysqli = portal_db();

// Cria a tabela se ainda não existir
$mysqli->query(
  "CREATE TABLE IF NOT EXISTS lnt_anexos (
     id INT AUTO_INCREMENT PRIMARY KEY,
     app VARCHAR(40) NOT NULL DEFAULT 'lnt',
     reg_key VARCHAR(160) NOT NULL,
     nome VARCHAR(255) NOT NULL,
     tipo VARCHAR(120) DEFAULT 'application/pdf',
     tamanho INT NOT NULL,
     conteudo LONGBLOB NOT NULL,
     criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_app_key (app, reg_key)
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
  $uid=intval($_SESSION['uid']); $un='';
  $r=$mysqli->query("SELECT username FROM portal_usuarios WHERE id=".$uid." LIMIT 1");
  if($r && ($x=$r->fetch_assoc())) $un=$x['username'];
  $ip=aud_ip(); $detalhe=substr($detalhe,0,250);
  $st=$mysqli->prepare("INSERT INTO portal_auditoria (uid,username,tipo,detalhe,ip) VALUES (?,?,?,?,?)");
  $st->bind_param('issss',$uid,$un,$tipo,$detalhe,$ip); $st->execute();
}

$app = isset($_GET['app']) ? $_GET['app'] : (isset($body['app']) ? $body['app'] : 'lnt');

/* ---------- Permissão: usuário precisa ter acesso ao card/app dono do anexo ----------
   Antes, qualquer usuário logado (de qualquer app do Hub) podia listar, baixar,
   substituir ou apagar o anexo de QUALQUER outro app só sabendo a reg_key.
   Agora exige role=admin OU perms_json[$app]=true, igual à permissão que o
   próprio Hub já usa para decidir quais cards aparecem pra cada usuário. */
function usuarioTemAcessoAoApp($mysqli, $uid, $app){
  $stmt = $mysqli->prepare("SELECT role, perms_json FROM portal_usuarios WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  if(!$row) return false;
  if($row['role'] === 'admin') return true;
  $pj = array();
  if(!empty($row['perms_json'])){ $t = json_decode($row['perms_json'], true); if(is_array($t)) $pj = $t; }
  return !empty($pj[$app]);
}
if(!usuarioTemAcessoAoApp($mysqli, intval($_SESSION['uid']), $app)) fail('Você não tem acesso a este sistema.');

/* ---------- LISTAR (metadados, sem o conteúdo) ---------- */
if($action === 'anexo_list'){
  $stmt = $mysqli->prepare(
    "SELECT reg_key,nome,tipo,tamanho,UNIX_TIMESTAMP(criado_em)*1000 AS ts
       FROM lnt_anexos WHERE app=? ORDER BY criado_em DESC");
  $stmt->bind_param('s', $app);
  $stmt->execute();
  $res = $stmt->get_result();
  $items = array();
  while($row = $res->fetch_assoc()){
    $items[] = array(
      'key' => $row['reg_key'],
      'nome' => $row['nome'],
      'tipo' => $row['tipo'],
      'tamanho' => intval($row['tamanho']),
      'ts' => intval($row['ts'])
    );
  }
  out(array('ok'=>true, 'items'=>$items));
}

/* ---------- OBTER (com o PDF em base64) ---------- */
if($action === 'anexo_get'){
  $key = isset($_GET['key']) ? $_GET['key'] : '';
  if($key === '') fail('Chave nao informada.');
  $stmt = $mysqli->prepare(
    "SELECT nome,tipo,tamanho,UNIX_TIMESTAMP(criado_em)*1000 AS ts,conteudo
       FROM lnt_anexos WHERE app=? AND reg_key=? LIMIT 1");
  $stmt->bind_param('ss', $app, $key);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res->fetch_assoc();
  if(!$row) out(array('ok'=>true, 'item'=>null));
  out(array('ok'=>true, 'item'=>array(
    'nome' => $row['nome'],
    'tipo' => $row['tipo'],
    'tamanho' => intval($row['tamanho']),
    'ts' => intval($row['ts']),
    'dados' => base64_encode($row['conteudo'])
  )));
}

/* ---------- SALVAR / SUBSTITUIR ---------- */
if($action === 'anexo_save'){
  $key  = isset($body['key'])  ? $body['key']  : '';
  $nome = isset($body['nome']) ? $body['nome'] : 'documento.pdf';
  $tipo = isset($body['tipo']) ? $body['tipo'] : 'application/pdf';
  $b64  = isset($body['dados'])? $body['dados']: '';
  if($key === '' || $b64 === '') fail('Dados incompletos.');

  $bin = base64_decode($b64, true);
  if($bin === false) fail('Conteudo invalido.');
  $tam = strlen($bin);
  if($tam <= 0) fail('Arquivo vazio.');
  if($tam > 16 * 1024 * 1024) fail('Arquivo excede 16 MB.');

  // Nunca confia no "tipo" que o cliente informou — detecta pelo conteúdo real do arquivo.
  $permitidos = array('application/pdf','image/png','image/jpeg','image/gif','image/webp');
  $finfo = new finfo(FILEINFO_MIME_TYPE);
  $tipoReal = $finfo->buffer($bin);
  if(!in_array($tipoReal, $permitidos, true)) fail('Tipo de arquivo não permitido: '.$tipoReal);
  $tipo = $tipoReal;

  $stmt = $mysqli->prepare(
    "INSERT INTO lnt_anexos (app,reg_key,nome,tipo,tamanho,conteudo)
       VALUES (?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE
       nome=VALUES(nome), tipo=VALUES(tipo), tamanho=VALUES(tamanho),
       conteudo=VALUES(conteudo), criado_em=CURRENT_TIMESTAMP");
  $nulo = NULL;
  $stmt->bind_param('ssssib', $app, $key, $nome, $tipo, $tam, $nulo);
  $stmt->send_long_data(5, $bin);
  if(!$stmt->execute()) fail('Erro ao salvar: '.$stmt->error);
  audit($mysqli, 'Anexou PDF', $nome.' ('.$key.')');
  out(array('ok'=>true));
}

/* ---------- REMOVER ---------- */
if($action === 'anexo_del'){
  $key = isset($body['key']) ? $body['key'] : '';
  if($key === '') fail('Chave nao informada.');
  $stmt = $mysqli->prepare("DELETE FROM lnt_anexos WHERE app=? AND reg_key=?");
  $stmt->bind_param('ss', $app, $key);
  if(!$stmt->execute()) fail('Erro ao remover.');
  audit($mysqli, 'Removeu PDF', $key);
  out(array('ok'=>true));
}

fail('Acao desconhecida.');
