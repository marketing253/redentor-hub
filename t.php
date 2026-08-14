<?php
/* ============================================================
   t.php — o endereço mais curto possível sem mexer no servidor.

       seudominio.com.br/t.php/RF7K2M      ← preferido
       seudominio.com.br/t.php?c=RF7K2M    ← reserva

   A primeira forma usa PATH_INFO, que funciona na Hostinger e na
   maioria das hospedagens sem nenhuma configuração. Economiza os
   quatro caracteres do "?c=" — e num teclado de controle remoto,
   onde ? e = ficam em telas separadas do teclado virtual, esses
   quatro custam mais tempo que os seis do código.

   O tv.php continua existindo: TVs já instaladas não param de
   funcionar por causa desta mudança.
   ============================================================ */

require __DIR__.'/db_config.php';

/* PATH_INFO primeiro; se o servidor não fornecer, cai na query.

   Terceira tentativa, nova: ler o próprio REQUEST_URI. Em algumas
   configurações da Hostinger o PATH_INFO vem vazio mesmo com o pedido
   chegando ao PHP — e aí o código estava logo ali, no endereço, sem
   ninguém olhar. Custa duas linhas e salva o caso. */
$bruto = '';
if(!empty($_SERVER['PATH_INFO']))              $bruto = $_SERVER['PATH_INFO'];
elseif(!empty($_SERVER['ORIG_PATH_INFO']))     $bruto = $_SERVER['ORIG_PATH_INFO'];
elseif(isset($_GET['c']))                      $bruto = $_GET['c'];
elseif(!empty($_SERVER['REQUEST_URI'])
       && preg_match('#/t(?:\.php)?/([A-Za-z0-9]{4,12})#', $_SERVER['REQUEST_URI'], $m)){
  $bruto = $m[1];
}

$c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $bruto));

function tela($titulo, $texto){
  http_response_code(404);
  echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
     . '<meta name="viewport" content="width=device-width,initial-scale=1">'
     . '<title>TV Indoor</title><style>'
     . 'html,body{height:100%;margin:0;color:#F1EFE7;'
     . 'background:radial-gradient(1200px 620px at 78% -14%,#2A2F6C 0,transparent 62%),#0C0E1C;'
     . 'font-family:"Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,Arial,sans-serif;'
     . 'display:flex;align-items:center;justify-content:center;text-align:center}'
     . 'div{max-width:620px;padding:24px;border-top:2px solid #C08A28}'
     . 'h1{font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;'
     . 'font-weight:400;font-size:2rem;margin:14px 0}'
     . 'p{color:#9EA2C0;font-size:1.05rem;line-height:1.6;margin:0}'
     . 'b{color:#ECDBAE;font-weight:600}'
     . '</style></head><body><div><h1>'.$titulo.'</h1><p>'.$texto.'</p></div></body></html>';
  exit;
}

if(strlen($c) !== 6){
  tela('Código inválido', 'O código tem 6 caracteres, como <b>RF7K2M</b>. '
     . 'Confira a folha de instalação e digite de novo.');
}

$db = portal_db();
if(!$db) tela('Sem conexão', 'O servidor não conseguiu falar com o banco de dados.');
$db->set_charset('utf8mb4');

$e = $db->real_escape_string($c);
$r = $db->query("SELECT token FROM tvi_tvs WHERE codigo_curto='$e' AND ativo=1 LIMIT 1");

if(!$r || !$r->num_rows){
  tela('Código não encontrado', 'Nenhuma TV ativa usa o código <b>'.htmlspecialchars($c).'</b>. '
     . 'Ele pode ter sido trocado no painel, ou a TV foi removida.');
}

$tv = $r->fetch_assoc();

/* Caminho ABSOLUTO, não relativo. Com PATH_INFO o navegador enxerga
   /t.php/RF7K2M como se fosse uma pasta, e "player.php" resolveria para
   /t.php/player.php — que não existe. Monta a partir do SCRIPT_NAME para
   funcionar também se o portal estiver numa subpasta. */
/* Endereço ABSOLUTO, não relativo.
   O caminho relativo funcionava no navegador, mas no WebView do
   aplicativo ele às vezes resolvia errado e caía na página de erro da
   hospedagem — aquele 404 do skatista que aparecia por um instante antes
   da programação entrar. Montar com esquema e host resolve. */
$esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
$dir     = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$base    = $host !== '' ? $esquema.'://'.$host.$dir : $dir;

// 302: se o token for rotacionado no painel, o código curto continua
// valendo e passa a levar ao token novo.
header('Location: '.$base.'/player.php?t='.urlencode($tv['token']), true, 302);
exit;
