<?php
/* ============================================================
   GOOGLE DRIVE (pasta pública) — Redentor Hub
   ?action=list&pasta=URL_ou_ID  → lista arquivos da pasta
   ?action=baixar&id=FILE_ID     → baixa o arquivo (xlsx)
   A pasta precisa estar compartilhada: "Qualquer pessoa com o link — Leitor".
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
function jout($d){ header('Content-Type: application/json; charset=utf-8'); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }
function jfail($m){ jout(array('ok'=>false,'error'=>$m)); }
function baixarUrl($url, $timeout=25){
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true, CURLOPT_MAXREDIRS=>8,
    CURLOPT_TIMEOUT=>$timeout, CURLOPT_SSL_VERIFYPEER=>true,
    CURLOPT_USERAGENT=>'Mozilla/5.0 (RedentorHub)'
  ));
  $r = curl_exec($ch);
  $http = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
  curl_close($ch);
  return array($r, $http);
}
try {

$action = isset($_GET['action']) ? $_GET['action'] : '';

require __DIR__.'/db_config.php';
$db = portal_db();
if(!$db) jfail('Sem conexão com o banco.');
function tokenOk($db,$u,$t){
  if(!$u||!$t)return false;
  $ue=$db->real_escape_string($u);
  $r=$db->query("SELECT token FROM portal_tokens WHERE username='$ue' LIMIT 1");
  $row=$r?$r->fetch_assoc():null;
  return $row && function_exists('hash_equals') ? hash_equals($row['token'],$t) : ($row && $row['token']===$t);
}
if(!tokenOk($db, isset($_GET['u'])?$_GET['u']:'', isset($_GET['t'])?$_GET['t']:''))
  jfail('Acesso restrito: faça login no Hub (sessão inválida).');

/* ── LISTAR ARQUIVOS DA PASTA ── */
if($action === 'list'){
  $pasta = isset($_GET['pasta']) ? trim($_GET['pasta']) : '';
  if(!$pasta) jfail('Informe o link da pasta do Drive.');
  /* Link de PLANILHA GOOGLE? (docs.google.com/spreadsheets/d/ID) */
  if(preg_match('~/spreadsheets/d/([A-Za-z0-9_\-]{10,})~', $pasta, $sm)){
    $sid = $sm[1];
    list($pg, $ph) = baixarUrl('https://docs.google.com/spreadsheets/d/'.$sid.'/edit');
    $nome = 'Planilha Google';
    if($pg !== false && preg_match('~<title>(.*?)(?: - Google (?:Drive|Docs|Sheets|Planilhas|Tabellen))?</title>~su', $pg, $tm)){
      $t = html_entity_decode(trim($tm[1]), ENT_QUOTES, 'UTF-8');
      if($t) $nome = $t;
    }
    jout(array('ok'=>true,'pasta'=>'','total'=>1,'aviso'=>'Link de uma Planilha Google detectado. Para ver todos os arquivos de uma vez, cole o link da PASTA.','arquivos'=>array(array('id'=>'sheet:'.$sid,'nome'=>$nome,'tipo'=>'sheet'))));
  }
  /* Link de ARQUIVO? (file/d/ID) — lista esse arquivo único */
  if(preg_match('~/file/d/([A-Za-z0-9_\-]{10,})~', $pasta, $fm)){
    $fid = $fm[1];
    list($pg, $ph) = baixarUrl('https://drive.google.com/file/d/'.$fid.'/view');
    $nome = 'arquivo.xlsx';
    if($pg !== false && preg_match('~<title>(.*?)(?: - Google (?:Drive|Docs|Sheets|Planilhas))?</title>~su', $pg, $tm)){
      $t = html_entity_decode(trim($tm[1]), ENT_QUOTES, 'UTF-8');
      if($t && stripos($t,'Google Drive')===false) $nome = $t;
    }
    jout(array('ok'=>true,'pasta'=>'','total'=>1,'aviso'=>'Você colou o link de um ARQUIVO — para ver todos os arquivos de uma vez, cole o link da PASTA (botão direito na pasta → Compartilhar → Copiar link).','arquivos'=>array(array('id'=>$fid,'nome'=>$nome))));
  }
  $id = '';
  if(preg_match('~/folders/([A-Za-z0-9_\-]{10,})~', $pasta, $m)) $id = $m[1];
  elseif(preg_match('~[?&]id=([A-Za-z0-9_\-]{10,})~', $pasta, $m)) $id = $m[1];
  elseif(preg_match('~^[A-Za-z0-9_\-]{10,}$~', $pasta)) $id = $pasta;
  if(!$id) jfail('Link inválido. No Drive, clique com o botão direito na PASTA das planilhas → Compartilhar → Copiar link (ele contém /folders/...). Também aceito o link de um arquivo específico (/file/d/...).');

  list($html, $http) = baixarUrl('https://drive.google.com/embeddedfolderview?id='.$id.'#list');
  if($html === false || $http >= 400) jfail('Não foi possível acessar a pasta (HTTP '.$http.').');
  if(stripos($html, 'ServiceLogin') !== false || stripos($html, 'accounts.google.com') !== false)
    jfail('A pasta não está pública. No Drive: botão direito na pasta → Compartilhar → "Qualquer pessoa com o link" → Leitor.');

  $arquivos = array();
  $chunks = preg_split('~class="flip-entry"~', $html);
  for($ci=1; $ci<count($chunks); $ci++){
    $ck = $chunks[$ci];
    if(!preg_match('~id="entry-([A-Za-z0-9_\-]+)"~', $ck, $em)) continue;
    if(!preg_match('~flip-entry-title">([^<]+)<~', $ck, $tm)) continue;
    $fid = $em[1];
    $nome = html_entity_decode(trim($tm[1]), ENT_QUOTES, 'UTF-8');
    $href = preg_match('~href="([^"]+)"~', $ck, $hm) ? $hm[1] : '';
    if(strpos($href, '/folders/') !== false) continue; /* subpasta: ignora */
    $tipo = (strpos($href, '/spreadsheets/') !== false) ? 'sheet' : 'file';
    $arquivos[] = array('id'=>($tipo==='sheet'?'sheet:':'').$fid, 'nome'=>$nome, 'tipo'=>$tipo);
  }
  jout(array('ok'=>true,'pasta'=>$id,'total'=>count($arquivos),'arquivos'=>$arquivos));
}

/* ── BAIXAR ARQUIVO ── */
if($action === 'baixar'){
  $id  = isset($_GET['id']) ? trim($_GET['id']) : '';
  $fmt = isset($_GET['fmt']) ? strtolower(trim($_GET['fmt'])) : ''; /* fmt=csv → texto (Painel de Combustível) */
  /* Planilha Google nativa: exporta como xlsx (padrão) ou csv (fmt=csv, 1ª aba) */
  if(strpos($id, 'sheet:') === 0){
    $sid = substr($id, 6);
    if(!preg_match('~^[A-Za-z0-9_\-]{10,}$~', $sid)) jfail('ID de planilha inválido.');
    if($fmt === 'csv'){
      list($txt, $http) = baixarUrl('https://docs.google.com/spreadsheets/d/'.$sid.'/export?format=csv', 60);
      if($txt === false || $http >= 400 || stripos(substr(ltrim($txt),0,60), '<html') !== false || stripos($txt,'ServiceLogin') !== false)
        jfail('Não foi possível exportar a Planilha Google como CSV. Confirme que ela está compartilhada como "Qualquer pessoa com o link — Leitor".');
      if(strlen($txt) > 30*1024*1024) jfail('Planilha muito grande (máx 30MB).');
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Length: '.strlen($txt));
      header('Cache-Control: no-store');
      echo $txt; exit;
    }
    list($bin, $http) = baixarUrl('https://docs.google.com/spreadsheets/d/'.$sid.'/export?format=xlsx', 60);
    if($bin === false || $http >= 400 || substr($bin,0,2) !== 'PK')
      jfail('Não foi possível exportar a Planilha Google. Confirme que ela está compartilhada como "Qualquer pessoa com o link — Leitor".');
    if(strlen($bin) > 30*1024*1024) jfail('Planilha muito grande (máx 30MB).');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: '.strlen($bin));
    header('Cache-Control: no-store');
    echo $bin; exit;
  }
  if(!preg_match('~^[A-Za-z0-9_\-]{10,}$~', $id)) jfail('ID de arquivo inválido.');

  /* fmt=csv → aceita conteúdo texto (arquivo .csv guardado na pasta); padrão → exige xlsx ("PK") */
  $aceita = function($r,$http) use ($fmt){
    if($r === false || $http >= 400) return false;
    if($fmt === 'csv') return substr(ltrim($r),0,1) !== '<' && stripos($r,'ServiceLogin') === false && substr($r,0,2) !== 'PK';
    return substr($r,0,2) === 'PK'; /* xlsx = zip = "PK" */
  };
  /* endpoint moderno primeiro; cai pro clássico se vier HTML */
  $urls = array(
    'https://drive.usercontent.google.com/download?id='.$id.'&export=download&confirm=t',
    'https://drive.google.com/uc?export=download&confirm=t&id='.$id
  );
  $bin = false;
  foreach($urls as $u){
    list($r, $http) = baixarUrl($u, 60);
    if($aceita($r,$http)){ $bin = $r; break; }
    /* página de confirmação? tenta extrair o form do usercontent */
    if($r !== false && stripos($r,'download-form') !== false && preg_match('~action="([^"]+)"~',$r,$fa)){
      $extra = '';
      if(preg_match_all('~<input type="hidden" name="([^"]+)" value="([^"]*)"~', $r, $hh, PREG_SET_ORDER)){
        foreach($hh as $h) $extra .= '&'.urlencode($h[1]).'='.urlencode($h[2]);
      }
      list($r2, $h2) = baixarUrl(html_entity_decode($fa[1]).'?'.ltrim($extra,'&'), 60);
      if($aceita($r2,$h2)){ $bin = $r2; break; }
    }
  }
  if($bin === false) jfail($fmt==='csv' ? 'Não foi possível baixar o CSV. Confirme que o arquivo está na pasta pública.' : 'Não foi possível baixar. Confirme que o arquivo é .xlsx e está na pasta pública.');
  if(strlen($bin) > 30*1024*1024) jfail('Arquivo muito grande (máx 30MB).');

  if($fmt === 'csv'){
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Length: '.strlen($bin));
    header('Cache-Control: no-store');
    echo $bin; exit;
  }
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Length: '.strlen($bin));
  header('Cache-Control: no-store');
  echo $bin; exit;
}

jfail('Ação desconhecida. Use action=list ou action=baixar.');

} catch(Throwable $e){
  jfail($e->getMessage().' (linha '.$e->getLine().')');
}
