<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   BACKUP AUTOMÁTICO DIÁRIO — Redentor Hub
   Agende no cron da Hostinger (1x por dia):
   curl -s "https://SEU-SITE/backup_auto.php?k=SUA_CHAVE" > /dev/null
   (a chave real fica em cron_secrets.php, fora do controle de versão)
   Mantém os últimos 14 backups em /backups/.
   ============================================================ */
$__cronsec = @include __DIR__.'/cron_secrets.php';
define('CHAVE_CRON', $__cronsec['lembrete_backup'] ?? '');

header('Content-Type: text/plain; charset=utf-8');
if(CHAVE_CRON === '' || !isset($_GET['k']) || !hash_equals(CHAVE_CRON, (string)$_GET['k'])){ http_response_code(403); die('Acesso negado.'); }

require __DIR__.'/db_config.php';
$mysqli = portal_db();
if(!$mysqli) die('ERRO: sem conexao com o banco.');
$mysqli->set_charset('utf8mb4');

$dados = array('gerado_em'=>date('c'), 'tipo'=>'backup_automatico', 'items'=>array(), 'usuarios'=>array());

$res = $mysqli->query("SELECT chave, valor FROM portal_dados");
while($r = $res->fetch_assoc()){ $dados['items'][] = array('chave'=>$r['chave'], 'valor'=>$r['valor']); }

$res = $mysqli->query("SELECT username, name, senha_hash, role, perm_fuel, perm_drive, perm_biart, perm_dash, totp_secret, totp_backup FROM portal_usuarios");
while($r = $res->fetch_assoc()){ $dados['usuarios'][] = $r; }

/* ── TV Indoor ────────────────────────────────────────────────
   O cadastro das TVs, as listas e a programação são a inteligência do
   módulo — os arquivos sobrevivem em disco, isto não. Vai tudo menos:
     tvi_exibicoes  histórico bruto, milhões de linhas; o consolidado
                    mensal (tvi_exibicoes_mes) preserva o que interessa
     tvi_cache      previsão do tempo, refaz sozinha
     tvi_capturas   imagens de tela, descartáveis
   Se as tabelas ainda não existirem, esta parte simplesmente não roda. */
$PULAR = array('tvi_exibicoes', 'tvi_cache', 'tvi_capturas');
$dados['tvindoor'] = array();
$linhas_tvi = 0;

$res = $mysqli->query("SHOW TABLES LIKE 'tvi\\_%'");
while($res && $t = $res->fetch_array()){
  $tabela = $t[0];
  if(in_array($tabela, $PULAR, true)) continue;
  $rows = array();
  $q = $mysqli->query("SELECT * FROM `$tabela`");
  while($q && $r = $q->fetch_assoc()) $rows[] = $r;
  $dados['tvindoor'][$tabela] = $rows;
  $linhas_tvi += count($rows);
}

$dir = __DIR__.'/backups';
if(!is_dir($dir)) mkdir($dir, 0755, true);
/* protege a pasta contra acesso via navegador */
if(!file_exists($dir.'/.htaccess')) file_put_contents($dir.'/.htaccess', "Require all denied\n");

$arq = $dir.'/backup_'.date('Y-m-d_Hi').'.json';
file_put_contents($arq, json_encode($dados, JSON_UNESCAPED_UNICODE));
echo "OK: ".basename($arq)." (".count($dados['items'])." conjuntos, ".count($dados['usuarios'])." usuarios, "
   . count($dados['tvindoor'])." tabelas TV Indoor / $linhas_tvi linhas)\n";

/* limpeza: mantém os 14 mais recentes */
$files = glob($dir.'/backup_*.json');
rsort($files);
foreach(array_slice($files, 14) as $velho){ unlink($velho); echo "removido: ".basename($velho)."\n"; }
echo "Total guardados: ".min(count($files),14)."\n";
