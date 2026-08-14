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
   LEMBRETE DIÁRIO DA AGENDA — Redentor Hub
   Agende no cron da Hostinger (ex.: 07:00, seg-sáb):
   curl -s "https://SEU-SITE/lembrete.php?k=SUA_CHAVE" > /dev/null
   (a chave real fica em cron_secrets.php, fora do controle de versão)
   Envia via n8n (webhook configurado no Hub) os compromissos
   de HOJE para cada pessoa cadastrada em Contatos do WhatsApp.
   ============================================================ */
$__cronsec = @include __DIR__.'/cron_secrets.php';
define('CHAVE_CRON', $__cronsec['lembrete_backup'] ?? '');

header('Content-Type: text/plain; charset=utf-8');
if(CHAVE_CRON === '' || !isset($_GET['k']) || !hash_equals(CHAVE_CRON, (string)$_GET['k'])){ http_response_code(403); die('Acesso negado.'); }

require __DIR__.'/db_config.php';
$mysqli = portal_db();
if(!$mysqli) die('ERRO: sem conexao com o banco.');
$mysqli->set_charset('utf8mb4');

function pega($mysqli, $chave){
  $ce = $mysqli->real_escape_string($chave);
  $res = $mysqli->query("SELECT valor FROM portal_dados WHERE chave='$ce' LIMIT 1");
  $r = $res ? $res->fetch_assoc() : null;
  return $r ? $r['valor'] : '';
}
function norm($s){
  $s = mb_strtolower(trim($s), 'UTF-8');
  $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
  return $t !== false ? preg_replace('/[^a-z0-9 ]/','',$t) : $s;
}

$webhook = trim(pega($mysqli, 'n8n_webhook_url'));
if($webhook === '' || !preg_match('~^https?://~i', $webhook)) die('ERRO: webhook n8n nao configurado no Hub.');

$contatos = json_decode(pega($mysqli, 'whats_contatos_v1'), true);
if(!is_array($contatos) || !count($contatos)) die('Nenhum contato WhatsApp cadastrado.');

$hoje = date('Y-m-d');
$hojeBr = date('d/m/Y');
$enviados = 0;

$res = $mysqli->query("SELECT username, name FROM portal_usuarios");
while($u = $res->fetch_assoc()){
  $ag = json_decode(pega($mysqli, 'agenda_'.$u['username']), true);
  if(!is_array($ag)) continue;
  $doDia = array();
  foreach($ag as $e){ if(is_array($e) && isset($e['data']) && $e['data'] === $hoje) $doDia[] = $e; }
  if(!count($doDia)) continue;                       /* sem compromissos: nao envia */
  usort($doDia, function($a,$b){ return strcmp(isset($a['inicio'])?$a['inicio']:'', isset($b['inicio'])?$b['inicio']:''); });

  /* acha o numero do WhatsApp pelo nome */
  $num = ''; $nu = norm($u['name']);
  foreach($contatos as $c){ if(isset($c['nome']) && norm($c['nome']) === $nu){ $num = $c['numero']; break; } }
  if($num === '') { echo "sem contato: ".$u['name']."\n"; continue; }

  $linhas = array();
  foreach($doDia as $e){
    $h = (isset($e['inicio'])?$e['inicio']:'') . (!empty($e['fim']) ? '-'.$e['fim'] : '');
    $l = !empty($e['local']) ? ' (📍 '.$e['local'].')' : '';
    $linhas[] = '• '.$h.' '.(isset($e['titulo'])?$e['titulo']:'').$l;
  }
  $msg = "🌅 Bom dia, ".$u['name']."!\n📆 Seus compromissos de hoje (".$hojeBr."):\n".implode("\n", $linhas)."\n\n— Redentor Hub";

  $payload = json_encode(array('tipo'=>'lembrete_diario','nome'=>$u['name'],'whatsapp'=>$num,'mensagem'=>$msg), JSON_UNESCAPED_UNICODE);
  $ch = curl_init($webhook);
  curl_setopt_array($ch, array(CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload,
    CURLOPT_HTTPHEADER=>array('Content-Type: application/json'),
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>true));
  curl_exec($ch);
  $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
  curl_close($ch);
  echo ($http>=200 && $http<300 ? "OK " : "FALHA($http) ").$u['name']." -> ".$num." (".count($doDia)." compromissos)\n";
  if($http>=200 && $http<300) $enviados++;
}
echo "Enviados: $enviados\n";
