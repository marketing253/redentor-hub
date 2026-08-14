<?php
/* ============================================================
   UPLOAD DE SISTEMAS (HTML) — Redentor Hub
   POST JSON: {username, nome, conteudo(base64)}
   Só admin. Salva em apps/<nome>.html
   ============================================================ */
date_default_timezone_set('America/Sao_Paulo');
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
if(!$body) fail('Corpo vazio.');

$db = portal_db();
if(!$db) fail('Sem conexão com o banco.');
$db->set_charset('utf8mb4');

/* Só admin */
$u = isset($body['username']) ? $db->real_escape_string(trim($body['username'])) : '';
if(!$u) fail('Usuário não informado.');
$res = $db->query("SELECT role FROM portal_usuarios WHERE username='$u' LIMIT 1");
$me = $res ? $res->fetch_assoc() : null;
if(!$me) fail('Usuário não encontrado.');
if($me['role'] !== 'admin') fail('Apenas administradores podem enviar sistemas.');
function tokenOk($db,$u,$t){
  if(!$u||!$t)return false;
  $ue=$db->real_escape_string($u);
  $r=$db->query("SELECT token FROM portal_tokens WHERE username='$ue' LIMIT 1");
  $row=$r?$r->fetch_assoc():null;
  return $row && function_exists('hash_equals') ? hash_equals($row['token'],$t) : ($row && $row['token']===$t);
}
if(!tokenOk($db, $u, isset($body['token'])?trim($body['token']):''))
  fail('Sessão inválida. Faça logout e login novamente.');

/* Nome do arquivo */
$nome = isset($body['nome']) ? strtolower(trim($body['nome'])) : '';
$nome = preg_replace('/\.html$/','',$nome);
if(!preg_match('/^[a-z0-9_\-]{2,40}$/', $nome)) fail('Nome inválido: use só letras minúsculas, números, hífen e sublinhado (2-40 caracteres).');

/* Conteúdo */
$b64 = isset($body['conteudo']) ? $body['conteudo'] : '';
if(!$b64) fail('Arquivo vazio.');
$conteudo = base64_decode($b64, true);
if($conteudo === false) fail('Arquivo corrompido (base64 inválido).');
if(strlen($conteudo) > 12*1024*1024) fail('Arquivo muito grande (máx 12MB).');
if(stripos($conteudo, '<html') === false) fail('O arquivo não parece ser um HTML válido.');

/* Salvar */
$dir = __DIR__.'/apps';
if(!is_dir($dir)) mkdir($dir, 0755, true);
$destino = $dir.'/'.$nome.'.html';
if(file_put_contents($destino, $conteudo) === false) fail('Não foi possível gravar o arquivo (permissões da pasta apps).');

out(array('ok'=>true,'arquivo'=>$nome,'bytes'=>strlen($conteudo)));

} catch(Throwable $e){
  http_response_code(200);
  echo json_encode(array('ok'=>false,'error'=>$e->getMessage().' (linha '.$e->getLine().')'), JSON_UNESCAPED_UNICODE);
  exit;
}
