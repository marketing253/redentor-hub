<?php
/* ============================================================
   db_config.php — Conexão com o MySQL (Portal Redentor)

   PRIMEIRO tenta reaproveitar o seu config.php já existente
   (o mesmo que o api.php usava). Se achar a conexão ou as
   credenciais lá, VOCÊ NÃO PRECISA PREENCHER NADA AQUI.

   Senão, usa db_secrets.php (fora do controle de versão — veja
   db_secrets.example.php para o modelo).
   ============================================================ */

$__dbsec = @include __DIR__.'/db_secrets.php';
$DB_HOST = $__dbsec['host'] ?? 'localhost';
$DB_NAME = $__dbsec['name'] ?? '';
$DB_USER = $__dbsec['user'] ?? '';
$DB_PASS = $__dbsec['pass'] ?? '';

function _pick($vars, $names, $fallback){
  foreach($names as $n){
    if(isset($vars[$n]) && $vars[$n] !== '') return $vars[$n];
    $C = strtoupper($n);
    if(defined($C) && constant($C) !== '') return constant($C);
  }
  return $fallback;
}

function portal_db(){
  global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;

  // 1) Inclui o config.php existente (se houver) na mesma pasta
  $cfg = __DIR__.'/config.php';
  if(file_exists($cfg)){ ob_start(); @include $cfg; @ob_end_clean(); }

  $vars = get_defined_vars();

  // 2) Se o config.php já criou uma conexão mysqli, reaproveita
  foreach($vars as $val){
    if($val instanceof mysqli){ @$val->set_charset('utf8mb4'); @$val->query("SET time_zone='-03:00'"); return $val; }
  }

  // 3) Procura credenciais em nomes comuns
  $host = _pick($vars, array('DB_HOST','host','servername','db_host','hostname','mysql_host'), $DB_HOST);
  $user = _pick($vars, array('DB_USER','user','username','db_user','dbuser','mysql_user'), $DB_USER);
  $pass = _pick($vars, array('DB_PASS','DB_PASSWORD','pass','password','db_pass','dbpass','senha','mysql_pass'), $DB_PASS);
  $name = _pick($vars, array('DB_NAME','db','dbname','database','db_name','banco','mysql_db'), $DB_NAME);

  $m = @new mysqli($host, $user, $pass, $name);
  if($m->connect_errno){
    header('Content-Type: application/json; charset=utf-8');
    $detalhe = 'Falha ao conectar ao banco ['.$m->connect_errno.']: '.$m->connect_error
             .' | host='.$host.' user='.$user.' db='.$name
             .' | config.php '.(file_exists($cfg)?'encontrado':'NAO encontrado');
    echo json_encode(array('ok'=>false, 'error'=>$detalhe, 'erro'=>$detalhe));
    exit;
  }
  $m->set_charset('utf8mb4');
  @$m->query("SET time_zone='-03:00'");
  return $m;
}
