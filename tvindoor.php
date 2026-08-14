<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   tvindoor.php — API do módulo TV Indoor (Marketing Indoor)
   Coloque na MESMA PASTA do index.html. As tabelas nascem sozinhas.

   MUDANÇA DE STACK, DE PROPÓSITO
   ------------------------------
   A arquitetura Laravel + Redis que desenhamos antes está certa para um
   SaaS com milhares de telas de clientes diferentes. Só que o Hub roda
   PHP + MySQL na Hostinger, sem Redis, e você não deveria manter duas
   stacks para um módulo interno.

   Então aqui o heartbeat grava direto no MySQL, num UPDATE de uma linha.
   Com as TVs da empresa (dezenas, não milhares) isso é 1 a 2 gravações
   por segundo — o banco nem sente. O desenho Redis passa a valer se um
   dia isso virar produto para terceiros; até lá, seria complexidade sem
   retorno.

   Duas famílias de ação neste arquivo:
     · dispositivo — heartbeat, manifesto, log. Autentica por TOKEN,
       nunca por sessão: a TV não faz login.
     · painel — tudo o mais. Exige a sessão do auth.php.
   ============================================================ */

require __DIR__.'/db_config.php';

/* Funções comuns aos três arquivos. O @ é deliberado: se comum.php faltar,
   cada arquivo ainda tem as próprias cópias e continua funcionando. */
@include_once __DIR__.'/comum.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
error_reporting(E_ALL);
ini_set('display_errors', 0);

/* SEMPRE JSON, ACONTEÇA O QUE ACONTECER
   ------------------------------------
   Sem isto, um erro de PHP devolve HTML e o app só consegue dizer
   "sem resposta do servidor" — que não ajuda ninguém a achar a causa.
   Mesmo padrão do auth.php e do upload.php. */
register_shutdown_function(function(){
  $e = error_get_last();
  if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))){
    http_response_code(200);
    echo json_encode(array('ok'=>false,
      'erro'=>'PHP: '.$e['message'].' (linha '.$e['line'].')'), JSON_UNESCAPED_UNICODE);
  }
});

/* Em PHP 8.1+ o mysqli lança exceção por padrão. O resto do portal foi
   escrito para o comportamento antigo (query devolve false), então voltamos
   a ele aqui e tratamos a falha na mão. Era esta a causa do erro genérico. */
if(function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

function _len($s){ return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); }
function _cut($s, $ini, $n){ return function_exists('mb_substr') ? mb_substr($s, $ini, $n, 'UTF-8') : substr($s, $ini, $n); }

function out($a){ echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail($m, $code = 200){ http_response_code($code); out(array('ok'=>false,'erro'=>$m)); }

$acao  = isset($_GET['action']) ? $_GET['action'] : '';
$body  = json_decode(file_get_contents('php://input'), true);
if(!is_array($body)) $body = array();

try {

$db = portal_db();
if(!$db) fail('Sem conexão com o banco. Confira o db_config.php.');
$db->set_charset('utf8mb4');

/* ══════════════ ESTRUTURA ══════════════ */

$db->query("CREATE TABLE IF NOT EXISTS tvi_grupos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(120) NOT NULL,
  cor CHAR(7) DEFAULT '#54d6c8',
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_tvs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo VARCHAR(20) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  token CHAR(40) NOT NULL,
  grupo_id INT NULL,
  local VARCHAR(150) NULL,
  cidade VARCHAR(100) NULL,
  uf CHAR(2) NULL,
  ativo TINYINT(1) DEFAULT 1,
  primeira_conexao DATETIME NULL,
  ultimo_sinal DATETIME NULL,
  ultimo_ip VARCHAR(45) NULL,
  so VARCHAR(80) NULL,
  resolucao VARCHAR(20) NULL,
  versao_player VARCHAR(20) NULL,
  versao_manifesto CHAR(64) NULL,
  ultima_midia INT NULL,
  estado_player VARCHAR(20) NULL,
  observacao TEXT NULL,
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_token (token),
  UNIQUE KEY uk_codigo (codigo),
  KEY idx_sinal (ultimo_sinal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_midias (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(200) NOT NULL,
  tipo ENUM('video','imagem','pdf','web','youtube') NOT NULL,
  arquivo VARCHAR(300) NULL,
  url_externa VARCHAR(1000) NULL,
  mime VARCHAR(100) NULL,
  bytes BIGINT DEFAULT 0,
  duracao_ms INT NULL,
  largura INT NULL, altura INT NULL,
  checksum CHAR(64) NULL,
  pasta VARCHAR(200) NULL,
  tags VARCHAR(300) NULL,
  valido_de DATE NULL,
  valido_ate DATE NULL,
  avisar_dias INT NULL,
  duracao_padrao_ms INT DEFAULT 10000,
  enviado_por VARCHAR(60) NULL,
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_checksum (checksum),
  KEY idx_validade (valido_ate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_paginas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  midia_id INT NOT NULL,
  pagina SMALLINT NOT NULL,
  arquivo VARCHAR(300) NOT NULL,
  UNIQUE KEY uk_pag (midia_id, pagina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_playlists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(150) NOT NULL,
  descricao VARCHAR(255) NULL,
  versao CHAR(64) NOT NULL,
  ativa TINYINT(1) DEFAULT 1,
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_itens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT NOT NULL,
  midia_id INT NOT NULL,
  ordem INT DEFAULT 0,
  duracao_ms INT DEFAULT 10000,
  prioridade TINYINT DEFAULT 0,
  dias TINYINT UNSIGNED DEFAULT 127,
  hora_de TIME NULL,
  hora_ate TIME NULL,
  data_de DATE NULL,
  data_ate DATE NULL,
  ajuste ENUM('cover','contain','fill') DEFAULT 'cover',
  mudo TINYINT(1) DEFAULT 1,
  KEY idx_pl (playlist_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_atribuicoes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT NOT NULL,
  alvo_tipo ENUM('tv','grupo') NOT NULL,
  alvo_id INT NOT NULL,
  UNIQUE KEY uk_atr (playlist_id, alvo_tipo, alvo_id),
  KEY idx_alvo (alvo_tipo, alvo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_comandos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tv_id INT NOT NULL,
  tipo VARCHAR(30) NOT NULL,
  carga TEXT NULL,
  entregue_em DATETIME NULL,
  confirmado_em DATETIME NULL,
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pend (tv_id, confirmado_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_exibicoes (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tv_id INT NOT NULL,
  midia_id INT NULL,
  playlist_id INT NULL,
  exibido_em DATETIME NOT NULL,
  duracao_ms INT DEFAULT 0,
  completo TINYINT(1) DEFAULT 1,
  KEY idx_tv_data (tv_id, exibido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_sinal_hora (
  tv_id INT NOT NULL,
  hora DATETIME NOT NULL,
  batidas SMALLINT DEFAULT 0,
  PRIMARY KEY (tv_id, hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_config (
  chave VARCHAR(60) PRIMARY KEY,
  valor TEXT NOT NULL,
  atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_cache (
  chave VARCHAR(120) PRIMARY KEY,
  valor LONGTEXT,
  atualizado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Módulos desligados por padrão são os que exigem processo, não os que
   exigem código. Aprovação só faz sentido quando mais de duas pessoas
   publicam — ligar antes disso é burocracia sem problema para resolver. */
$PADRAO = array(
  'mod_relatorio'  => '1',
  'mod_paineis'    => '1',
  'mod_urgente'    => '1',
  'mod_zonas'      => '1',
  /* O editor saiu da página de Conteúdo e foi para Configurações →
     Fontes de conteúdo, recolhido: quem só envia arquivo não esbarra
     nele, e quem precisa de um aviso rápido continua tendo. */
  'mod_editor'     => '1',
  'mod_calendario' => '1',
  'mod_aprovacao'  => '0',
  'retencao_dias'  => '90',
);
/* fetch_assoc devolve null se a consulta falhar, e acessar ['n'] em null
   gera aviso. Numa instalação com display_errors ligado, esse aviso sai
   ANTES do JSON e quebra a resposta inteira. */
$r = $db->query("SELECT COUNT(*) n FROM tvi_config");
$linha = $r ? $r->fetch_assoc() : null;
if($linha && (int)$linha['n'] === 0){
  foreach($PADRAO as $k => $v) $db->query("INSERT IGNORE INTO tvi_config (chave,valor) VALUES ('$k','$v')");
}

function cfg($db, $chave, $padrao = '0'){
  static $c = null;
  if($c === null){
    $c = array();
    $r = $db->query("SELECT chave, valor FROM tvi_config");
    while($r && $x = $r->fetch_assoc()) $c[$x['chave']] = $x['valor'];
  }
  return isset($c[$chave]) ? $c[$chave] : $padrao;
}

/* Migrações incrementais. Rodam uma vez e ficam quietas. */
function coluna($db, $tabela, $col, $ddl){
  $r = $db->query("SHOW COLUMNS FROM $tabela LIKE '$col'");
  if(!$r || !$r->num_rows) $db->query("ALTER TABLE $tabela ADD COLUMN $ddl");
}
coluna($db, 'tvi_playlists', 'layout',  "layout ENUM('cheia','lateral','rodape','completo') NOT NULL DEFAULT 'cheia'");
coluna($db, 'tvi_playlists', 'ticker',  "ticker VARCHAR(600) NULL");
coluna($db, 'tvi_playlists', 'cidade_clima', "cidade_clima VARCHAR(60) NULL");
coluna($db, 'tvi_midias',    'aprovado', "aprovado TINYINT(1) NOT NULL DEFAULT 1");
coluna($db, 'tvi_midias',    'aprovado_por', "aprovado_por VARCHAR(60) NULL");
coluna($db, 'tvi_itens',     'expira_em', "expira_em DATETIME NULL");
coluna($db, 'tvi_itens',     'pagina_ms', "pagina_ms INT DEFAULT 8000");

/* Histórico do que foi publicado.
   Quando aparece algo errado na parede, hoje não há como saber quem
   colocou nem quando. Um registro simples resolve — e é o tipo de coisa
   que só faz falta depois que já precisou. */
$db->query("CREATE TABLE IF NOT EXISTS tvi_historico (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quando DATETIME DEFAULT CURRENT_TIMESTAMP,
  usuario VARCHAR(60) NULL,
  acao VARCHAR(40) NOT NULL,
  alvo VARCHAR(160) NULL,
  detalhe VARCHAR(255) NULL,
  KEY idx_h_quando (quando)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Aparelhos aguardando vínculo.
   O box liga, mostra um código de 6 caracteres na tela e fica perguntando
   ao servidor se já foi vinculado. Quem instala não digita endereço nenhum
   — só lê o código na parede e escolhe a TV no painel.

   É como funciona qualquer sistema de sinalização comercial, e pela mesma
   razão: digitar uma URL com token de 39 caracteres no controle remoto de
   uma TV a três metros de altura é onde a instalação trava. */
$db->query("CREATE TABLE IF NOT EXISTS tvi_aparelhos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  aparelho VARCHAR(64) NOT NULL UNIQUE,
  codigo CHAR(6) NOT NULL,
  modelo VARCHAR(80) NULL,
  tv_id INT NULL,
  visto_em DATETIME NULL,
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  versao_app INT DEFAULT 0,
  tela VARCHAR(16) NULL,
  mem_livre INT DEFAULT 0,
  KEY idx_ap_cod (codigo),
  KEY idx_ap_tv (tv_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
coluna($db, 'tvi_aparelhos', 'versao_app', "versao_app INT DEFAULT 0");
coluna($db, 'tvi_aparelhos', 'tela',       "tela VARCHAR(16) NULL");
coluna($db, 'tvi_aparelhos', 'mem_livre',   "mem_livre INT DEFAULT 0");
coluna($db, 'tvi_aparelhos', 'disco_livre', "disco_livre INT DEFAULT 0");
coluna($db, 'tvi_aparelhos', 'app_usado',   "app_usado INT DEFAULT 0");
coluna($db, 'tvi_aparelhos', 'android',     "android VARCHAR(10) NULL");

/* Agenda da contabilidade. Tabela própria, não uma chave em tvi_config:
   isso aqui é uma LISTA que cresce, precisa de ordem por data e de filtro
   por mês. Guardar como texto numa configuração daria o mesmo trabalho de
   parsear tudo a cada exibição. */
$db->query("CREATE TABLE IF NOT EXISTS tvi_agenda (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setor VARCHAR(30) NOT NULL DEFAULT 'contabilidade',
  data DATE NOT NULL,
  titulo VARCHAR(80) NOT NULL,
  detalhe VARCHAR(160) NULL,
  cor VARCHAR(12) NOT NULL DEFAULT 'azul',
  criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ag (setor, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Índices ──────────────────────────────────────────────────
   Adicionados depois de olhar as consultas que o relatório realmente faz.
   O índice existente em tvi_exibicoes começa por tv_id, então uma consulta
   que filtra SÓ por data não consegue usá-lo: o banco varre a tabela
   inteira. Com 90 dias de histórico e dez telas isso já são centenas de
   milhares de linhas lidas para montar um gráfico.

   idx_existe() evita erro quando o índice já foi criado — CREATE INDEX
   não tem "IF NOT EXISTS" em MySQL. */
function idx_existe($db, $tabela, $nome){
  $t = $db->real_escape_string($tabela);
  $n = $db->real_escape_string($nome);
  $r = $db->query("SHOW INDEX FROM `$t` WHERE Key_name='$n'");
  return $r && $r->num_rows > 0;
}
function idx($db, $tabela, $nome, $colunas){
  if(idx_existe($db, $tabela, $nome)) return;
  @$db->query("CREATE INDEX `$nome` ON `$tabela` ($colunas)");
}

// Relatório por período: filtra por data, sem tv_id na frente.
idx($db, 'tvi_exibicoes', 'idx_ex_data',  'exibido_em');
// Agrupamento por conteúdo dentro do período.
idx($db, 'tvi_exibicoes', 'idx_ex_midia', 'midia_id, exibido_em');
// Expurgo e consolidação varrem por data.
idx($db, 'tvi_erros',     'idx_er_data',  'ocorrido_em');
idx($db, 'tvi_reinicios', 'idx_re_data',  'ocorrido_em');
// tvi_cache: a limpeza procura por idade.
idx($db, 'tvi_cache',     'idx_ca_data',  'atualizado_em');
// Mídia: a biblioteca ordena por data de envio.
idx($db, 'tvi_midias',    'idx_md_criado','criado_em');
// Itens por mídia: usado ao editar validade e ao excluir.
idx($db, 'tvi_itens',     'idx_it_midia', 'midia_id');
// Capturas: sempre buscadas pela TV mais recente.
idx($db, 'tvi_capturas',  'idx_cp_tv',    'tv_id, criado_em');
coluna($db, 'tvi_tvs',       'sessao_id',  "sessao_id CHAR(16) NULL");
coluna($db, 'tvi_tvs',       'sessao_ip',  "sessao_ip VARCHAR(45) NULL");
coluna($db, 'tvi_tvs',       'sessao_em',  "sessao_em DATETIME NULL");
coluna($db, 'tvi_tvs',       'sessao_liberada_em', "sessao_liberada_em DATETIME NULL");
// a lista de aniversariantes não cabe em VARCHAR(255)
$r = $db->query("SHOW COLUMNS FROM tvi_config LIKE 'valor'");
if($r && ($x = $r->fetch_assoc()) && stripos($x['Type'], 'varchar') !== false){
  $db->query("ALTER TABLE tvi_config MODIFY valor TEXT NOT NULL");
}

/* Uma TV, uma sessão. Se o mesmo link for aberto num segundo navegador —
   alguém testando no computador enquanto a tela roda —, os dois passam a
   bater e a registrar exibição: o último sinal fica pingando e o relatório
   de veiculação dobra. A concessão vale por este tanto de segundos sem
   sinal; passado isso, a sessão é considerada morta e qualquer um assume.
   150s = cinco batidas perdidas. Curto demais e a TV que oscilou perde a
   própria vaga; longo demais e uma TV que caiu de verdade fica travada. */
define('SESSAO_TTL', 150);

$db->query("CREATE TABLE IF NOT EXISTS tvi_erros (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tv_id INT NOT NULL,
  midia_id INT NULL,
  codigo VARCHAR(40) NOT NULL,
  detalhe VARCHAR(400) NULL,
  ocorrido_em DATETIME NOT NULL,
  resolvido_em DATETIME NULL,
  KEY idx_erro_tv (tv_id, ocorrido_em),
  KEY idx_erro_aberto (resolvido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Consolidado mensal. tvi_exibicoes é a fonte detalhada e tem prazo de
   validade; esta tabela é a memória longa, e cabe num dedal. */
$db->query("CREATE TABLE IF NOT EXISTS tvi_exibicoes_mes (
  mes CHAR(7) NOT NULL,
  midia_id INT NOT NULL,
  nome VARCHAR(200) NULL,
  tv_id INT NOT NULL,
  exibicoes INT DEFAULT 0,
  completas INT DEFAULT 0,
  segundos INT DEFAULT 0,
  PRIMARY KEY (mes, midia_id, tv_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* Cada vez que a TV volta com identidade nova de sessão, isso é um
   reinício: o aparelho desligou, o navegador foi morto, ou alguém reabriu
   o link. Recarregar a página não conta, porque a identidade fica no
   sessionStorage e sobrevive ao reload. É justamente o que se quer medir
   antes de comprar dez aparelhos iguais. */
$db->query("CREATE TABLE IF NOT EXISTS tvi_reinicios (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tv_id INT NOT NULL,
  ocorrido_em DATETIME NOT NULL,
  fora_segundos INT DEFAULT 0,
  sozinho TINYINT(1) DEFAULT 1,
  KEY idx_re_tv (tv_id, ocorrido_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$db->query("CREATE TABLE IF NOT EXISTS tvi_capturas (
  tv_id INT PRIMARY KEY,
  imagem MEDIUMTEXT NULL,
  capturado_em DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

define('JANELA_OFFLINE', 120);   // segundos sem sinal até virar offline
define('PASTA_MIDIA', __DIR__.'/midias_tv');
/* Retenção do detalhe de exibição. Definida em Configurações porque
   auditoria pede prazo maior de vez em quando, e ninguém deveria ter que
   editar PHP para isso. O consolidado mensal nunca é apagado. */
$__ret = (int)cfg($db, 'retencao_dias', '90');
define('RETENCAO_DIAS', max(30, min(730, $__ret ?: 90)));

/* MIGRAÇÃO — código curto de pareamento
   O token tem 39 caracteres. Ninguém digita isso no controle remoto de uma
   televisão. O código curto tem 6 e existe só para ser datilografado uma vez;
   o tv.php troca ele pelo token de verdade, que o player guarda sozinho. */
$temCurto = false;
$r = $db->query("SHOW COLUMNS FROM tvi_tvs LIKE 'codigo_curto'");
if($r && $r->num_rows) $temCurto = true;
if(!$temCurto){
  $db->query("ALTER TABLE tvi_tvs ADD COLUMN codigo_curto CHAR(6) NULL AFTER codigo");
  $db->query("ALTER TABLE tvi_tvs ADD UNIQUE KEY uk_curto (codigo_curto)");
}

/* ══════════════ DIAGNÓSTICO ══════════════
   Sem sessão de propósito: é a primeira coisa a abrir quando algo não
   funciona, e você não deveria precisar estar logado para descobrir que
   a pasta de mídia não tem permissão de escrita.
   Abra no navegador: /tvindoor.php?action=ping                        */

if($acao === 'ping'){
  /* Sem sessão o ping responde só "estou de pé". Versão do PHP, versão do
     MySQL, nomes de tabela e limite de upload são exatamente o mapa que
     alguém usaria para escolher um ataque — e não custam nada para quem
     está apenas conferindo se o arquivo subiu. */
  $logado = false;
  if(session_status() !== PHP_SESSION_ACTIVE){
    $sec = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if(PHP_VERSION_ID >= 70300){
      @session_set_cookie_params(array('lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$sec,'samesite'=>'Lax'));
    }
    @session_start();
  }
  $logado = !empty($_SESSION['uid']);

  if(!$logado){
    out(array('ok'=>true, 'servico'=>'tvindoor', 'banco'=>'conectado',
              'detalhe'=>'Entre no Hub para ver o diagnóstico completo.'));
  }

  $tabelas = array();
  $r = $db->query("SHOW TABLES LIKE 'tvi_%'");
  while($r && $t = $r->fetch_array()) $tabelas[] = $t[0];

  /* Antes o diagnóstico só reclamava, e a pasta só nascia no primeiro
     envio: quem conferia a instalação via "NÃO EXISTE" e ia criar à mão
     sem precisar. Aqui ele TENTA criar, e se não der diz por quê — que é
     a informação que resolve, não o fato de estar faltando. */
  $pasta = is_dir(PASTA_MIDIA);
  $motivo = '';

  if(!$pasta){
    $pai = dirname(PASTA_MIDIA);
    if(!is_dir($pai)){
      $motivo = 'a pasta acima ('.basename($pai).') não existe';
    } elseif(!is_writable($pai)){
      $motivo = 'sem permissão de escrita em '.basename($pai)
              . '. No gerenciador de arquivos, ajuste essa pasta para 755';
    } else {
      @mkdir(PASTA_MIDIA, 0755, true);
      $pasta = is_dir(PASTA_MIDIA);
      if(!$pasta){
        $e = error_get_last();
        $motivo = $e && !empty($e['message']) ? _cut($e['message'], 0, 90) : 'o servidor recusou';
      }
    }
  }

  // Recém-criada ou não, garante a proteção contra listagem.
  if($pasta){
    if(!file_exists(PASTA_MIDIA.'/index.html'))
      @file_put_contents(PASTA_MIDIA.'/index.html', '<!DOCTYPE html><title></title>');
    if(!file_exists(PASTA_MIDIA.'/.htaccess'))
      @file_put_contents(PASTA_MIDIA.'/.htaccess',
        "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\n  Require all denied\n</FilesMatch>\n");
  }
  out(array(
    'ok'            => true,
    'php'           => PHP_VERSION,
    'banco'         => 'conectado',
    'servidor'      => $db->server_info,
    'tabelas'       => $tabelas,
    'tabelas_ok'    => count($tabelas) >= 8,
    'pasta_midia'   => $pasta
        ? 'ok ('.PASTA_MIDIA.')'
        : 'NÃO CONSEGUI CRIAR: '.$motivo.'. Crie a pasta midias_tv dentro de '
          .basename(dirname(PASTA_MIDIA)).' e dê permissão 755',
    'pasta_caminho' => PASTA_MIDIA,
    'pasta_escrita' => $pasta ? (is_writable(PASTA_MIDIA) ? 'ok' : 'SEM PERMISSÃO. Ajuste para 755') : '–',
    'imagick'       => class_exists('Imagick') ? 'disponível, PDF convertido em imagens'
                                               : 'ausente, PDF depende do leitor da TV',
    'mbstring'      => function_exists('mb_substr') ? 'ok' : 'ausente (há fallback)',
    'mail'          => function_exists('mail') ? 'ok' : 'ausente, sem aviso de vencimento por e-mail',
    'retencao_dias' => RETENCAO_DIAS,
    'upload_max'    => ini_get('upload_max_filesize'),
    'post_max'      => ini_get('post_max_size'),
    'sessao'        => 'não verificada nesta ação',
  ));
}

/* ══════════════ AÇÕES DO DISPOSITIVO (token, sem sessão) ══════════════
   Ficam ANTES do portão de sessão. A TV não faz login e não tem cookie. */

function tv_por_token($db, $token){
  $t = $db->real_escape_string($token);
  $r = $db->query("SELECT * FROM tvi_tvs WHERE token='$t' AND ativo=1 LIMIT 1");
  return $r ? $r->fetch_assoc() : null;
}

function versao_manifesto($db, $tv){
  // Hash das versões das playlists que valem para esta TV. É o que o player
  // compara a cada batida para saber se precisa sincronizar.
  $pls = playlists_da_tv($db, $tv);
  $p = array();
  foreach($pls as $pl) $p[] = $pl['id'].':'.$pl['versao'];
  return hash('sha256', $p ? implode('|', $p) : 'vazio');
}

function playlists_da_tv($db, $tv){
  // Modo prévia: uma lista só, escolhida pelo painel.
  if(!empty($GLOBALS['_previa_playlist'])) return array($GLOBALS['_previa_playlist']);

  $id = (int)$tv['id'];
  $gid = $tv['grupo_id'] !== null ? (int)$tv['grupo_id'] : 0;

  // Atribuição direta na TV vence a do grupo.
  $sql = "SELECT p.* FROM tvi_playlists p
          JOIN tvi_atribuicoes a ON a.playlist_id=p.id
          WHERE p.ativa=1 AND a.alvo_tipo='tv' AND a.alvo_id=$id";
  $r = $db->query($sql);
  $out = array();
  while($r && $row = $r->fetch_assoc()) $out[] = $row;
  if($out || !$gid) return $out;

  $sql = "SELECT p.* FROM tvi_playlists p
          JOIN tvi_atribuicoes a ON a.playlist_id=p.id
          WHERE p.ativa=1 AND a.alvo_tipo='grupo' AND a.alvo_id=$gid";
  $r = $db->query($sql);
  while($r && $row = $r->fetch_assoc()) $out[] = $row;
  return $out;
}

/** Data mais restritiva: a validade do arquivo é teto sobre a do item. */
function data_max($a, $b){ if(!$a) return $b; if(!$b) return $a; return $a > $b ? $a : $b; }
function data_min($a, $b){ if(!$a) return $b; if(!$b) return $a; return $a < $b ? $a : $b; }

function paginas_de($db, $midiaId, $base){
  static $cache = array();
  if(isset($cache[$midiaId])) return $cache[$midiaId];
  $p = array();
  $r = $db->query("SELECT arquivo FROM tvi_paginas WHERE midia_id=$midiaId ORDER BY pagina");
  while($r && $x = $r->fetch_assoc()) $p[] = $base.'/midias_tv/'.$x['arquivo'];
  $cache[$midiaId] = $p ?: null;
  return $cache[$midiaId];
}

function montar_manifesto($db, $tv){
  $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'];

  $listas = array();
  foreach(playlists_da_tv($db, $tv) as $pl){
    $pid = (int)$pl['id'];
    $r = $db->query("SELECT i.*, m.nome AS m_nome, m.tipo, m.arquivo, m.url_externa,
                            m.duracao_ms AS m_dur, m.checksum, m.bytes,
                            m.valido_de, m.valido_ate
                     FROM tvi_itens i JOIN tvi_midias m ON m.id=i.midia_id
                     WHERE i.playlist_id=$pid
                       AND m.aprovado=1
                       AND (i.expira_em IS NULL OR i.expira_em > NOW())
                     ORDER BY i.ordem");
    $itens = array();
    while($r && $it = $r->fetch_assoc()){
      $itens[] = array(
        'id'       => (int)$it['id'],
        'media_id' => (int)$it['midia_id'],
        'type'     => $it['tipo'] === 'imagem' ? 'image' : $it['tipo'],
        'name'     => $it['m_nome'],
        'url'      => $it['arquivo'] ? $base.'/midias_tv/'.$it['arquivo'] : $it['url_externa'],
        'pages'    => paginas_de($db, (int)$it['midia_id'], $base),
        /* Segundos por PAGINA em vez de dividir o total. Dividindo, um PDF de
           30 paginas com 20s de item dava 0,6s por pagina, o piso de 3s entrava,
           e a peca passava 90s no ar estourando a programacao seguinte. Agora o
           item dura exatamente paginas x este valor, e o painel mostra a conta. */
        'page_ms'  => max(2000, (int)(isset($it['pagina_ms']) ? $it['pagina_ms'] : 8000)),
        'checksum' => $it['checksum'],
        'bytes'    => (int)$it['bytes'],
        'duration' => $it['tipo'] === 'video'
                        ? (int)($it['m_dur'] ?: $it['duracao_ms'])
                        : (int)$it['duracao_ms'],
        'fit'      => $it['ajuste'],
        'mute'     => (bool)$it['mudo'],
        'priority' => (int)$it['prioridade'],
        'rules'    => array(
          // A validade do arquivo colapsa aqui, na janela do item. O player
          // recebe uma regra só, já resolvida, e continua aplicando offline.
          'starts_on' => data_max($it['data_de'],  $it['valido_de']),
          'ends_on'   => data_min($it['data_ate'], $it['valido_ate']),
          'starts_at' => $it['hora_de'],
          'ends_at'   => $it['hora_ate'],
          'weekdays'  => (int)$it['dias'],
        ),
      );
    }
    $listas[] = array(
      'id' => $pid, 'name' => $pl['nome'], 'version' => $pl['versao'],
      'loop' => true,
      // Zonas: o player monta a tela a partir daqui. 'cheia' é o comportamento
      // antigo, então TV com player velho continua funcionando igual.
      'layout' => isset($pl['layout']) ? $pl['layout'] : 'cheia',
      'ticker' => isset($pl['ticker']) ? $pl['ticker'] : null,
      'clima'  => !empty($pl['cidade_clima']) ? $pl['cidade_clima'] : 'Curitiba',
      'base'   => $base,
      'items' => $itens
    );
  }

  return array(
    'version'        => versao_manifesto($db, $tv),
    'generated_at'   => time(),
    'tv'             => array('id'=>(int)$tv['id'],'code'=>$tv['codigo'],'name'=>$tv['nome'],
                              'timezone'=>'America/Sao_Paulo'),
    'heartbeat_secs' => 30,
    'offline_secs'   => JANELA_OFFLINE,
    'playlists'      => $listas,
  );
}

function ip_cliente(){
  foreach(array('HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR') as $k){
    if(!empty($_SERVER[$k])){ $v = explode(',', $_SERVER[$k]); return trim($v[0]); }
  }
  return '';
}

/* ══════════ VÍNCULO POR CÓDIGO ══════════
   Estas duas ações são PÚBLICAS: quem chama é o aparelho na parede, que
   ainda não tem token nenhum — é justamente o que ele está pedindo.
   A proteção é outra: o código só vale enquanto ninguém o vinculou, e
   quem vincula precisa estar logado no painel. */

/* Diagnóstico do vínculo. Público como o parear, e sem dado sensível:
   mostra apenas se o aparelho foi encontrado e o que falta para ele
   receber o endereço. Sem isso, descobrir por que uma TV não vira exige
   abrir o phpMyAdmin. */
if($acao === 'parear_diag'){
  $lista = array();
  $r = $db->query("SELECT a.aparelho, a.codigo, a.tv_id, a.modelo,
                          t.nome tv_nome, t.codigo_curto, t.ativo
                   FROM tvi_aparelhos a LEFT JOIN tvi_tvs t ON t.id = a.tv_id
                   ORDER BY a.visto_em DESC LIMIT 10");
  while($r && $x = $r->fetch_assoc()){
    $lista[] = array(
      'aparelho' => substr($x['aparelho'], 0, 12).'…',
      'modelo'   => $x['modelo'],
      'codigo'   => $x['codigo'],
      'tv_id'    => $x['tv_id'],
      'tv_nome'  => $x['tv_nome'],
      'codigo_curto' => $x['codigo_curto'],
      'tv_ativo' => $x['ativo'],
      'vai_abrir' => (!empty($x['tv_id']) && !empty($x['codigo_curto']))
                     ? tvi_base_url().'/t.php?c='.$x['codigo_curto']
                     : 'NAO — falta '.(empty($x['tv_id']) ? 'vincular' : 'codigo_curto na TV'),
    );
  }
  out(array('ok'=>true,'base_url'=>tvi_base_url(),'aparelhos'=>$lista));
}

/* Leitura de configuração. Ficavam definidas depois do primeiro uso —
   e como o PHP executa o arquivo de cima para baixo, a ação app_estado
   morria com "Call to undefined function". Funções declaradas dentro de
   blocos condicionais não são içadas como as de nível superior. */
/* Registra uma ação no histórico. Falha em silêncio de propósito: se o
   registro der errado, a ação em si não pode parar por causa disso. */
function anotar($db, $acao, $alvo = '', $detalhe = ''){
  try {
    $u = isset($_SESSION['username']) ? $_SESSION['username'] : 'sistema';
    $st = $db->prepare("INSERT INTO tvi_historico (usuario,acao,alvo,detalhe)
                        VALUES (?,?,?,?)");
    if(!$st) return;
    $a = _cut($acao, 0, 40); $al = _cut($alvo, 0, 160); $d = _cut($detalhe, 0, 255);
    $st->bind_param('ssss', $u, $a, $al, $d);
    $st->execute();
  } catch(Throwable $e) { }
}

function cfg_txt($db, $chave, $padrao = ''){
  $e = $db->real_escape_string($chave);
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$e'");
  return ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : $padrao;
}
function cfg_num($db, $chave, $padrao = 0){
  return (int)cfg_txt($db, $chave, (string)$padrao);
}
function cfg_set($db, $chave, $valor){
  $c = $db->real_escape_string($chave);
  $v = $db->real_escape_string((string)$valor);
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$c','$v')
              ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
}

/* Estado do aparelho: o app avisa que está vivo e recebe instruções.
   PÚBLICO como o parear — quem chama é o box na parede.

   Existe para separar duas coisas que hoje parecem iguais no painel:
   "a internet caiu" e "alguém desligou o box da tomada". A primeira se
   resolve sozinha; a segunda precisa de alguém indo até lá. */
if($acao === 'app_estado'){
  $ap = preg_replace('/[^A-Za-z0-9\-]/', '', (string)(isset($_GET['d']) ? $_GET['d'] : ''));
  if(strlen($ap) < 8) out(array('ok'=>false));

  $ape = $db->real_escape_string($ap);
  $ver   = (int)(isset($_GET['v']) ? $_GET['v'] : 0);
  $mem   = (int)(isset($_GET['mem']) ? $_GET['mem'] : 0);
  $disco = (int)(isset($_GET['disco']) ? $_GET['disco'] : 0);
  $usado = (int)(isset($_GET['usado']) ? $_GET['usado'] : 0);
  $tela  = preg_replace('/[^0-9x]/', '', (string)(isset($_GET['tela']) ? $_GET['tela'] : ''));
  $andr  = _cut(preg_replace('/[^0-9.]/', '', (string)(isset($_GET['android']) ? $_GET['android'] : '')), 0, 10);
  $mod   = _cut(trim((string)(isset($_GET['modelo']) ? $_GET['modelo'] : '')), 0, 80);

  $sql = "UPDATE tvi_aparelhos SET visto_em=NOW(), versao_app=$ver,
          tela='".$db->real_escape_string($tela)."', mem_livre=$mem,
          disco_livre=$disco, app_usado=$usado,
          android='".$db->real_escape_string($andr)."'";
  /* Só sobrescreve o modelo se veio preenchido: o vínculo já gravou um, e
     apagar por causa de uma batida sem o dado seria perder informação. */
  if($mod !== '') $sql .= ", modelo='".$db->real_escape_string($mod)."'";
  $sql .= " WHERE aparelho='$ape'";
  $db->query($sql);

  /* Versão nova disponível? O app compara e avisa na tela. */
  $vMin = (int)cfg_num($db, 'app_versao', 0);
  $urlApk = cfg_txt($db, 'app_url', '');

  /* Horário de reinício programado. Reiniciar de madrugada resolve a
     maior parte dos travamentos ANTES de virarem problema — é o que
     todo sistema de sinalização faz, e por isso mesmo. */
  $hora = cfg_txt($db, 'app_reinicio', '04:00');

  /* Janela de atualização forçada: enquanto ela vale, o aviso volta a
     aparecer mesmo que a pessoa já tenha dispensado antes. */
  $forcarAte = cfg_txt($db, 'app_forcar_ate', '');
  $forcando  = ($forcarAte !== '' && strtotime($forcarAte) > time());

  out(array('ok'=>true,
            'versao_nova' => ($vMin > $ver && $urlApk !== '') ? $vMin : 0,
            'apk'         => ($vMin > $ver) ? $urlApk : '',
            'forcar'      => ($forcando && $vMin > $ver) ? 1 : 0,
            'reinicio'    => $hora,
            'agora'       => date('Y-m-d H:i:s')));   // para o box corrigir o relógio
}

/* Diagnóstico de uma fonte de notícias.
   Existe porque "a imagem não aparece" tem cinco causas possíveis, e
   testar uma por uma no escuro é o que consome a tarde. Diz em uma tela:
   o servidor respondeu? o feed tem imagem? em qual formato? */
if($acao === 'feed_diag'){
  $url = trim((string)(isset($_GET['url']) ? $_GET['url'] : ''));
  if(!preg_match('#^https?://#i', $url)) out(array('ok'=>false,'erro'=>'endereço inválido'));

  $r = array('url'=>$url);

  $bruto = function_exists('tvi_http') ? tvi_http($url, array('timeout'=>12)) : null;
  $r['respondeu'] = ($bruto !== null && $bruto !== '');
  $r['tamanho']   = $bruto ? strlen($bruto) : 0;

  if(!$r['respondeu']){
    $r['diagnostico'] = 'O servidor do veículo não respondeu ou recusou. '
      . 'Alguns bloqueiam qualquer acesso que não seja de navegador.';
    out(array('ok'=>true,'diag'=>$r));
  }

  /* Onde a imagem estaria, se estivesse */
  $formatos = array(
    'enclosure'      => (int)(bool)preg_match('#<enclosure[^>]+url=#i', $bruto),
    'media:content'  => (int)(bool)preg_match('#<media:content[^>]+url=#i', $bruto),
    'media:thumbnail'=> (int)(bool)preg_match('#<media:thumbnail[^>]+url=#i', $bruto),
    'content:encoded'=> (int)(bool)(strpos($bruto, 'content:encoded') !== false),
    'img no html'    => (int)(bool)preg_match('#&lt;img|<img#i', $bruto),
  );
  $r['formatos'] = $formatos;
  $r['itens'] = preg_match_all('#<item[\s>]#i', $bruto);

  /* content:encoded NÃO é imagem: é o texto da notícia em HTML. Contar
     ele como "tem imagem" foi o que me fez dar diagnóstico errado — o
     feed da Jovem Pan tem content:encoded e nenhuma foto. */
  $temImagem = $formatos['enclosure'] || $formatos['media:content']
            || $formatos['media:thumbnail'] || $formatos['img no html'];

  if($temImagem){
    $r['diagnostico'] = 'O feed traz imagem. Se a peça está sem foto, o '
      . 'problema é o endereço da imagem não chegar à TV — o que o img.php resolve.';
  } elseif($formatos['content:encoded']){
    $r['diagnostico'] = 'O feed NÃO traz imagem em campo próprio, só o texto '
      . 'da notícia. A foto existe na página do veículo: o sistema busca a '
      . 'og:image de cada notícia, que é a mesma imagem que aparece quando '
      . 'alguém compartilha o link no WhatsApp.';
  } else {
    $r['diagnostico'] = 'O feed responde, mas não traz imagem nem texto em '
      . 'HTML. Só há manchete: não há foto para extrair.';
  }

  out(array('ok'=>true,'diag'=>$r));
}

if($acao === 'parear'){
  $ap = preg_replace('/[^A-Za-z0-9\-]/', '', (string)(isset($_GET['d']) ? $_GET['d'] : ''));
  if(strlen($ap) < 8) out(array('ok'=>false,'erro'=>'identificador inválido'));

  $modelo = _cut(trim((string)(isset($_GET['m']) ? $_GET['m'] : '')), 0, 80);
  $ape = $db->real_escape_string($ap);
  $me  = $db->real_escape_string($modelo);

  $r = $db->query("SELECT a.id, a.codigo, a.tv_id, t.codigo_curto curto, t.nome
                   FROM tvi_aparelhos a LEFT JOIN tvi_tvs t ON t.id = a.tv_id
                   WHERE a.aparelho='$ape' LIMIT 1");

  if($r && $r->num_rows){
    $a = $r->fetch_assoc();
    $db->query("UPDATE tvi_aparelhos SET visto_em=NOW() WHERE id=".(int)$a['id']);

    // Já vinculado: devolve o endereço e o aparelho para de perguntar.
    /* TV vinculada mas sem código curto: gera agora. Sem isto o aparelho
       ficava preso na tela do código para sempre, mesmo vinculado. */
    if(!empty($a['tv_id']) && empty($a['curto'])){
      $rc = $db->query("SELECT nome FROM tvi_tvs WHERE id=".(int)$a['tv_id']);
      if($rc && $rc->num_rows){
        $nm = $rc->fetch_assoc()['nome'];
        $novoC = codigo_curto($db, $nm);
        $ce3 = $db->real_escape_string($novoC);
        $db->query("UPDATE tvi_tvs SET codigo_curto='$ce3', ativo=1 WHERE id=".(int)$a['tv_id']);
        $a['curto'] = $novoC;
        $a['nome'] = $nm;
      }
    }

    if(!empty($a['tv_id']) && !empty($a['curto'])){
      /* Devolve o endereço com ?c=, não com PATH_INFO: essa forma
         funciona em qualquer configuração de servidor. */
      out(array('ok'=>true,'vinculado'=>true,
                'url'=>tvi_base_url().'/t.php?c='.$a['curto'],
                'tv'=>$a['nome']));
    }
    out(array('ok'=>true,'vinculado'=>false,'codigo'=>$a['codigo']));
  }

  /* Primeiro contato: gera um código curto. Sem 0/O e 1/I — a pessoa lê
     da parede e digita no painel, e esses pares são onde se erra. */
  $letras = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for($tent = 0; $tent < 12; $tent++){
    $cod = '';
    for($i = 0; $i < 6; $i++) $cod .= $letras[random_int(0, strlen($letras)-1)];
    $ce = $db->real_escape_string($cod);
    $existe = $db->query("SELECT id FROM tvi_aparelhos WHERE codigo='$ce' AND tv_id IS NULL");
    if(!$existe || !$existe->num_rows) break;
  }
  $db->query("INSERT INTO tvi_aparelhos (aparelho,codigo,modelo,visto_em)
              VALUES ('$ape','$ce','$me',NOW())");
  out(array('ok'=>true,'vinculado'=>false,'codigo'=>$cod));
}

if($acao === 'heartbeat' || $acao === 'manifest' || $acao === 'log'
   || $acao === 'erro' || $acao === 'captura'){
  $token = isset($_GET['t']) ? $_GET['t'] : '';
  $tv = tv_por_token($db, $token);
  if(!$tv) fail('Dispositivo não reconhecido.', 401);
  $id = (int)$tv['id'];

  /* Teto de requisições por token. O esperado é 2 batidas por minuto; 30
     dá folga enorme para retry e relógio torto. Serve para dois casos:
     player com defeito entrando em laço — que já derrubou signage por aí —
     e token vazado sendo usado para martelar o banco. Arquivo em vez de
     tabela: escrever no banco para controlar escrita no banco não ajuda. */
  $jan = sys_get_temp_dir().'/tvi_rl_'.md5($token).'_'.date('YmdHi');
  $n = (int)@file_get_contents($jan);
  if($n > 30){
    http_response_code(429);
    out(array('ok'=>false,'erro'=>'muitas_requisicoes'));
  }
  @file_put_contents($jan, $n + 1);
  if(random_int(1, 200) === 1){
    // Faxina barata: remove as janelas de minutos passados.
    foreach(glob(sys_get_temp_dir().'/tvi_rl_*') as $velho){
      if(filemtime($velho) < time() - 300) @unlink($velho);
    }
  }

  /* Quando um vídeo falha numa TV, o player engolia o erro e seguia a fila.
     A tela ficava pulando conteúdo em silêncio e o relatório mostrava menos
     exibições sem explicar por quê. Agora ele conta. */
  if($acao === 'erro'){
    $cod = isset($body['codigo']) ? preg_replace('/[^a-z_]/','', $body['codigo']) : 'desconhecido';
    $det = isset($body['detalhe']) ? substr($body['detalhe'], 0, 400) : null;
    $mid = isset($body['midia_id']) ? (int)$body['midia_id'] : null;

    // Um erro repetido na mesma TV e mídia atualiza a hora em vez de virar
    // mil linhas: um cartaz corrompido reclamaria a cada 10 segundos.
    $r = $db->query("SELECT id FROM tvi_erros WHERE tv_id=$id AND resolvido_em IS NULL
                     AND codigo='".$db->real_escape_string($cod)."'
                     AND ".($mid ? "midia_id=$mid" : "midia_id IS NULL")." LIMIT 1");
    if($r && $r->num_rows){
      $db->query("UPDATE tvi_erros SET ocorrido_em=NOW() WHERE id=".(int)$r->fetch_assoc()['id']);
    } else {
      $st = $db->prepare("INSERT INTO tvi_erros (tv_id,midia_id,codigo,detalhe,ocorrido_em)
                          VALUES (?,?,?,?,NOW())");
      $st->bind_param('iiss', $id, $mid, $cod, $det);
      $st->execute();
    }
    out(array('ok'=>true));
  }

  if($acao === 'captura'){
    $img = isset($body['imagem']) ? $body['imagem'] : '';
    // Só aceita data URL de imagem, e com teto. Sem isto o campo vira
    // depósito de qualquer coisa que caiba em 900 KB.
    if(!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $img)){
      fail('Formato de captura inválido.');
    }
    if(strlen($img) > 900000) fail('Imagem grande demais.');
    $st = $db->prepare("INSERT INTO tvi_capturas (tv_id,imagem,capturado_em) VALUES (?,?,NOW())
                        ON DUPLICATE KEY UPDATE imagem=VALUES(imagem), capturado_em=NOW()");
    $st->bind_param('is', $id, $img);
    $st->execute();
    out(array('ok'=>true));
  }

  if($acao === 'manifest'){
    out(montar_manifesto($db, $tv));
  }

  if($acao === 'log'){
    $ent = isset($body['entries']) ? $body['entries'] : array();
    /* INSERT em lote, não um por linha.
       O player acumula exibições e manda a cada 5 minutos: um lote de 30
       gerava 30 idas ao banco, cada uma com sua ida e volta na rede. Com
       dez telas isso são 3.000 escritas por hora onde 100 bastam.
       Junto num INSERT só, em blocos de 200 para não estourar o limite de
       tamanho do pacote do MySQL. */
    $linhas = array();
    foreach($ent as $e){
      $mid = isset($e['media_id']) && $e['media_id'] !== null ? (int)$e['media_id'] : 'NULL';
      $pid = isset($e['playlist_id']) && $e['playlist_id'] !== null ? (int)$e['playlist_id'] : 'NULL';
      $q   = $db->real_escape_string(isset($e['played_at']) ? substr($e['played_at'],0,19) : date('Y-m-d H:i:s'));
      $d   = isset($e['duration_ms']) ? (int)$e['duration_ms'] : 0;
      $c   = isset($e['completed']) ? (int)$e['completed'] : 1;
      $linhas[] = "($id,$mid,$pid,'$q',$d,$c)";
    }

    $gravados = 0;
    foreach(array_chunk($linhas, 200) as $bloco){
      if($db->query("INSERT INTO tvi_exibicoes
                     (tv_id,midia_id,playlist_id,exibido_em,duracao_ms,completo)
                     VALUES ".implode(',', $bloco))){
        $gravados += count($bloco);
      }
    }
    out(array('ok'=>true,'gravados'=>$gravados));
  }

  /* heartbeat */
  $agora   = date('Y-m-d H:i:s');
  $ip      = ip_cliente();

  /* ── Concessão de sessão ────────────────────────────────────
     Quem chega primeiro fica. O segundo recebe 'em_uso' e não reproduz —
     mas continua batendo, então assume sozinho assim que o primeiro sair
     do ar. Nenhuma intervenção necessária no caso normal. */
  $inst = isset($body['instancia']) ? substr(preg_replace('/[^a-z0-9]/i','', $body['instancia']), 0, 16) : '';
  if($inst !== ''){
    $dono   = $tv['sessao_id'];
    $desde  = $tv['sessao_em'] ? strtotime($tv['sessao_em']) : 0;
    $morta  = (time() - $desde) > SESSAO_TTL;

    /* O APLICATIVO tem prioridade sobre navegador.
       A trava de sessão única existe para dois motivos: evitar que duas
       janelas contem exibição em dobro no relatório, e impedir que um
       link vazado seja aberto por qualquer um.

       Nenhum dos dois se aplica ao aplicativo instalado no box: ele foi
       vinculado a esta TV pelo painel, e é a fonte legítima. Fazê-lo
       esperar alguém clicar em "Liberar" é o oposto do que se quer — a
       parede fica parada por causa de uma aba que alguém esqueceu aberta
       no computador.

       Um navegador que chegar depois continua sendo barrado. */
    $ehApp = (strpos((string)(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''),
                     'TVIndoorRedentor') !== false)
             || !empty($body['app']);

    if($dono && $dono !== $inst && !$morta && !$ehApp){
      out(array(
        'ok'        => false,
        'erro'      => 'em_uso',
        'desde'     => $tv['sessao_em'],
        'ip'        => $tv['sessao_ip'],
        'libera_em' => max(0, SESSAO_TTL - (time() - $desde)),
      ));
    }

    if($dono !== $inst){
      /* Identidade nova assumindo a vaga. Se já havia dono antes, isto é um
         reinício e vale registrar: quanto tempo ficou fora, e se voltou por
         conta própria ou porque alguém foi lá liberar a sessão no painel.
         Sem essa distinção o número engana: "voltou" com um humano no meio
         não é a mesma coisa que voltou sozinha. */
      if($dono && $tv['ultimo_sinal']){
        $fora = max(0, time() - strtotime($tv['ultimo_sinal']));
        $lib  = $tv['sessao_liberada_em'] ? strtotime($tv['sessao_liberada_em']) : 0;
        $sozinho = ($lib && $lib >= strtotime($tv['ultimo_sinal'])) ? 0 : 1;
        $st = $db->prepare("INSERT INTO tvi_reinicios (tv_id,ocorrido_em,fora_segundos,sozinho)
                            VALUES (?,?,?,?)");
        $st->bind_param('isii', $id, $agora, $fora, $sozinho);
        $st->execute();
      }
      $st = $db->prepare("UPDATE tvi_tvs SET sessao_id=?, sessao_ip=?, sessao_em=? WHERE id=?");
      $st->bind_param('sssi', $inst, $ip, $agora, $id);
      $st->execute();
      $marcarSessao = false;   // já gravado aqui: assumir a vaga é raro
    } else {
      /* Não grava aqui: a escrita de sessão vai junto com a de telemetria,
         logo abaixo. Eram dois UPDATE seguidos na MESMA linha — com dez
         telas, 57 mil escritas por dia onde 28 mil bastam. */
      $marcarSessao = true;
    }
  }
  $estado  = isset($body['status']) ? substr($body['status'],0,20) : 'playing';
  $ver     = isset($body['player_version']) ? substr($body['player_version'],0,20) : null;
  $res     = isset($body['screen']) ? substr($body['screen'],0,20) : null;
  $so      = isset($body['os']) ? substr($body['os'],0,80) : null;
  $mid     = isset($body['current_media_id']) ? (int)$body['current_media_id'] : null;
  $manif   = isset($body['manifest_version']) ? substr($body['manifest_version'],0,64) : null;

  /* Uma escrita só por batida. O sessao_em entra aqui quando a sessão já
     era desta instância, que é o caso de 99% das batidas. */
  $sess = !empty($marcarSessao) ? ', sessao_em=?' : '';
  $st = $db->prepare("UPDATE tvi_tvs SET ultimo_sinal=?, ultimo_ip=?, estado_player=?,
                        versao_player=?, resolucao=?, so=?, ultima_midia=?, versao_manifesto=?,
                        primeira_conexao=IFNULL(primeira_conexao,?)$sess
                      WHERE id=?");
  if($sess){
    $st->bind_param('ssssssisssi', $agora,$ip,$estado,$ver,$res,$so,$mid,$manif,$agora,$agora,$id);
  } else {
    $st->bind_param('ssssssissi', $agora,$ip,$estado,$ver,$res,$so,$mid,$manif,$agora,$id);
  }
  $st->execute();

  // Contador por hora, para o gráfico de disponibilidade.
  $hora = date('Y-m-d H:00:00');
  $db->query("INSERT INTO tvi_sinal_hora (tv_id,hora,batidas) VALUES ($id,'$hora',1)
              ON DUPLICATE KEY UPDATE batidas=batidas+1");

  $cmds = array();
  $r = $db->query("SELECT id,tipo,carga FROM tvi_comandos
                   WHERE tv_id=$id AND confirmado_em IS NULL ORDER BY id LIMIT 5");
  $entregues = array();
  while($r && $c = $r->fetch_assoc()){
    $cmds[] = array('id'=>(int)$c['id'],'type'=>$c['tipo'],
                    'payload'=>$c['carga'] ? json_decode($c['carga'], true) : null);
    $entregues[] = (int)$c['id'];
  }
  /* Uma escrita para todos, em vez de uma por comando. Um "Recarregar TVs"
     em dez telas gerava dez UPDATE; agora gera um por TV. */
  if($entregues){
    $db->query("UPDATE tvi_comandos SET entregue_em=NOW(), confirmado_em=NOW()
                WHERE id IN (".implode(',', $entregues).")");
  }

  out(array(
    'ok'               => true,
    'server_time'      => time(),
    'manifest_version' => versao_manifesto($db, $tv),
    'commands'         => $cmds,
    'rollout_seconds'  => 60,
  ));
}

/* Auxiliares usadas tanto pelo webhook (que roda ANTES do login) quanto
   pelo painel. Ficam aqui em cima justamente por isso: PHP só enxerga a
   função depois de a definição ter sido executada, e o webhook sai da
   execução antes de chegar ao meio do arquivo. */
/* Qualquer mudança na agenda precisa chegar à parede sem esperar. */
function agenda_tocar($db){
  $r = $db->query("SELECT DISTINCT i.playlist_id p FROM tvi_itens i
                   JOIN tvi_midias m ON m.id=i.midia_id
                   WHERE m.url_externa LIKE '%tipo=agenda%'");
  while($r && $x = $r->fetch_assoc()) toca_playlist($db, $x['p']);
}

function salvar_fontes($db, $lista){
  $j = $db->real_escape_string(json_encode(array_values($lista), JSON_UNESCAPED_UNICODE));
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('fontes_proprias','$j')
              ON DUPLICATE KEY UPDATE valor='$j'");
}

function base_url(){
  $p = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  return $p.'://'.$_SERVER['HTTP_HOST'];
}

function nova_versao(){ return hash('sha256', uniqid('', true)); }
function toca_playlist($db, $id){
  $v = nova_versao();
  $db->query("UPDATE tvi_playlists SET versao='$v' WHERE id=".(int)$id);
}

/* ══════════════ WEBHOOK PARA AUTOMAÇÃO ══════════════
   Fica ANTES do portão de sessão: o n8n não faz login. Autentica por
   chave própria, que não é o token de nenhuma TV nem a senha de ninguém.

   COMO O "SÓ MOSTRA QUANDO TEM" FUNCIONA
   Sem inventar mecanismo novo: o webhook grava a lista E marca a mídia
   como válida SÓ PARA HOJE. A regra de validade já existe, já colapsa no
   manifesto e o player já a aplica offline. Amanhã, sem receber nada, a
   data de ontem vence sozinha e o item some da rotação. Nenhuma tela
   precisa ser tocada, e nenhuma TV mostra lista vazia. */

/* Lê a mensagem que a automação já monta para o grupo.
   O formato real do WhatsApp é assim:

     🎉 *Aniversariantes do dia 31/07/2026* 📅
     🎈 *Alexandre Radtke*
     💼 Diretor Administrativo
     ...
     ✨ Desejamos um feliz aniversário! 🎂

   Ou seja: emoji na frente, negrito com asterisco marcando o NOME, a linha
   seguinte sem asterisco sendo o CARGO, e cabeçalho e rodapé para descartar.
   Ler isso ingenuamente linha a linha produziria "Diretor Administrativo"
   como aniversariante. */

function _limpa_linha($l){
  /* Tira emoji, símbolo e a marcação de negrito do WhatsApp.
     O modificador /u exige PCRE com suporte a unicode. Existe em toda
     hospedagem moderna, mas se faltar o preg_replace devolve null e a
     linha sumiria: por isso o retorno é conferido. */
  $sem = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2190}-\x{2BFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}]/u', ' ', $l);
  if($sem !== null) $l = $sem;
  $l = str_replace(array('*', '_', '~', '`'), '', $l);
  $sem = preg_replace('/\s+/u', ' ', $l);
  $l = ($sem !== null) ? $sem : preg_replace('/\s+/', ' ', $l);
  return trim($l, " \t\r\n-•·–—:|");
}

function ler_mensagem($texto){
  $t = str_replace(array('<br>','<br/>','<br />','</p>','</li>','\\n'), "\n", $texto);
  $t = strip_tags(html_entity_decode($t, ENT_QUOTES, 'UTF-8'));
  $brutas = preg_split('/[\r\n]+/u', $t);

  // Descartáveis: cabeçalho, rodapé e linha só com data.
  $lixo = '/(aniversariant|parab|desejamos|feliz\s|equipe|bom\s*dia|recursos\s*humanos|^rh$)/iu';

  // Se ALGUMA linha usa negrito, o negrito é que marca os nomes.
  $temNegrito = false;
  foreach($brutas as $b){
    if(substr_count($b, '*') >= 2 && !preg_match($lixo, _limpa_linha($b))) { $temNegrito = true; break; }
  }

  $saida = array();
  foreach($brutas as $b){
    $negrito = (substr_count($b, '*') >= 2);
    $l = _limpa_linha($b);
    if($l === '' || _len($l) < 3) continue;
    if(preg_match($lixo, $l)) continue;
    if(preg_match('#^\d{1,2}[/-]\d{1,2}([/-]\d{2,4})?$#', $l)) continue;

    if(!$temNegrito){
      $saida[] = array('nome' => _cut($l, 0, 60), 'cargo' => '');
      continue;
    }
    if($negrito){
      $saida[] = array('nome' => _cut($l, 0, 60), 'cargo' => '');
    } elseif($saida){
      // Linha sem negrito logo abaixo de um nome: é o cargo dele.
      $i = count($saida) - 1;
      if($saida[$i]['cargo'] === '') $saida[$i]['cargo'] = _cut($l, 0, 44);
    }
  }
  return $saida;
}

/* ── Webhook do Instagram ──────────────────────────────────────
   Mesmo padrão do webhook de aniversariantes, que já está rodando aqui.

   Por que assim: o Instagram exige autenticação para entregar posts, e
   fazer essa autenticação DENTRO deste sistema significaria manter token,
   renovação e app da Meta só para isso. O n8n já está instalado, já roda
   todo dia, e já sabe autenticar em serviço externo. Ele busca e empurra
   para cá — que é exatamente o desenho do fluxo de aniversariantes que
   você já validou.

   Aceita as formas que uma automação naturalmente produz:
     { "chave":"...", "posts":[ {"img":"https://...","legenda":"..."} ] }
     { "chave":"...", "img":"https://...", "legenda":"..." }   (um por vez)
   No segundo formato, cada chamada empilha e as 2 mais recentes vão ao ar. */

if($acao === 'hook_instagram'){
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='webhook_chave'");
  $chave = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  $env = isset($_GET['k']) ? $_GET['k'] : (isset($body['chave']) ? $body['chave'] : '');

  if($chave === '') fail('Webhook não configurado. Gere a chave no painel.', 403);
  if(!hash_equals($chave, (string)$env)) fail('Chave inválida.', 403);

  // Normaliza as duas formas de entrada num vetor só.
  $entrada = array();
  if(!empty($body['posts']) && is_array($body['posts'])){
    $entrada = $body['posts'];
  } elseif(!empty($body['img']) || !empty($body['imagem']) || !empty($body['media_url'])){
    $entrada = array($body);
  }

  $novos = array();
  foreach($entrada as $x){
    if(!is_array($x)) continue;
    // Nomes diferentes conforme a origem: aceita todos.
    $img = '';
    foreach(array('img','imagem','media_url','thumbnail_url','image','url') as $c){
      if(!empty($x[$c]) && preg_match('#^https?://#i', $x[$c])){ $img = $x[$c]; break; }
    }
    if($img === '') continue;

    $leg = '';
    foreach(array('legenda','caption','texto','title','descricao') as $c){
      if(!empty($x[$c])){ $leg = (string)$x[$c]; break; }
    }
    $leg = trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($leg, ENT_QUOTES, 'UTF-8'))));

    // Data: aceita ISO ou vazio.
    $em = '';
    foreach(array('data','timestamp','pubDate','em') as $c){
      if(!empty($x[$c])){ $t = strtotime($x[$c]); if($t) $em = date('Y-m-d H:i:s', $t); break; }
    }

    $novos[] = array(
      'id'      => crc32($img),          // mesma imagem não duplica
      'img'     => $img,
      'legenda' => _cut($leg, 0, 180),
      'link'    => (!empty($x['permalink']) && preg_match('#^https?://#i', $x['permalink'])) ? $x['permalink'] : '',
      'em'      => $em ?: date('Y-m-d H:i:s'),
      'origem'  => 'webhook',
    );
  }

  if(!$novos) fail('Nenhuma imagem válida no corpo. Envie "img" com um endereço http.');

  /* Substituição x acúmulo: mandando a lista pronta, ela troca tudo, que é
     o certo para um fluxo que roda de hora em hora. Mandando um por vez, o
     novo entra na frente e o resto desce. */
  if(!empty($body['posts'])){
    $lista = $novos;
  } else {
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='insta_manual'");
    $lista = ($r && $r->num_rows) ? json_decode($r->fetch_assoc()['valor'], true) : array();
    if(!is_array($lista)) $lista = array();
    foreach($novos as $n){
      $ja = false;
      foreach($lista as $x) if ((int)$x['id'] === (int)$n['id']) $ja = true;
      if(!$ja) array_unshift($lista, $n);
    }
  }
  $lista = array_slice($lista, 0, 6);

  $j = $db->real_escape_string(json_encode(array_values($lista), JSON_UNESCAPED_UNICODE));
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('insta_manual','$j')
              ON DUPLICATE KEY UPDATE valor='$j'");
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('insta_hook_em','".date('Y-m-d H:i:s')."')
              ON DUPLICATE KEY UPDATE valor='".date('Y-m-d H:i:s')."'");

  out(array('ok'=>true,'recebidos'=>count($novos),'no_ar'=>min(2, count($lista)),
            'total_guardado'=>count($lista)));
}

if($acao === 'hook_aniversarios'){
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='webhook_chave'");
  $chave = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  $env = isset($_GET['k']) ? $_GET['k'] : (isset($body['chave']) ? $body['chave'] : '');

  if($chave === '' ) fail('Webhook não configurado. Gere a chave no painel.', 403);
  if(!hash_equals($chave, (string)$env)) fail('Chave inválida.', 403);

  /* Aceita as duas formas que uma automação naturalmente manda: uma lista
     pronta, ou um bloco de texto igual ao da mensagem do grupo. */
  $nomes = array();

  if(!empty($body['nomes']) && is_array($body['nomes'])){
    foreach($body['nomes'] as $n){
      if(is_array($n)){
        $nm = trim(strip_tags((string)(isset($n['nome']) ? $n['nome'] : '')));
        $cg = trim(strip_tags((string)(isset($n['cargo']) ? $n['cargo'] : '')));
      } else { $nm = trim(strip_tags((string)$n)); $cg = ''; }
      if($nm !== '') $nomes[] = array('nome' => _cut($nm, 0, 60), 'cargo' => _cut($cg, 0, 44));
    }
  } elseif(isset($body['texto'])){
    $nomes = ler_mensagem((string)$body['texto']);
  }

  $hoje = date('Y-m-d');
  $limpo = array();
  $vistos = array();
  foreach(array_slice($nomes, 0, 24) as $n){
    // Sem mbstring, strtolower ainda serve para comparar duplicata.
    $ch = function_exists('mb_strtolower') ? mb_strtolower($n['nome'], 'UTF-8') : strtolower($n['nome']);
    if(isset($vistos[$ch])) continue;
    $vistos[$ch] = 1;
    $limpo[] = $n;
  }

  $st = $db->prepare("INSERT INTO tvi_config (chave,valor) VALUES ('aniversarios_hoje',?)
                      ON DUPLICATE KEY UPDATE valor=VALUES(valor)");
  $payload = json_encode(array('data'=>$hoje,'nomes'=>$limpo,
                               'recebido_em'=>date('Y-m-d H:i:s')), JSON_UNESCAPED_UNICODE);
  $st->bind_param('s', $payload);
  $st->execute();

  // A mídia da extensão é criada na primeira chamada e reaproveitada depois.
  $url = base_url().'/widget.php?tipo=aniversarios&modo=hoje';
  $ue  = $db->real_escape_string($url);
  $r = $db->query("SELECT id FROM tvi_midias WHERE url_externa='$ue' LIMIT 1");
  if($r && $r->num_rows){
    $mid = (int)$r->fetch_assoc()['id'];
  } else {
    $st = $db->prepare("INSERT INTO tvi_midias (nome,tipo,url_externa,duracao_padrao_ms,enviado_por,aprovado)
                        VALUES ('Aniversariantes de hoje','web',?,20000,'automação',1)");
    $st->bind_param('s', $url);
    $st->execute();
    $mid = $db->insert_id;
  }

  /* Válido só hoje, e só quando veio gente. Lista vazia zera a validade,
     então o item some no mesmo instante. */
  if($limpo){
    $db->query("UPDATE tvi_midias SET valido_de='$hoje', valido_ate='$hoje' WHERE id=$mid");
  } else {
    $db->query("UPDATE tvi_midias SET valido_de=NULL, valido_ate='1970-01-01' WHERE id=$mid");
  }

  // Toca as listas que usam este item, para as TVs recalcularem na batida.
  $r = $db->query("SELECT DISTINCT playlist_id FROM tvi_itens WHERE midia_id=$mid");
  $listas = 0;
  while($r && $x = $r->fetch_assoc()){ toca_playlist($db, $x['playlist_id']); $listas++; }

  out(array('ok'=>true, 'data'=>$hoje, 'nomes'=>count($limpo),
            'midia_id'=>$mid, 'listas_atualizadas'=>$listas,
            'aviso'=>$listas ? null : 'A extensão ainda não está em nenhuma lista de reprodução.'));
}

/* ── Instagram: busca, cache e renovação ───────────────────────
   Três coisas que separam isto de um fetch simples:

   1. CACHE. Dez TVs pedindo de 5 em 5 minutos dariam 2.880 chamadas por
      dia e o Instagram limitaria a conta. Uma busca serve todas.

   2. ÚLTIMA RESPOSTA BOA. Se a API cair, o painel devolve o que tinha em
      vez de vazio. Numa parede, conteúdo velho é melhor que buraco.

   3. RENOVAÇÃO DO TOKEN. O de longa duração vale 60 dias. Sem renovar,
      a parede simplesmente para num dia qualquer e ninguém liga uma coisa
      à outra. A renovação acontece sozinha quando faltam menos de 10 dias. */

/* Cache simples em tabela. O widget tem funções iguais, mas cada arquivo
   roda sozinho: duplicar dez linhas é melhor que criar um include que
   precisa existir nos dois lugares para qualquer um funcionar. */
/* Delegam para comum.php. A implementação local fica como reserva. */
function insta_cache_get($db, $chave, $ttl){
  if(function_exists('tvi_cache_ler')) return tvi_cache_ler($db, $chave, $ttl);
  return _insta_cache_get_local($db, $chave, $ttl);
}
function _insta_cache_get_local($db, $chave, $ttl){
  $c = $db->real_escape_string($chave);
  $r = $db->query("SELECT valor, atualizado_em FROM tvi_cache WHERE chave='$c' LIMIT 1");
  if(!$r || !$r->num_rows) return null;
  $x = $r->fetch_assoc();
  $d = json_decode($x['valor'], true);
  if($d === null) return null;
  return array('dados' => $d, 'velho' => (time() - strtotime($x['atualizado_em'])) > $ttl);
}

function insta_cache_set($db, $chave, $dados){
  if(function_exists('tvi_cache_gravar')) return tvi_cache_gravar($db, $chave, $dados);
  return _insta_cache_set_local($db, $chave, $dados);
}
function _insta_cache_set_local($db, $chave, $dados){
  $c = $db->real_escape_string($chave);
  $v = $db->real_escape_string(json_encode($dados, JSON_UNESCAPED_UNICODE));
  $db->query("INSERT INTO tvi_cache (chave,valor,atualizado_em) VALUES ('$c','$v',NOW())
              ON DUPLICATE KEY UPDATE valor='$v', atualizado_em=NOW()");
}

function insta_buscar($url, $segundos = 10){
  if(function_exists('tvi_http')) return tvi_http($url, array('timeout'=>$segundos));
  return _insta_buscar_local($url, $segundos);
}
function _insta_buscar_local($url, $segundos = 10){
  if(function_exists('curl_init')){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER => 1, CURLOPT_TIMEOUT => $segundos,
      CURLOPT_SSL_VERIFYPEER => 1, CURLOPT_USERAGENT => 'TVIndoor/1.0'));
    $r = curl_exec($ch);
    curl_close($ch);
    return $r ?: null;
  }
  $ctx = stream_context_create(array('http' => array('timeout' => $segundos, 'ignore_errors' => true)));
  return @file_get_contents($url, false, $ctx) ?: null;
}

function insta_cfg($db, $k){
  $ke = $db->real_escape_string($k);
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$ke'");
  return ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
}

function insta_set($db, $k, $v){
  $ke = $db->real_escape_string($k);
  $ve = $db->real_escape_string((string)$v);
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$ke','$ve')
              ON DUPLICATE KEY UPDATE valor='$ve'");
}

/* Renova antes de vencer. Chamada a cada busca: é uma comparação de datas,
   não custa nada, e evita o cenário em que a parede morre calada. */
function insta_renovar($db, $token){
  $exp = insta_cfg($db, 'insta_expira');
  if(!$exp) return $token;
  $faltam = (strtotime($exp) - time()) / 86400;
  if($faltam > 10) return $token;

  $r = insta_buscar('https://graph.facebook.com/v21.0/oauth/access_token'
                   .'?grant_type=fb_exchange_token'
                   .'&client_id='.rawurlencode(insta_cfg($db, 'insta_app_id'))
                   .'&client_secret='.rawurlencode(insta_cfg($db, 'insta_app_secret'))
                   .'&fb_exchange_token='.rawurlencode($token));
  $d = $r ? json_decode($r, true) : null;
  if(!empty($d['access_token'])){
    insta_set($db, 'insta_token', $d['access_token']);
    insta_set($db, 'insta_expira', date('Y-m-d', time() + (int)(isset($d['expires_in']) ? $d['expires_in'] : 5184000)));
    return $d['access_token'];
  }
  return $token;
}

/* ── Busca automática só com o @ ───────────────────────────────
   O Instagram não publica uma API aberta para isso. O que existe é a
   página pública do perfil, que às vezes traz os posts embutidos no HTML e
   às vezes exige login — depende do perfil, da região e do humor deles
   naquele dia. Então esta função é BEST EFFORT de verdade:

     - tenta várias formas de extrair, em ordem
     - guarda o resultado por 1 hora (bater muito é o que faz bloquear)
     - quando falha, NÃO limpa nada: cai para a última resposta boa, depois
       para os posts cadastrados à mão, depois para o convite

   Ou seja: pode parar de funcionar num dia qualquer, sem aviso, e nesse
   dia a parede continua no ar. Essa é a diferença entre depender disto e
   ser quebrado por isto. */

function insta_extrair_publico($arroba){
  $url = 'https://www.instagram.com/'.rawurlencode($arroba).'/';
  $html = insta_buscar($url, 12);
  if(!$html || strlen($html) < 500) return array();

  $achados = array();

  /* 1. Blocos JSON embutidos na página. É onde os dados ficam hoje, e o
        formato muda sem aviso — por isso procuro pelos NOMES dos campos e
        não pela estrutura, que é o que sobrevive a reformulação. */
  if(preg_match_all('#"display_url"\s*:\s*"([^"]+)"#', $html, $m)){
    foreach($m[1] as $u) $achados[] = array('img' => insta_deses($u), 'legenda' => '');
  }
  if(!$achados && preg_match_all('#"thumbnail_src"\s*:\s*"([^"]+)"#', $html, $m)){
    foreach($m[1] as $u) $achados[] = array('img' => insta_deses($u), 'legenda' => '');
  }
  if(!$achados && preg_match_all('#"image_versions2".{0,600}?"url"\s*:\s*"(https?:[^"]+)"#s', $html, $m)){
    foreach($m[1] as $u) $achados[] = array('img' => insta_deses($u), 'legenda' => '');
  }

  // Legendas, quando vierem, na mesma ordem das imagens.
  $legs = array();
  if(preg_match_all('#"edge_media_to_caption".{0,200}?"text"\s*:\s*"([^"]*)"#s', $html, $m)){
    foreach($m[1] as $t) $legs[] = insta_deses($t);
  } elseif(preg_match_all('#"caption"\s*:\s*\{.{0,400}?"text"\s*:\s*"([^"]*)"#s', $html, $m)){
    foreach($m[1] as $t) $legs[] = insta_deses($t);
  }
  foreach($achados as $k => $x){
    if(isset($legs[$k])) $achados[$k]['legenda'] = $legs[$k];
  }

  /* 2. Se nada saiu, a og:image da própria página ainda dá UMA imagem: a
        foto do perfil ou o último post, conforme o caso. Melhor que nada
        numa parede, e é o mesmo mecanismo que o WhatsApp usa. */
  $ogFallback = '';
  if(preg_match('#<meta[^>]+property=["\']og:image["\'][^>]*content=["\']([^"\']+)["\']#i', $html, $m)){
    $ogFallback = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
  }
  if(!$achados && $ogFallback !== ''){
    $achados[] = array('img' => $ogFallback, 'legenda' => '');
  }

  /* Descarta ícone e avatar, que aparecem misturados no HTML. O filtro roda
     ANTES do plano B da og:image: se sobrar nada dos posts, a og ainda vale,
     porque numa parede uma imagem da conta é melhor que espaço vazio. */
  $limpos = array();
  foreach($achados as $x){
    if(!preg_match('#^https?://#i', $x['img'])) continue;
    if(preg_match('#(s150x150|s320x320|/rsrc\.php/|sprite)#i', $x['img'])) continue;
    $limpos[] = $x;
    if(count($limpos) >= 3) break;
  }
  return $limpos;
}

/* O JSON da página vem com \u00e1 e \/ escapados. */
function insta_deses($t){
  $t = str_replace('\\/', '/', $t);
  $t = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($m){
    return html_entity_decode('&#'.hexdec($m[1]).';', ENT_QUOTES, 'UTF-8');
  }, $t);
  return trim(preg_replace('/\s+/u', ' ', $t));
}

function insta_posts($db, $forcar = false){
  $token = insta_cfg($db, 'insta_token');
  $uid   = insta_cfg($db, 'insta_user_id');
  if($token === '' || $uid === ''){
    return array('erro' => 'Instagram ainda não configurado. Vá em Configurações e cole o token.');
  }

  $chave = 'insta:'.$uid;
  if(!$forcar){
    $c = insta_cache_get($db, $chave, 600);    // 10 minutos
    if($c && !$c['velho']) return array('posts' => $c['dados']);
  }

  $token = insta_renovar($db, $token);

  $u = 'https://graph.facebook.com/v21.0/'.rawurlencode($uid).'/media'
     . '?fields=id,caption,media_type,media_url,thumbnail_url,permalink,timestamp'
     . '&limit=6&access_token='.rawurlencode($token);
  $r = insta_buscar($u);
  $d = $r ? json_decode($r, true) : null;

  if(isset($d['error'])){
    $msg = isset($d['error']['message']) ? $d['error']['message'] : 'erro desconhecido';
    insta_set($db, 'insta_ultimo_erro', date('Y-m-d H:i').' — '._cut($msg, 0, 160));
    $c = insta_cache_get($db, $chave, 999999);
    // Vale o conteúdo velho: parede com post de ontem é melhor que buraco.
    if($c) return array('posts' => $c['dados'], 'aviso' => $msg);
    return array('erro' => $msg);
  }

  if(!isset($d['data'])){
    $c = insta_cache_get($db, $chave, 999999);
    if($c) return array('posts' => $c['dados'], 'aviso' => 'Sem resposta da API agora.');
    return array('erro' => 'Não consegui falar com o Instagram.');
  }

  $posts = array();
  foreach($d['data'] as $m){
    $tipo = isset($m['media_type']) ? $m['media_type'] : 'IMAGE';
    // Vídeo não roda bem em parede sem som: usa a miniatura.
    $img = ($tipo === 'VIDEO' && !empty($m['thumbnail_url'])) ? $m['thumbnail_url']
         : (isset($m['media_url']) ? $m['media_url'] : '');
    if($img === '') continue;

    $ts = isset($m['timestamp']) ? strtotime($m['timestamp']) : 0;
    $h  = $ts ? time() - $ts : 0;
    $quando = !$ts ? '' : ($h < 3600 ? max(1,(int)($h/60)).' min'
            : ($h < 86400 ? (int)($h/3600).'h'
            : ((int)($h/86400) < 7 ? (int)($h/86400).'d' : date('d/m', $ts))));

    $posts[] = array(
      'img' => $img, 'tipo' => $tipo, 'quando' => $quando,
      'legenda' => _cut(trim(preg_replace('/\s+/u', ' ', isset($m['caption']) ? $m['caption'] : '')), 0, 150),
    );
    if(count($posts) >= 3) break;              // as 3 últimas
  }

  if($posts){
    insta_cache_set($db, $chave, $posts);
    insta_set($db, 'insta_ultimo_erro', '');
  }
  return array('posts' => $posts);
}


/* Rota pública dos posts. Fica antes do portão porque quem chama é a TV,
   que não tem login. Devolve só o que vai para a tela: imagem, tipo,
   quando e legenda. Nunca o token. */
if($acao === 'insta_publico'){
  $d = insta_posts($db);

  /* Sem token, tenta a busca pública pelo @. Cache de 1 hora: post do
     Instagram não muda de minuto em minuto, e bater pouco é o que evita
     ser bloqueado. */
  if((isset($d['erro']) || empty($d['posts']))){
    $arroba = insta_cfg($db, 'insta_arroba');
    if($arroba !== ''){
      $chave = 'instapub:'.$arroba;
      $c = insta_cache_get($db, $chave, 3600);
      $lista = ($c && !$c['velho']) ? $c['dados'] : null;

      if($lista === null){
        $achados = insta_extrair_publico($arroba);
        if($achados){
          $lista = array();
          foreach($achados as $x){
            $lista[] = array('img' => $x['img'], 'tipo' => 'IMAGE', 'quando' => '',
                             'legenda' => _cut($x['legenda'], 0, 180));
          }
          insta_cache_set($db, $chave, $lista);
          insta_set($db, 'insta_pub_em', date('Y-m-d H:i:s'));
          insta_set($db, 'insta_pub_erro', '');
        } else {
          insta_set($db, 'insta_pub_erro', date('Y-m-d H:i').' — o Instagram não entregou os posts');
          // Última resposta boa, de qualquer idade: parede não fica sem conteúdo.
          $c = insta_cache_get($db, $chave, 999999);
          if($c) $lista = $c['dados'];
        }
      }

      if($lista) out(array('ok'=>true,'posts'=>array_slice($lista, 0, 3),'origem'=>'publico'));
    }
  }

  /* Sem API, ou com API falhando, valem os posts colocados à mão. A parede
     não fica sem conteúdo por causa de burocracia de token. */
  if(isset($d['erro']) || empty($d['posts'])){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='insta_manual'");
    $man = ($r && $r->num_rows) ? json_decode($r->fetch_assoc()['valor'], true) : array();
    if(is_array($man) && $man){
      $posts = array();
      foreach(array_slice($man, 0, 3) as $x){
        $h = time() - strtotime($x['em']);
        $posts[] = array(
          'img' => $x['img'], 'tipo' => 'IMAGE',
          'quando' => $h < 86400 ? (max(1,(int)($h/3600)).'h')
                    : ((int)($h/86400) < 7 ? (int)($h/86400).'d' : date('d/m', strtotime($x['em']))),
          'legenda' => $x['legenda'],
        );
      }
      out(array('ok'=>true,'posts'=>$posts,'origem'=>'manual'));
    }
  }

  if(isset($d['erro'])) out(array('ok'=>false,'erro'=>$d['erro']));
  out(array('ok'=>true,'posts'=>$d['posts'],'origem'=>'api',
            'aviso'=>isset($d['aviso']) ? $d['aviso'] : null));
}

/* ══════════════ PORTÃO DE SESSÃO (painel) ══════════════ */

if(session_status() !== PHP_SESSION_ACTIVE){
  $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
  if(PHP_VERSION_ID >= 70300){
    session_set_cookie_params(array('lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$secure,'samesite'=>'Lax'));
  } else {
    session_set_cookie_params(0,'/','',$secure,true);
  }
  session_start();
}
if(empty($_SESSION['uid'])) fail('Sessão expirada. Entre novamente.');
$usuario = isset($_SESSION['username']) ? $_SESSION['username'] : 'usuario';

/* ── Quem pode fazer ações de alto impacto ────────────────────
   Antes, qualquer pessoa com login no Hub e acesso ao card do TV Indoor
   podia excluir uma TV, apagar uma playlist inteira, disparar comunicado
   urgente pra todas as telas, zerar a chave de segurança dos webhooks ou
   mudar a configuração geral — o mesmo nível de acesso de quem só queria
   subir uma mídia no dia a dia. Isso protege só as ações que causam
   estrago real ou afetam todas as TVs de uma vez; cadastrar TV, montar
   playlist e subir mídia continuam abertos pra qualquer usuário do
   módulo, porque é o trabalho normal de quem usa a ferramenta.
   Mesmo padrão já usado em pode_agenda(): admin sempre pode; os demais,
   só com a permissão 'tvindoor_admin' ligada em Configurações do Hub. */
function pode_admin_tv($db){
  $uid = (int)$_SESSION['uid'];
  $r = $db->query("SELECT role, perms_json FROM portal_usuarios WHERE id=$uid LIMIT 1");
  if(!$r || !$r->num_rows) return false;
  $u = $r->fetch_assoc();
  if(isset($u['role']) && $u['role'] === 'admin') return true;
  $p = !empty($u['perms_json']) ? json_decode($u['perms_json'], true) : array();
  return is_array($p) && !empty($p['tvindoor_admin']);
}
$PODE_ADMIN_TV = array('tv_excluir','recarregar_todas','urgente','urgente_encerrar',
  'config_salvar','seguranca_zerar','webhook_chave','grupo_excluir','aparelho_excluir',
  'playlist_excluir','banco_limpar');
if(in_array($acao, $PODE_ADMIN_TV, true) && !pode_admin_tv($db)){
  fail('Apenas administradores podem fazer isso. Fale com quem administra o Hub para liberar essa permissão.');
}

/* ── Quem pode mexer na agenda ────────────────────────────────
   A agenda é da contabilidade: quem não é do setor pode VER na TV, mas
   não deve lançar nem apagar vencimento fiscal.

   Aproveito o perms_json que o Hub já mantém por usuário, em vez de criar
   cadastro paralelo. A chave é 'tvindoor_agenda'. Quem administra o Hub
   liga para as pessoas da contabilidade em Configurações do portal.

   Padrão: administrador do Hub sempre pode. Os demais, só com a chave
   ligada. É o inverso do padrão dos outros recursos, e de propósito —
   com dado fiscal, o silêncio deve significar "não". */
function pode_agenda($db){
  $uid = (int)$_SESSION['uid'];
  $r = $db->query("SELECT role, perms_json FROM portal_usuarios WHERE id=$uid LIMIT 1");
  if(!$r || !$r->num_rows) return false;
  $u = $r->fetch_assoc();

  if(isset($u['role']) && $u['role'] === 'admin') return true;

  $p = !empty($u['perms_json']) ? json_decode($u['perms_json'], true) : array();
  return is_array($p) && !empty($p['tvindoor_agenda']);
}

/* Alfabeto sem 0/O e 1/I/L: quem digita isso está olhando para uma TV a três
   metros, com um controle remoto na mão. Ambiguidade custa uma ligação. */
define('ALFABETO', 'ABCDEFGHJKMNPQRSTUVWXYZ23456789');

function prefixo_de($nome){
  $n = strtoupper(iconv('UTF-8','ASCII//TRANSLIT', $nome));
  $n = preg_replace('/[^A-Z]/', '', $n);
  if(strlen($n) >= 2){
    // Iniciais reconhecíveis: "Refeitório" vira RF, "Terminal Central" vira TC.
    $partes = preg_split('/\s+/', trim(strtoupper(iconv('UTF-8','ASCII//TRANSLIT', $nome))));
    if(count($partes) >= 2 && strlen($partes[0]) && strlen($partes[1]))
      return substr($partes[0],0,1).substr($partes[1],0,1);
    return substr($n, 0, 2);
  }
  return 'TV';
}

function codigo_curto($db, $nome){
  $pre = prefixo_de($nome);
  for($tentativa = 0; $tentativa < 40; $tentativa++){
    $s = $pre;
    for($i = 0; $i < 4; $i++) $s .= ALFABETO[random_int(0, strlen(ALFABETO)-1)];
    $e = $db->real_escape_string($s);
    $r = $db->query("SELECT id FROM tvi_tvs WHERE codigo_curto='$e' LIMIT 1");
    if(!$r || !$r->num_rows) return $s;
  }
  return 'TV'.substr(str_shuffle(ALFABETO), 0, 4);
}

function criar_tv($db, $nome, $extra = array()){
  $nome = trim($nome);
  if($nome === '') return null;

  $r = $db->query("SELECT COALESCE(MAX(id),0)+1 n FROM tvi_tvs");
  $n = $r ? (int)$r->fetch_assoc()['n'] : 1;
  $codigo = 'TV'.str_pad($n, 4, '0', STR_PAD_LEFT);

  // Colisão de código é possível se alguém apagou uma TV do meio. Anda até achar.
  while(true){
    $e = $db->real_escape_string($codigo);
    $c = $db->query("SELECT id FROM tvi_tvs WHERE codigo='$e' LIMIT 1");
    if(!$c || !$c->num_rows) break;
    $n++; $codigo = 'TV'.str_pad($n, 4, '0', STR_PAD_LEFT);
  }

  $token = 'tk_'.bin2hex(random_bytes(18));
  $curto = codigo_curto($db, $nome);

  $st = $db->prepare("INSERT INTO tvi_tvs (codigo,codigo_curto,nome,token,grupo_id,local,cidade,uf)
                      VALUES (?,?,?,?,?,?,?,?)");
  $g  = !empty($extra['grupo_id']) ? (int)$extra['grupo_id'] : null;
  $lo = isset($extra['local'])  ? $extra['local']  : null;
  $ci = isset($extra['cidade']) ? $extra['cidade'] : 'Curitiba';
  $uf = isset($extra['uf'])     ? $extra['uf']     : 'PR';
  $st->bind_param('ssssisss', $codigo,$curto,$nome,$token,$g,$lo,$ci,$uf);
  if(!$st->execute()) return null;

  return array('id'=>$db->insert_id, 'nome'=>$nome, 'codigo'=>$codigo,
               'codigo_curto'=>$curto, 'token'=>$token);
}

/* Cria várias TVs de uma vez a partir de uma lista de nomes.
   Instalar signage é trabalho de lote: chega o eletricista com dez telas e
   uma lista de locais. Cadastrar uma por uma é onde o erro entra. */
if($acao === 'tv_lote'){
  $nomes = isset($body['nomes']) ? (array)$body['nomes'] : array();
  $grupo = !empty($body['grupo_id']) ? (int)$body['grupo_id'] : null;
  $cidade = isset($body['cidade']) ? $body['cidade'] : 'Curitiba';

  $criadas = array(); $erros = array();
  foreach($nomes as $nome){
    $nome = trim($nome);
    if($nome === '') continue;
    if(_len($nome) > 120){ $erros[] = $nome.' (nome longo demais)'; continue; }

    $tv = criar_tv($db, $nome, array('grupo_id'=>$grupo, 'cidade'=>$cidade, 'local'=>$nome));
    if($tv){
      $tv['link']       = base_url().'/player.php?t='.$tv['token'];
      $tv['link_curto'] = base_url().'/t.php/'.$tv['codigo_curto'];
      $tv['link_alt']   = base_url().'/t.php?c='.$tv['codigo_curto'];
      $criadas[] = $tv;
    } else {
      $erros[] = $nome;
    }
  }
  out(array('ok'=>true, 'criadas'=>$criadas, 'erros'=>$erros, 'base'=>base_url()));
}

/* Lista as TVs já cadastradas com os dois links, para reimprimir a folha
   sem precisar cadastrar de novo. */
if($acao === 'links'){
  $lista = array();
  $r = $db->query("SELECT id,codigo,codigo_curto,nome,local,token,ultimo_sinal,primeira_conexao
                   FROM tvi_tvs WHERE ativo=1 ORDER BY codigo");
  while($r && $t = $r->fetch_assoc()){
    // TV antiga, cadastrada antes do código curto existir: gera agora.
    if(!$t['codigo_curto']){
      $c = codigo_curto($db, $t['nome']);
      $db->query("UPDATE tvi_tvs SET codigo_curto='$c' WHERE id=".(int)$t['id']);
      $t['codigo_curto'] = $c;
    }
    $seg = $t['ultimo_sinal'] ? (time() - strtotime($t['ultimo_sinal'])) : null;
    $lista[] = array(
      'id'=>(int)$t['id'], 'codigo'=>$t['codigo'], 'codigo_curto'=>$t['codigo_curto'],
      'nome'=>$t['nome'], 'local'=>$t['local'],
      'link'=>base_url().'/player.php?t='.$t['token'],
      'link_curto'=>base_url().'/t.php/'.$t['codigo_curto'],
      'link_alt'=>base_url().'/t.php?c='.$t['codigo_curto'],
      'ja_conectou'=>(bool)$t['primeira_conexao'],
      'seen'=>$seg
    );
  }
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='instalacao_modo'");
  $modo = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  out(array('ok'=>true,'tvs'=>$lista,'base'=>base_url(),'instalacao'=>$modo));
}

/* ── Painel ─────────────────────────────────────────────────────────── */

if($acao === 'resumo'){
  /* Memória livre e modelo do aparelho entram no resumo: quando UMA tela
     falha em várias peças diferentes, a causa costuma ser o aparelho, não
     o conteúdo — e o número que revela isso estava numa outra página. */
  $apar = array();
  $r = $db->query("SELECT tv_id, modelo, mem_livre, disco_livre, versao_app, android
                   FROM tvi_aparelhos WHERE tv_id IS NOT NULL");
  while($r && $a = $r->fetch_assoc()) $apar[(int)$a['tv_id']] = $a;

  $tvs = array();
  $r = $db->query("SELECT t.*, g.nome AS grupo FROM tvi_tvs t
                   LEFT JOIN tvi_grupos g ON g.id=t.grupo_id ORDER BY t.codigo");
  $c = array('online'=>0,'offline'=>0,'atualizando'=>0,'nunca'=>0);
  while($r && $t = $r->fetch_assoc()){
    $seg = $t['ultimo_sinal'] ? (time() - strtotime($t['ultimo_sinal'])) : null;
    if(!$t['primeira_conexao'])        $est = 'nunca';
    elseif($seg === null || $seg > JANELA_OFFLINE) $est = 'offline';
    elseif($t['estado_player'] === 'syncing')      $est = 'atualizando';
    else                                            $est = 'online';
    $c[$est]++;
    $tvs[] = array(
      'id'=>(int)$t['id'], 'code'=>$t['codigo'], 'name'=>$t['nome'],
      'location'=>$t['local'], 'group'=>$t['grupo'], 'state'=>$est, 'seen'=>$seg,
      'ip'=>$t['ultimo_ip'], 'so'=>$t['so'], 'res'=>$t['resolucao'],
      'player'=>$t['versao_player'], 'token'=>$t['token'],
      'tocando'=>$t['ultima_midia'] ? (int)$t['ultima_midia'] : null,
      'sessao_ip'=>$t['sessao_ip'],
      'sessao_viva'=>($t['sessao_em'] && (time() - strtotime($t['sessao_em'])) <= SESSAO_TTL),
      'modelo'   => $apar[(int)$t['id']]['modelo']      ?? null,
      'mem'      => isset($apar[(int)$t['id']]) ? (int)$apar[(int)$t['id']]['mem_livre'] : null,
      'disco'    => isset($apar[(int)$t['id']]) ? (int)$apar[(int)$t['id']]['disco_livre'] : null,
      'versao_app' => $apar[(int)$t['id']]['versao_app'] ?? null,
    );
  }

  // Disponibilidade das últimas 24h: 120 batidas na hora = todas no ar.
  $horas = array();
  $r = $db->query("SELECT hora, COUNT(DISTINCT tv_id) n FROM tvi_sinal_hora
                   WHERE hora >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                   GROUP BY hora ORDER BY hora");
  $mapa = array();
  while($r && $h = $r->fetch_assoc()) $mapa[substr($h['hora'],0,13)] = (int)$h['n'];
  for($i=23; $i>=0; $i--) $horas[] = isset($mapa[date('Y-m-d H', strtotime("-$i hour"))])
    ? $mapa[date('Y-m-d H', strtotime("-$i hour"))] : 0;

  $disco = 0;
  $r = $db->query("SELECT COALESCE(SUM(bytes),0) s FROM tvi_midias");
  if($r && $x = $r->fetch_assoc()) $disco = (int)$x['s'];

  $pubHoje = 0;
  $r = $db->query("SELECT COUNT(*) n FROM tvi_midias WHERE DATE(criado_em)=CURDATE()");
  if($r && $x = $r->fetch_assoc()) $pubHoje = (int)$x['n'];

  // Vencendo: o campo avisar_dias só vira útil quando alguém lê.
  $vencendo = array();
  $r = $db->query("SELECT id,nome,valido_ate FROM tvi_midias
                   WHERE valido_ate IS NOT NULL
                     AND valido_ate >= CURDATE()
                     AND valido_ate <= DATE_ADD(CURDATE(), INTERVAL COALESCE(avisar_dias,7) DAY)
                   ORDER BY valido_ate LIMIT 10");
  while($r && $m = $r->fetch_assoc()) $vencendo[] = $m;

  // Nome do que cada TV está exibindo agora — responde "o que está passando
  // no refeitório?" sem ninguém precisar ir até lá.
  $nomes = array();
  $r = $db->query("SELECT id, nome FROM tvi_midias");
  while($r && $x = $r->fetch_assoc()) $nomes[(int)$x['id']] = $x['nome'];
  foreach($tvs as &$t){
    $t['tocando_nome'] = ($t['tocando'] && isset($nomes[$t['tocando']])) ? $nomes[$t['tocando']] : null;
  }
  unset($t);

  $erros = array();
  $r = $db->query("SELECT e.*, t.nome tv_nome, m.nome midia_nome
                   FROM tvi_erros e
                   JOIN tvi_tvs t ON t.id=e.tv_id
                   LEFT JOIN tvi_midias m ON m.id=e.midia_id
                   WHERE e.resolvido_em IS NULL
                     AND e.ocorrido_em > DATE_SUB(NOW(), INTERVAL 7 DAY)
                   ORDER BY e.ocorrido_em DESC LIMIT 12");
  while($r && $x = $r->fetch_assoc()) $erros[] = $x;

  out(array('ok'=>true,'tvs'=>$tvs,'counts'=>$c,'total'=>count($tvs),
            'hourly'=>$horas,'storage'=>$disco,'published_today'=>$pubHoje,
            'expiring'=>$vencendo,'erros'=>$erros));
}

if($acao === 'tv_salvar'){
  $id     = isset($body['id']) ? (int)$body['id'] : 0;
  $nome   = trim(isset($body['nome']) ? $body['nome'] : '');
  $codigo = trim(isset($body['codigo']) ? $body['codigo'] : '');
  if($nome === '') fail('Informe o nome da TV.');

  if(!$id){
    $tv = criar_tv($db, $nome, array(
      'grupo_id' => isset($body['grupo_id']) ? $body['grupo_id'] : null,
      'local'    => isset($body['local'])  ? $body['local']  : null,
      'cidade'   => isset($body['cidade']) ? $body['cidade'] : 'Curitiba',
      'uf'       => isset($body['uf'])     ? $body['uf']     : 'PR',
    ));
    if(!$tv) fail('Não consegui cadastrar. Confira se o nome já não existe.');
    $tv['ok'] = true;
    $tv['link']       = base_url().'/player.php?t='.$tv['token'];
    $tv['link_curto'] = base_url().'/t.php/'.$tv['codigo_curto'];
    $tv['link_alt']   = base_url().'/t.php?c='.$tv['codigo_curto'];
    out($tv);
  }

  $st = $db->prepare("UPDATE tvi_tvs SET nome=?, grupo_id=?, local=?, cidade=?, uf=?, observacao=?, ativo=? WHERE id=?");
  $g = isset($body['grupo_id']) && $body['grupo_id'] ? (int)$body['grupo_id'] : null;
  $a = isset($body['ativo']) ? (int)$body['ativo'] : 1;
  $st->bind_param('sissssii', $nome,$g,$body['local'],$body['cidade'],$body['uf'],$body['observacao'],$a,$id);
  $st->execute();
  out(array('ok'=>true,'id'=>$id));
}

/* Solta a vaga na marra. Serve para o caso em que a TV antiga não vai
   voltar (trocaram o aparelho, o box queimou) e ninguém quer esperar. */
if($acao === 'sessao_liberar'){
  $id = (int)$body['id'];
  // Marca a hora: o próximo reinício desta TV não conta como espontâneo.
  $db->query("UPDATE tvi_tvs SET sessao_id=NULL, sessao_ip=NULL, sessao_em=NULL,
              sessao_liberada_em=NOW() WHERE id=$id");
  out(array('ok'=>true));
}

if($acao === 'tv_token'){
  anotar($db, 'trocou o link da TV', 'tv #'.(int)$body['id'], 'link antigo invalidado');
  // Rotaciona o token sem trocar a TV de lugar. Útil quando o link vaza.
  $id = (int)$body['id'];
  $token = 'tk_'.bin2hex(random_bytes(18));
  $db->query("UPDATE tvi_tvs SET token='$token' WHERE id=$id");
  out(array('ok'=>true,'token'=>$token));
}

/* Qualidade de sinal por hora, últimas 48h. tvi_sinal_hora já é
   alimentada a cada heartbeat (função de dispositivo, mais acima) —
   isso aqui só lê o que já existe e calcula um % em cima da batida
   esperada (a cada 30s = 120 por hora), pra dar pra ver hora a hora
   se o Wi-Fi de uma TV específica cai sempre no mesmo horário, por
   exemplo, em vez de só saber que "às vezes falha". */
if($acao === 'sinal_historico'){
  $id = (int)$body['id'];
  $r = $db->query("SELECT hora, batidas FROM tvi_sinal_hora
                     WHERE tv_id=$id AND hora >= DATE_SUB(NOW(), INTERVAL 48 HOUR)");
  $batidasPorHora = array();
  while($r && $x = $r->fetch_assoc()) $batidasPorHora[$x['hora']] = (int)$x['batidas'];

  $esperadoPorHora = 120; // heartbeat a cada 30s
  $serie = array();
  $agora = strtotime(date('Y-m-d H:00:00'));
  for($i = 47; $i >= 0; $i--){
    $h = date('Y-m-d H:00:00', $agora - $i*3600);
    $b = isset($batidasPorHora[$h]) ? $batidasPorHora[$h] : 0;
    $serie[] = array(
      'hora'    => $h,
      'batidas' => $b,
      'pct'     => min(100, round($b / $esperadoPorHora * 100)),
    );
  }
  out(array('ok'=>true,'serie'=>$serie));
}

if($acao === 'tv_excluir'){
  anotar($db, 'excluiu TV', 'tv #'.(int)$body['id'], '');
  $id = (int)$body['id'];
  $db->query("DELETE FROM tvi_tvs WHERE id=$id");
  $db->query("DELETE FROM tvi_atribuicoes WHERE alvo_tipo='tv' AND alvo_id=$id");
  out(array('ok'=>true));
}

/* Recarregar TODAS de uma vez. Existe porque a alternativa, depois de
   qualquer mudança visual, é ir tela por tela — ou esperar as 4 da manhã.
   Com dez telas isso é meia hora de escada. */
if($acao === 'recarregar_todas'){
  $r = $db->query("SELECT id FROM tvi_tvs WHERE ativo=1");
  $n = 0;
  while($r && $t = $r->fetch_assoc()){
    $st = $db->prepare("INSERT INTO tvi_comandos (tv_id,tipo,carga) VALUES (?,'reload','{}')");
    $st->bind_param('i', $t['id']);
    $st->execute();
    $n++;
  }
  /* Toca todas as listas junto: o comando faz a página recarregar, e a
     versão nova do manifesto garante que ela volte com o conteúdo certo. */
  $r = $db->query("SELECT id FROM tvi_playlists");
  while($r && $p = $r->fetch_assoc()) toca_playlist($db, $p['id']);

  out(array('ok'=>true,'tvs'=>$n));
}

if($acao === 'tv_comando'){
  $id   = (int)$body['id'];
  $tipo = preg_replace('/[^a-z_]/','', isset($body['tipo']) ? $body['tipo'] : '');
  if(!$tipo) fail('Comando inválido.');
  $carga = isset($body['carga']) ? json_encode($body['carga'], JSON_UNESCAPED_UNICODE) : null;
  $st = $db->prepare("INSERT INTO tvi_comandos (tv_id,tipo,carga) VALUES (?,?,?)");
  $st->bind_param('iss', $id, $tipo, $carga);
  $st->execute();
  out(array('ok'=>true));
}

/* ── Grupos ─────────────────────────────────────────────────────────── */

if($acao === 'grupos'){
  $g = array();
  $r = $db->query("SELECT g.*, (SELECT COUNT(*) FROM tvi_tvs t WHERE t.grupo_id=g.id) tvs
                   FROM tvi_grupos g ORDER BY g.nome");
  while($r && $x = $r->fetch_assoc()) $g[] = $x;
  out(array('ok'=>true,'grupos'=>$g));
}

if($acao === 'grupo_excluir'){
  $id = (int)$body['id'];
  // As TVs não somem junto: ficam sem grupo. Apagar tela por tabela.
  $db->query("UPDATE tvi_tvs SET grupo_id=NULL WHERE grupo_id=$id");
  $db->query("DELETE FROM tvi_atribuicoes WHERE alvo_tipo='grupo' AND alvo_id=$id");
  $db->query("DELETE FROM tvi_grupos WHERE id=$id");
  out(array('ok'=>true));
}

/* Mover várias telas de uma vez. Com 6 TVs ninguém sente; com 30, remanejar
   uma por uma é meia hora perdida. */
if($acao === 'tvs_mover'){
  $ids = isset($body['tvs']) ? array_map('intval', (array)$body['tvs']) : array();
  if(!$ids) fail('Selecione ao menos uma TV.');
  $g = !empty($body['grupo_id']) ? (int)$body['grupo_id'] : null;
  $lista = implode(',', $ids);
  if($g) $db->query("UPDATE tvi_tvs SET grupo_id=$g WHERE id IN ($lista)");
  else   $db->query("UPDATE tvi_tvs SET grupo_id=NULL WHERE id IN ($lista)");
  out(array('ok'=>true,'movidas'=>count($ids)));
}

if($acao === 'grupo_salvar'){
  $nome = trim($body['nome']);
  if($nome === '') fail('Informe o nome do grupo.');
  $id = isset($body['id']) ? (int)$body['id'] : 0;
  if($id){ $st = $db->prepare("UPDATE tvi_grupos SET nome=? WHERE id=?"); $st->bind_param('si',$nome,$id); }
  else   { $st = $db->prepare("INSERT INTO tvi_grupos (nome) VALUES (?)"); $st->bind_param('s',$nome); }
  $st->execute();
  out(array('ok'=>true,'id'=>$id ?: $db->insert_id));
}

/* ── Biblioteca ─────────────────────────────────────────────────────── */

if($acao === 'midias'){
  // Paginas por PDF, para o painel avisar quando a conversao deu zero.
  $pgs = array();
  $rp = $db->query("SELECT midia_id, COUNT(*) n FROM tvi_paginas GROUP BY midia_id");
  while($rp && $xp = $rp->fetch_assoc()) $pgs[(int)$xp['midia_id']] = (int)$xp['n'];
  $m = array();
  $r = $db->query("SELECT * FROM tvi_midias ORDER BY criado_em DESC LIMIT 500");
  while($r && $x = $r->fetch_assoc()){
    $x['em_uso'] = 0;
    $m[] = $x;
  }
  $r = $db->query("SELECT midia_id, COUNT(*) n FROM tvi_itens GROUP BY midia_id");
  $uso = array();
  while($r && $u = $r->fetch_assoc()) $uso[(int)$u['midia_id']] = (int)$u['n'];
  foreach($m as &$x){
    $x['em_uso'] = isset($uso[(int)$x['id']]) ? $uso[(int)$x['id']] : 0;
    // PDF sem página convertida é PDF que não vai aparecer na TV.
    if($x['tipo'] === 'pdf') $x['paginas'] = isset($pgs[(int)$x['id']]) ? $pgs[(int)$x['id']] : 0;
  }
  unset($x);
  out(array('ok'=>true,'midias'=>$m));
}

/* Sobe o APK pelo painel. Antes era preciso FTP: alguém tinha que colocar
   o arquivo numa pasta do site e colar o endereço na mão — e o endereço
   colado com um erro só aparece quando a TV não atualiza. */
if($acao === 'app_upload'){
  $pasta = __DIR__ . '/app';
  if(!is_dir($pasta)) @mkdir($pasta, 0755, true);
  if(!is_dir($pasta)) fail('Não consegui criar a pasta app/. Crie manualmente com permissão de escrita.');
  if(!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) fail('Não recebi o arquivo.');
  $f = $_FILES['arquivo'];
  if(strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'apk') fail('Envie um arquivo .apk.');
  if($f['size'] > 200*1024*1024) fail('APK acima de 200 MB.');

  $versao = isset($_POST['versao']) ? (int)$_POST['versao'] : 0;
  if($versao < 1) fail('Informe o número da versão publicada.');

  $nome = 'tvindoor-v'.$versao.'.apk';
  if(!move_uploaded_file($f['tmp_name'], $pasta.'/'.$nome)) fail('Falha ao gravar o APK no servidor.');
  @chmod($pasta.'/'.$nome, 0644);
  /* cópia com nome fixo: quem já tem o endereço antigo continua funcionando */
  @copy($pasta.'/'.$nome, $pasta.'/tvindoor.apk');
  if(!file_exists($pasta.'/index.html')) @file_put_contents($pasta.'/index.html', '<!DOCTYPE html><title></title>');

  $base = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
        . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
  $url = $base . '/app/' . $nome;

  cfg_set($db, 'app_versao', (string)$versao);
  cfg_set($db, 'app_url', $url);
  out(array('ok'=>true, 'url'=>$url, 'versao'=>$versao,
            'tamanho'=>(int)filesize($pasta.'/'.$nome)));
}

/* Força a atualização: em vez de esperar o aparelho notar sozinho, abre uma
   janela em que TODA batida recebe o aviso — e manda as telas mostrarem o
   convite na hora. Quem aceita continua sendo a pessoa, no controle: o
   Android não deixa instalar sem confirmação. */
if($acao === 'app_forcar'){
  $ver = (int)cfg_num($db, 'app_versao', 0);
  $url = cfg_txt($db, 'app_url', '');
  if($ver < 1 || $url === '') fail('Publique a versão e o endereço do APK antes de forçar.');

  $minutos = isset($_POST['minutos']) ? max(5, min(180, (int)$_POST['minutos'])) : 30;
  cfg_set($db, 'app_forcar_ate', date('Y-m-d H:i:s', time() + $minutos*60));

  /* Avisa também as telas (player web), para aparecer algo agora e não
     daqui a cinco minutos, que é o intervalo da batida do aplicativo. */
  $carga = json_encode(array('versao'=>$ver,'url'=>$url), JSON_UNESCAPED_UNICODE);
  $n = 0;
  $r = $db->query("SELECT id FROM tvi_tvs WHERE ativo=1");
  $st = $db->prepare("INSERT INTO tvi_comandos (tv_id,tipo,carga) VALUES (?,'atualizar_app',?)");
  while($r && $x = $r->fetch_assoc()){
    $st->bind_param('is', $x['id'], $carga);
    $st->execute();
    $n++;
  }
  out(array('ok'=>true,'minutos'=>$minutos,'telas'=>$n,'versao'=>$ver));
}

if($acao === 'upload'){
  if(!is_dir(PASTA_MIDIA)) @mkdir(PASTA_MIDIA, 0755, true);
  if(!is_dir(PASTA_MIDIA)) fail('Não consegui criar a pasta midias_tv. Crie manualmente e dê permissão de escrita.');

  /* Os arquivos precisam ser públicos — a TV os busca sem sessão. Mas
     LISTAR a pasta não pode: com a listagem aberta, qualquer um baixa a
     campanha inteira antes de ela ir ao ar. Os nomes são aleatórios de
     32 caracteres, então sem índice ninguém adivinha. */
  if(!file_exists(PASTA_MIDIA.'/index.html')){
    @file_put_contents(PASTA_MIDIA.'/index.html', '<!DOCTYPE html><title></title>');
  }
  if(!file_exists(PASTA_MIDIA.'/.htaccess')){
    @file_put_contents(PASTA_MIDIA.'/.htaccess',
      "Options -Indexes\n"
    . "<FilesMatch \"\\.(php|phtml|phar|cgi|pl|py|sh)$\">\n"
    . "  Require all denied\n"
    . "</FilesMatch>\n");
  }

  if(empty($_FILES['arquivo'])) fail('Nenhum arquivo recebido.');
  $f = $_FILES['arquivo'];
  if($f['error'] !== UPLOAD_ERR_OK) fail('Falha no envio (código '.$f['error'].'). Verifique upload_max_filesize.');

  $cfg = json_decode(isset($_POST['config']) ? $_POST['config'] : '{}', true);
  if(!is_array($cfg)) $cfg = array();

  // Assinatura real do arquivo. Extensão é sugestão do usuário, não prova.
  $head = file_get_contents($f['tmp_name'], false, null, 0, 32);
  $tipo = null; $ext = null;
  if(substr($head,4,4) === 'ftyp')                    { $tipo='video';  $ext='mp4'; }
  elseif(strpos($head,"\xFF\xD8\xFF") === 0)          { $tipo='imagem'; $ext='jpg'; }
  elseif(strpos($head,"\x89PNG\r\n\x1A\n") === 0)     { $tipo='imagem'; $ext='png'; }
  elseif(strpos($head,'GIF8') === 0)                  { $tipo='imagem'; $ext='gif'; }
  elseif(strpos($head,'RIFF') === 0 && substr($head,8,4)==='WEBP'){ $tipo='imagem'; $ext='webp'; }
  elseif(strpos($head,'%PDF-') === 0)                 { $tipo='pdf';    $ext='pdf'; }
  if(!$tipo) fail('Formato não suportado. Envie MP4, JPG, PNG, WEBP, GIF ou PDF.');

  $checksum = hash_file('sha256', $f['tmp_name']);

  // Mesmo arquivo de novo: reaproveita o binário, cria só a veiculação.
  $r = $db->query("SELECT * FROM tvi_midias WHERE checksum='$checksum' LIMIT 1");
  $existente = $r ? $r->fetch_assoc() : null;

  if($existente){
    $midiaId = (int)$existente['id'];
    $vd = !empty($cfg['media']['valid_from'])  ? "'".$db->real_escape_string($cfg['media']['valid_from'])."'"  : 'valido_de';
    $va = !empty($cfg['media']['valid_until']) ? "'".$db->real_escape_string($cfg['media']['valid_until'])."'" : 'valido_ate';
    $db->query("UPDATE tvi_midias SET valido_de=$vd, valido_ate=$va WHERE id=$midiaId");
  } else {
    $nomeArq = bin2hex(random_bytes(16)).'.'.$ext;
    if(!move_uploaded_file($f['tmp_name'], PASTA_MIDIA.'/'.$nomeArq)) fail('Não consegui gravar o arquivo.');

    $st = $db->prepare("INSERT INTO tvi_midias
      (nome,tipo,arquivo,mime,bytes,duracao_ms,largura,altura,checksum,pasta,tags,
       valido_de,valido_ate,avisar_dias,duracao_padrao_ms,enviado_por,aprovado)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $nome  = isset($cfg['media']['name']) ? $cfg['media']['name'] : pathinfo($f['name'], PATHINFO_FILENAME);
    $mime  = $f['type'];
    $bytes = (int)$f['size'];
    $dur   = isset($cfg['media']['duration_ms']) ? (int)$cfg['media']['duration_ms'] : null;
    $larg  = isset($cfg['media']['width'])  ? (int)$cfg['media']['width']  : null;
    $alt   = isset($cfg['media']['height']) ? (int)$cfg['media']['height'] : null;
    $pasta = isset($cfg['media']['folder']) ? $cfg['media']['folder'] : null;
    $tags  = isset($cfg['media']['tags']) ? implode(',', (array)$cfg['media']['tags']) : null;
    $vd    = !empty($cfg['media']['valid_from'])  ? $cfg['media']['valid_from']  : null;
    $va    = !empty($cfg['media']['valid_until']) ? $cfg['media']['valid_until'] : null;
    $av    = isset($cfg['media']['expiry_notify_days']) ? (int)$cfg['media']['expiry_notify_days'] : null;
    $dp    = isset($cfg['media']['default_duration_ms']) ? (int)$cfg['media']['default_duration_ms'] : 10000;
    // Com o módulo de aprovação ligado, o arquivo entra pendente e não chega
    // às TVs até alguém liberar. Desligado, entra aprovado direto.
    $ap = cfg($db,'mod_aprovacao') === '1' ? 0 : 1;
    $st->bind_param('ssssiiiissssssisi',
      $nome,$tipo,$nomeArq,$mime,$bytes,$dur,$larg,$alt,$checksum,$pasta,$tags,$vd,$va,$av,$dp,$usuario,$ap);
    $st->execute();
    $midiaId = $db->insert_id;

    /* PDF vira uma imagem por página quando o Imagick existe. É a única
       forma estável: Tizen e webOS renderizam PDF mal ou não renderizam.
       Sem Imagick, segue como antes e o app avisa no envio. */
    /* O comentário acima dizia "o app avisa no envio", mas esse aviso nunca
       existiu: sem Imagick o PDF subia, não virava página nenhuma e ia para
       a parede como arquivo que a TV não sabe abrir. Agora o envio devolve
       o que aconteceu e o painel mostra. */
    $pdfPaginas = 0;
    $pdfErro = null;

    if($tipo === 'pdf' && !class_exists('Imagick')){
      $pdfErro = 'O servidor não tem Imagick, então o PDF não virou imagens. '
               . 'A maioria das TVs não abre PDF: exporte as páginas como JPG e envie como imagem.';
    }

    if($tipo === 'pdf' && class_exists('Imagick')){
      try {
        $im = new Imagick();
        $im->setResolution(150, 150);
        $im->readImage(PASTA_MIDIA.'/'.$nomeArq);
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(85);
        $n = min($im->getNumberImages(), 30);
        for($p = 0; $p < $n; $p++){
          $im->setIteratorIndex($p);
          $pg = $im->getImage();
          $pg->setImageBackgroundColor('white');
          $pg = $pg->flattenImages();
          $nomePg = pathinfo($nomeArq, PATHINFO_FILENAME).'-p'.($p+1).'.jpg';
          $pg->writeImage(PASTA_MIDIA.'/'.$nomePg);
          $st2 = $db->prepare("INSERT IGNORE INTO tvi_paginas (midia_id,pagina,arquivo) VALUES (?,?,?)");
          $pn = $p + 1;
          $st2->bind_param('iis', $midiaId, $pn, $nomePg);
          $st2->execute();
          $pg->destroy();
          $pdfPaginas++;
        }
        $im->destroy();
        if($pdfPaginas === 0) $pdfErro = 'O PDF foi lido, mas nenhuma página pôde ser convertida.';
      } catch (Throwable $e) {
        $pdfErro = 'Falha ao converter o PDF: '._cut($e->getMessage(), 0, 120);
      }
    }
  }

  // Veiculação
  $pub   = isset($cfg['publish']) ? $cfg['publish'] : array();
  $regra = isset($pub['item']) ? $pub['item'] : array();
  $criados = array();

  /* ARQUIVO NÃO É LISTA.
     Antes, escolher uma TV no envio criava uma playlist com o nome do
     arquivo por trás. O operador acabava com uma tela de listas cheia de
     nomes de vídeo, sem entender de onde vinham. Envio agora vai para a
     biblioteca; a composição acontece na tela de listas, onde é o lugar.
     Adicionar a uma lista JÁ EXISTENTE continua valendo, e é opcional. */
  $listas = isset($pub['playlist_ids']) ? (array)$pub['playlist_ids'] : array();

  foreach($listas as $plId){
    $plId = (int)$plId;
    $r = $db->query("SELECT COALESCE(MAX(ordem),0)+1 o FROM tvi_itens WHERE playlist_id=$plId");
    $ordem = $r ? (int)$r->fetch_assoc()['o'] : 1;

    $st = $db->prepare("INSERT INTO tvi_itens
      (playlist_id,midia_id,ordem,duracao_ms,prioridade,dias,hora_de,hora_ate,data_de,data_ate)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $dur  = isset($regra['duration_ms']) ? (int)$regra['duration_ms'] : 10000;
    $pri  = isset($regra['priority']) ? (int)$regra['priority'] : 0;
    $dias = isset($regra['weekdays']) ? (int)$regra['weekdays'] : 127;
    $hd   = !empty($regra['starts_at']) ? $regra['starts_at'] : null;
    $ha   = !empty($regra['ends_at'])   ? $regra['ends_at']   : null;
    $dd   = !empty($regra['starts_on']) ? $regra['starts_on'] : null;
    $da   = !empty($regra['ends_on'])   ? $regra['ends_on']   : null;
    $st->bind_param('iiiiiissss', $plId,$midiaId,$ordem,$dur,$pri,$dias,$hd,$ha,$dd,$da);
    $st->execute();

    // Muda a versão: é isso que faz as TVs sincronizarem na próxima batida,
    // sem ninguém apertar nada.
    toca_playlist($db, $plId);
    $criados[] = $plId;
  }

  out(array('ok'=>true,'midia_id'=>$midiaId,'reaproveitado'=>(bool)$existente,'playlists'=>$criados,
            'pdf_paginas'=>isset($pdfPaginas) ? $pdfPaginas : null,
            'pdf_erro'=>isset($pdfErro) ? $pdfErro : null));
}

/* Renomear e ajustar validade sem reenviar o arquivo. Antes, corrigir
   "video_final_v3_REAL.mp4" exigia excluir e subir os 200 MB de novo. */
if($acao === 'midia_editar'){
  $id = (int)$body['id'];
  $nome = trim(isset($body['nome']) ? $body['nome'] : '');
  if($nome === '') fail('O nome não pode ficar vazio.');

  $st = $db->prepare("UPDATE tvi_midias SET nome=?, pasta=?, valido_de=?, valido_ate=?,
                        avisar_dias=?, duracao_padrao_ms=? WHERE id=?");
  $pasta = isset($body['pasta']) && $body['pasta'] !== '' ? $body['pasta'] : null;
  $vd = !empty($body['valido_de'])  ? $body['valido_de']  : null;
  $va = !empty($body['valido_ate']) ? $body['valido_ate'] : null;
  $av = isset($body['avisar_dias']) ? (int)$body['avisar_dias'] : null;
  $dp = isset($body['duracao_ms']) ? max(1000, (int)$body['duracao_ms']) : 10000;
  $st->bind_param('ssssiii', $nome, $pasta, $vd, $va, $av, $dp, $id);
  $st->execute();

  // A validade entra no manifesto, então as listas que usam este arquivo
  // precisam de versão nova para as TVs recalcularem.
  $r = $db->query("SELECT DISTINCT playlist_id FROM tvi_itens WHERE midia_id=$id");
  while($r && $x = $r->fetch_assoc()) toca_playlist($db, $x['playlist_id']);
  out(array('ok'=>true));
}

if($acao === 'midia_excluir'){
  anotar($db, 'excluiu arquivo', 'arquivo #'.(int)$body['id'], '');
  $id = (int)$body['id'];
  $r = $db->query("SELECT arquivo FROM tvi_midias WHERE id=$id");
  if($r && $m = $r->fetch_assoc()){
    if($m['arquivo'] && file_exists(PASTA_MIDIA.'/'.$m['arquivo'])) @unlink(PASTA_MIDIA.'/'.$m['arquivo']);
  }
  $r = $db->query("SELECT DISTINCT playlist_id FROM tvi_itens WHERE midia_id=$id");
  while($r && $x = $r->fetch_assoc()) toca_playlist($db, $x['playlist_id']);
  $db->query("DELETE FROM tvi_itens WHERE midia_id=$id");
  $db->query("DELETE FROM tvi_midias WHERE id=$id");
  out(array('ok'=>true));
}

/* ── Playlists ──────────────────────────────────────────────────────── */

if($acao === 'playlists'){
  $p = array();
  $r = $db->query("SELECT p.*,
                     (SELECT COUNT(*) FROM tvi_itens i WHERE i.playlist_id=p.id) itens
                   FROM tvi_playlists p
                   WHERE p.descricao <> 'Comunicado urgente' OR p.descricao IS NULL
                   ORDER BY p.nome");
  while($r && $x = $r->fetch_assoc()){
    $x['alvos'] = array();
    $p[$x['id']] = $x;
  }
  $r = $db->query("SELECT a.*, COALESCE(g.nome, t.nome) alvo_nome
                   FROM tvi_atribuicoes a
                   LEFT JOIN tvi_grupos g ON a.alvo_tipo='grupo' AND g.id=a.alvo_id
                   LEFT JOIN tvi_tvs    t ON a.alvo_tipo='tv'    AND t.id=a.alvo_id");
  while($r && $a = $r->fetch_assoc()){
    if(isset($p[$a['playlist_id']])) $p[$a['playlist_id']]['alvos'][] = $a;
  }
  out(array('ok'=>true,'playlists'=>array_values($p)));
}

/* Manifesto de uma lista, para a prévia. Exige sessão: é o painel pedindo,
   não um dispositivo. Monta pelo mesmo caminho do manifesto real — prévia
   que usa outro código não é prévia, é palpite. */
if($acao === 'manifest_preview'){
  $pl = (int)$_GET['pl'];
  $r = $db->query("SELECT * FROM tvi_playlists WHERE id=$pl LIMIT 1");
  if(!$r || !$r->num_rows) fail('Lista não encontrada.');
  $p = $r->fetch_assoc();

  // TV fictícia que enxerga só esta lista.
  $falsa = array('id'=>0, 'codigo'=>'PREVIA', 'nome'=>'Prévia', 'grupo_id'=>null, 'token'=>'previa');
  $GLOBALS['_previa_playlist'] = $p;
  $m = montar_manifesto($db, $falsa);
  out($m);
}

if($acao === 'playlist_itens'){
  $id = (int)$_GET['id'];
  $i = array();
  $r = $db->query("SELECT i.*, m.nome m_nome, m.tipo, m.arquivo, m.valido_ate,
                     (SELECT COUNT(*) FROM tvi_paginas pg WHERE pg.midia_id=m.id) paginas
                   FROM tvi_itens i JOIN tvi_midias m ON m.id=i.midia_id
                   WHERE i.playlist_id=$id ORDER BY i.ordem");
  while($r && $x = $r->fetch_assoc()) $i[] = $x;
  out(array('ok'=>true,'itens'=>$i));
}

if($acao === 'playlist_salvar'){
  $id   = isset($body['id']) ? (int)$body['id'] : 0;
  $nome = trim($body['nome']);
  if($nome === '') fail('Informe o nome da lista.');
  if($id){
    $st = $db->prepare("UPDATE tvi_playlists SET nome=?, descricao=? WHERE id=?");
    $st->bind_param('ssi', $nome, $body['descricao'], $id);
    $st->execute();
    toca_playlist($db, $id);
  } else {
    $v = nova_versao();
    $st = $db->prepare("INSERT INTO tvi_playlists (nome,descricao,versao) VALUES (?,?,?)");
    $st->bind_param('sss', $nome, $body['descricao'], $v);
    $st->execute();
    $id = $db->insert_id;
  }

  if(isset($body['alvos'])){
    $db->query("DELETE FROM tvi_atribuicoes WHERE playlist_id=$id");
    foreach((array)$body['alvos'] as $a){
      $t = $a['tipo'] === 'grupo' ? 'grupo' : 'tv';
      $ai = (int)$a['id'];
      $db->query("INSERT IGNORE INTO tvi_atribuicoes (playlist_id,alvo_tipo,alvo_id) VALUES ($id,'$t',$ai)");
    }
    toca_playlist($db, $id);
  }
  out(array('ok'=>true,'id'=>$id));
}

/* ══════════════ MANUTENÇÃO DIÁRIA ══════════════

   Hospedagem compartilhada nem sempre tem cron, e depender de cron para
   algo que precisa acontecer é um jeito de descobrir seis meses depois
   que nunca aconteceu. Então isto roda sozinho, no primeiro acesso do dia:
   consolida o mês, expurga o detalhe velho e gera os avisos de vencimento.

   Com 20 TVs 24h no ar, tvi_exibicoes ganha ~2 milhões de linhas por ano.
   Sem expurgo, a cota do banco estoura sem avisar e o relatório empaca. */

function manutencao($db){
  $hoje = date('Y-m-d');
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='ultima_manutencao'");
  $ult = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  if($ult === $hoje) return;

  // Marca antes de trabalhar: se algo falhar no meio, não fica repetindo
  // a cada requisição do dia inteiro.
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('ultima_manutencao','$hoje')
              ON DUPLICATE KEY UPDATE valor='$hoje'");

  // 1. Consolida tudo o que está fora da janela de retenção.
  $corte = date('Y-m-d', strtotime('-'.RETENCAO_DIAS.' days'));
  $db->query("INSERT INTO tvi_exibicoes_mes (mes, midia_id, tv_id, nome, exibicoes, completas, segundos)
              SELECT DATE_FORMAT(e.exibido_em,'%Y-%m'), COALESCE(e.midia_id,0), e.tv_id,
                     MAX(m.nome), COUNT(*), SUM(e.completo), ROUND(SUM(e.duracao_ms)/1000)
              FROM tvi_exibicoes e LEFT JOIN tvi_midias m ON m.id=e.midia_id
              WHERE e.exibido_em < '$corte 00:00:00'
              GROUP BY DATE_FORMAT(e.exibido_em,'%Y-%m'), e.midia_id, e.tv_id
              ON DUPLICATE KEY UPDATE
                exibicoes=VALUES(exibicoes), completas=VALUES(completas), segundos=VALUES(segundos)");

  // 2. Expurga em lotes: DELETE de milhão de linhas de uma vez trava a tabela.
  for($i = 0; $i < 20; $i++){
    $db->query("DELETE FROM tvi_exibicoes WHERE exibido_em < '$corte 00:00:00' LIMIT 5000");
    if($db->affected_rows < 5000) break;
  }

  // 3. Erros antigos já resolvidos não interessam mais.
  $db->query("DELETE FROM tvi_erros WHERE ocorrido_em < DATE_SUB(NOW(), INTERVAL 60 DAY)");
  $db->query("DELETE FROM tvi_reinicios WHERE ocorrido_em < DATE_SUB(NOW(), INTERVAL 180 DAY)");

  /* ── Tabelas que cresciam para sempre ────────────────────────
     Estas três não eram limpas por ninguém. Com o sistema rodando o ano
     inteiro, viram o maior peso do banco — e a pior é a de capturas,
     porque guarda IMAGEM em base64: cada "Ver tela" deixa algo entre 100 e
     600 KB no banco, permanentemente. Vinte capturas por dia numa parede
     de dez telas passam de 1 GB por ano.

     Os prazos abaixo saem do uso real de cada uma:
       captura   serve para conferir agora, não semana passada
       comando   depois de confirmado, é registro morto
       sinal     alimenta o gráfico de 24h; guardo 30 dias por folga */
  $db->query("DELETE FROM tvi_capturas WHERE criado_em < DATE_SUB(NOW(), INTERVAL 7 DAY)");
  $db->query("DELETE FROM tvi_comandos WHERE confirmado_em IS NOT NULL
              AND confirmado_em < DATE_SUB(NOW(), INTERVAL 14 DAY)");
  // Comando que ninguém buscou em 30 dias é de TV que não existe mais.
  $db->query("DELETE FROM tvi_comandos WHERE confirmado_em IS NULL
              AND criado_em < DATE_SUB(NOW(), INTERVAL 30 DAY)");
  $db->query("DELETE FROM tvi_sinal_hora WHERE hora < DATE_SUB(NOW(), INTERVAL 30 DAY)");
  // Cache vencido de sobra: previsão de 3 dias atrás não serve a ninguém.
  $db->query("DELETE FROM tvi_cache WHERE atualizado_em < DATE_SUB(NOW(), INTERVAL 3 DAY)");

  /* Páginas de PDF de mídia que já foi excluída. Ficavam órfãs no banco E
     no disco, porque a exclusão da mídia não olhava para elas. */
  $r = $db->query("SELECT pg.arquivo FROM tvi_paginas pg
                   LEFT JOIN tvi_midias m ON m.id = pg.midia_id
                   WHERE m.id IS NULL LIMIT 200");
  while($r && $x = $r->fetch_assoc()){
    $f = PASTA_MIDIA.'/'.basename($x['arquivo']);
    if(is_file($f)) @unlink($f);
  }
  $db->query("DELETE pg FROM tvi_paginas pg
              LEFT JOIN tvi_midias m ON m.id = pg.midia_id
              WHERE m.id IS NULL");

  // 4. Avisos de vencimento. O campo avisar_dias existia e ninguém lia.
  $vencendo = array();
  $r = $db->query("SELECT id, nome, valido_ate FROM tvi_midias
                   WHERE valido_ate IS NOT NULL AND valido_ate >= CURDATE()
                     AND valido_ate <= DATE_ADD(CURDATE(), INTERVAL COALESCE(avisar_dias,7) DAY)");
  while($r && $x = $r->fetch_assoc()) $vencendo[] = $x;

  $rv = $db->query("SELECT valor FROM tvi_config WHERE chave='alerta_email'");
  $email = ($rv && $rv->num_rows) ? trim($rv->fetch_assoc()['valor']) : '';

  if($vencendo && $email && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('mail')){
    $linhas = '';
    foreach($vencendo as $v){
      $linhas .= '- '.$v['nome'].' vence em '.date('d/m/Y', strtotime($v['valido_ate']))."\n";
    }
    $corpo = "Conteudos do TV Indoor vencendo nos proximos dias:\n\n".$linhas
           . "\nAcesse o Hub > TV Indoor > Conteudo para renovar ou substituir.\n";
    @mail($email, 'TV Indoor: conteudo vencendo', $corpo,
          "From: no-reply@".(isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost')."\r\n"
        . "Content-Type: text/plain; charset=UTF-8");
  }
}

manutencao($db);

/* ══════════════ CONFIGURAÇÃO DOS MÓDULOS ══════════════ */

if($acao === 'config'){
  $c = array();
  $r = $db->query("SELECT chave, valor FROM tvi_config");
  while($r && $x = $r->fetch_assoc()) $c[$x['chave']] = $x['valor'];
  out(array('ok'=>true,'config'=>$c));
}

if($acao === 'config_salvar'){
  foreach((array)$body as $k => $v){
    if($k === 'retencao_dias'){
      $d = max(30, min(730, (int)$v));
      $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('retencao_dias','$d')
                  ON DUPLICATE KEY UPDATE valor='$d'");
      continue;
    }
    if(!preg_match('/^mod_[a-z_]{2,40}$/', $k)) continue;
    $ke = $db->real_escape_string($k);
    $ve = $db->real_escape_string($v ? '1' : '0');
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$ke','$ve')
                ON DUPLICATE KEY UPDATE valor='$ve'");
  }
  out(array('ok'=>true));
}

/* Quanto o conjunto aguenta. É o número que responde "esse aparelho serve?"
   antes de comprar mais nove iguais. */
if($acao === 'resiliencia'){
  $dias = isset($_GET['dias']) ? max(1, min(365, (int)$_GET['dias'])) : 30;
  $lista = array();

  $r = $db->query("SELECT t.id, t.codigo, t.nome, t.primeira_conexao, t.ultimo_sinal,
                     COUNT(r.id) reinicios,
                     COALESCE(SUM(r.sozinho),0) sozinhos,
                     COALESCE(MAX(r.fora_segundos),0) maior_parada,
                     COALESCE(ROUND(AVG(r.fora_segundos)),0) media_parada,
                     MAX(r.ocorrido_em) ultimo
                   FROM tvi_tvs t
                   LEFT JOIN tvi_reinicios r
                     ON r.tv_id=t.id AND r.ocorrido_em >= DATE_SUB(NOW(), INTERVAL $dias DAY)
                   WHERE t.ativo=1
                   GROUP BY t.id ORDER BY reinicios DESC, t.codigo");
  while($r && $x = $r->fetch_assoc()){
    // Dias observados: não faz sentido comparar uma TV de ontem com uma de meses.
    $obs = $x['primeira_conexao']
      ? min($dias, max(1, (int)floor((time() - strtotime($x['primeira_conexao'])) / 86400)))
      : 0;
    $lista[] = array(
      'id' => (int)$x['id'], 'codigo' => $x['codigo'], 'nome' => $x['nome'],
      'reinicios' => (int)$x['reinicios'], 'sozinhos' => (int)$x['sozinhos'],
      'maior_parada' => (int)$x['maior_parada'], 'media_parada' => (int)$x['media_parada'],
      'ultimo' => $x['ultimo'], 'observada' => $obs,
      'nunca_abriu' => empty($x['primeira_conexao']),
    );
  }
  out(array('ok'=>true,'dias'=>$dias,'tvs'=>$lista));
}

if($acao === 'erro_resolver'){
  $id = (int)$body['id'];
  $db->query("UPDATE tvi_erros SET resolvido_em=NOW() WHERE id=$id");
  out(array('ok'=>true));
}

if($acao === 'captura_ver'){
  $tv = (int)$_GET['tv'];
  $r = $db->query("SELECT imagem, capturado_em FROM tvi_capturas WHERE tv_id=$tv");
  if(!$r || !$r->num_rows) out(array('ok'=>true,'imagem'=>null));
  $x = $r->fetch_assoc();
  out(array('ok'=>true,'imagem'=>$x['imagem'],'em'=>$x['capturado_em']));
}

/* Configuração das extensões. Cada uma guarda o que precisa e nada mais:
   a chave do futebol, a data do contador, a lista de aniversariantes. */
/* Fontes cadastradas pelo usuário. Ficam no BANCO, não no navegador:
   quem cadastra é o operador num computador, quem consome são as TVs em
   outra rede. localStorage guardaria a lista só na máquina de quem
   cadastrou, e nenhuma tela veria. */
/* ══════════════ INSTAGRAM ══════════════
   O token vive AQUI, no servidor, nunca no player. Se ficasse no
   JavaScript da TV, qualquer pessoa com acesso àquela tela leria o token
   e passaria a publicar pela conta da empresa. É a razão principal de o
   Instagram não ser buscado direto pelo navegador.

   IMPORTANTE sobre a API: a Basic Display API foi encerrada. O caminho
   atual é a Graph API, que exige conta Business ou Criador ligada a uma
   Página do Facebook. Conta pessoal não tem API — nesse caso o caminho é
   salvar as artes e enviar como imagem, que já funciona hoje. */

/* O @ do perfil. Serve para a chamada na tela e para o QR, que funcionam
   SEM API nenhuma. Se um dia o token for configurado, os posts entram por
   cima; até lá, a peça é um convite a seguir, que numa sala de espera
   funciona melhor do que parece. */
/* ── Posts colocados à mão ─────────────────────────────────────
   O Instagram não deixa ler perfil sem token, e a burocracia da API afasta
   a maioria. Mas o que a peça precisa é imagem e legenda — e isso o
   marketing tem na mão no momento em que publica.

   Então: cola o link do post e a legenda, e escolhe a arte da biblioteca
   (a mesma que já foi enviada para a TV). Dois minutos por post, nenhuma
   dependência, e não quebra quando a Meta muda regra.
   Se a API for configurada depois, ela assume e isto vira reserva. */
if($acao === 'insta_manual'){
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='insta_manual'");
  $j = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '[]';
  $l = json_decode($j, true);
  out(array('ok'=>true,'posts'=>is_array($l) ? $l : array()));
}

if($acao === 'insta_manual_salvar'){
  $mid = (int)(isset($body['midia_id']) ? $body['midia_id'] : 0);
  $leg = trim((string)(isset($body['legenda']) ? $body['legenda'] : ''));
  $id  = (int)(isset($body['id']) ? $body['id'] : 0);
  $url = trim((string)(isset($body['img_url']) ? $body['img_url'] : ''));

  /* Dois caminhos: escolher da biblioteca, ou colar o endereço da imagem.
     O segundo evita a viagem "sai daqui, vai enviar, volta" para quem já
     tem a arte publicada em algum lugar. */
  if($url !== ''){
    if(!preg_match('#^https?://#i', $url)) fail('O endereço da imagem precisa começar com http.');
    $img = $url;
    $nome = _cut(basename(parse_url($url, PHP_URL_PATH)) ?: 'Publicação', 0, 50);
  } else {
    if(!$mid) fail('Escolha a arte na biblioteca ou cole o endereço da imagem.');
    $r = $db->query("SELECT arquivo, url_externa, nome FROM tvi_midias WHERE id=$mid LIMIT 1");
    if(!$r || !$r->num_rows) fail('Arte não encontrada.');
    $m = $r->fetch_assoc();
    $img = $m['arquivo'] ? base_url().'/midias_tv/'.rawurlencode($m['arquivo']) : $m['url_externa'];
    $nome = $m['nome'];
  }

  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='insta_manual'");
  $lista = ($r && $r->num_rows) ? json_decode($r->fetch_assoc()['valor'], true) : array();
  if(!is_array($lista)) $lista = array();

  $novo = array('id'=>$id ?: (time() % 100000), 'midia_id'=>$mid, 'img'=>$img,
                'legenda'=>_cut($leg, 0, 180), 'nome'=>$nome,
                'em'=>date('Y-m-d H:i:s'));

  if($id){
    foreach($lista as $k => $x) if((int)$x['id'] === $id){ $novo['em'] = $x['em']; $lista[$k] = $novo; }
  } else {
    array_unshift($lista, $novo);        // mais recente primeiro
  }
  $lista = array_slice($lista, 0, 6);    // guarda poucos: é vitrine, não arquivo

  $j = $db->real_escape_string(json_encode(array_values($lista), JSON_UNESCAPED_UNICODE));
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('insta_manual','$j')
              ON DUPLICATE KEY UPDATE valor='$j'");
  out(array('ok'=>true,'total'=>count($lista)));
}

if($acao === 'insta_manual_excluir'){
  $id = (int)$body['id'];
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='insta_manual'");
  $lista = ($r && $r->num_rows) ? json_decode($r->fetch_assoc()['valor'], true) : array();
  $nova = array();
  foreach((array)$lista as $x) if((int)$x['id'] !== $id) $nova[] = $x;
  $j = $db->real_escape_string(json_encode($nova, JSON_UNESCAPED_UNICODE));
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('insta_manual','$j')
              ON DUPLICATE KEY UPDATE valor='$j'");
  out(array('ok'=>true,'total'=>count($nova)));
}

/* Política da Qualidade, Missão, Visão e Valores.
   Guardados no banco em vez de escritos no código porque estes textos
   passam por revisão — o "Rev.01" no rodapé existe justamente por isso.
   Quando mudar, muda no painel, não num arquivo PHP. */
/* Redes sociais. Guardo só o identificador de cada uma; a URL é montada
   na hora. Assim o operador cola "@avredentor_oficial" ou o link inteiro,
   tanto faz, e o sistema entende os dois. */
/* Convite para o grupo de mensagens. Guardo link, título, chamada e a
   lista do que o grupo entrega — essa última é o que convence: "entre no
   grupo" sozinho não diz nada, "avisos de linha alterada" diz.

   O nome da ação é 'convite', não 'grupo': já existe 'grupo_salvar' para
   os grupos de TVs, e as duas colidiam. A primeira definição vencia, e
   salvar o convite caía na validação da outra, pedindo "nome do grupo".
   Nome de ação é espaço compartilhado — vale conferir antes de criar. */
/* ── Agenda por setor ────────────────────────────────────────
   Nasceu para a contabilidade, mas o campo 'setor' já existe: quando o RH
   ou a manutenção quiserem a própria agenda, é só outro valor — sem tabela
   nova e sem duplicar tela. */
if($acao === 'agenda'){
  $setor = isset($_GET['setor']) ? preg_replace('/[^a-z]/', '', $_GET['setor']) : 'contabilidade';
  $mes   = isset($_GET['mes']) && preg_match('/^\d{4}-\d{2}$/', $_GET['mes'])
           ? $_GET['mes'] : date('Y-m');

  $se = $db->real_escape_string($setor);
  $me = $db->real_escape_string($mes);
  $lista = array();
  $r = $db->query("SELECT id, data, titulo, detalhe, cor FROM tvi_agenda
                   WHERE setor='$se' AND DATE_FORMAT(data,'%Y-%m')='$me'
                   ORDER BY data, id");
  while($r && $x = $r->fetch_assoc()) $lista[] = $x;

  // Meses que têm algo, para o painel navegar sem tentativa e erro.
  $meses = array();
  $r = $db->query("SELECT DISTINCT DATE_FORMAT(data,'%Y-%m') m FROM tvi_agenda
                   WHERE setor='$se' ORDER BY m DESC LIMIT 24");
  while($r && $x = $r->fetch_assoc()) $meses[] = $x['m'];

  $cfg = array();
  foreach(array('agenda_titulo','agenda_frase','agenda_rodape') as $k){
    $rr = $db->query("SELECT valor FROM tvi_config WHERE chave='$k'");
    $cfg[$k] = ($rr && $rr->num_rows) ? $rr->fetch_assoc()['valor'] : '';
  }
  if($cfg['agenda_titulo'] === ''){
    $cfg = array(
      'agenda_titulo' => 'Calendário Contábil',
      'agenda_frase'  => 'Organização hoje, tranquilidade amanhã, resultados sempre.',
      'agenda_rodape' => 'Disciplina, foco e organização são a base do sucesso contábil',
    );
  }
  out(array('ok'=>true,'mes'=>$mes,'setor'=>$setor,'itens'=>$lista,'meses'=>$meses,
            'config'=>$cfg,'pode_editar'=>pode_agenda($db)));
}

if($acao === 'agenda_salvar'){
  if(!pode_agenda($db)) fail('Só a contabilidade pode alterar esta agenda. '
                           . 'Peça a liberação a quem administra o portal.', 403);
  $id      = isset($body['id']) ? (int)$body['id'] : 0;
  $setor   = preg_replace('/[^a-z]/', '', isset($body['setor']) ? $body['setor'] : 'contabilidade');
  $data    = isset($body['data']) ? trim($body['data']) : '';
  $titulo  = trim((string)(isset($body['titulo']) ? $body['titulo'] : ''));
  $detalhe = trim((string)(isset($body['detalhe']) ? $body['detalhe'] : ''));
  $cor     = preg_replace('/[^a-z]/', '', isset($body['cor']) ? $body['cor'] : 'azul');

  if(!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) fail('Informe a data.');
  if($titulo === '') fail('Informe o que vence nesse dia.');
  if(!in_array($cor, array('azul','verde','roxo','laranja','vermelho'), true)) $cor = 'azul';

  $st = $db->prepare($id
    ? "UPDATE tvi_agenda SET data=?, titulo=?, detalhe=?, cor=? WHERE id=? AND setor=?"
    : "INSERT INTO tvi_agenda (data,titulo,detalhe,cor,setor) VALUES (?,?,?,?,?)");
  $t = _cut($titulo, 0, 80);
  $d = _cut($detalhe, 0, 160);
  if($id) $st->bind_param('ssssis', $data, $t, $d, $cor, $id, $setor);
  else    $st->bind_param('sssss', $data, $t, $d, $cor, $setor);
  $st->execute();

  agenda_tocar($db);
  out(array('ok'=>true,'id'=>$id ?: $db->insert_id));
}

if($acao === 'agenda_excluir'){
  if(!pode_agenda($db)) fail('Só a contabilidade pode alterar esta agenda. '
                           . 'Peça a liberação a quem administra o portal.', 403);
  $id = (int)$body['id'];
  $db->query("DELETE FROM tvi_agenda WHERE id=$id");
  agenda_tocar($db);
  out(array('ok'=>true));
}

if($acao === 'agenda_config'){
  if(!pode_agenda($db)) fail('Só a contabilidade pode alterar esta agenda. '
                           . 'Peça a liberação a quem administra o portal.', 403);
  foreach(array('agenda_titulo','agenda_frase','agenda_rodape') as $k){
    if(!isset($body[$k])) continue;
    $v = $db->real_escape_string(_cut(trim((string)$body[$k]), 0, 200));
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$k','$v')
                ON DUPLICATE KEY UPDATE valor='$v'");
  }
  agenda_tocar($db);
  out(array('ok'=>true));
}

/* Lista os aparelhos esperando vínculo, para o painel mostrar. */
/* Publicar versão nova do aplicativo. O painel guarda o número e o
   endereço do APK; os aparelhos comparam e avisam na tela. */
if($acao === 'app_publicar'){
  $ver = (int)(isset($body['versao']) ? $body['versao'] : 0);
  $url = trim((string)(isset($body['url']) ? $body['url'] : ''));
  if($url !== '' && !preg_match('#^https?://#i', $url)) fail('O endereço do APK precisa começar com http.');
  $ve = $db->real_escape_string((string)$ver);
  $ue = $db->real_escape_string(_cut($url, 0, 300));
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('app_versao','$ve')
              ON DUPLICATE KEY UPDATE valor='$ve'");
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('app_url','$ue')
              ON DUPLICATE KEY UPDATE valor='$ue'");
  out(array('ok'=>true));
}

if($acao === 'app_config'){
  out(array('ok'=>true,
            'versao'   => cfg_num($db, 'app_versao', 0),
            'url'      => cfg_txt($db, 'app_url', ''),
            'reinicio' => cfg_txt($db, 'app_reinicio', '04:00')));
}

if($acao === 'app_reinicio'){
  $h = preg_replace('/[^0-9:]/', '', (string)(isset($body['hora']) ? $body['hora'] : ''));
  if(!preg_match('/^\d{2}:\d{2}$/', $h)) fail('Use o formato 04:00.');
  $he = $db->real_escape_string($h);
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('app_reinicio','$he')
              ON DUPLICATE KEY UPDATE valor='$he'");
  out(array('ok'=>true));
}

/* Descarta aparelhos que apareceram no pareamento e nunca viraram TV —
   teste de instalação, box devolvido, celular que abriu o app uma vez.
   Ficavam para sempre na lista de "esperando vínculo", competindo com o
   aparelho que realmente está esperando.

   Só remove os SEM vínculo: apagar um aparelho já ligado a uma TV faria
   o box voltar à tela de código sozinho. */
if($acao === 'aparelho_excluir'){
  /* O painel manda JSON (o $body do topo do arquivo); o formulário
     tradicional manda $_POST. Aceitar os dois evita o tipo de falha que
     não aparece em teste por linha de comando e só quebra no navegador. */
  $ids = array();
  if(isset($body['ids']) && is_array($body['ids']))        $ids = $body['ids'];
  elseif(isset($_POST['ids']) && is_array($_POST['ids']))  $ids = $_POST['ids'];
  elseif(isset($body['id']))                               $ids = array($body['id']);
  elseif(isset($_POST['id']))                              $ids = array($_POST['id']);
  $ids = array_values(array_filter(array_map('intval', $ids)));

  /* "antigos" limpa de uma vez o que não dá mais sinal há dias. */
  $querAntigos = (string)($body['antigos'] ?? $_POST['antigos'] ?? '');
  if(!$ids && $querAntigos === '1'){
    $dias = max(1, min(90, (int)($body['dias'] ?? $_POST['dias'] ?? 2)));
    $r = $db->query("SELECT id FROM tvi_aparelhos
                     WHERE tv_id IS NULL AND visto_em < DATE_SUB(NOW(), INTERVAL $dias DAY)");
    while($r && $x = $r->fetch_assoc()) $ids[] = (int)$x['id'];
  }
  if(!$ids) fail('Nenhum aparelho para remover.');

  $lista = implode(',', $ids);
  $db->query("DELETE FROM tvi_aparelhos WHERE tv_id IS NULL AND id IN ($lista)");
  out(array('ok'=>true, 'removidos'=>$db->affected_rows));
}

if($acao === 'aparelhos'){
  $lista = array();
  $r = $db->query("SELECT a.id, a.codigo, a.modelo, a.visto_em, a.tv_id,
                          a.versao_app, a.tela, a.mem_livre, a.disco_livre,
                          a.app_usado, a.android, t.nome tv_nome,
                          TIMESTAMPDIFF(MINUTE, a.visto_em, NOW()) min_sem_ver
                   FROM tvi_aparelhos a LEFT JOIN tvi_tvs t ON t.id=a.tv_id
                   WHERE a.visto_em > DATE_SUB(NOW(), INTERVAL 30 DAY)
                   ORDER BY a.tv_id IS NOT NULL, a.visto_em DESC");
  while($r && $x = $r->fetch_assoc()) $lista[] = $x;
  out(array('ok'=>true,'aparelhos'=>$lista));
}

/* Vincula um código a uma TV. Aceita TV existente ou cria uma na hora —
   na prática quem instala está com o box na mão e ainda não cadastrou
   nada, e obrigar a criar antes só adiciona um passo. */
if($acao === 'parear_vincular'){
  $cod  = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)(isset($body['codigo']) ? $body['codigo'] : '')));
  $tvId = isset($body['tv_id']) ? (int)$body['tv_id'] : 0;
  $novo = trim((string)(isset($body['nome_novo']) ? $body['nome_novo'] : ''));

  if(strlen($cod) !== 6) fail('O código tem 6 caracteres.');

  $ce = $db->real_escape_string($cod);
  $r = $db->query("SELECT id, tv_id FROM tvi_aparelhos WHERE codigo='$ce' ORDER BY visto_em DESC LIMIT 1");
  if(!$r || !$r->num_rows) fail('Código não encontrado. Confira na tela da TV — ele muda se o aparelho for reinstalado.');
  $ap = $r->fetch_assoc();
  if(!empty($ap['tv_id'])) fail('Este código já está vinculado a uma TV.');

  if(!$tvId){
    if($novo === '') fail('Escolha uma TV ou dê um nome para a nova.');
    /* criar_tv devolve o REGISTRO da TV (array), não o id. Eu tratei o
       retorno como número — e "$tvId" virava a palavra "Array" dentro da
       consulta SQL, que não encontrava nada. O vínculo então falhava em
       silêncio: o painel dizia "vinculado" e o banco não recebia. */
    $nomeNovo = _cut($novo, 0, 60);
    $nova = criar_tv($db, $nomeNovo, array());

    /* Não depender do insert_id: em algumas configurações ele volta 0
       depois de um prepare/execute, e a TV é criada mas o vínculo falha
       com "não consegui criar" — quando ela está lá. Procurar pelo
       código curto é confiável porque ele é único por definição. */
    $tvId = (!empty($nova) && !empty($nova['id'])) ? (int)$nova['id'] : 0;

    if(!$tvId && !empty($nova['codigo_curto'])){
      $cc = $db->real_escape_string($nova['codigo_curto']);
      $rr = $db->query("SELECT id FROM tvi_tvs WHERE codigo_curto='$cc' LIMIT 1");
      if($rr && $rr->num_rows) $tvId = (int)$rr->fetch_assoc()['id'];
    }
    if(!$tvId){
      $ne = $db->real_escape_string($nomeNovo);
      $rr = $db->query("SELECT id FROM tvi_tvs WHERE nome='$ne' ORDER BY id DESC LIMIT 1");
      if($rr && $rr->num_rows) $tvId = (int)$rr->fetch_assoc()['id'];
    }
    if(!$tvId) fail('Não consegui criar a TV. Crie pelo cadastro e vincule a ela.');
  }
  $tvId = (int)$tvId;   // vindo do painel também pode chegar como texto

  /* Confere a TV ANTES de gravar o vínculo.
     Um tv_id que não existe — TV apagada, id vindo errado do painel —
     era gravado assim mesmo, e o aparelho ficava ligado a nada. A
     resposta vinha com nome vazio e o box continuava mostrando o código,
     sem ninguém entender por quê. */
  $r = $db->query("SELECT id, nome, codigo_curto, ativo FROM tvi_tvs WHERE id=$tvId LIMIT 1");
  if(!$r || !$r->num_rows){
    fail('A TV escolhida não existe mais (id '.$tvId.'). '
       . 'Recarregue a página e escolha de novo, ou crie uma TV nova.');
  }
  $tv = $r->fetch_assoc();

  if(empty($tv['codigo_curto'])){
    /* TV antiga, criada antes do código curto existir. Gera agora em vez
       de recusar: o operador não tem como saber disso nem como resolver. */
    $novoCurto = codigo_curto($db, $tv['nome']);
    $ce2 = $db->real_escape_string($novoCurto);
    $db->query("UPDATE tvi_tvs SET codigo_curto='$ce2' WHERE id=$tvId");
    $tv['codigo_curto'] = $novoCurto;
  }

  if(empty($tv['ativo'])) $db->query("UPDATE tvi_tvs SET ativo=1 WHERE id=$tvId");

  $ok = $db->query("UPDATE tvi_aparelhos SET tv_id=$tvId WHERE id=".(int)$ap['id']);
  if(!$ok || $db->affected_rows < 1){
    fail('Não consegui gravar o vínculo. Tente de novo.');
  }

  anotar($db, 'vinculou aparelho', $tv['nome'], 'código '.$cod);
  out(array('ok'=>true,'tv_id'=>$tvId,'tv'=>$tv['nome'],
            'curto'=>$tv['codigo_curto'],
            'url'=>tvi_base_url().'/t.php?c='.$tv['codigo_curto']));
}

/* Desvincula: o aparelho volta a mostrar um código e pode ir para outra
   TV. Serve quando o box é trocado de lugar. */
if($acao === 'parear_soltar'){
  $id = (int)$body['id'];
  $db->query("UPDATE tvi_aparelhos SET tv_id=NULL WHERE id=$id");
  out(array('ok'=>true));
}

/* Duplicar lista. Com dez telas parecidas, montar cada uma do zero é
   meia hora que poderia ser um clique — e cada remontagem manual é uma
   chance de esquecer um item. */
if($acao === 'playlist_duplicar'){
  $id = (int)$body['id'];
  $novoNome = trim((string)(isset($body['nome']) ? $body['nome'] : ''));

  $r = $db->query("SELECT * FROM tvi_playlists WHERE id=$id LIMIT 1");
  if(!$r || !$r->num_rows) fail('Lista não encontrada.');
  $o = $r->fetch_assoc();

  if($novoNome === '') $novoNome = $o['nome'].' (cópia)';

  $st = $db->prepare("INSERT INTO tvi_playlists (nome,descricao,layout,ticker,cidade_clima)
                      VALUES (?,?,?,?,?)");
  $ne = _cut($novoNome, 0, 80);
  $de = isset($o['descricao']) ? $o['descricao'] : '';
  $la = isset($o['layout']) ? $o['layout'] : 'cheia';
  $ti = isset($o['ticker']) ? $o['ticker'] : '';
  $ci = isset($o['cidade_clima']) ? $o['cidade_clima'] : 'Curitiba';
  $st->bind_param('sssss', $ne, $de, $la, $ti, $ci);
  $st->execute();

  $novoId = (int)$db->insert_id;
  if(!$novoId){
    $rr = $db->query("SELECT id FROM tvi_playlists WHERE nome='".$db->real_escape_string($ne)."'
                      ORDER BY id DESC LIMIT 1");
    if($rr && $rr->num_rows) $novoId = (int)$rr->fetch_assoc()['id'];
  }
  if(!$novoId) fail('Não consegui criar a cópia.');

  /* Copia os itens com ordem, duração e as regras de dia e horário.
     Copiar sem as regras seria pior que não copiar: a pessoa acharia que
     está tudo lá e descobriria na parede que não estava. */
  $n = 0;
  $r = $db->query("SELECT midia_id, ordem, duracao_ms, prioridade, dias,
                          hora_de, hora_ate, valido_de, valido_ate, pagina_ms
                   FROM tvi_itens WHERE playlist_id=$id ORDER BY ordem");
  while($r && $x = $r->fetch_assoc()){
    $st = $db->prepare("INSERT INTO tvi_itens
      (playlist_id,midia_id,ordem,duracao_ms,prioridade,dias,hora_de,hora_ate,
       valido_de,valido_ate,pagina_ms)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    if(!$st) continue;
    $mi = (int)$x['midia_id']; $or = (int)$x['ordem']; $du = (int)$x['duracao_ms'];
    $pr = (int)$x['prioridade']; $di = (int)$x['dias']; $pg = (int)$x['pagina_ms'];
    $hd = $x['hora_de']; $ha = $x['hora_ate']; $vd = $x['valido_de']; $va = $x['valido_ate'];
    $st->bind_param('iiiiiissssi', $novoId,$mi,$or,$du,$pr,$di,$hd,$ha,$vd,$va,$pg);
    $st->execute();
    $n++;
  }

  /* As TVs NÃO são copiadas de propósito: duas listas na mesma tela
     brigariam pelo espaço. Quem duplica quer o conteúdo, e escolhe as
     telas depois. */
  anotar($db, 'duplicou lista', $o['nome'], 'cópia: '.$ne.', '.$n.' itens');
  out(array('ok'=>true,'id'=>$novoId,'nome'=>$ne,'itens'=>$n));
}

/* Histórico: quem publicou o quê, e quando. */
if($acao === 'historico'){
  $lista = array();
  $r = $db->query("SELECT quando, usuario, acao, alvo, detalhe
                   FROM tvi_historico ORDER BY id DESC LIMIT 200");
  while($r && $x = $r->fetch_assoc()) $lista[] = $x;
  out(array('ok'=>true,'itens'=>$lista));
}

if($acao === 'convite'){
  $c = array();
  foreach(array('grupo_link','grupo_titulo','grupo_chamada','grupo_itens',
                'grupo_rodape') as $k){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$k'");
    $c[$k] = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  }
  if($c['grupo_titulo'] === ''){
    $c['grupo_titulo']  = 'Redentor Informa';
    $c['grupo_chamada'] = 'Entre no nosso grupo';
    $c['grupo_itens']   = "Avisos de linha alterada\nMudanças de horário\nNovidades da empresa";
    $c['grupo_rodape']  = 'Aponte a câmera do celular';
  }
  out(array('ok'=>true,'config'=>$c));
}

if($acao === 'convite_salvar'){
  $campos = array('grupo_link','grupo_titulo','grupo_chamada','grupo_itens','grupo_rodape');
  foreach((array)$body as $k => $v){
    if(!in_array($k, $campos, true)) continue;
    $v = trim((string)$v);

    if($k === 'grupo_link' && $v !== ''){
      if(!preg_match('#^https?://#i', $v)) $v = 'https://'.ltrim($v, '/');
      /* Só aceita endereço de convite de verdade. Link errado numa parede
         é pior que nenhum: a pessoa aponta a câmera, não acontece nada, e
         não volta a tentar. */
      if(!preg_match('#^https://(chat\.whatsapp\.com|t\.me|telegram\.me)/#i', $v)){
        fail('Use o link de convite do grupo (chat.whatsapp.com/... ou t.me/...). '
           . 'No WhatsApp: Info do grupo, Convidar via link.');
      }
    }

    $ve = $db->real_escape_string(_cut($v, 0, 400));
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$k','$ve')
                ON DUPLICATE KEY UPDATE valor='$ve'");
  }
  $r = $db->query("SELECT DISTINCT i.playlist_id p FROM tvi_itens i
                   JOIN tvi_midias m ON m.id=i.midia_id
                   WHERE m.url_externa LIKE '%tipo=grupo%'");
  while($r && $x = $r->fetch_assoc()) toca_playlist($db, $x['p']);
  out(array('ok'=>true));
}

if($acao === 'redes'){
  $c = array();
  foreach(array('rede_instagram','rede_facebook','rede_linkedin','rede_youtube',
                'rede_site','rede_chamada') as $k){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$k'");
    $c[$k] = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  }
  // O Instagram já pode ter sido configurado no bloco dele: reaproveita.
  if($c['rede_instagram'] === ''){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='insta_arroba'");
    if($r && $r->num_rows) $c['rede_instagram'] = $r->fetch_assoc()['valor'];
  }
  out(array('ok'=>true,'config'=>$c));
}

if($acao === 'redes_salvar'){
  $campos = array('rede_instagram','rede_facebook','rede_linkedin','rede_youtube',
                  'rede_site','rede_chamada');
  foreach((array)$body as $k => $v){
    if(!in_array($k, $campos, true)) continue;
    $v = trim((string)$v);

    /* Aceita as três formas que a pessoa tem à mão: o @, o nome do perfil,
       ou a URL copiada do navegador. Exigir um formato só é o tipo de rigor
       que gera erro de digitação e nenhum ganho. */
    if($k !== 'rede_chamada' && $k !== 'rede_site' && $v !== ''){
      $v = ltrim($v, '@');
      if(preg_match('#(?:instagram|facebook|linkedin|youtube)\.com/([^/?\s]+)#i', $v, $m)){
        $v = $m[1];
      }
      $v = preg_replace('/[^A-Za-z0-9._\-]/', '', $v);
    }
    if($k === 'rede_site' && $v !== '' && !preg_match('#^https?://#i', $v)){
      $v = 'https://'.ltrim($v, '/');
    }

    $ve = $db->real_escape_string(_cut($v, 0, 200));
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$k','$ve')
                ON DUPLICATE KEY UPDATE valor='$ve'");
  }

  $r = $db->query("SELECT DISTINCT i.playlist_id p FROM tvi_itens i
                   JOIN tvi_midias m ON m.id=i.midia_id
                   WHERE m.url_externa LIKE '%tipo=redes%'");
  while($r && $x = $r->fetch_assoc()) toca_playlist($db, $x['p']);
  out(array('ok'=>true));
}

if($acao === 'qualidade'){
  $c = array();
  foreach(array('qa_politica','qa_rev','qa_missao','qa_visao','qa_valores') as $k){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$k'");
    $c[$k] = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  }

  // Primeira abertura: semeia com o texto vigente da empresa.
  if($c['qa_politica'] === ''){
    $c = array(
      'qa_politica' => 'Transportar passageiros visando sua satisfação e a melhoria '
                     . 'contínua dos nossos processos e serviços',
      'qa_rev'      => 'Rev.01',
      'qa_missao'   => 'Transportar pessoas com cordialidade, pontualidade, limpeza e '
                     . 'segurança, buscando o desenvolvimento dos colaboradores, da '
                     . 'comunidade e a rentabilidade da empresa.',
      'qa_visao'    => 'Ser pioneira em soluções de mobilidade e eletromobilidade em '
                     . 'Curitiba, consolidando nossa posição como referência nacional em '
                     . 'inovação, sustentabilidade e alta performance dos nossos colaboradores.',
      'qa_valores'  => "Respeito\nHonestidade\nIntegridade\nComprometimento\n"
                     . "Melhoria contínua\nVisão sistêmica\nSustentabilidade\nTransparência",
    );
    foreach($c as $k => $v){
      $ve = $db->real_escape_string($v);
      $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$k','$ve')
                  ON DUPLICATE KEY UPDATE valor='$ve'");
    }
  }
  out(array('ok'=>true,'config'=>$c));
}

if($acao === 'qualidade_salvar'){
  $ok = array('qa_politica','qa_rev','qa_missao','qa_visao','qa_valores');
  foreach((array)$body as $k => $v){
    if(!in_array($k, $ok, true)) continue;
    $ve = $db->real_escape_string(_cut(trim((string)$v), 0, 2000));
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$k','$ve')
                ON DUPLICATE KEY UPDATE valor='$ve'");
  }
  /* A peça é lida pela TV a cada carga, mas as listas precisam de versão
     nova para o player buscar de novo sem esperar. */
  $r = $db->query("SELECT DISTINCT i.playlist_id p FROM tvi_itens i
                   JOIN tvi_midias m ON m.id=i.midia_id
                   WHERE m.url_externa LIKE '%tipo=qualidade%'");
  while($r && $x = $r->fetch_assoc()) toca_playlist($db, $x['p']);
  out(array('ok'=>true));
}

if($acao === 'insta_arroba'){
  $a = trim((string)(isset($body['arroba']) ? $body['arroba'] : ''));
  $a = ltrim($a, '@');
  // Aceita colar a URL inteira: quem copia do navegador cola assim.
  if(preg_match('#instagram\.com/([A-Za-z0-9._]+)#i', $a, $m)) $a = $m[1];
  $a = preg_replace('/[^A-Za-z0-9._]/', '', $a);
  if($a === '') fail('Informe o @ do perfil.');
  if(strlen($a) > 30) fail('Perfil do Instagram tem no máximo 30 caracteres.');

  $e = $db->real_escape_string($a);
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('insta_arroba','$e')
              ON DUPLICATE KEY UPDATE valor='$e'");
  out(array('ok'=>true,'arroba'=>$a,'url'=>'https://instagram.com/'.$a));
}

/* Confere se o perfil existe. NÃO lê os posts: o Instagram bloqueia isso
   sem autenticação, e qualquer truque para contornar quebra na primeira
   mudança do site. O teste responde o que dá para responder de verdade. */
/* Diagnóstico cru da página do perfil. Existe porque "não consegui" não
   ajuda ninguém: com o código HTTP, o tamanho e o que veio no título dá
   para saber se é login exigido, perfil errado ou bloqueio de IP — e cada
   um tem uma saída diferente. */
if($acao === 'insta_diag'){
  $a = isset($_GET['arroba']) ? preg_replace('/[^A-Za-z0-9._]/', '', ltrim($_GET['arroba'], '@')) : '';
  if($a === '') fail('Informe o @.');

  $url = 'https://www.instagram.com/'.$a.'/';
  $codigo = 0; $html = ''; $erroRede = '';

  if(function_exists('curl_init')){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>15, CURLOPT_FOLLOWLOCATION=>1,
      CURLOPT_SSL_VERIFYPEER=>1,
      CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                        .'(KHTML, like Gecko) Chrome/120.0 Safari/537.36'));
    $html = (string)curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if($html === '') $erroRede = curl_error($ch);
    curl_close($ch);
  } else {
    $erroRede = 'cURL não disponível no servidor';
  }

  $titulo = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(strip_tags($m[1])) : '';

  // O que a página revela sobre si mesma
  $sinais = array(
    'display_url'      => substr_count($html, '"display_url"'),
    'image_versions2'  => substr_count($html, '"image_versions2"'),
    'thumbnail_src'    => substr_count($html, '"thumbnail_src"'),
    'og_image'         => (int)preg_match('#property=["\']og:image["\']#i', $html),
    'pede_login'       => (int)(stripos($html, 'loginForm') !== false
                              || stripos($titulo, 'login') !== false
                              || stripos($html, 'Entre para ver') !== false),
  );

  $extraidos = count(insta_extrair_publico($a));

  // Conclusão em português, com a saída correspondente
  if($erroRede !== ''){
    $conclusao = 'O servidor não conseguiu alcançar o Instagram: '.$erroRede
               . '. Pode ser bloqueio de saída na hospedagem.';
  } elseif($codigo === 404){
    $conclusao = 'Perfil não encontrado (404). Confira a escrita do @.';
  } elseif($codigo === 429 || $codigo === 403){
    $conclusao = 'O Instagram recusou a requisição (código '.$codigo.'). '
               . 'Costuma ser bloqueio do endereço do servidor.';
  } elseif($extraidos > 0){
    $conclusao = 'Funcionou: '.$extraidos.' publicação(ões) extraída(s).';
  } elseif($sinais['pede_login']){
    $conclusao = 'A página abriu, mas o Instagram está exigindo login para ver as '
               . 'publicações. Não há como contornar isso pelo servidor.';
  } elseif($codigo === 200){
    $conclusao = 'A página abriu (200) mas veio sem os dados das publicações. '
               . 'O Instagram entrega a página vazia e carrega os posts depois, por '
               . 'dentro do navegador — o servidor não enxerga esse segundo passo.';
  } else {
    $conclusao = 'Resposta inesperada: código '.$codigo.'.';
  }

  out(array('ok'=>true,'arroba'=>$a,'codigo'=>$codigo,'bytes'=>strlen($html),
            'titulo'=>_cut($titulo, 0, 80), 'sinais'=>$sinais,
            'extraidos'=>$extraidos, 'conclusao'=>$conclusao));
}

if($acao === 'insta_verificar'){
  $a = isset($_GET['arroba']) ? preg_replace('/[^A-Za-z0-9._]/', '', ltrim($_GET['arroba'], '@')) : '';
  if($a === '') fail('Informe o @.');

  $url = 'https://www.instagram.com/'.$a.'/';
  $codigo = 0;
  if(function_exists('curl_init')){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
      CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10, CURLOPT_FOLLOWLOCATION=>1,
      CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; TVIndoor/1.0)'));
    curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  }

  $temToken = insta_cfg($db, 'insta_token') !== '' && insta_cfg($db, 'insta_user_id') !== '';
  $posts = 0; $erroApi = ''; $viaPublico = 0;
  if($temToken){
    $d = insta_posts($db, true);
    if(isset($d['erro'])) $erroApi = $d['erro'];
    else $posts = count($d['posts']);
  }
  // Testa a busca só com o @, que é o caminho que o operador quer usar.
  if(!$posts){
    $achados = insta_extrair_publico($a);
    $viaPublico = count($achados);
    if($viaPublico){
      $lista = array();
      foreach($achados as $x){
        $lista[] = array('img'=>$x['img'],'tipo'=>'IMAGE','quando'=>'','legenda'=>_cut($x['legenda'],0,180));
      }
      insta_cache_set($db, 'instapub:'.$a, $lista);
      insta_set($db, 'insta_pub_em', date('Y-m-d H:i:s'));
    }
  }

  out(array(
    'ok'        => true,
    'arroba'    => $a,
    'url'       => 'https://instagram.com/'.$a,
    'existe'    => ($codigo === 200),
    'codigo'    => $codigo,
    'tem_token' => $temToken,
    'posts'      => $posts,
    'erro_api'   => $erroApi,
    'via_publico'=> isset($viaPublico) ? $viaPublico : 0,
  ));
}

if($acao === 'insta_config'){
  $c = array();
  foreach(array('insta_token','insta_user_id','insta_expira','insta_ultimo_erro','insta_arroba') as $k){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$k'");
    $c[$k] = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  }
  // Nunca devolve o token inteiro: só o fim, para conferir sem expor.
  $c['insta_token_fim'] = $c['insta_token'] !== '' ? substr($c['insta_token'], -6) : '';
  $c['insta_token'] = '';
  $c['dias_restantes'] = $c['insta_expira'] ? max(0, (int)floor((strtotime($c['insta_expira']) - time())/86400)) : null;
  foreach(array('insta_pub_em','insta_pub_erro') as $k){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$k'");
    $c[$k] = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  }
  out(array('ok'=>true,'config'=>$c));
}

if($acao === 'insta_salvar'){
  $tok = trim((string)(isset($body['token']) ? $body['token'] : ''));
  if($tok === '') fail('Cole o token.');

  /* Troca o token de curta duração pelo de longa duração já na hora de
     salvar. O de curta vale 1 hora: guardar ele daria uma parede que
     funciona no teste e morre antes do fim do expediente. */
  $app_id     = trim((string)(isset($body['app_id']) ? $body['app_id'] : ''));
  $app_secret = trim((string)(isset($body['app_secret']) ? $body['app_secret'] : ''));
  $longo = $tok;
  $expira = date('Y-m-d', strtotime('+60 days'));

  if($app_id !== '' && $app_secret !== ''){
    $u = 'https://graph.facebook.com/v21.0/oauth/access_token'
       . '?grant_type=fb_exchange_token&client_id='.rawurlencode($app_id)
       . '&client_secret='.rawurlencode($app_secret)
       . '&fb_exchange_token='.rawurlencode($tok);
    $r = insta_buscar($u);
    $d = $r ? json_decode($r, true) : null;
    if(!empty($d['access_token'])){
      $longo = $d['access_token'];
      if(!empty($d['expires_in'])) $expira = date('Y-m-d', time() + (int)$d['expires_in']);
    }
  }

  // Descobre o ID da conta a partir da Página, para o operador não caçar.
  $uid = trim((string)(isset($body['user_id']) ? $body['user_id'] : ''));
  if($uid === ''){
    $r = insta_buscar('https://graph.facebook.com/v21.0/me/accounts'
                     .'?fields=instagram_business_account,name&access_token='.rawurlencode($longo));
    $d = $r ? json_decode($r, true) : null;
    if(!empty($d['data'])){
      foreach($d['data'] as $pag){
        if(!empty($pag['instagram_business_account']['id'])){
          $uid = $pag['instagram_business_account']['id'];
          break;
        }
      }
    }
  }
  if($uid === '') fail('Token aceito, mas não achei conta do Instagram ligada a uma Página. '
                     . 'Confirme que a conta é Business ou Criador e está vinculada a uma Página do Facebook.');

  $guardar = array('insta_token'=>$longo, 'insta_user_id'=>$uid,
                   'insta_expira'=>$expira, 'insta_ultimo_erro'=>'');
  // Sem app_id e secret não dá para renovar sozinho depois.
  if($app_id !== '')     $guardar['insta_app_id'] = $app_id;
  if($app_secret !== '') $guardar['insta_app_secret'] = $app_secret;
  foreach($guardar as $k => $v){
    $ve = $db->real_escape_string($v);
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$k','$ve')
                ON DUPLICATE KEY UPDATE valor='$ve'");
  }
  $db->query("DELETE FROM tvi_cache WHERE chave LIKE 'insta:%'");
  out(array('ok'=>true,'user_id'=>$uid,'expira'=>$expira,'trocou'=>($longo !== $tok)));
}

if($acao === 'insta_testar'){
  $d = insta_posts($db, true);
  if(isset($d['erro'])) out(array('ok'=>false,'erro'=>$d['erro']));
  out(array('ok'=>true,'total'=>count($d['posts']),
            'posts'=>array_map(function($p){
              return array('tipo'=>$p['tipo'],'quando'=>$p['quando'],
                           'legenda'=>_cut($p['legenda'], 0, 70));
            }, array_slice($d['posts'], 0, 3))));
}

if($acao === 'fontes'){
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='fontes_proprias'");
  $j = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : null;
  $lista = $j !== null ? json_decode($j, true) : null;

  /* Primeira vez: semeia as sugeridas na lista, e daí em diante elas são
     fontes normais — dá para renomear, corrigir o endereço e excluir. Ter
     um catálogo fixo ao lado de outro editável eram duas listas fazendo a
     mesma coisa, e só uma obedecia.
     A marca 'fontes_iniciadas' garante que uma fonte excluída não volte
     sozinha na próxima abertura. */
  $r2 = $db->query("SELECT valor FROM tvi_config WHERE chave='fontes_iniciadas'");
  $jaSemeou = ($r2 && $r2->num_rows);

  if(!is_array($lista) && !$jaSemeou){
    $lista = array(
      array('id'=>1,  'nome'=>'CNN Brasil',          'url'=>'https://www.cnnbrasil.com.br/'),
      array('id'=>2,  'nome'=>'CNN · Tecnologia',    'url'=>'https://www.cnnbrasil.com.br/tecnologia/'),
      array('id'=>3,  'nome'=>'Agência Brasil',      'url'=>'https://agenciabrasil.ebc.com.br/ultimas-noticias'),
      array('id'=>4,  'nome'=>'Poder360',            'url'=>'https://www.poder360.com.br/'),
      array('id'=>5,  'nome'=>'Tecnoblog',           'url'=>'https://tecnoblog.net/'),
      array('id'=>6,  'nome'=>'Tecnoblog · IA',      'url'=>'https://tecnoblog.net/tag/inteligencia-artificial/'),
      array('id'=>7,  'nome'=>'Olhar Digital',       'url'=>'https://olhardigital.com.br/'),
      array('id'=>8,  'nome'=>'Olhar Digital · IA',  'url'=>'https://olhardigital.com.br/editorias/inteligencia-artificial/'),
      array('id'=>9,  'nome'=>'Canaltech',           'url'=>'https://canaltech.com.br/'),
      array('id'=>10, 'nome'=>'MIT Tech Review BR',  'url'=>'https://mittechreview.com.br/'),
    );
    salvar_fontes($db, $lista);
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('fontes_iniciadas','1')
                ON DUPLICATE KEY UPDATE valor='1'");
  }

  out(array('ok'=>true,'fontes'=>is_array($lista) ? $lista : array()));
}

if($acao === 'fonte_salvar'){
  $nome = trim((string)(isset($body['nome']) ? $body['nome'] : ''));
  $url  = trim((string)(isset($body['url']) ? $body['url'] : ''));
  $id   = isset($body['id']) ? (int)$body['id'] : 0;

  if($nome === '') fail('Dê um nome à fonte.');
  if($url !== '' && !preg_match('#^https?://#i', $url)) $url = 'https://'.ltrim($url, '/');
  if(!filter_var($url, FILTER_VALIDATE_URL)) fail('Endereço inválido.');

  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='fontes_proprias'");
  $lista = ($r && $r->num_rows) ? json_decode($r->fetch_assoc()['valor'], true) : array();
  if(!is_array($lista)) $lista = array();

  /* Alguns veículos publicam a arte com a manchete JÁ ESCRITA na imagem.
     Mostrar nosso título por cima duplica o texto e polui. Esta marca faz
     a peça exibir só a foto, com o crédito pequeno no canto. */
  $soImagem = !empty($body['so_imagem']) ? 1 : 0;

  if($id){
    foreach($lista as $k => $f){
      if((int)$f['id'] === $id){
        $lista[$k]['nome'] = _cut($nome,0,50);
        $lista[$k]['url'] = $url;
        $lista[$k]['so_imagem'] = $soImagem;
      }
    }
  } else {
    // Mesma URL já cadastrada: atualiza o nome em vez de duplicar.
    foreach($lista as $k => $f){
      if($f['url'] === $url){
        $lista[$k]['nome'] = _cut($nome,0,50);
        salvar_fontes($db, $lista);
        out(array('ok'=>true,'id'=>(int)$f['id'],'ja_existia'=>true));
      }
    }
    $maior = 0;
    foreach($lista as $f) $maior = max($maior, (int)$f['id']);
    $lista[] = array('id'=>$maior+1, 'nome'=>_cut($nome,0,50), 'url'=>$url,
                     'so_imagem'=>$soImagem);
    $id = $maior+1;
  }

  salvar_fontes($db, $lista);
  out(array('ok'=>true,'id'=>$id,'total'=>count($lista)));
}

if($acao === 'fonte_excluir'){
  $id = (int)$body['id'];
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='fontes_proprias'");
  $lista = ($r && $r->num_rows) ? json_decode($r->fetch_assoc()['valor'], true) : array();
  if(!is_array($lista)) $lista = array();
  $nova = array();
  foreach($lista as $f) if((int)$f['id'] !== $id) $nova[] = $f;
  salvar_fontes($db, $nova);
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('fontes_iniciadas','1')
              ON DUPLICATE KEY UPDATE valor='1'");
  /* A fonte sai do catálogo, mas os itens que já estão nas listas continuam
     funcionando: a URL está gravada neles. Excluir aqui é tirar do menu, não
     apagar da parede, e o painel avisa isso. */
  out(array('ok'=>true,'total'=>count($nova)));
}

/* Peso do banco, por tabela. Existe porque "otimizar" sem medir é palpite:
   com este número dá para ver o que realmente cresce nesta instalação, que
   pode ser diferente do que eu suponho daqui. */
if($acao === 'banco_peso'){
  $linhas = array();
  $total = 0;
  $r = $db->query("SELECT table_name t,
                     COALESCE(data_length,0) + COALESCE(index_length,0) bytes,
                     COALESCE(table_rows,0) linhas
                   FROM information_schema.TABLES
                   WHERE table_schema = DATABASE() AND table_name LIKE 'tvi\\_%'
                   ORDER BY bytes DESC");
  while($r && $x = $r->fetch_assoc()){
    $linhas[] = array('tabela'=>$x['t'], 'bytes'=>(int)$x['bytes'], 'linhas'=>(int)$x['linhas']);
    $total += (int)$x['bytes'];
  }

  // Espaço em disco das mídias, que costuma pesar mais que o banco.
  $disco = 0; $arquivos = 0;
  if(is_dir(PASTA_MIDIA)){
    foreach(glob(PASTA_MIDIA.'/*') as $f){
      if(is_file($f)){ $disco += filesize($f); $arquivos++; }
    }
  }

  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='ultima_manutencao'");
  $ult = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';

  out(array('ok'=>true,'tabelas'=>$linhas,'total'=>$total,
            'disco'=>$disco,'arquivos'=>$arquivos,'ultima_manutencao'=>$ult));
}

/* Roda a manutenção agora, sem esperar o primeiro acesso do dia. */
if($acao === 'banco_limpar'){
  $antes = 0;
  $r = $db->query("SELECT SUM(COALESCE(data_length,0)+COALESCE(index_length,0)) b
                   FROM information_schema.TABLES
                   WHERE table_schema = DATABASE() AND table_name LIKE 'tvi\\_%'");
  if($r && $x = $r->fetch_assoc()) $antes = (int)$x['b'];

  $db->query("DELETE FROM tvi_config WHERE chave='ultima_manutencao'");
  manutencao($db);

  /* OPTIMIZE devolve ao disco o espaço das linhas apagadas. Sem ele o
     arquivo da tabela continua do mesmo tamanho, e a limpeza parece não
     ter feito nada. */
  foreach(array('tvi_exibicoes','tvi_capturas','tvi_comandos','tvi_sinal_hora',
                'tvi_erros','tvi_reinicios','tvi_cache') as $t){
    @$db->query("OPTIMIZE TABLE `$t`");
  }

  $depois = 0;
  $r = $db->query("SELECT SUM(COALESCE(data_length,0)+COALESCE(index_length,0)) b
                   FROM information_schema.TABLES
                   WHERE table_schema = DATABASE() AND table_name LIKE 'tvi\\_%'");
  if($r && $x = $r->fetch_assoc()) $depois = (int)$x['b'];

  out(array('ok'=>true,'antes'=>$antes,'depois'=>$depois,'liberado'=>max(0, $antes - $depois)));
}

if($acao === 'ext_config'){
  $c = array();
  foreach(array('futebol_token','futebol_campeonato','seguranca_desde',
                'seguranca_recorde','aniversarios','noticias_feed','instalacao_modo','noticias_segundos') as $k){
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$k'");
    $c[$k] = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  }
  // Nunca devolve a chave inteira: mostra só o fim, para conferir sem expor.
  if($c['futebol_token'] !== ''){
    $c['futebol_token_fim'] = substr($c['futebol_token'], -6);
    $c['futebol_token'] = '';
  }
  out(array('ok'=>true,'config'=>$c));
}

/* Teste de fonte, respondendo JSON em vez de o painel ter que ler HTML.
   Reporta o endereço realmente encontrado, quantas notícias vieram e,
   principalmente, quantas trouxeram foto. Feed sem imagem vira cartaz de
   texto na parede, e é melhor descobrir isso aqui do que lá. */
if($acao === 'feed_testar'){
  $u = isset($_GET['url']) ? trim($_GET['url']) : '';
  if(!preg_match('#^https?://#i', $u)) fail('O endereço precisa começar com http:// ou https://');

  /* Chama o próprio widget e lê o resultado. Reimplementar a leitura de RSS
     aqui daria duas versões para manter, e um dia elas discordariam. */
  $alvo = base_url().'/widget.php?tipo=noticias&feed='.rawurlencode($u);
  $ctx = stream_context_create(array('http' => array('timeout' => 25, 'ignore_errors' => true)));
  $html = @file_get_contents($alvo, false, $ctx);
  if($html === false && function_exists('curl_init')){
    $ch = curl_init($alvo);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>25));
    $html = curl_exec($ch); curl_close($ch);
  }

  if(!$html) out(array('ok'=>false,'erro'=>'Não consegui chamar o widget para testar.'));

  preg_match_all('#data-origem="([a-z:]*)"#', $html, $og);
  $origens = array_count_values(array_filter(isset($og[1]) ? $og[1] : array()));
  preg_match_all('#<h1 class="mat__tit">(.*?)</h1>#s', $html, $t);
  $titulos = array_map(function($x){ return html_entity_decode(strip_tags($x), ENT_QUOTES, 'UTF-8'); },
                       isset($t[1]) ? $t[1] : array());
  $comFoto = preg_match_all('#<article class="matéria"[^>]*background-image#u', $html);
  preg_match('#<p class="erro">(.*?)</p>#s', $html, $e);

  out(array(
    'ok' => true,
    'itens' => count($titulos),
    'com_foto' => (int)$comFoto,
    'titulos' => array_slice($titulos, 0, 3),
    'origem_img' => $origens,
    'erro_tela' => isset($e[1]) ? trim(strip_tags($e[1])) : '',
  ));
}

if($acao === 'webhook_chave'){
  $nova = 'wh_'.bin2hex(random_bytes(16));
  $e = $db->real_escape_string($nova);
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('webhook_chave','$e')
              ON DUPLICATE KEY UPDATE valor='$e'");
  out(array('ok'=>true,'chave'=>$nova,'url'=>base_url().'/tvindoor.php?action=hook_aniversarios'));
}

if($acao === 'webhook_estado'){
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='webhook_chave'");
  $chave = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='aniversarios_hoje'");
  $dados = ($r && $r->num_rows) ? json_decode($r->fetch_assoc()['valor'], true) : null;
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='insta_hook_em'");
  $igEm = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : '';
  out(array('ok'=>true, 'chave'=>$chave,
            'url'=>base_url().'/tvindoor.php?action=hook_aniversarios',
            'url_instagram'=>base_url().'/tvindoor.php?action=hook_instagram',
            'instagram_em'=>$igEm,
            'ultimo'=>$dados));
}

if($acao === 'ext_salvar'){
  $ok = array('futebol_token','futebol_campeonato','seguranca_desde',
              'aniversarios','noticias_feed','instalacao_modo','noticias_segundos');
  $gravados = array();

  foreach((array)$body as $k => $v){
    if(!in_array($k, $ok, true)) continue;
    if($k === 'futebol_token' && trim($v) === '') continue;   // vazio não apaga

    if($k === 'noticias_segundos'){
      $v = (string)max(6, min(60, (int)$v));
    }

    if($k === 'noticias_feed'){
      $v = trim((string)$v);
      /* Recusar por falta de "https://" era o comportamento antigo, e o aviso
         passava batido: a pessoa colava "tecnoblog.net", via o campo aceitar
         o texto e só descobria que não salvou ao voltar na aba. Completar é
         mais útil que reclamar. */
      if($v !== '' && !preg_match('#^https?://#i', $v)){
        $v = 'https://'.ltrim($v, '/');
      }
      if($v !== '' && !filter_var($v, FILTER_VALIDATE_URL)){
        fail('Não reconheci "'.htmlspecialchars(substr($v, 0, 60)).'" como endereço.');
      }
    }

    $ve = $db->real_escape_string(substr((string)$v, 0, 4000));
    $ke = $db->real_escape_string($k);
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('$ke','$ve')
                ON DUPLICATE KEY UPDATE valor='$ve'");

    // Relê do banco e devolve: o painel confirma com o que ficou gravado,
    // não com o que foi enviado.
    $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$ke'");
    $gravados[$k] = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : null;
  }

  if(!$gravados) fail('Nada para salvar.');
  out(array('ok'=>true,'gravado'=>$gravados));
}

/* Zerar o contador. Guarda o recorde anterior antes, senão a marca que a
   equipe levou meses para construir some com um clique. */
if($acao === 'seguranca_zerar'){
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='seguranca_desde'");
  $desde = ($r && $r->num_rows) ? $r->fetch_assoc()['valor'] : date('Y-m-d');
  $dias = max(0, (int)floor((strtotime(date('Y-m-d')) - strtotime($desde)) / 86400));

  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='seguranca_recorde'");
  $rec = ($r && $r->num_rows) ? (int)$r->fetch_assoc()['valor'] : 0;
  if($dias > $rec) $rec = $dias;

  $hoje = date('Y-m-d');
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('seguranca_desde','$hoje')
              ON DUPLICATE KEY UPDATE valor='$hoje'");
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('seguranca_recorde','$rec')
              ON DUPLICATE KEY UPDATE valor='$rec'");
  out(array('ok'=>true,'zerado_de'=>$dias,'recorde'=>$rec));
}

if($acao === 'alerta_email'){
  $e = trim(isset($body['email']) ? $body['email'] : '');
  if($e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL)) fail('E-mail inválido.');
  $ee = $db->real_escape_string($e);
  $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('alerta_email','$ee')
              ON DUPLICATE KEY UPDATE valor='$ee'");
  out(array('ok'=>true));
}

/* ══════════════ RELATÓRIO DE VEICULAÇÃO ══════════════
   Os dados já estavam sendo gravados desde o primeiro dia. Isto só lê. */

if($acao === 'relatorio'){
  $de  = isset($_GET['de'])  ? $db->real_escape_string(substr($_GET['de'],0,10))  : date('Y-m-d', strtotime('-30 days'));
  $ate = isset($_GET['ate']) ? $db->real_escape_string(substr($_GET['ate'],0,10)) : date('Y-m-d');
  /* Filtro por tela ou grupo. "A campanha rodou 1.240 vezes" é útil;
     "rodou 1.240 vezes nos terminais" é o que vai para a reunião.

     Duas versões da mesma condição — uma para consulta sem JOIN e outra
     com o prefixo da tabela. Montar uma e reescrever com str_replace
     parece esperto e quebra no dia em que a consulta mudar. */
  $filtro = '';
  if(!empty($_GET['tv'])){
    $filtro = ' AND %sTV_ID%s='.(int)$_GET['tv'];
  } elseif(!empty($_GET['grupo'])){
    $g = (int)$_GET['grupo'];
    $filtro = " AND %sTV_ID%s IN (SELECT id FROM tvi_tvs WHERE grupo_id=$g)";
  }
  function janela($de, $ate, $filtro, $pre = ''){
    $j = $pre."exibido_em BETWEEN '$de 00:00:00' AND '$ate 23:59:59'";
    if($filtro) $j .= str_replace(array('%sTV_ID%s'), $pre.'tv_id', $filtro);
    return $j;
  }
  $jan  = janela($de, $ate, $filtro);        // sem JOIN
  $janE = janela($de, $ate, $filtro, 'e.');  // com alias e.

  $tot = array('exibicoes'=>0,'completas'=>0,'segundos'=>0,'telas'=>0,'conteudos'=>0);
  $r = $db->query("SELECT COUNT(*) n, SUM(completo) c, SUM(duracao_ms)/1000 s,
                          COUNT(DISTINCT tv_id) t, COUNT(DISTINCT midia_id) m
                   FROM tvi_exibicoes WHERE $jan");
  if($r && $x = $r->fetch_assoc()){
    $tot = array('exibicoes'=>(int)$x['n'], 'completas'=>(int)$x['c'],
                 'segundos'=>(int)$x['s'], 'telas'=>(int)$x['t'], 'conteudos'=>(int)$x['m']);
  }

  // Por conteúdo: é a linha que marketing leva para a reunião.
  $porMidia = array();
  $r = $db->query("SELECT e.midia_id, COALESCE(m.nome,'(removido)') nome, m.tipo,
                          COUNT(*) n, SUM(e.completo) c, SUM(e.duracao_ms)/1000 s,
                          COUNT(DISTINCT e.tv_id) telas,
                          MIN(e.exibido_em) primeira, MAX(e.exibido_em) ultima
                   FROM tvi_exibicoes e LEFT JOIN tvi_midias m ON m.id=e.midia_id
                   WHERE $janE GROUP BY e.midia_id ORDER BY n DESC LIMIT 60");
  while($r && $x = $r->fetch_assoc()) $porMidia[] = $x;

  $porTv = array();
  $r = $db->query("SELECT e.tv_id, COALESCE(t.nome,'(removida)') nome, t.codigo,
                          COUNT(*) n, SUM(e.duracao_ms)/1000 s
                   FROM tvi_exibicoes e LEFT JOIN tvi_tvs t ON t.id=e.tv_id
                   WHERE $janE GROUP BY e.tv_id ORDER BY n DESC LIMIT 60");
  while($r && $x = $r->fetch_assoc()) $porTv[] = $x;

  $porDia = array();
  $r = $db->query("SELECT DATE(exibido_em) d, COUNT(*) n FROM tvi_exibicoes
                   WHERE $jan GROUP BY DATE(exibido_em) ORDER BY d");
  while($r && $x = $r->fetch_assoc()) $porDia[] = $x;

  $porHora = array_fill(0, 24, 0);
  $r = $db->query("SELECT HOUR(exibido_em) h, COUNT(*) n FROM tvi_exibicoes
                   WHERE $jan GROUP BY HOUR(exibido_em)");
  while($r && $x = $r->fetch_assoc()) $porHora[(int)$x['h']] = (int)$x['n'];

  out(array('ok'=>true,'de'=>$de,'ate'=>$ate,'total'=>$tot,
            'filtro'=>($filtroTv ? 'ativo' : 'nenhum'),
            'por_midia'=>$porMidia,'por_tv'=>$porTv,'por_dia'=>$porDia,'por_hora'=>$porHora));
}

/* ══════════════ COMUNICADO URGENTE ══════════════
   Escreve, escolhe as telas, entra no ar. Sai sozinho na hora marcada —
   é a parte que impede o aviso de ontem de ficar na parede por três dias. */

if($acao === 'urgente'){
  $texto  = trim(isset($body['texto']) ? $body['texto'] : '');
  $alvos  = isset($body['alvos']) ? (array)$body['alvos'] : array();
  $minutos = isset($body['minutos']) ? max(1, min(1440, (int)$body['minutos'])) : 60;
  $seg    = isset($body['segundos']) ? max(3, min(120, (int)$body['segundos'])) : 12;
  if($texto === '') fail('Escreva a mensagem.');
  if(!$alvos) fail('Escolha ao menos uma TV ou grupo.');

  $url = base_url().'/widget.php?tipo=aviso&txt='.rawurlencode($texto);
  $st = $db->prepare("INSERT INTO tvi_midias (nome,tipo,url_externa,duracao_padrao_ms,enviado_por,aprovado)
                      VALUES (?,'web',?,?,?,1)");
  $nome = 'Urgente: '._cut($texto, 0, 60);
  $ms = $seg * 1000;
  $st->bind_param('ssis', $nome, $url, $ms, $usuario);
  $st->execute();
  $midiaId = $db->insert_id;

  $v = nova_versao();
  $st = $db->prepare("INSERT INTO tvi_playlists (nome,descricao,versao) VALUES (?,?,?)");
  $d = 'Comunicado urgente';
  $st->bind_param('sss', $nome, $d, $v);
  $st->execute();
  $plId = $db->insert_id;

  // prioridade 2: enquanto estiver válido, nada mais toca naquelas telas.
  $expira = date('Y-m-d H:i:s', time() + $minutos*60);
  $st = $db->prepare("INSERT INTO tvi_itens (playlist_id,midia_id,ordem,duracao_ms,prioridade,dias,expira_em)
                      VALUES (?,?,1,?,2,127,?)");
  $st->bind_param('iiis', $plId, $midiaId, $ms, $expira);
  $st->execute();

  foreach($alvos as $a){
    $t = $a['tipo'] === 'grupo' ? 'grupo' : 'tv';
    $ai = (int)$a['id'];
    $db->query("INSERT IGNORE INTO tvi_atribuicoes (playlist_id,alvo_tipo,alvo_id) VALUES ($plId,'$t',$ai)");
  }
  /* O urgente é a ação de maior alcance do sistema: interrompe a
   programação de todas as telas marcadas. Registrar quem disparou não é
   burocracia — é o que permite entender depois o que aconteceu. */
anotar($db, 'disparou urgente', _cut($texto, 0, 80), count($alvos).' tela(s)');
out(array('ok'=>true,'playlist_id'=>$plId,'expira_em'=>$expira));
}

if($acao === 'urgente_encerrar'){
  $pl = (int)$body['playlist_id'];
  anotar($db, 'encerrou urgente', 'lista #'.$pl, '');
  $db->query("UPDATE tvi_itens SET expira_em=NOW() WHERE playlist_id=$pl");
  toca_playlist($db, $pl);
  out(array('ok'=>true));
}

if($acao === 'urgentes_ativos'){
  $lista = array();
  $r = $db->query("SELECT p.id, p.nome, MIN(i.expira_em) expira
                   FROM tvi_playlists p JOIN tvi_itens i ON i.playlist_id=p.id
                   WHERE p.descricao='Comunicado urgente' AND i.expira_em > NOW()
                   GROUP BY p.id ORDER BY expira");
  while($r && $x = $r->fetch_assoc()) $lista[] = $x;
  out(array('ok'=>true,'ativos'=>$lista));
}

/* ══════════════ CONTEÚDO POR ENDEREÇO ══════════════
   O caminho para colocar os painéis que você já construiu nas telas.
   O IAK, o de combustível, o de acidentes — todos já são páginas. */

if($acao === 'web_add'){
  $url  = trim(isset($body['url']) ? $body['url'] : '');
  $nome = trim(isset($body['nome']) ? $body['nome'] : '');
  $seg  = isset($body['segundos']) ? max(5, min(600, (int)$body['segundos'])) : 30;
  if(!preg_match('#^https?://#i', $url)) fail('O endereço precisa começar com http:// ou https://');
  if($nome === '') $nome = parse_url($url, PHP_URL_HOST);

  $tipo = preg_match('#youtube\.com|youtu\.be#i', $url) ? 'youtube' : 'web';
  $ms = $seg * 1000;
  $ap = cfg($db,'mod_aprovacao') === '1' ? 0 : 1;
  $st = $db->prepare("INSERT INTO tvi_midias (nome,tipo,url_externa,duracao_padrao_ms,enviado_por,aprovado)
                      VALUES (?,?,?,?,?,?)");
  $st->bind_param('sssisi', $nome, $tipo, $url, $ms, $usuario, $ap);
  $st->execute();
  out(array('ok'=>true,'id'=>$db->insert_id,'nome'=>$nome,'aprovado'=>$ap));
}

/* ══════════════ APROVAÇÃO ══════════════ */

if($acao === 'aprovar'){
  $id = (int)$body['id'];
  $ok = !empty($body['aprovado']) ? 1 : 0;
  $st = $db->prepare("UPDATE tvi_midias SET aprovado=?, aprovado_por=? WHERE id=?");
  $st->bind_param('isi', $ok, $usuario, $id);
  $st->execute();
  $r = $db->query("SELECT DISTINCT playlist_id FROM tvi_itens WHERE midia_id=$id");
  while($r && $x = $r->fetch_assoc()) toca_playlist($db, $x['playlist_id']);
  out(array('ok'=>true));
}

/* ══════════════ CALENDÁRIO ══════════════
   O valor não está no que está agendado — está nos buracos. */

if($acao === 'calendario'){
  $mes = isset($_GET['mes']) ? $db->real_escape_string(substr($_GET['mes'],0,7)) : date('Y-m');
  $ini = $mes.'-01';
  $fim = date('Y-m-t', strtotime($ini));

  $itens = array();
  $r = $db->query("SELECT i.id, i.data_de, i.data_ate, i.dias, i.hora_de, i.hora_ate, i.prioridade,
                          m.nome m_nome, m.tipo, m.valido_ate, p.nome p_nome, p.id p_id
                   FROM tvi_itens i
                   JOIN tvi_midias m ON m.id=i.midia_id
                   JOIN tvi_playlists p ON p.id=i.playlist_id
                   WHERE p.ativa=1 AND m.aprovado=1
                     AND (i.expira_em IS NULL OR i.expira_em > NOW())
                     AND (i.data_de  IS NULL OR i.data_de  <= '$fim')
                     AND (i.data_ate IS NULL OR i.data_ate >= '$ini')
                   ORDER BY i.data_de");
  while($r && $x = $r->fetch_assoc()) $itens[] = $x;

  out(array('ok'=>true,'mes'=>$mes,'inicio'=>$ini,'fim'=>$fim,'itens'=>$itens));
}

/* ══════════════ ZONAS E NOME DA LISTA ══════════════ */

if($acao === 'playlist_layout'){
  $id = (int)$body['id'];
  $lay = in_array($body['layout'], array('cheia','lateral','rodape','completo'), true) ? $body['layout'] : 'cheia';
  $st = $db->prepare("UPDATE tvi_playlists SET layout=?, ticker=?, cidade_clima=? WHERE id=?");
  $tk = isset($body['ticker']) ? _cut($body['ticker'], 0, 600) : null;
  $cc = isset($body['cidade_clima']) ? $body['cidade_clima'] : 'Curitiba';
  $st->bind_param('sssi', $lay, $tk, $cc, $id);
  $st->execute();
  toca_playlist($db, $id);
  out(array('ok'=>true));
}

if($acao === 'playlist_renomear'){
  $id = (int)$body['id'];
  $nome = trim($body['nome']);
  if($nome === '') fail('O nome não pode ficar vazio.');
  $st = $db->prepare("UPDATE tvi_playlists SET nome=?, descricao=? WHERE id=?");
  $desc = isset($body['descricao']) ? $body['descricao'] : '';
  $st->bind_param('ssi', $nome, $desc, $id);
  $st->execute();
  out(array('ok'=>true));
}

/* Grava a ordem inteira de uma vez. Arrastar gera uma sequência nova a cada
   solta — mandar item por item viraria uma rajada de requisições e a ordem
   poderia chegar embaralhada no servidor. */
if($acao === 'item_ordenar'){
  $pl  = (int)$body['playlist_id'];
  $ids = isset($body['ordem']) ? (array)$body['ordem'] : array();
  if(!$pl || !$ids) fail('Ordem vazia.');

  $st = $db->prepare("UPDATE tvi_itens SET ordem=? WHERE id=? AND playlist_id=?");
  $n = 0;
  foreach($ids as $id){
    $n++;
    $iid = (int)$id;
    $st->bind_param('iii', $n, $iid, $pl);
    $st->execute();
  }
  toca_playlist($db, $pl);
  out(array('ok'=>true,'itens'=>$n));
}

if($acao === 'item_excluir'){
  anotar($db, 'tirou item da lista', 'item #'.(int)$body['id'], '');
  $id = (int)$body['id'];
  $r = $db->query("SELECT playlist_id FROM tvi_itens WHERE id=$id");
  $pl = $r ? (int)$r->fetch_assoc()['playlist_id'] : 0;
  $db->query("DELETE FROM tvi_itens WHERE id=$id");
  if($pl) toca_playlist($db, $pl);
  out(array('ok'=>true));
}

/* Adiciona conteúdo já existente na biblioteca a uma lista. É o caminho do
   "quero esse vídeo também na Sala Inovação" sem reenviar o arquivo. */
if($acao === 'item_add'){
  $pl  = (int)$body['playlist_id'];
  $ids = isset($body['midia_ids']) ? (array)$body['midia_ids'] : array();
  if(!$pl || !$ids) fail('Escolha a lista e ao menos um conteúdo.');

  $r = $db->query("SELECT COALESCE(MAX(ordem),0) o FROM tvi_itens WHERE playlist_id=$pl");
  $ordem = $r ? (int)$r->fetch_assoc()['o'] : 0;

  $regra = isset($body['item']) ? $body['item'] : array();
  $n = 0;
  foreach($ids as $mid){
    $mid = (int)$mid;
    // Duração padrão do próprio arquivo, se a lista não mandar outra.
    $rm = $db->query("SELECT duracao_padrao_ms, duracao_ms, tipo FROM tvi_midias WHERE id=$mid");
    if(!$rm || !$rm->num_rows) continue;
    $m = $rm->fetch_assoc();
    $dur = isset($regra['duration_ms']) ? (int)$regra['duration_ms']
         : ($m['tipo'] === 'video' ? (int)($m['duracao_ms'] ?: 10000) : (int)$m['duracao_padrao_ms']);

    $ordem++;
    $st = $db->prepare("INSERT INTO tvi_itens
      (playlist_id,midia_id,ordem,duracao_ms,prioridade,dias,hora_de,hora_ate,data_de,data_ate)
      VALUES (?,?,?,?,?,?,?,?,?,?)");
    $pri  = isset($regra['priority']) ? (int)$regra['priority'] : 0;
    $dias = isset($regra['weekdays']) ? (int)$regra['weekdays'] : 127;
    $hd = !empty($regra['starts_at']) ? $regra['starts_at'] : null;
    $ha = !empty($regra['ends_at'])   ? $regra['ends_at']   : null;
    $dd = !empty($regra['starts_on']) ? $regra['starts_on'] : null;
    $da = !empty($regra['ends_on'])   ? $regra['ends_on']   : null;
    $st->bind_param('iiiiiissss', $pl,$mid,$ordem,$dur,$pri,$dias,$hd,$ha,$dd,$da);
    $st->execute();
    $n++;
  }
  toca_playlist($db, $pl);
  anotar($db, 'incluiu na lista', 'lista #'.$pl, $n.' item(ns)');
  // A posição volta junto: o painel usa para dizer ONDE o item entrou.
  $r = $db->query("SELECT COUNT(*) n FROM tvi_itens WHERE playlist_id=$pl");
  $total = ($r && $x = $r->fetch_assoc()) ? (int)$x['n'] : 0;
  out(array('ok'=>true,'adicionados'=>$n,'total'=>$total));
}

/* Sobe ou desce um item. Ordem importa: numa lista de espera as pessoas veem
   a sequência inteira, e a ordem é parte da mensagem. */
if($acao === 'item_mover'){
  $id  = (int)$body['id'];
  $dir = $body['direcao'] === 'cima' ? -1 : 1;

  $r = $db->query("SELECT playlist_id, ordem FROM tvi_itens WHERE id=$id");
  if(!$r || !$r->num_rows) fail('Item não encontrado.');
  $it = $r->fetch_assoc();
  $pl = (int)$it['playlist_id']; $o = (int)$it['ordem'];

  $cmp = $dir < 0 ? '<' : '>';
  $ord = $dir < 0 ? 'DESC' : 'ASC';
  $v = $db->query("SELECT id, ordem FROM tvi_itens
                   WHERE playlist_id=$pl AND ordem $cmp $o ORDER BY ordem $ord LIMIT 1");
  if(!$v || !$v->num_rows) out(array('ok'=>true,'movido'=>false));  // já está na ponta

  $viz = $v->fetch_assoc();
  $db->query("UPDATE tvi_itens SET ordem=".(int)$viz['ordem']." WHERE id=$id");
  $db->query("UPDATE tvi_itens SET ordem=$o WHERE id=".(int)$viz['id']);
  toca_playlist($db, $pl);
  out(array('ok'=>true,'movido'=>true));
}

if($acao === 'item_editar'){
  if(isset($body['pagina_ms'])){
    $pm = max(2000, min(60000, (int)$body['pagina_ms']));
    $iid = (int)$body['id'];
    $db->query("UPDATE tvi_itens SET pagina_ms=$pm WHERE id=$iid");
  }
  $id = (int)$body['id'];
  $r = $db->query("SELECT playlist_id FROM tvi_itens WHERE id=$id");
  if(!$r || !$r->num_rows) fail('Item não encontrado.');
  $pl = (int)$r->fetch_assoc()['playlist_id'];

  $st = $db->prepare("UPDATE tvi_itens SET duracao_ms=?, prioridade=?, dias=?,
                        hora_de=?, hora_ate=?, data_de=?, data_ate=? WHERE id=?");
  $dur  = isset($body['duracao_ms']) ? (int)$body['duracao_ms'] : 10000;
  $pri  = isset($body['prioridade']) ? (int)$body['prioridade'] : 0;
  $dias = isset($body['dias']) ? (int)$body['dias'] : 127;
  $hd = !empty($body['hora_de'])  ? $body['hora_de']  : null;
  $ha = !empty($body['hora_ate']) ? $body['hora_ate'] : null;
  $dd = !empty($body['data_de'])  ? $body['data_de']  : null;
  $da = !empty($body['data_ate']) ? $body['data_ate'] : null;
  $st->bind_param('iiissssi', $dur,$pri,$dias,$hd,$ha,$dd,$da,$id);
  $st->execute();
  toca_playlist($db, $pl);
  out(array('ok'=>true));
}

/* Extensões: conteúdo que se atualiza sozinho. Entram na biblioteca como
   qualquer outro arquivo, e por isso ganham agendamento e prioridade de
   graça — sem virar um caso especial dentro do player. */
if($acao === 'widget_add'){
  /* Sublinhado precisa passar: "aniversarios_hoje" virava "aniversarioshoje"
     e caía no "Extensão desconhecida". A lista branca logo abaixo é que faz
     a segurança de verdade, não o filtro de caracteres. */
  $tipo   = isset($body['widget']) ? preg_replace('/[^a-z_]/','', $body['widget']) : '';
  $cidade = isset($body['cidade']) ? $body['cidade'] : 'Curitiba';
  $modo   = isset($body['modo']) ? preg_replace('/[^a-z]/','', $body['modo']) : '';

  $CAT = array(
    'relogio'      => array('Relógio', 10000, ''),
    'clima'        => array('Previsão do tempo', 15000, ''),
    'seguranca'    => array('Dias sem acidente', 15000, ''),
    'aniversarios' => array('Aniversariantes do mês', 20000, ''),
    'aniversarios_hoje' => array('Aniversariantes de hoje', 20000, ''),
    'noticias'     => array('Notícias', 20000, ''),
    'instagram'    => array('Instagram', 20000, ''),
    /* 52s: abertura de 6s mais quatro telas de ~11s. Institucional pede
       tempo — ninguém decora valor de empresa em passagem rápida. */
    'qualidade'    => array('Política da Qualidade', 52000, ''),
    /* 22s: tempo de alguém parado tirar o celular do bolso, abrir a câmera
       e apontar. Menos que isso e o QR vira enfeite. */
    'redes'        => array('Redes sociais', 22000, ''),
    'cotacao'      => array('Cotação do dia', 14000, ''),
    /* 30s: é um calendário inteiro. Menos que isso e ninguém acha o
       próprio compromisso antes de a peça sair. */
    'agenda'       => array('Agenda da contabilidade', 30000, ''),
    /* 20s: convite pede menos tempo que a tela de redes, porque tem um QR
       só e a decisão é mais simples — entrar ou não. */
    'grupo'        => array('Grupo Redentor Informa', 20000, ''),
    'futebol'      => array('Brasileirão', 18000, ''),
  );
  if(!isset($CAT[$tipo])) fail('Extensão desconhecida.');

  /* A do dia é a mesma página, em outro modo. Assim o webhook e a inclusão
     manual apontam para a MESMA mídia, e a validade que o robô grava vale
     para o item que já está na lista. */
  $paginaTipo = ($tipo === 'aniversarios_hoje') ? 'aniversarios&modo=hoje' : $tipo;
  $url  = base_url().'/widget.php?tipo='.$paginaTipo;
  $nome = $CAT[$tipo][0];
  $dur  = $CAT[$tipo][1];

  if($tipo === 'clima'){
    $url .= '&cidade='.rawurlencode($cidade);
    $nome .= ' · '.$cidade;
  }
  if($tipo === 'futebol'){
    $m = in_array($modo, array('hoje','tabela','proximos'), true) ? $modo : 'hoje';
    $url .= '&modo='.$m;
    $nome .= ' · '.($m === 'tabela' ? 'Classificação' : ($m === 'proximos' ? 'Próximos jogos' : 'Jogos de hoje'));
  }
  /* Cada fonte vira um item próprio, então a mesma lista pode alternar
     entre CNN e notícia de tecnologia sem precisar escolher uma só. */
  if($tipo === 'noticias'){
    $feed = isset($body['feed']) ? trim($body['feed']) : '';
    if($feed !== ''){
      if(!preg_match('#^https?://#i', $feed)) fail('O endereço do feed precisa começar com http:// ou https://');
      $url .= '&feed='.rawurlencode($feed);
      // A marca viaja junto: a peça precisa saber sem consultar o cadastro.
      if(!empty($body['so_imagem'])) $url .= '&soimg=1';
      $rot = isset($body['rotulo']) ? trim($body['rotulo']) : '';
      $nome .= ' · '.($rot !== '' ? _cut($rot, 0, 40) : parse_url($feed, PHP_URL_HOST));
    }
  }

  /* Notícia: o item precisa durar pelo menos duas manchetes, senão a peça
     entra e sai antes de a segunda aparecer. */
  if($tipo === 'noticias'){
    $r2 = $db->query("SELECT valor FROM tvi_config WHERE chave='noticias_segundos'");
    $seg = ($r2 && $r2->num_rows) ? (int)$r2->fetch_assoc()['valor'] : 18;
    $dur = max(20000, ($seg + 6) * 2 * 1000);
  }

  $r = $db->query("SELECT id FROM tvi_midias WHERE url_externa='".$db->real_escape_string($url)."' LIMIT 1");
  if($r && $r->num_rows) out(array('ok'=>true,'id'=>(int)$r->fetch_assoc()['id'],'ja_existia'=>true));

  $st = $db->prepare("INSERT INTO tvi_midias (nome,tipo,url_externa,duracao_padrao_ms,enviado_por)
                      VALUES (?,'web',?,?,?)");
  $st->bind_param('ssis', $nome, $url, $dur, $usuario);
  $st->execute();
  out(array('ok'=>true,'id'=>$db->insert_id,'nome'=>$nome));
}

/* Remove as listas que o envio criou sozinho antes desta correção.
   Só apaga as que têm exatamente um item e nasceram do envio — e só quando
   o usuário pede. Apagar conteúdo por conta própria não é limpeza, é perda. */
if($acao === 'limpar_fantasmas'){
  $achadas = array();
  $r = $db->query("SELECT p.id, p.nome,
                     (SELECT COUNT(*) FROM tvi_itens i WHERE i.playlist_id=p.id) itens
                   FROM tvi_playlists p
                   WHERE p.descricao='Criada no envio do arquivo'
                   HAVING itens <= 1");
  while($r && $x = $r->fetch_assoc()) $achadas[] = $x;

  if(empty($body['confirmar'])){
    out(array('ok'=>true,'previa'=>$achadas,'total'=>count($achadas)));
  }

  foreach($achadas as $a){
    $id = (int)$a['id'];
    $db->query("DELETE FROM tvi_itens WHERE playlist_id=$id");
    $db->query("DELETE FROM tvi_atribuicoes WHERE playlist_id=$id");
    $db->query("DELETE FROM tvi_playlists WHERE id=$id");
  }
  out(array('ok'=>true,'removidas'=>count($achadas)));
}

if($acao === 'playlist_excluir'){
  anotar($db, 'excluiu lista', 'lista #'.(int)$body['id'], '');
  $id = (int)$body['id'];
  $db->query("DELETE FROM tvi_itens WHERE playlist_id=$id");
  $db->query("DELETE FROM tvi_atribuicoes WHERE playlist_id=$id");
  $db->query("DELETE FROM tvi_playlists WHERE id=$id");
  out(array('ok'=>true));
}

fail('Ação desconhecida: '.$acao);

} catch (Throwable $e) {
  // Qualquer exceção vira JSON legível em vez de página de erro branca.
  fail('Erro no servidor: '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')');
}
