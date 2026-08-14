<?php
date_default_timezone_set('America/Sao_Paulo');
/* Polyfills mb_* (caso mbstring não esteja habilitado na hospedagem) */
if(!function_exists('mb_strlen')){ function mb_strlen($s,$e=null){ return preg_match_all('//u',$s,$m); } }
if(!function_exists('mb_substr')){ function mb_substr($s,$start,$len=null,$e=null){
  $ar=preg_split('//u',$s,-1,PREG_SPLIT_NO_EMPTY);
  $ar=array_slice($ar,$start,$len);
  return implode('',$ar);
} }
if(!function_exists('mb_strtolower')){ function mb_strtolower($s,$e=null){ return strtolower($s); } }
/* ============================================================
   CHAT INTERNO v2 — Redentor Hub
   Compatível com hospedagem SEM mysqlnd (usa query + real_escape_string).
   ============================================================ */
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);
register_shutdown_function(function(){
  $e = error_get_last();
  if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))){
    http_response_code(200);
    echo json_encode(array('ok'=>false,'error'=>'PHP: '.$e['message'].' (linha '.$e['line'].')'), JSON_UNESCAPED_UNICODE);
  }
});

try {

require __DIR__.'/db_config.php';
function out($d){ echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function fail($m){ out(array('ok'=>false,'error'=>$m)); }

$body = json_decode(file_get_contents('php://input'), true);
if(!$body) $body = array();
$action = isset($_GET['action']) ? $_GET['action'] : '';

$db = portal_db();
if(!$db) fail('Sem conexão com o banco.');
$db->set_charset('utf8mb4');
function esc($s){ global $db; return $db->real_escape_string($s); }

if($action === 'ping'){
  out(array('ok'=>true,'msg'=>'chat.php respondendo','php'=>PHP_VERSION,'recebido'=>$body));
}

function tokenOk($db,$u,$t){
  if(!$u||!$t)return false;
  $ue=$db->real_escape_string($u);
  $r=$db->query("SELECT token FROM portal_tokens WHERE username='$ue' LIMIT 1");
  $row=$r?$r->fetch_assoc():null;
  return $row && function_exists('hash_equals') ? hash_equals($row['token'],$t) : ($row && $row['token']===$t);
}

/* Autenticação por token (todas as ações) */
$__u = isset($body['username']) ? trim($body['username']) : '';
$__t = isset($body['token']) ? trim($body['token']) : '';
if(!tokenOk($db, $__u, $__t)) fail('Sessão inválida ou expirada. Faça logout e login novamente.');

/* Tabelas */
$db->query("CREATE TABLE IF NOT EXISTS portal_heartbeat (
  username VARCHAR(60) PRIMARY KEY, nome VARCHAR(120) DEFAULT '',
  last_seen DATETIME DEFAULT CURRENT_TIMESTAMP, typing_to VARCHAR(60) DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$db->query("CREATE TABLE IF NOT EXISTS portal_mensagens (
  id BIGINT AUTO_INCREMENT PRIMARY KEY, de_user VARCHAR(60) NOT NULL, de_nome VARCHAR(120) DEFAULT '',
  para_user VARCHAR(60) NOT NULL, tipo VARCHAR(20) DEFAULT 'texto', mensagem MEDIUMTEXT NOT NULL,
  lida TINYINT DEFAULT 0, criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_para (para_user, lida), INDEX idx_conv (de_user, para_user, criado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Colunas extras (compat MySQL antigo) */
function colExists($db,$tab,$col){ $r=$db->query("SHOW COLUMNS FROM `$tab` LIKE '$col'"); return $r && $r->num_rows>0; }
if(!colExists($db,'portal_mensagens','tipo')) @$db->query("ALTER TABLE portal_mensagens ADD COLUMN tipo VARCHAR(20) DEFAULT 'texto'");
if(!colExists($db,'portal_heartbeat','typing_to')) @$db->query("ALTER TABLE portal_heartbeat ADD COLUMN typing_to VARCHAR(60) DEFAULT ''");
$temTipo = colExists($db,'portal_mensagens','tipo');
$temTyping = colExists($db,'portal_heartbeat','typing_to');

/* ── HEARTBEAT ── */
if($action === 'heartbeat'){
  $u = isset($body['username']) ? esc(trim($body['username'])) : '';
  $n = isset($body['nome']) ? esc(trim($body['nome'])) : '';
  $tt = ($temTyping && isset($body['typing_to'])) ? esc(trim($body['typing_to'])) : '';
  if(!$u) fail('Username obrigatório.');
  if($temTyping) $db->query("REPLACE INTO portal_heartbeat (username, nome, last_seen, typing_to) VALUES ('$u','$n',NOW(),'$tt')");
  else $db->query("REPLACE INTO portal_heartbeat (username, nome, last_seen) VALUES ('$u','$n',NOW())");
  out(array('ok'=>true));
}

/* ── ONLINE ── */
if($action === 'online'){
  $tcol = $temTyping ? 'typing_to' : "'' as typing_to";
  $res = $db->query("SELECT username, nome, last_seen, $tcol FROM portal_heartbeat WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 2 MINUTE) ORDER BY nome");
  $list = array();
  if($res) while($r = $res->fetch_assoc()){ $list[] = $r; }
  out(array('ok'=>true,'online'=>$list));
}

/* ── Autenticação leve ── */
$u = isset($body['username']) ? esc(trim($body['username'])) : '';
if(!$u) fail('Usuário não informado. Faça login novamente.');
$res = $db->query("SELECT username, name FROM portal_usuarios WHERE username='$u' LIMIT 1");
$me = $res ? $res->fetch_assoc() : null;
if(!$me) fail('Usuário "'.$u.'" não encontrado. Faça logout e login novamente.');
$meU = esc($me['username']);
$meN = esc($me['name']);

/* ── SEND ── */
if($action === 'send'){
  $para = isset($body['para']) ? esc(trim($body['para'])) : '';
  $msgRaw = isset($body['mensagem']) ? $body['mensagem'] : '';
  $tipo = ($temTipo && isset($body['tipo'])) ? esc(trim($body['tipo'])) : 'texto';
  if(!$para || $msgRaw==='') fail('Destinatário e mensagem obrigatórios.');
  if($tipo==='texto' && mb_strlen($msgRaw)>2000) $msgRaw = mb_substr($msgRaw,0,2000);
  if($tipo==='arquivo' && strlen($msgRaw)>5*1024*1024) fail('Arquivo muito grande (máx 5MB).');
  if($tipo==='audio' && strlen($msgRaw)>3*1024*1024) fail('Áudio muito grande (máx 3MB).');
  $msg = esc($msgRaw);
  if($temTipo) $ok = $db->query("INSERT INTO portal_mensagens (de_user,de_nome,para_user,tipo,mensagem) VALUES ('$meU','$meN','$para','$tipo','$msg')");
  else $ok = $db->query("INSERT INTO portal_mensagens (de_user,de_nome,para_user,mensagem) VALUES ('$meU','$meN','$para','$msg')");
  if(!$ok) fail('Erro ao gravar: '.$db->error);
  out(array('ok'=>true,'id'=>intval($db->insert_id)));
}

/* ── INBOX ── */
if($action === 'inbox'){
  $res = $db->query("SELECT COUNT(*) as total FROM portal_mensagens WHERE para_user='$meU' AND lida=0");
  $unread = $res ? intval($res->fetch_assoc()['total']) : 0;
  $res = $db->query("
    SELECT parceiro, MAX(criado_em) as ultima,
      SUM(IF(para_user='$meU' AND lida=0,1,0)) as nao_lidas
    FROM (SELECT IF(de_user='$meU',para_user,de_user) as parceiro, para_user, lida, criado_em
          FROM portal_mensagens WHERE de_user='$meU' OR para_user='$meU') t
    GROUP BY parceiro ORDER BY ultima DESC LIMIT 30");
  $convs = array();
  if($res) while($r = $res->fetch_assoc()){
    $pu = esc($r['parceiro']);
    $rn = $db->query("SELECT name FROM portal_usuarios WHERE username='$pu' LIMIT 1");
    $nn = $rn ? $rn->fetch_assoc() : null;
    $r['parceiro_nome'] = $nn ? $nn['name'] : $r['parceiro'];
    $r['hora'] = date('H:i', strtotime($r['ultima']));
    $tsel = $temTipo ? 'tipo, mensagem' : "'texto' as tipo, mensagem";
    $lm = $db->query("SELECT $tsel FROM portal_mensagens WHERE (de_user='$meU' AND para_user='$pu') OR (de_user='$pu' AND para_user='$meU') ORDER BY criado_em DESC LIMIT 1");
    $lmr = $lm ? $lm->fetch_assoc() : null;
    if($lmr){
      if($lmr['tipo']==='audio') $r['ultimo_msg']='🎤 Áudio';
      else if($lmr['tipo']==='arquivo') $r['ultimo_msg']='📎 Arquivo';
      else $r['ultimo_msg']=mb_substr($lmr['mensagem'],0,50);
    } else $r['ultimo_msg']='';
    $convs[] = $r;
  }
  out(array('ok'=>true,'unread'=>$unread,'conversas'=>$convs));
}

/* ── HISTORY (incremental via desde_id) ── */
if($action === 'history'){
  $com = isset($body['com']) ? esc(trim($body['com'])) : '';
  if(!$com) fail('Informe com quem.');
  $lim = min(80, max(1, isset($body['limit'])?intval($body['limit']):60));
  $desde = isset($body['desde_id']) ? intval($body['desde_id']) : 0;
  $tsel = $temTipo ? 'tipo' : "'texto' as tipo";
  if($desde > 0){
    /* só o que é novo — reduz tráfego do polling e preserva áudio/rolagem no cliente */
    $res = $db->query("SELECT id, de_user, de_nome, para_user, $tsel, mensagem, lida, criado_em
      FROM portal_mensagens WHERE ((de_user='$meU' AND para_user='$com') OR (de_user='$com' AND para_user='$meU')) AND id > $desde
      ORDER BY id ASC LIMIT $lim");
    $msgs = array();
    if($res) while($r = $res->fetch_assoc()){
      $r['hora'] = date('H:i', strtotime($r['criado_em']));
      $r['data'] = date('d/m/Y', strtotime($r['criado_em']));
      $msgs[] = $r;
    }
  } else {
    $res = $db->query("SELECT id, de_user, de_nome, para_user, $tsel, mensagem, lida, criado_em
      FROM portal_mensagens WHERE (de_user='$meU' AND para_user='$com') OR (de_user='$com' AND para_user='$meU')
      ORDER BY criado_em DESC LIMIT $lim");
    $msgs = array();
    if($res) while($r = $res->fetch_assoc()){
      $r['hora'] = date('H:i', strtotime($r['criado_em']));
      $r['data'] = date('d/m/Y', strtotime($r['criado_em']));
      $msgs[] = $r;
    }
    $msgs = array_reverse($msgs);
  }
  /* typing + presença do parceiro (online / visto por último) */
  $typing = false; $parceiro = array('online'=>false,'ultimo'=>'');
  $tcolH = $temTyping ? 'typing_to' : "'' as typing_to";
  $tr = $db->query("SELECT last_seen, $tcolH FROM portal_heartbeat WHERE username='$com' LIMIT 1");
  $trr = $tr ? $tr->fetch_assoc() : null;
  if($trr){
    $on = (strtotime($trr['last_seen']) >= time()-120);
    $parceiro['online'] = $on;
    $parceiro['ultimo'] = $on ? '' : ((date('d/m/Y',strtotime($trr['last_seen']))===date('d/m/Y'))
      ? ('hoje às '.date('H:i',strtotime($trr['last_seen'])))
      : date('d/m \\à\\s H:i', strtotime($trr['last_seen'])));
    if($temTyping && strtotime($trr['last_seen']) >= time()-15 && $trr['typing_to'] === $me['username']) $typing = true;
  }
  /* até qual id minhas mensagens já foram lidas (para os ✓✓) */
  $la = $db->query("SELECT MAX(id) as m FROM portal_mensagens WHERE de_user='$meU' AND para_user='$com' AND lida=1");
  $lar = $la ? $la->fetch_assoc() : null;
  $lidas_ate = $lar && $lar['m'] ? intval($lar['m']) : 0;
  /* mensagens apagadas recentes (para o outro lado refletir) */
  $apagadas = array();
  if($temTipo){
    $ap = $db->query("SELECT id FROM portal_mensagens WHERE ((de_user='$meU' AND para_user='$com') OR (de_user='$com' AND para_user='$meU')) AND tipo='apagada' ORDER BY id DESC LIMIT 80");
    if($ap) while($r = $ap->fetch_assoc()){ $apagadas[] = intval($r['id']); }
  }
  out(array('ok'=>true,'mensagens'=>$msgs,'typing'=>$typing,'parceiro'=>$parceiro,'lidas_ate'=>$lidas_ate,'apagadas'=>$apagadas));
}

/* ── APAGAR mensagem (soft delete, só as próprias) ── */
if($action === 'apagar'){
  $id = isset($body['id']) ? intval($body['id']) : 0;
  if(!$id) fail('Informe a mensagem.');
  if(!$temTipo) fail('Recurso indisponível nesta hospedagem.');
  $db->query("UPDATE portal_mensagens SET tipo='apagada', mensagem='' WHERE id=$id AND de_user='$meU'");
  if($db->affected_rows < 1) fail('Só é possível apagar as suas próprias mensagens.');
  out(array('ok'=>true));
}

/* ── BUSCAR nas minhas conversas ── */
if($action === 'buscar'){
  $termo = isset($body['termo']) ? trim($body['termo']) : '';
  if(mb_strlen($termo) < 2) fail('Digite ao menos 2 letras.');
  $te = esc($termo);
  $tsel = $temTipo ? 'tipo' : "'texto' as tipo";
  $res = $db->query("SELECT id, de_user, de_nome, para_user, $tsel, mensagem, criado_em
    FROM portal_mensagens
    WHERE (de_user='$meU' OR para_user='$meU') AND ".($temTipo?"tipo='texto'":'1=1')." AND mensagem LIKE '%$te%'
    ORDER BY id DESC LIMIT 20");
  $itens = array();
  if($res) while($r = $res->fetch_assoc()){
    $r['parceiro'] = ($r['de_user']===$me['username']) ? $r['para_user'] : $r['de_user'];
    $pu = esc($r['parceiro']);
    $rn = $db->query("SELECT name FROM portal_usuarios WHERE username='$pu' LIMIT 1");
    $nn = $rn ? $rn->fetch_assoc() : null;
    $r['parceiro_nome'] = $nn ? $nn['name'] : $r['parceiro'];
    $r['quando'] = date('d/m H:i', strtotime($r['criado_em']));
    $r['trecho'] = mb_substr($r['mensagem'], 0, 70);
    unset($r['mensagem']);
    $itens[] = $r;
  }
  out(array('ok'=>true,'resultados'=>$itens));
}

/* ── READ ── */
if($action === 'read'){
  $de = isset($body['de']) ? esc(trim($body['de'])) : '';
  if(!$de) fail('Informe de quem.');
  $db->query("UPDATE portal_mensagens SET lida=1 WHERE de_user='$de' AND para_user='$meU' AND lida=0");
  out(array('ok'=>true,'marcadas'=>$db->affected_rows));
}

fail('Ação desconhecida.');

} catch(Throwable $e){
  http_response_code(200);
  echo json_encode(array('ok'=>false,'error'=>$e->getMessage().' (linha '.$e->getLine().')'), JSON_UNESCAPED_UNICODE);
  exit;
}
