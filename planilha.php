<?php
/* ============================================================
   FONTES DE DADOS (planilhas na nuvem) — Redentor Hub
   GET planilha.php?sistema=NOME            → CSV da planilha cadastrada
   GET planilha.php?sistema=NOME&formato=json → JSON (array de objetos)
   GET planilha.php?sistema=NOME&refresh=1  → ignora cache (teste)
   As URLs são cadastradas em Configurações → Fontes de Dados
   e ficam guardadas no banco (chave fontes_dados_v1).
   Cache no servidor: 5 minutos.
   ============================================================ */
date_default_timezone_set('America/Sao_Paulo');
error_reporting(E_ALL);
ini_set('display_errors', 0);
register_shutdown_function(function(){
  $e = error_get_last();
  if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))){
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('ok'=>false,'error'=>'PHP: '.$e['message']), JSON_UNESCAPED_UNICODE);
  }
});
try {

require __DIR__.'/db_config.php';
function jfail($m){ header('Content-Type: application/json; charset=utf-8'); echo json_encode(array('ok'=>false,'error'=>$m), JSON_UNESCAPED_UNICODE); exit; }

$sistema = isset($_GET['sistema']) ? preg_replace('/[^a-z0-9_\-]/','', strtolower($_GET['sistema'])) : '';
if(!$sistema) jfail('Informe ?sistema=nome');

$db = portal_db();
if(!$db) jfail('Sem conexão com o banco.');
$db->set_charset('utf8mb4');

function tokenOk($db,$u,$t){
  if(!$u||!$t)return false;
  $ue=$db->real_escape_string($u);
  $r=$db->query("SELECT token FROM portal_tokens WHERE username='$ue' LIMIT 1");
  $row=$r?$r->fetch_assoc():null;
  return $row && function_exists('hash_equals') ? hash_equals($row['token'],$t) : ($row && $row['token']===$t);
}
if(!tokenOk($db, isset($_GET['u'])?$_GET['u']:'', isset($_GET['t'])?$_GET['t']:''))
  jfail('Acesso restrito: faça login no Hub (sessão inválida).');

/* Buscar a URL cadastrada */
$res = $db->query("SELECT valor FROM portal_dados WHERE chave='fontes_dados_v1' LIMIT 1");
$row = $res ? $res->fetch_assoc() : null;
$fontes = $row ? json_decode($row['valor'], true) : array();
if(!is_array($fontes)) $fontes = array();
$fonte = null;
foreach($fontes as $f){ if(isset($f['sistema']) && $f['sistema']===$sistema){ $fonte=$f; break; } }
if(!$fonte || empty($fonte['url'])) jfail('Nenhuma planilha cadastrada para "'.$sistema.'". Cadastre em Configurações → Fontes de Dados.');
$url = trim($fonte['url']);
if(!preg_match('~^https://~i', $url)) jfail('URL da fonte precisa ser https.');

/* Cache de 5 minutos */
$dir = __DIR__.'/cache_planilhas';
if(!is_dir($dir)){ mkdir($dir, 0755, true); @file_put_contents($dir.'/.htaccess', "Require all denied\n"); }
$cacheFile = $dir.'/'.md5($sistema).'.csv';
$refresh = isset($_GET['refresh']);
$csv = null;
if(!$refresh && file_exists($cacheFile) && (time()-filemtime($cacheFile)) < 300){
  $csv = file_get_contents($cacheFile);
}
if($csv === null){
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>5,
    CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_USERAGENT=>'RedentorHub/1.0'
  ));
  $csv = curl_exec($ch);
  $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
  $err = curl_error($ch);
  curl_close($ch);
  if($csv === false || $http < 200 || $http >= 300){
    /* se falhou mas há cache velho, serve o velho */
    if(file_exists($cacheFile)){ $csv = file_get_contents($cacheFile); }
    else jfail('Falha ao baixar a planilha (HTTP '.$http.($err?' — '.$err:'').'). Confirme que ela está PUBLICADA na web como CSV.');
  } else {
    file_put_contents($cacheFile, $csv);
  }
}

/* Saída */
$formato = isset($_GET['formato']) ? $_GET['formato'] : 'csv';
if($formato === 'json'){
  header('Content-Type: application/json; charset=utf-8');
  /* parse CSV → array de objetos usando a 1ª linha como cabeçalho */
  $linhas = preg_split("/\r\n|\n|\r/", trim($csv));
  if(!count($linhas)){ echo '[]'; exit; }
  $sep = (substr_count($linhas[0], ';') > substr_count($linhas[0], ',')) ? ';' : ',';
  $head = str_getcsv($linhas[0], $sep);
  $out = array();
  for($i=1; $i<count($linhas); $i++){
    if(trim($linhas[$i])==='') continue;
    $vals = str_getcsv($linhas[$i], $sep);
    $obj = array();
    foreach($head as $k=>$h){ $obj[trim($h)] = isset($vals[$k]) ? $vals[$k] : ''; }
    $out[] = $obj;
  }
  echo json_encode(array('ok'=>true,'sistema'=>$sistema,'linhas'=>count($out),'dados'=>$out), JSON_UNESCAPED_UNICODE);
} else {
  header('Content-Type: text/csv; charset=utf-8');
  header('Cache-Control: no-store');
  echo $csv;
}

} catch(Throwable $e){
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array('ok'=>false,'error'=>$e->getMessage().' (linha '.$e->getLine().')'), JSON_UNESCAPED_UNICODE);
  exit;
}
