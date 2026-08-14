<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   tvi_saude.php — checagem de saúde do TV Indoor, via cron.

   O QUE FAZ
   Roda de tempos em tempos (configure um Cron Job no hPanel da
   Hostinger) e avisa por push quem é administrador quando:
     - uma TV para de mandar sinal (fica muda por mais de X minutos);
     - qualquer erro novo aparece no painel de Alertas (o mesmo que
       já existe na aba do TV Indoor) e ainda não tinha sido avisado.

   Não é um sistema de alerta separado: usa a mesma tabela tvi_erros
   que já alimenta o painel "ALERTAS" que você já usa. O push é só
   um aviso a mais de algo que o painel já ia mostrar de qualquer
   jeito — a diferença é você saber na hora, sem precisar abrir o
   Hub pra conferir.

   COMO CONFIGURAR NA HOSTINGER
     hPanel > Avançado > Cron Jobs > Criar novo
     Comando:  php /home/SEU_USUARIO/public_html/tvi_saude.php
     Frequência sugerida: a cada 5 ou 10 minutos.

   Se preferir configurar por URL (em vez de comando PHP direto),
   também funciona:
     https://SEUDOMINIO/tvi_saude.php?k=SUA_CHAVE
   (a chave real fica em cron_secrets.php, fora do controle de versão)
   Usar por CLI (comando PHP direto) é mais seguro, porque aí nem
   precisa de chave nenhuma: só quem tem acesso ao servidor roda.
   ============================================================ */

$__cronsec = @include __DIR__.'/cron_secrets.php';
$CHAVE_CRON = $__cronsec['tvi_saude'] ?? '';

$viaCli = (php_sapi_name() === 'cli');
if(!$viaCli){
  $k = isset($_GET['k']) ? $_GET['k'] : '';
  if($CHAVE_CRON === '' || !hash_equals($CHAVE_CRON, $k)){
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Acesso negado.\n");
  }
  header('Content-Type: text/plain; charset=utf-8');
}

require __DIR__.'/db_config.php';
define('PUSH_LIB_ONLY', true);
require __DIR__.'/push.php';

$db = portal_db();
if(!$db){ echo "Sem conexão com o banco.\n"; exit; }
$db->set_charset('utf8mb4');

/* Coluna nova, criada sozinha na primeira vez que este script roda —
   marca quando um erro já gerou um push, pra não avisar de novo a
   cada execução enquanto ele continuar sem ser resolvido. */
$r = $db->query("SHOW COLUMNS FROM tvi_erros LIKE 'alertado_em'");
if($r && !$r->num_rows) $db->query("ALTER TABLE tvi_erros ADD COLUMN alertado_em DATETIME NULL");

function saude_cfg($db, $chave, $padrao){
  $c = $db->real_escape_string($chave);
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$c' LIMIT 1");
  $x = $r ? $r->fetch_assoc() : null;
  return ($x && $x['valor'] !== '') ? $x['valor'] : $padrao;
}
// Quantos minutos sem sinal até considerar a TV realmente offline.
// Ajustável direto no banco (tabela tvi_config, chave saude_offline_min)
// sem precisar mexer neste arquivo.
$limiteMin = max(3, (int)saude_cfg($db, 'saude_offline_min', '10'));

$avisos = array();

/* ── 1) TVs que pararam de mandar sinal ── */
$r = $db->query("SELECT id, nome FROM tvi_tvs
                   WHERE ativo=1 AND ultimo_sinal IS NOT NULL
                     AND ultimo_sinal < DATE_SUB(NOW(), INTERVAL $limiteMin MINUTE)");
while($r && $tv = $r->fetch_assoc()){
  $id = (int)$tv['id'];
  $ja = $db->query("SELECT id, alertado_em FROM tvi_erros
                      WHERE tv_id=$id AND resolvido_em IS NULL AND codigo='tv_offline' LIMIT 1");
  if($ja && $ja->num_rows){
    $row = $ja->fetch_assoc();
    if($row['alertado_em'] === null){
      $avisos[] = $tv['nome'].' está sem sinal há mais de '.$limiteMin.' min.';
      $db->query("UPDATE tvi_erros SET alertado_em=NOW() WHERE id=".(int)$row['id']);
    }
    // já existia e já tinha sido avisado: não repete o push a cada execução.
  } else {
    $det = 'sem sinal há mais de '.$limiteMin.' minutos';
    $st = $db->prepare("INSERT INTO tvi_erros (tv_id,codigo,detalhe,ocorrido_em,alertado_em)
                         VALUES (?,'tv_offline',?,NOW(),NOW())");
    $st->bind_param('is', $id, $det);
    $st->execute();
    $avisos[] = $tv['nome'].' está sem sinal há mais de '.$limiteMin.' min.';
  }
}

/* ── 2) TV que voltou a mandar sinal: resolve o alerta sozinho ── */
$db->query("UPDATE tvi_erros e
              JOIN tvi_tvs t ON t.id = e.tv_id
               SET e.resolvido_em = NOW()
             WHERE e.codigo='tv_offline' AND e.resolvido_em IS NULL
               AND t.ultimo_sinal >= DATE_SUB(NOW(), INTERVAL $limiteMin MINUTE)");

/* ── 3) Qualquer outro erro (mídia, página que não carregou etc.)
        que já está no painel mas ainda não gerou push ── */
$r = $db->query("SELECT e.id, e.detalhe, t.nome
                    FROM tvi_erros e JOIN tvi_tvs t ON t.id = e.tv_id
                   WHERE e.resolvido_em IS NULL AND e.alertado_em IS NULL
                     AND e.codigo != 'tv_offline'");
while($r && $er = $r->fetch_assoc()){
  $avisos[] = $er['nome'].': '.$er['detalhe'];
  $db->query("UPDATE tvi_erros SET alertado_em=NOW() WHERE id=".(int)$er['id']);
}

if($avisos){
  $texto = implode(' · ', array_slice($avisos, 0, 3));
  if(count($avisos) > 3) $texto .= ' e mais '.(count($avisos)-3).'.';
  $res = push_enviar_admins($db, 'TV Indoor — atenção', $texto, 'tvi-saude', '/index.html');
  echo count($avisos)." aviso(s) novo(s) — push entregue a ".$res['ok']." admin(s), ".$res['falhas']." falha(s).\n";
} else {
  echo "Tudo certo, nada novo para avisar.\n";
}
