<?php
date_default_timezone_set('America/Sao_Paulo');
/* ============================================================
   sgi.php — API do SGI (Sistema de Gestão Integrada)
   Coloque na MESMA PASTA do index.html. As tabelas nascem sozinhas.

   O QUE O MÓDULO COBRE (fase 1)
   -----------------------------
     · Documentos   — POPs, instruções, formulários, com versão,
                      aprovação e ciência de leitura
     · Ocorrências  — não conformidade (RNC), reclamação de passageiro
                      e oportunidade de melhoria, com análise de causa
                      e verificação de eficácia
     · Ações        — plano de ação de cada ocorrência, com responsável,
                      prazo e evidência
     · Indicadores  — meta e lançamento mensal por empresa
     · Painel       — o que está vencido, o que está pendente, e comigo

   QUEM PODE O QUÊ
   ---------------
   Três papéis, gravados em sgi_acessos (um por usuário do Hub):
     · gestor  — a Qualidade central: vê as empresas todas, aprova
                 documento, verifica eficácia, encerra RNC, configura.
                 Administrador do Hub é sempre gestor.
     · membro  — trabalha nas empresas dele: registra, analisa, monta
                 plano de ação, lança indicador, envia documento.
     · leitor  — lê documento vigente, acompanha e registra ocorrência.
                 Qualquer pessoa precisa poder apontar um problema.
   Usuário do Hub sem linha em sgi_acessos não entra no módulo.

   POR QUE O BANCO PASSA POR UMA CLASSE
   ------------------------------------
   O resto do Hub escapa texto na mão com real_escape_string. Aqui tudo
   vai por consulta preparada — e a mesma classe fala com SQLite, o que
   permite testar o módulo inteiro sem um MySQL rodando
   (testes/sgi_teste.php).
   ============================================================ */

define('SGI_SCHEMA', 1);
define('SGI_MAX_BYTES', 25 * 1024 * 1024);

class SgiErro extends Exception {}
function sgi_erro($m){ throw new SgiErro($m); }

/* ══════════════ BANCO ══════════════ */

class SgiDb {
  public $drv;
  private $c;

  function __construct($c){
    $this->c = $c;
    $this->drv = ($c instanceof PDO) ? 'sqlite' : 'mysql';
    if($this->drv === 'sqlite') $c->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  }

  /* Executa e devolve as linhas (SELECT) ou [] (demais). */
  function q($sql, $p = array()){
    $p = array_values($p);
    if($this->drv === 'sqlite'){
      $st = $this->c->prepare($sql);
      $st->execute($p);
      $this->afetadas = $st->rowCount();
      return $st->columnCount() ? $st->fetchAll(PDO::FETCH_ASSOC) : array();
    }
    $st = $this->c->prepare($sql);
    if(!$st) throw new Exception('SQL: '.$this->c->error);
    if($p){
      $tipos = '';
      foreach($p as $i => $v){
        if(is_bool($v)) $p[$i] = $v ? 1 : 0;
        $tipos .= is_int($v) || is_bool($v) ? 'i' : (is_float($v) ? 'd' : 's');
      }
      $st->bind_param($tipos, ...$p);
    }
    if(!$st->execute()) throw new Exception('SQL: '.$st->error);
    $this->afetadas = $st->affected_rows;
    $r = $st->get_result();
    $linhas = $r ? $r->fetch_all(MYSQLI_ASSOC) : array();
    $st->close();
    return $linhas;
  }
  public $afetadas = 0;

  function linha($sql, $p = array()){ $r = $this->q($sql, $p); return $r ? $r[0] : null; }
  function valor($sql, $p = array()){ $r = $this->linha($sql, $p); return $r ? reset($r) : null; }
  function id(){ return (int)($this->drv === 'sqlite' ? $this->c->lastInsertId() : $this->c->insert_id); }

  function inserir($tabela, $dados){
    $cols = array_keys($dados);
    $this->q("INSERT INTO $tabela (".implode(',', $cols).") VALUES (".implode(',', array_fill(0, count($cols), '?')).")",
             array_values($dados));
    return $this->id();
  }
  function atualizar($tabela, $dados, $id){
    $sets = array();
    foreach(array_keys($dados) as $c) $sets[] = "$c=?";
    $v = array_values($dados); $v[] = (int)$id;
    $this->q("UPDATE $tabela SET ".implode(',', $sets)." WHERE id=?", $v);
  }

  function inicio(){ $this->drv === 'sqlite' ? $this->c->beginTransaction() : $this->c->begin_transaction(); }
  function fim(){ $this->c->commit(); }
  function desfaz(){ $this->drv === 'sqlite' ? $this->c->rollBack() : $this->c->rollback(); }

  /* DDL escrito em MySQL; no SQLite dos testes, traduz o pouco que muda. */
  function ddl($sql){
    if($this->drv === 'sqlite'){
      $sql = str_replace('INT AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
      $sql = preg_replace('/,\s*KEY \w+ \([^)]*\)/', '', $sql);
      $sql = preg_replace('/UNIQUE KEY \w+ \(/', 'UNIQUE (', $sql);
      $sql = preg_replace('/\)\s*ENGINE=.*$/s', ')', $sql);
      $this->c->exec($sql);
      return;
    }
    if(!$this->c->query($sql)) throw new Exception('Criação de tabela: '.$this->c->error);
  }
}

function sgi_hoje(){ return date('Y-m-d'); }
function sgi_agora(){ return date('Y-m-d H:i:s'); }

/* ══════════════ ESTRUTURA ══════════════ */

function sgi_estrutura(SgiDb $db){
  $db->ddl("CREATE TABLE IF NOT EXISTS sgi_config (
    chave VARCHAR(60) NOT NULL PRIMARY KEY,
    valor TEXT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  /* As tabelas só são conferidas quando a versão do esquema muda — não a
     cada chamada, como faz o TV Indoor: são treze CREATE por pedido. */
  if((int)$db->valor("SELECT valor FROM sgi_config WHERE chave='schema'") >= SGI_SCHEMA) return;

  $t = array();
  $t[] = "CREATE TABLE IF NOT EXISTS sgi_empresas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    sigla VARCHAR(20) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_acessos (
    usuario_id INT NOT NULL PRIMARY KEY,
    papel VARCHAR(20) NOT NULL,
    empresas TEXT NULL,
    atualizado_em DATETIME NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NULL,
    codigo VARCHAR(40) NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    tipo VARCHAR(40) NOT NULL,
    processo VARCHAR(80) NULL,
    normas VARCHAR(60) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
    versao_vigente INT NULL,
    revisao_meses INT NOT NULL DEFAULT 12,
    revisar_em DATE NULL,
    exige_ciencia TINYINT(1) NOT NULL DEFAULT 0,
    criado_por INT NULL,
    criado_em DATETIME NULL,
    atualizado_em DATETIME NULL,
    UNIQUE KEY uk_codigo (codigo),
    KEY idx_revisar (revisar_em)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_doc_versoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_id INT NOT NULL,
    versao INT NOT NULL,
    arquivo VARCHAR(120) NOT NULL,
    nome_original VARCHAR(200) NOT NULL,
    mime VARCHAR(100) NULL,
    bytes INT NOT NULL DEFAULT 0,
    alteracao TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    enviado_por INT NULL,
    enviado_em DATETIME NULL,
    decidido_por INT NULL,
    decidido_em DATETIME NULL,
    parecer TEXT NULL,
    UNIQUE KEY uk_versao (documento_id, versao)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_doc_ciencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    documento_id INT NOT NULL,
    versao INT NOT NULL,
    usuario_id INT NOT NULL,
    em DATETIME NULL,
    UNIQUE KEY uk_ciencia (documento_id, versao, usuario_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_ocorrencias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    numero VARCHAR(20) NOT NULL,
    ano INT NOT NULL,
    seq INT NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    descricao TEXT NULL,
    origem VARCHAR(60) NULL,
    origem_id INT NULL,
    norma VARCHAR(10) NULL,
    processo VARCHAR(80) NULL,
    local VARCHAR(120) NULL,
    data_fato DATE NULL,
    gravidade VARCHAR(10) NOT NULL DEFAULT 'media',
    status VARCHAR(20) NOT NULL DEFAULT 'aberta',
    responsavel_id INT NULL,
    prazo DATE NULL,
    acao_imediata TEXT NULL,
    causa_metodo VARCHAR(20) NULL,
    causa_dados TEXT NULL,
    causa_raiz TEXT NULL,
    eficacia VARCHAR(10) NULL,
    eficacia_obs TEXT NULL,
    verificado_por INT NULL,
    verificado_em DATETIME NULL,
    encerrada_em DATETIME NULL,
    canal VARCHAR(40) NULL,
    linha VARCHAR(40) NULL,
    veiculo VARCHAR(20) NULL,
    reclamante VARCHAR(120) NULL,
    contato VARCHAR(120) NULL,
    protocolo_externo VARCHAR(40) NULL,
    resposta TEXT NULL,
    respondida_em DATETIME NULL,
    aberta_por INT NULL,
    criado_em DATETIME NULL,
    atualizado_em DATETIME NULL,
    UNIQUE KEY uk_numero (numero),
    KEY idx_empresa (empresa_id, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_acoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ocorrencia_id INT NOT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'corretiva',
    descricao TEXT NOT NULL,
    responsavel_id INT NULL,
    prazo DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pendente',
    evidencia TEXT NULL,
    concluida_em DATETIME NULL,
    criado_por INT NULL,
    criado_em DATETIME NULL,
    KEY idx_oc (ocorrencia_id),
    KEY idx_resp (responsavel_id, status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_anexos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_tipo VARCHAR(20) NOT NULL,
    ref_id INT NOT NULL,
    arquivo VARCHAR(120) NOT NULL,
    nome_original VARCHAR(200) NOT NULL,
    mime VARCHAR(100) NULL,
    bytes INT NOT NULL DEFAULT 0,
    enviado_por INT NULL,
    enviado_em DATETIME NULL,
    KEY idx_ref (ref_tipo, ref_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_historico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_tipo VARCHAR(20) NOT NULL,
    ref_id INT NOT NULL,
    usuario_id INT NULL,
    tipo VARCHAR(20) NOT NULL DEFAULT 'evento',
    texto TEXT NOT NULL,
    em DATETIME NULL,
    KEY idx_ref (ref_tipo, ref_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_indicadores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    descricao TEXT NULL,
    unidade VARCHAR(20) NULL,
    sentido VARCHAR(10) NOT NULL DEFAULT 'maior',
    meta DECIMAL(14,4) NULL,
    casas INT NOT NULL DEFAULT 1,
    norma VARCHAR(10) NULL,
    processo VARCHAR(80) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ordem INT NOT NULL DEFAULT 0
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  $t[] = "CREATE TABLE IF NOT EXISTS sgi_ind_valores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    indicador_id INT NOT NULL,
    empresa_id INT NOT NULL,
    competencia CHAR(7) NOT NULL,
    valor DECIMAL(14,4) NOT NULL,
    obs TEXT NULL,
    lancado_por INT NULL,
    lancado_em DATETIME NULL,
    UNIQUE KEY uk_valor (indicador_id, empresa_id, competencia)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

  foreach($t as $sql) $db->ddl($sql);

  /* Indicadores de partida, comuns a quem opera ônibus urbano. As metas
     são ponto de partida: o gestor ajusta em Configurações. */
  if(!(int)$db->valor("SELECT COUNT(*) FROM sgi_indicadores")){
    $base = array(
      array('Pontualidade das viagens', '%', 'maior', 95, 1, '9001', 'Operação',
            'Viagens iniciadas no horário ÷ viagens programadas × 100'),
      array('Cumprimento de viagens', '%', 'maior', 98, 1, '9001', 'Operação',
            'Viagens realizadas ÷ viagens programadas × 100'),
      array('Reclamações por 100 mil passageiros', 'índice', 'menor', 5, 2, '9001', 'Atendimento',
            'Reclamações recebidas ÷ passageiros transportados × 100.000'),
      array('Disponibilidade da frota', '%', 'maior', 95, 1, '9001', 'Manutenção',
            'Veículos disponíveis no pico ÷ frota necessária × 100'),
      array('Média de consumo (km/L)', 'km/L', 'maior', 2.15, 2, '14001', 'Manutenção',
            'Quilômetros rodados ÷ litros de diesel consumidos'),
      array('Acidentes de trânsito por 100 mil km', 'índice', 'menor', 1, 2, '39001', 'Operação',
            'Acidentes com o veículo em serviço ÷ km rodados × 100.000'),
      array('Taxa de frequência de acidentes de trabalho', 'índice', 'menor', 10, 2, '45001', 'SST',
            'Acidentes com afastamento × 1.000.000 ÷ horas-homem trabalhadas'),
      array('Horas de treinamento por colaborador', 'h', 'maior', 2, 1, '9001', 'RH',
            'Horas de treinamento no mês ÷ colaboradores ativos'),
    );
    foreach($base as $i => $b){
      $db->inserir('sgi_indicadores', array(
        'nome'=>$b[0], 'unidade'=>$b[1], 'sentido'=>$b[2], 'meta'=>$b[3], 'casas'=>$b[4],
        'norma'=>$b[5], 'processo'=>$b[6], 'descricao'=>$b[7], 'ativo'=>1, 'ordem'=>$i + 1));
    }
  }

  if($db->valor("SELECT valor FROM sgi_config WHERE chave='schema'") === null)
    $db->q("INSERT INTO sgi_config (chave, valor) VALUES ('schema', ?)", array((string)SGI_SCHEMA));
  else
    $db->q("UPDATE sgi_config SET valor=? WHERE chave='schema'", array((string)SGI_SCHEMA));
}

/* ══════════════ LISTAS FIXAS ══════════════ */

function sgi_listas(){
  return array(
    'normas' => array(
      '9001'  => 'ISO 9001 · Qualidade',
      '14001' => 'ISO 14001 · Meio ambiente',
      '45001' => 'ISO 45001 · Saúde e segurança',
      '39001' => 'ISO 39001 · Segurança viária',
    ),
    'tipos_ocorrencia' => array(
      'nc'         => 'Não conformidade',
      'reclamacao' => 'Reclamação de passageiro',
      'melhoria'   => 'Oportunidade de melhoria',
    ),
    'origens' => array('Auditoria interna', 'Auditoria externa', 'Reclamação de cliente',
      'Inspeção / fiscalização', 'Indicador fora da meta', 'Processo interno', 'Fornecedor',
      'Acidente / incidente', 'Sugestão de colaborador', 'Requisito legal'),
    'canais' => array('SAC / telefone', '156 / Prefeitura', 'URBS', 'E-mail', 'Redes sociais',
      'WhatsApp', 'Presencial', 'Outro'),
    'gravidades' => array('baixa'=>'Baixa', 'media'=>'Média', 'alta'=>'Alta', 'critica'=>'Crítica'),
    'status' => array('aberta'=>'Aberta', 'analise'=>'Em análise', 'acao'=>'Em ação',
      'verificacao'=>'Verificação de eficácia', 'encerrada'=>'Encerrada', 'cancelada'=>'Cancelada'),
    'tipos_acao' => array('contencao'=>'Contenção', 'corretiva'=>'Corretiva',
      'preventiva'=>'Preventiva', 'melhoria'=>'Melhoria'),
    'tipos_documento' => array('Manual', 'Política', 'Procedimento (POP)', 'Instrução de trabalho',
      'Formulário', 'Plano', 'Registro', 'Documento externo'),
    'metodos_causa' => array('5porques'=>'5 Porquês', 'ishikawa'=>'Ishikawa (6M)', 'livre'=>'Descrição livre'),
    'papeis' => array('gestor'=>'Gestor do SGI', 'membro'=>'Membro', 'leitor'=>'Leitor'),
  );
}

/* ══════════════ QUEM ESTÁ PEDINDO ══════════════ */

function sgi_eu(SgiDb $db, $uid){
  $u = $db->linha("SELECT id, name, username, role FROM portal_usuarios WHERE id=?", array((int)$uid));
  if(!$u) sgi_erro('Usuário não encontrado. Entre novamente no Hub.');
  $a = $db->linha("SELECT papel, empresas FROM sgi_acessos WHERE usuario_id=?", array((int)$uid));

  $papel = $a ? $a['papel'] : null;
  if($u['role'] === 'admin') $papel = 'gestor';

  $todas = array_map('intval', array_column(
    $db->q("SELECT id FROM sgi_empresas WHERE ativo=1 ORDER BY ordem, nome"), 'id'));
  if($papel === 'gestor') $emp = $todas;
  else {
    $lista = $a && $a['empresas'] ? json_decode($a['empresas'], true) : array();
    $emp = array_values(array_intersect($todas, array_map('intval', (array)$lista)));
  }
  return array('id'=>(int)$u['id'], 'nome'=>$u['name'] ?: $u['username'],
               'papel'=>$papel, 'empresas'=>$emp, 'admin_hub'=>$u['role'] === 'admin');
}

function sgi_nivel($papel){ return array('leitor'=>1, 'membro'=>2, 'gestor'=>3)[$papel] ?? 0; }
function sgi_exige($me, $papel){
  if(sgi_nivel($me['papel']) < sgi_nivel($papel)){
    sgi_erro($papel === 'gestor' ? 'Só o gestor do SGI pode fazer isso.'
                                 : 'Seu acesso ao SGI é só de leitura para isso.');
  }
}
function sgi_empresa_ok($me, $empresa_id){
  if(!in_array((int)$empresa_id, $me['empresas'], true))
    sgi_erro('Você não tem acesso a essa empresa no SGI.');
}
/* "IN (?,?,?)" com as empresas do usuário. Sem empresa, nada casa. */
function sgi_in($ids){
  if(!$ids) return array('(0)', array());
  return array('('.implode(',', array_fill(0, count($ids), '?')).')', array_values($ids));
}

/* ══════════════ VALIDAÇÃO ══════════════ */

function sgi_txt($b, $k, $max, $obrig = false, $rotulo = null){
  $v = isset($b[$k]) ? trim((string)$b[$k]) : '';
  if($obrig && $v === '') sgi_erro('Preencha: '.($rotulo ?: $k).'.');
  if(mb_strlen($v, 'UTF-8') > $max) sgi_erro(($rotulo ?: $k).': no máximo '.$max.' caracteres.');
  return $v === '' ? null : $v;
}
function sgi_data($b, $k, $obrig = false, $rotulo = null){
  $v = isset($b[$k]) ? trim((string)$b[$k]) : '';
  if($v === ''){ if($obrig) sgi_erro('Informe a data: '.($rotulo ?: $k).'.'); return null; }
  $d = DateTime::createFromFormat('Y-m-d', $v);
  if(!$d || $d->format('Y-m-d') !== $v) sgi_erro('Data inválida em '.($rotulo ?: $k).'.');
  return $v;
}
function sgi_escolha($b, $k, $opcoes, $padrao = null){
  $v = isset($b[$k]) ? (string)$b[$k] : '';
  if($v === '') return $padrao;
  if(!in_array($v, $opcoes, true)) sgi_erro('Valor inválido em '.$k.'.');
  return $v;
}
function sgi_usuario_valido(SgiDb $db, $id){
  if($id === null || $id === '' || (int)$id === 0) return null;
  if(!$db->valor("SELECT id FROM portal_usuarios WHERE id=?", array((int)$id))) sgi_erro('Responsável não encontrado.');
  return (int)$id;
}

function sgi_hist(SgiDb $db, $ref, $id, $me, $texto, $tipo = 'evento'){
  $db->inserir('sgi_historico', array('ref_tipo'=>$ref, 'ref_id'=>(int)$id,
    'usuario_id'=>$me ? $me['id'] : null, 'tipo'=>$tipo, 'texto'=>$texto, 'em'=>sgi_agora()));
}

function sgi_nomes(SgiDb $db){
  $m = array();
  foreach($db->q("SELECT id, name, username FROM portal_usuarios") as $u)
    $m[(int)$u['id']] = $u['name'] ?: $u['username'];
  return $m;
}

/* ══════════════ ARQUIVOS ══════════════ */

function sgi_pasta(){
  $d = defined('SGI_PASTA') ? SGI_PASTA : __DIR__.'/uploads/sgi';
  if(!is_dir($d)) @mkdir($d, 0755, true);
  /* Documento interno e evidência de RNC não são públicos: a pasta nega
     tudo, e o download passa por baixar(), que confere o acesso. */
  if(!file_exists($d.'/.htaccess'))
    @file_put_contents($d.'/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
  if(!is_dir($d) || !is_writable($d)) sgi_erro('A pasta uploads/sgi não existe ou não aceita gravação.');
  return $d;
}

function sgi_guardar_arquivo($f){
  if(!$f || !isset($f['error'])) sgi_erro('Nenhum arquivo recebido.');
  if($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE)
    sgi_erro('Arquivo maior que o servidor aceita.');
  if($f['error'] !== UPLOAD_ERR_OK) sgi_erro('O envio do arquivo falhou (código '.$f['error'].').');
  if($f['size'] > SGI_MAX_BYTES) sgi_erro('Arquivo acima de 25 MB.');

  $nome = basename((string)$f['name']);
  $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
  $ok = array('pdf','doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp','txt','csv',
              'jpg','jpeg','png','webp','gif','heic','mp4','mov','zip');
  if(!in_array($ext, $ok, true)) sgi_erro('Tipo de arquivo não aceito (.'.$ext.').');

  $guardado = bin2hex(random_bytes(16)).'.'.$ext;
  $destino = sgi_pasta().'/'.$guardado;
  $movido = defined('SGI_TESTE') ? @copy($f['tmp_name'], $destino) : @move_uploaded_file($f['tmp_name'], $destino);
  if(!$movido) sgi_erro('Não foi possível gravar o arquivo no servidor.');

  $mime = 'application/octet-stream';
  if(function_exists('finfo_open')){ $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = finfo_file($fi, $destino) ?: $mime; finfo_close($fi); }
  return array('arquivo'=>$guardado, 'nome_original'=>mb_substr($nome, 0, 200, 'UTF-8'),
               'mime'=>$mime, 'bytes'=>(int)$f['size']);
}

/* ══════════════ REGRAS DAS OCORRÊNCIAS ══════════════ */

function sgi_prefixo($tipo){ return array('nc'=>'RNC', 'reclamacao'=>'REC', 'melhoria'=>'OM')[$tipo]; }

/* Para onde cada tipo pode ir a partir de cada fase. A reclamação não
   passa por análise de causa nem eficácia: se o problema for sistêmico,
   ela gera uma RNC, que passa. */
function sgi_transicoes($tipo){
  if($tipo === 'nc') return array(
    'aberta'=>array('analise','cancelada'),
    'analise'=>array('acao','aberta','cancelada'),
    'acao'=>array('verificacao','analise','cancelada'),
    'verificacao'=>array('encerrada','acao'),
    'encerrada'=>array('analise'),
    'cancelada'=>array('aberta'));
  return array(
    'aberta'=>array('acao','encerrada','cancelada'),
    'acao'=>array('encerrada','aberta','cancelada'),
    'encerrada'=>array('acao'),
    'cancelada'=>array('aberta'));
}

function sgi_oc_carregar(SgiDb $db, $me, $id){
  $o = $db->linha("SELECT * FROM sgi_ocorrencias WHERE id=?", array((int)$id));
  if(!$o) sgi_erro('Ocorrência não encontrada.');
  sgi_empresa_ok($me, $o['empresa_id']);
  return $o;
}

function sgi_oc_campos(SgiDb $db, $b, $tipo){
  $L = sgi_listas();
  $c = array(
    'titulo'        => sgi_txt($b, 'titulo', 200, true, 'título'),
    'descricao'     => sgi_txt($b, 'descricao', 8000, true, 'descrição'),
    'origem'        => sgi_txt($b, 'origem', 60),
    'norma'         => sgi_escolha($b, 'norma', array_map('strval', array_keys($L['normas']))),
    'processo'      => sgi_txt($b, 'processo', 80),
    'local'         => sgi_txt($b, 'local', 120),
    'data_fato'     => sgi_data($b, 'data_fato', false, 'data do fato'),
    'gravidade'     => sgi_escolha($b, 'gravidade', array_keys($L['gravidades']), 'media'),
    'responsavel_id'=> sgi_usuario_valido($db, $b['responsavel_id'] ?? null),
    'prazo'         => sgi_data($b, 'prazo', false, 'prazo'),
    'acao_imediata' => sgi_txt($b, 'acao_imediata', 8000),
  );
  if($c['data_fato'] && $c['data_fato'] > sgi_hoje()) sgi_erro('A data do fato não pode ser no futuro.');
  if($tipo === 'reclamacao'){
    $c += array(
      'canal'             => sgi_txt($b, 'canal', 40),
      'linha'             => sgi_txt($b, 'linha', 40),
      'veiculo'           => sgi_txt($b, 'veiculo', 20),
      'reclamante'        => sgi_txt($b, 'reclamante', 120),
      'contato'           => sgi_txt($b, 'contato', 120),
      'protocolo_externo' => sgi_txt($b, 'protocolo_externo', 40),
    );
    if(!$c['origem']) $c['origem'] = 'Reclamação de cliente';
  }
  return $c;
}

function sgi_oc_criar(SgiDb $db, $me, $empresa_id, $tipo, $campos){
  $ano = (int)date('Y');
  for($tentativa = 0; $tentativa < 5; $tentativa++){
    $seq = 1 + (int)$db->valor("SELECT MAX(seq) FROM sgi_ocorrencias WHERE tipo=? AND ano=?", array($tipo, $ano));
    $numero = sgi_prefixo($tipo).'-'.$ano.'-'.str_pad($seq, 4, '0', STR_PAD_LEFT);
    try {
      $id = $db->inserir('sgi_ocorrencias', $campos + array(
        'empresa_id'=>(int)$empresa_id, 'tipo'=>$tipo, 'numero'=>$numero, 'ano'=>$ano, 'seq'=>$seq,
        'status'=>'aberta', 'aberta_por'=>$me['id'], 'criado_em'=>sgi_agora(), 'atualizado_em'=>sgi_agora()));
      return array($id, $numero);
    } catch(Exception $e){
      /* Dois registros ao mesmo tempo disputam o mesmo número: o índice
         único barra o segundo, que tenta o seguinte. */
      if(stripos($e->getMessage(), 'uniq') === false && stripos($e->getMessage(), 'Duplicate') === false) throw $e;
    }
  }
  sgi_erro('Não consegui numerar a ocorrência. Tente de novo.');
}

/* Membro edita o que é da empresa dele; leitor só o que ele mesmo abriu
   e ainda não andou. */
function sgi_pode_editar_oc($me, $o){
  if(sgi_nivel($me['papel']) >= 2) return true;
  return (int)$o['aberta_por'] === $me['id'] && $o['status'] === 'aberta';
}

/* ══════════════ AÇÕES DA API ══════════════ */

function sgi_executar(SgiDb $db, $uid, $acao, $b, $arquivos = array()){
  $me = sgi_eu($db, $uid);
  $L = sgi_listas();
  $hoje = sgi_hoje();

  if($acao === 'eu'){
    $emp = $db->q("SELECT id, nome, sigla, ativo, ordem FROM sgi_empresas ORDER BY ordem, nome");
    $usuarios = array();
    if($me['papel']){
      foreach($db->q("SELECT u.id, u.name, u.username, u.role, a.papel, a.empresas
                      FROM portal_usuarios u LEFT JOIN sgi_acessos a ON a.usuario_id=u.id
                      ORDER BY u.name") as $u){
        $p = $u['role'] === 'admin' ? 'gestor' : $u['papel'];
        if(!$p) continue;
        $usuarios[] = array('id'=>(int)$u['id'], 'nome'=>$u['name'] ?: $u['username'], 'papel'=>$p,
          'empresas'=>$p === 'gestor' ? 'todas' : array_map('intval', (array)json_decode($u['empresas'] ?: '[]', true)));
      }
    }
    return array('eu'=>$me, 'empresas'=>$emp, 'usuarios'=>$usuarios, 'listas'=>$L, 'hoje'=>$hoje);
  }

  if(!$me['papel']) sgi_erro('Você ainda não tem acesso ao SGI. Peça ao gestor do SGI para liberar.');

  switch($acao){

  /* ─────────────── PAINEL ─────────────── */
  case 'painel': {
    $ids = $me['empresas'];
    if(!empty($b['empresa'])){ sgi_empresa_ok($me, $b['empresa']); $ids = array((int)$b['empresa']); }
    list($in, $pin) = sgi_in($ids);
    $ativas = "status NOT IN ('encerrada','cancelada')";

    $porTipo = array('nc'=>0, 'reclamacao'=>0, 'melhoria'=>0);
    foreach($db->q("SELECT tipo, COUNT(*) n FROM sgi_ocorrencias WHERE empresa_id IN $in AND $ativas GROUP BY tipo", $pin) as $r)
      $porTipo[$r['tipo']] = (int)$r['n'];

    $porStatus = array();
    foreach($db->q("SELECT status, COUNT(*) n FROM sgi_ocorrencias WHERE empresa_id IN $in AND tipo='nc' AND $ativas GROUP BY status", $pin) as $r)
      $porStatus[$r['status']] = (int)$r['n'];

    $acoesVencidas = (int)$db->valor("SELECT COUNT(*) FROM sgi_acoes a JOIN sgi_ocorrencias o ON o.id=a.ocorrencia_id
      WHERE o.empresa_id IN $in AND a.status='pendente' AND a.prazo < ?", array_merge($pin, array($hoje)));
    $acoesSemana = (int)$db->valor("SELECT COUNT(*) FROM sgi_acoes a JOIN sgi_ocorrencias o ON o.id=a.ocorrencia_id
      WHERE o.empresa_id IN $in AND a.status='pendente' AND a.prazo BETWEEN ? AND ?",
      array_merge($pin, array($hoje, date('Y-m-d', strtotime('+7 days')))));

    $mes = date('Y-m'); $mesAnt = date('Y-m', strtotime('first day of last month'));
    $rec = function($m) use ($db, $in, $pin){
      return (int)$db->valor("SELECT COUNT(*) FROM sgi_ocorrencias WHERE empresa_id IN $in AND tipo='reclamacao'
        AND criado_em >= ? AND criado_em < ?", array_merge($pin, array($m.'-01', date('Y-m-01', strtotime($m.'-01 +1 month')))));
    };

    $docsVis = "(empresa_id IS NULL OR empresa_id IN $in)";
    $docRevVencida = (int)$db->valor("SELECT COUNT(*) FROM sgi_documentos WHERE $docsVis AND status='vigente' AND revisar_em < ?", array_merge($pin, array($hoje)));
    $docRev30 = (int)$db->valor("SELECT COUNT(*) FROM sgi_documentos WHERE $docsVis AND status='vigente' AND revisar_em BETWEEN ? AND ?",
      array_merge($pin, array($hoje, date('Y-m-d', strtotime('+30 days')))));
    $docPend = (int)$db->valor("SELECT COUNT(DISTINCT d.id) FROM sgi_documentos d JOIN sgi_doc_versoes v ON v.documento_id=d.id
      WHERE (d.empresa_id IS NULL OR d.empresa_id IN $in) AND v.status='pendente'", $pin);

    /* Indicadores: o último mês fechado com algum lançamento. */
    $ultimo = $db->valor("SELECT MAX(competencia) FROM sgi_ind_valores WHERE empresa_id IN $in", $pin);
    $ind = array('competencia'=>$ultimo, 'dentro'=>0, 'fora'=>0, 'fora_lista'=>array());
    if($ultimo){
      foreach($db->q("SELECT i.nome, i.sentido, i.meta, i.unidade, i.casas, v.valor, v.empresa_id FROM sgi_ind_valores v
                      JOIN sgi_indicadores i ON i.id=v.indicador_id
                      WHERE v.competencia=? AND v.empresa_id IN $in AND i.ativo=1", array_merge(array($ultimo), $pin)) as $r){
        if($r['meta'] === null) continue;
        $ok = $r['sentido'] === 'menor' ? (float)$r['valor'] <= (float)$r['meta'] : (float)$r['valor'] >= (float)$r['meta'];
        if($ok) $ind['dentro']++; else { $ind['fora']++; $ind['fora_lista'][] = $r; }
      }
    }

    /* Tabela por empresa — só faz sentido para quem vê mais de uma. */
    $porEmpresa = array();
    if(count($ids) > 1){
      $vencPorEmp = array();
      foreach($db->q("SELECT o.empresa_id, COUNT(*) n FROM sgi_acoes a JOIN sgi_ocorrencias o ON o.id=a.ocorrencia_id
                      WHERE o.empresa_id IN $in AND a.status='pendente' AND a.prazo < ? GROUP BY o.empresa_id",
                      array_merge($pin, array($hoje))) as $r) $vencPorEmp[(int)$r['empresa_id']] = (int)$r['n'];
      foreach($db->q("SELECT e.id, e.nome, e.sigla,
          (SELECT COUNT(*) FROM sgi_ocorrencias o WHERE o.empresa_id=e.id AND o.tipo='nc' AND o.status NOT IN ('encerrada','cancelada')) nc,
          (SELECT COUNT(*) FROM sgi_ocorrencias o WHERE o.empresa_id=e.id AND o.tipo='reclamacao' AND o.criado_em >= ?) rec_mes
          FROM sgi_empresas e WHERE e.id IN $in ORDER BY e.ordem, e.nome", array_merge(array($mes.'-01'), $pin)) as $r){
        $r['acoes_vencidas'] = $vencPorEmp[(int)$r['id']] ?? 0;
        $porEmpresa[] = $r;
      }
    }

    /* O que é comigo, em qualquer empresa que eu veja. */
    list($inMe, $pinMe) = sgi_in($me['empresas']);
    $minhasAcoes = $db->q("SELECT a.id, a.descricao, a.prazo, a.tipo, o.id ocorrencia_id, o.numero, o.titulo
      FROM sgi_acoes a JOIN sgi_ocorrencias o ON o.id=a.ocorrencia_id
      WHERE a.responsavel_id=? AND a.status='pendente' AND o.empresa_id IN $inMe
      ORDER BY (a.prazo IS NULL), a.prazo LIMIT 50", array_merge(array($me['id']), $pinMe));
    $minhasOc = $db->q("SELECT id, numero, titulo, status, prazo, tipo FROM sgi_ocorrencias
      WHERE responsavel_id=? AND $ativas AND empresa_id IN $inMe ORDER BY (prazo IS NULL), prazo LIMIT 50",
      array_merge(array($me['id']), $pinMe));
    $ciencia = $db->q("SELECT d.id, d.codigo, d.titulo, d.versao_vigente FROM sgi_documentos d
      WHERE d.status='vigente' AND d.exige_ciencia=1 AND (d.empresa_id IS NULL OR d.empresa_id IN $inMe)
      AND NOT EXISTS (SELECT 1 FROM sgi_doc_ciencias c WHERE c.documento_id=d.id AND c.versao=d.versao_vigente AND c.usuario_id=?)
      ORDER BY d.codigo LIMIT 50", array_merge($pinMe, array($me['id'])));
    $aprovar = array();
    if($me['papel'] === 'gestor'){
      $aprovar = $db->q("SELECT d.id, d.codigo, d.titulo, v.versao, v.enviado_em FROM sgi_doc_versoes v
        JOIN sgi_documentos d ON d.id=v.documento_id WHERE v.status='pendente' ORDER BY v.enviado_em");
    }
    $verificar = $me['papel'] === 'gestor' ? $db->q("SELECT id, numero, titulo FROM sgi_ocorrencias
      WHERE status='verificacao' AND empresa_id IN $inMe ORDER BY atualizado_em", $pinMe) : array();

    return array(
      'abertas'=>$porTipo, 'nc_por_status'=>$porStatus,
      'acoes_vencidas'=>$acoesVencidas, 'acoes_semana'=>$acoesSemana,
      'reclamacoes_mes'=>$rec($mes), 'reclamacoes_mes_anterior'=>$rec($mesAnt),
      'docs'=>array('revisao_vencida'=>$docRevVencida, 'revisao_30'=>$docRev30, 'pendentes'=>$docPend),
      'indicadores'=>$ind, 'por_empresa'=>$porEmpresa,
      'minhas'=>array('acoes'=>$minhasAcoes, 'ocorrencias'=>$minhasOc, 'ciencia'=>$ciencia,
                      'aprovar'=>$aprovar, 'verificar'=>$verificar));
  }

  /* ─────────────── OCORRÊNCIAS ─────────────── */
  case 'oc_lista': {
    $ids = $me['empresas'];
    if(!empty($b['empresa'])){ sgi_empresa_ok($me, $b['empresa']); $ids = array((int)$b['empresa']); }
    list($in, $p) = sgi_in($ids);
    $w = array("o.empresa_id IN $in");
    if(!empty($b['tipo'])){ $w[] = 'o.tipo=?'; $p[] = sgi_escolha($b, 'tipo', array_keys($L['tipos_ocorrencia'])); }
    $st = $b['status'] ?? 'ativas';
    if($st === 'ativas') $w[] = "o.status NOT IN ('encerrada','cancelada')";
    elseif($st !== 'todas'){ $w[] = 'o.status=?'; $p[] = sgi_escolha($b, 'status', array_keys($L['status'])); }
    if(!empty($b['meu'])){ $w[] = '(o.responsavel_id=? OR o.aberta_por=?)'; $p[] = $me['id']; $p[] = $me['id']; }
    if(!empty($b['texto'])){
      $w[] = '(o.numero LIKE ? OR o.titulo LIKE ? OR o.descricao LIKE ? OR o.linha LIKE ? OR o.veiculo LIKE ?)';
      $t = '%'.mb_substr((string)$b['texto'], 0, 80, 'UTF-8').'%';
      array_push($p, $t, $t, $t, $t, $t);
    }
    $linhas = $db->q("SELECT o.id, o.numero, o.tipo, o.titulo, o.status, o.gravidade, o.empresa_id, o.norma, o.origem,
        o.responsavel_id, o.prazo, o.criado_em, o.linha, o.veiculo, o.canal,
        (SELECT COUNT(*) FROM sgi_acoes a WHERE a.ocorrencia_id=o.id AND a.status='pendente') acoes_pendentes,
        (SELECT COUNT(*) FROM sgi_acoes a WHERE a.ocorrencia_id=o.id AND a.status='pendente' AND a.prazo < ?) acoes_vencidas
      FROM sgi_ocorrencias o WHERE ".implode(' AND ', $w)." ORDER BY o.criado_em DESC, o.id DESC LIMIT 500",
      array_merge(array($hoje), $p));
    $n = sgi_nomes($db);
    foreach($linhas as &$l) $l['responsavel'] = $n[(int)$l['responsavel_id']] ?? null;
    return array('itens'=>$linhas);
  }

  case 'oc_detalhe': {
    $o = sgi_oc_carregar($db, $me, $b['id'] ?? 0);
    $n = sgi_nomes($db);
    $o['responsavel'] = $n[(int)$o['responsavel_id']] ?? null;
    $o['aberta_por_nome'] = $n[(int)$o['aberta_por']] ?? null;
    $o['verificado_por_nome'] = $n[(int)$o['verificado_por']] ?? null;
    $o['causa_dados'] = $o['causa_dados'] ? json_decode($o['causa_dados'], true) : null;
    $acoes = $db->q("SELECT * FROM sgi_acoes WHERE ocorrencia_id=? ORDER BY id", array((int)$o['id']));
    $idsAcao = array_map('intval', array_column($acoes, 'id'));
    foreach($acoes as &$a){ $a['responsavel'] = $n[(int)$a['responsavel_id']] ?? null; $a['anexos'] = array(); }
    unset($a);
    $anexos = $db->q("SELECT id, ref_tipo, ref_id, nome_original, bytes, enviado_por, enviado_em FROM sgi_anexos
      WHERE ref_tipo='ocorrencia' AND ref_id=? ORDER BY id", array((int)$o['id']));
    if($idsAcao){
      list($inA, $pa) = sgi_in($idsAcao);
      foreach($db->q("SELECT id, ref_id, nome_original, bytes, enviado_em FROM sgi_anexos WHERE ref_tipo='acao' AND ref_id IN $inA", $pa) as $x)
        foreach($acoes as &$a) if((int)$a['id'] === (int)$x['ref_id']) $a['anexos'][] = $x;
      unset($a);
    }
    $hist = $db->q("SELECT usuario_id, tipo, texto, em FROM sgi_historico WHERE ref_tipo='ocorrencia' AND ref_id=? ORDER BY id DESC",
      array((int)$o['id']));
    foreach($hist as &$h) $h['usuario'] = $n[(int)$h['usuario_id']] ?? 'Sistema';
    unset($h);
    $ligadas = $db->q("SELECT id, numero, titulo, status FROM sgi_ocorrencias WHERE origem_id=? OR id=?",
      array((int)$o['id'], (int)$o['origem_id']));
    $ligadas = array_values(array_filter($ligadas, function($x) use ($o){ return (int)$x['id'] !== (int)$o['id']; }));
    return array('ocorrencia'=>$o, 'acoes'=>$acoes, 'anexos'=>$anexos, 'historico'=>$hist,
      'ligadas'=>$ligadas, 'pode_editar'=>sgi_pode_editar_oc($me, $o),
      'transicoes'=>sgi_transicoes($o['tipo'])[$o['status']] ?? array());
  }

  case 'oc_salvar': {
    $id = (int)($b['id'] ?? 0);
    if($id){
      $o = sgi_oc_carregar($db, $me, $id);
      if(!sgi_pode_editar_oc($me, $o)) sgi_erro('Você não pode editar esta ocorrência.');
      if(in_array($o['status'], array('encerrada','cancelada'), true)) sgi_erro('Ocorrência encerrada. Reabra antes de editar.');
      $c = sgi_oc_campos($db, $b, $o['tipo']);
      if(!empty($b['empresa_id']) && (int)$b['empresa_id'] !== (int)$o['empresa_id']){
        sgi_exige($me, 'gestor'); sgi_empresa_ok($me, $b['empresa_id']);
        $c['empresa_id'] = (int)$b['empresa_id'];
      }
      $c['atualizado_em'] = sgi_agora();
      $db->atualizar('sgi_ocorrencias', $c, $id);
      sgi_hist($db, 'ocorrencia', $id, $me, 'Dados da ocorrência atualizados.');
      return array('id'=>$id);
    }
    $tipo = sgi_escolha($b, 'tipo', array_keys($L['tipos_ocorrencia']));
    if(!$tipo) sgi_erro('Escolha o tipo da ocorrência.');
    if(empty($b['empresa_id'])) sgi_erro('Escolha a empresa.');
    sgi_empresa_ok($me, $b['empresa_id']);
    $c = sgi_oc_campos($db, $b, $tipo);
    if(sgi_nivel($me['papel']) < 2){ $c['responsavel_id'] = null; $c['prazo'] = null; }
    list($novo, $numero) = sgi_oc_criar($db, $me, $b['empresa_id'], $tipo, $c);
    sgi_hist($db, 'ocorrencia', $novo, $me, 'Registrada como '.$numero.'.');
    return array('id'=>$novo, 'numero'=>$numero);
  }

  case 'oc_analise': {
    sgi_exige($me, 'membro');
    $o = sgi_oc_carregar($db, $me, $b['id'] ?? 0);
    if(in_array($o['status'], array('encerrada','cancelada'), true)) sgi_erro('Ocorrência encerrada.');
    $metodo = sgi_escolha($b, 'causa_metodo', array_keys($L['metodos_causa']), 'livre');
    $dados = isset($b['causa_dados']) && is_array($b['causa_dados']) ? $b['causa_dados'] : null;
    if($dados !== null){
      /* Guarda só texto curto, com as chaves conhecidas: isto volta para a tela. */
      $limpo = array();
      foreach($dados as $k => $v){
        if(!preg_match('/^[a-z0-9_]{1,20}$/', (string)$k)) continue;
        $limpo[$k] = is_array($v) ? array_slice(array_map(function($x){ return mb_substr((string)$x, 0, 500, 'UTF-8'); }, $v), 0, 10)
                                  : mb_substr((string)$v, 0, 500, 'UTF-8');
      }
      $dados = json_encode($limpo, JSON_UNESCAPED_UNICODE);
    }
    /* Só grava o que veio: a tela da reclamação não manda causa, e a da
       RNC não manda resposta — e nenhuma das duas pode apagar a outra. */
    $mud = array('atualizado_em'=>sgi_agora());
    if(array_key_exists('causa_metodo', $b)){ $mud['causa_metodo'] = $metodo; $mud['causa_dados'] = $dados; }
    foreach(array('causa_raiz', 'acao_imediata', 'resposta') as $k)
      if(array_key_exists($k, $b)) $mud[$k] = sgi_txt($b, $k, 8000);
    $db->atualizar('sgi_ocorrencias', $mud, $o['id']);
    sgi_hist($db, 'ocorrencia', $o['id'], $me, $o['tipo'] === 'reclamacao' ? 'Tratamento/resposta atualizado.' : 'Análise de causa atualizada.');
    return array('id'=>(int)$o['id']);
  }

  case 'oc_status': {
    $o = sgi_oc_carregar($db, $me, $b['id'] ?? 0);
    $para = (string)($b['para'] ?? '');
    $de = $o['status'];
    $pode = sgi_transicoes($o['tipo'])[$de] ?? array();
    if(!in_array($para, $pode, true)) sgi_erro('Não é possível passar de "'.$L['status'][$de].'" para essa fase.');
    sgi_exige($me, 'membro');

    $obs = sgi_txt($b, 'obs', 4000);
    $pend = (int)$db->valor("SELECT COUNT(*) FROM sgi_acoes WHERE ocorrencia_id=? AND status='pendente'", array((int)$o['id']));
    $total = (int)$db->valor("SELECT COUNT(*) FROM sgi_acoes WHERE ocorrencia_id=? AND status<>'cancelada'", array((int)$o['id']));
    $mud = array('status'=>$para, 'atualizado_em'=>sgi_agora());
    $texto = 'Fase: '.$L['status'][$de].' → '.$L['status'][$para].'.';

    if($para === 'cancelada'){
      sgi_exige($me, 'gestor');
      if(!$obs) sgi_erro('Informe o motivo do cancelamento.');
    }
    if(in_array($de, array('encerrada','cancelada'), true)){
      sgi_exige($me, 'gestor');
      if(!$obs) sgi_erro('Informe o motivo da reabertura.');
      $mud['encerrada_em'] = null; $mud['eficacia'] = null; $mud['eficacia_obs'] = null;
      $mud['verificado_por'] = null; $mud['verificado_em'] = null;
    }
    if($o['tipo'] === 'nc'){
      if($para === 'acao' && $de === 'analise' && !trim((string)$o['causa_raiz']))
        sgi_erro('Registre a causa raiz antes de montar o plano de ação.');
      if($para === 'verificacao'){
        if(!$total) sgi_erro('Cadastre pelo menos uma ação no plano.');
        if($pend) sgi_erro('Ainda há '.$pend.' ação(ões) pendente(s) no plano.');
      }
      if($para === 'encerrada'){
        sgi_exige($me, 'gestor');
        $ef = sgi_escolha($b, 'eficacia', array('eficaz','ineficaz'));
        if(!$ef) sgi_erro('Diga se as ações foram eficazes.');
        if(!$obs) sgi_erro('Descreva a evidência de eficácia (o que foi verificado).');
        if($ef === 'ineficaz'){
          /* Ação ineficaz não encerra: volta para a análise, porque a causa
             encontrada provavelmente não era a raiz. */
          $mud['status'] = 'analise';
          $texto = 'Verificação: ações INEFICAZES. Volta para análise de causa.';
        } else {
          $mud['encerrada_em'] = sgi_agora();
          $texto = 'Verificação: ações eficazes. Ocorrência encerrada.';
        }
        $mud['eficacia'] = $ef; $mud['eficacia_obs'] = $obs;
        $mud['verificado_por'] = $me['id']; $mud['verificado_em'] = sgi_agora();
      }
    } else if($para === 'encerrada'){
      if($pend) sgi_erro('Ainda há '.$pend.' ação(ões) pendente(s).');
      if($o['tipo'] === 'reclamacao'){
        if(!trim((string)$o['resposta'])) sgi_erro('Registre a resposta dada ao passageiro antes de encerrar.');
        $mud['respondida_em'] = $o['respondida_em'] ?: sgi_agora();
      }
      $mud['encerrada_em'] = sgi_agora();
    }
    $db->atualizar('sgi_ocorrencias', $mud, $o['id']);
    sgi_hist($db, 'ocorrencia', $o['id'], $me, $texto.($obs ? ' '.$obs : ''));
    return array('status'=>$mud['status']);
  }

  case 'oc_gerar_nc': {
    sgi_exige($me, 'membro');
    $o = sgi_oc_carregar($db, $me, $b['id'] ?? 0);
    if($o['tipo'] === 'nc') sgi_erro('Esta ocorrência já é uma não conformidade.');
    $ja = $db->linha("SELECT numero FROM sgi_ocorrencias WHERE origem_id=? AND tipo='nc'", array((int)$o['id']));
    if($ja) sgi_erro('Esta ocorrência já gerou a '.$ja['numero'].'.');
    list($id, $numero) = sgi_oc_criar($db, $me, $o['empresa_id'], 'nc', array(
      'titulo'=>$o['titulo'], 'descricao'=>$o['descricao'],
      'origem'=>$o['tipo'] === 'reclamacao' ? 'Reclamação de cliente' : 'Processo interno',
      'origem_id'=>(int)$o['id'], 'norma'=>$o['norma'] ?: '9001', 'processo'=>$o['processo'],
      'local'=>$o['local'], 'data_fato'=>$o['data_fato'], 'gravidade'=>$o['gravidade']));
    sgi_hist($db, 'ocorrencia', $id, $me, 'Gerada a partir de '.$o['numero'].'.');
    sgi_hist($db, 'ocorrencia', $o['id'], $me, 'Gerou a não conformidade '.$numero.'.');
    return array('id'=>$id, 'numero'=>$numero);
  }

  case 'oc_comentar': {
    $o = sgi_oc_carregar($db, $me, $b['id'] ?? 0);
    $t = sgi_txt($b, 'texto', 4000, true, 'comentário');
    sgi_hist($db, 'ocorrencia', $o['id'], $me, $t, 'comentario');
    return array();
  }

  /* ─────────────── AÇÕES DO PLANO ─────────────── */
  case 'acao_salvar': {
    sgi_exige($me, 'membro');
    $id = (int)($b['id'] ?? 0);
    if($id){
      $a = $db->linha("SELECT * FROM sgi_acoes WHERE id=?", array($id));
      if(!$a) sgi_erro('Ação não encontrada.');
      $o = sgi_oc_carregar($db, $me, $a['ocorrencia_id']);
    } else {
      $o = sgi_oc_carregar($db, $me, $b['ocorrencia_id'] ?? 0);
    }
    if(in_array($o['status'], array('encerrada','cancelada','verificacao'), true))
      sgi_erro('O plano desta ocorrência está fechado nesta fase.');
    $c = array(
      'tipo'=>sgi_escolha($b, 'tipo', array_keys($L['tipos_acao']), 'corretiva'),
      'descricao'=>sgi_txt($b, 'descricao', 4000, true, 'o que será feito'),
      'responsavel_id'=>sgi_usuario_valido($db, $b['responsavel_id'] ?? null),
      'prazo'=>sgi_data($b, 'prazo', true, 'prazo'),
    );
    if(!$c['responsavel_id']) sgi_erro('Escolha o responsável pela ação.');
    if($id){
      if($a['status'] !== 'pendente') sgi_erro('Só dá para editar ação pendente.');
      $db->atualizar('sgi_acoes', $c, $id);
      sgi_hist($db, 'ocorrencia', $o['id'], $me, 'Ação editada: '.$c['descricao']);
    } else {
      if($c['prazo'] < $hoje) sgi_erro('O prazo não pode ser no passado.');
      $id = $db->inserir('sgi_acoes', $c + array('ocorrencia_id'=>(int)$o['id'], 'status'=>'pendente',
        'criado_por'=>$me['id'], 'criado_em'=>sgi_agora()));
      sgi_hist($db, 'ocorrencia', $o['id'], $me, 'Nova ação ('.$L['tipos_acao'][$c['tipo']].'): '.$c['descricao']);
    }
    return array('id'=>$id);
  }

  case 'acao_concluir': {
    $a = $db->linha("SELECT * FROM sgi_acoes WHERE id=?", array((int)($b['id'] ?? 0)));
    if(!$a) sgi_erro('Ação não encontrada.');
    $o = sgi_oc_carregar($db, $me, $a['ocorrencia_id']);
    $souDono = (int)$a['responsavel_id'] === $me['id'];
    if(!$souDono && sgi_nivel($me['papel']) < 2) sgi_erro('Só o responsável ou um membro do SGI conclui esta ação.');
    if($a['status'] !== 'pendente') sgi_erro('A ação não está pendente.');
    $cancelar = !empty($b['cancelar']);
    $ev = sgi_txt($b, 'evidencia', 4000, true, $cancelar ? 'motivo' : 'evidência do que foi feito');
    if($cancelar) sgi_exige($me, 'membro');
    $db->atualizar('sgi_acoes', array('status'=>$cancelar ? 'cancelada' : 'concluida', 'evidencia'=>$ev,
      'concluida_em'=>sgi_agora()), $a['id']);
    sgi_hist($db, 'ocorrencia', $o['id'], $me, ($cancelar ? 'Ação cancelada: ' : 'Ação concluída: ').$a['descricao'].' — '.$ev);
    return array();
  }

  case 'acao_lista': {
    list($in, $p) = sgi_in($me['empresas']);
    $w = array("o.empresa_id IN $in");
    if(!empty($b['empresa'])){ sgi_empresa_ok($me, $b['empresa']); $w[] = 'o.empresa_id=?'; $p[] = (int)$b['empresa']; }
    $st = $b['status'] ?? 'pendente';
    if($st === 'vencidas'){ $w[] = "a.status='pendente' AND a.prazo < ?"; $p[] = $hoje; }
    elseif($st !== 'todas'){ $w[] = 'a.status=?'; $p[] = sgi_escolha($b, 'status', array('pendente','concluida','cancelada')); }
    if(!empty($b['meu'])){ $w[] = 'a.responsavel_id=?'; $p[] = $me['id']; }
    $linhas = $db->q("SELECT a.*, o.numero, o.titulo, o.empresa_id, o.tipo tipo_ocorrencia
      FROM sgi_acoes a JOIN sgi_ocorrencias o ON o.id=a.ocorrencia_id
      WHERE ".implode(' AND ', $w)." ORDER BY (a.prazo IS NULL), a.prazo LIMIT 500", $p);
    $n = sgi_nomes($db);
    foreach($linhas as &$l) $l['responsavel'] = $n[(int)$l['responsavel_id']] ?? null;
    return array('itens'=>$linhas);
  }

  /* ─────────────── ANEXOS ─────────────── */
  case 'anexo_enviar': {
    $ref = sgi_escolha($b, 'ref_tipo', array('ocorrencia','acao'));
    $refId = (int)($b['ref_id'] ?? 0);
    if($ref === 'acao'){
      $a = $db->linha("SELECT * FROM sgi_acoes WHERE id=?", array($refId));
      if(!$a) sgi_erro('Ação não encontrada.');
      $o = sgi_oc_carregar($db, $me, $a['ocorrencia_id']);
      if((int)$a['responsavel_id'] !== $me['id'] && sgi_nivel($me['papel']) < 2) sgi_erro('Sem permissão para anexar aqui.');
    } else {
      $o = sgi_oc_carregar($db, $me, $refId);
      if(!sgi_pode_editar_oc($me, $o) && (int)$o['responsavel_id'] !== $me['id']) sgi_erro('Sem permissão para anexar aqui.');
    }
    $f = sgi_guardar_arquivo($arquivos['arquivo'] ?? null);
    $id = $db->inserir('sgi_anexos', $f + array('ref_tipo'=>$ref, 'ref_id'=>$refId,
      'enviado_por'=>$me['id'], 'enviado_em'=>sgi_agora()));
    sgi_hist($db, 'ocorrencia', $o['id'], $me, 'Anexo enviado: '.$f['nome_original']);
    return array('id'=>$id);
  }

  /* ─────────────── DOCUMENTOS ─────────────── */
  case 'doc_lista': {
    list($in, $p) = sgi_in($me['empresas']);
    $w = array("(d.empresa_id IS NULL OR d.empresa_id IN $in)");
    if(!empty($b['empresa'])){ $w[] = '(d.empresa_id IS NULL OR d.empresa_id=?)'; $p[] = (int)$b['empresa']; }
    $st = $b['status'] ?? 'ativos';
    if($st === 'ativos') $w[] = "d.status<>'obsoleto'";
    elseif($st !== 'todos'){ $w[] = 'd.status=?'; $p[] = sgi_escolha($b, 'status', array('rascunho','vigente','obsoleto')); }
    if(sgi_nivel($me['papel']) < 2) $w[] = "d.status='vigente'";
    if(!empty($b['texto'])){
      $t = '%'.mb_substr((string)$b['texto'], 0, 80, 'UTF-8').'%';
      $w[] = '(d.codigo LIKE ? OR d.titulo LIKE ? OR d.processo LIKE ?)'; array_push($p, $t, $t, $t);
    }
    $linhas = $db->q("SELECT d.*,
        (SELECT COUNT(*) FROM sgi_doc_versoes v WHERE v.documento_id=d.id AND v.status='pendente') pendente,
        (SELECT COUNT(*) FROM sgi_doc_ciencias c WHERE c.documento_id=d.id AND c.versao=d.versao_vigente AND c.usuario_id=?) li
      FROM sgi_documentos d WHERE ".implode(' AND ', $w)." ORDER BY d.codigo LIMIT 1000",
      array_merge(array($me['id']), $p));
    return array('itens'=>$linhas);
  }

  case 'doc_detalhe': {
    $d = sgi_doc_carregar($db, $me, $b['id'] ?? 0);
    $n = sgi_nomes($db);
    $vers = $db->q("SELECT id, versao, nome_original, bytes, alteracao, status, enviado_por, enviado_em,
      decidido_por, decidido_em, parecer FROM sgi_doc_versoes WHERE documento_id=? ORDER BY versao DESC", array((int)$d['id']));
    if(sgi_nivel($me['papel']) < 2)
      $vers = array_values(array_filter($vers, function($v) use ($d){ return (int)$v['versao'] === (int)$d['versao_vigente']; }));
    foreach($vers as &$v){ $v['enviado_por_nome'] = $n[(int)$v['enviado_por']] ?? null; $v['decidido_por_nome'] = $n[(int)$v['decidido_por']] ?? null; }
    unset($v);
    $ciencias = array();
    if(sgi_nivel($me['papel']) >= 2 && $d['versao_vigente']){
      foreach($db->q("SELECT usuario_id, em FROM sgi_doc_ciencias WHERE documento_id=? AND versao=? ORDER BY em",
                     array((int)$d['id'], (int)$d['versao_vigente'])) as $c)
        $ciencias[] = array('nome'=>$n[(int)$c['usuario_id']] ?? '?', 'em'=>$c['em']);
    }
    $li = (bool)$db->valor("SELECT 1 FROM sgi_doc_ciencias WHERE documento_id=? AND versao=? AND usuario_id=?",
      array((int)$d['id'], (int)$d['versao_vigente'], $me['id']));
    $hist = $db->q("SELECT usuario_id, texto, em FROM sgi_historico WHERE ref_tipo='documento' AND ref_id=? ORDER BY id DESC", array((int)$d['id']));
    foreach($hist as &$h) $h['usuario'] = $n[(int)$h['usuario_id']] ?? 'Sistema';
    unset($h);
    return array('documento'=>$d, 'versoes'=>$vers, 'ciencias'=>$ciencias, 'li'=>$li, 'historico'=>$hist);
  }

  case 'doc_salvar': {
    sgi_exige($me, 'membro');
    $id = (int)($b['id'] ?? 0);
    $emp = !empty($b['empresa_id']) ? (int)$b['empresa_id'] : null;
    if($emp === null) sgi_exige($me, 'gestor'); else sgi_empresa_ok($me, $emp);
    $normas = array();
    foreach((array)($b['normas'] ?? array()) as $nr) if(isset($L['normas'][(string)$nr])) $normas[] = (string)$nr;
    $c = array(
      'empresa_id'=>$emp,
      'codigo'=>strtoupper(sgi_txt($b, 'codigo', 40, true, 'código')),
      'titulo'=>sgi_txt($b, 'titulo', 200, true, 'título'),
      'tipo'=>sgi_txt($b, 'tipo', 40, true, 'tipo'),
      'processo'=>sgi_txt($b, 'processo', 80),
      'normas'=>$normas ? implode(',', $normas) : null,
      'revisao_meses'=>max(1, min(60, (int)($b['revisao_meses'] ?? 12))),
      'exige_ciencia'=>!empty($b['exige_ciencia']) ? 1 : 0,
      'atualizado_em'=>sgi_agora(),
    );
    if(!preg_match('/^[A-Z0-9._\-\/]+$/', $c['codigo'])) sgi_erro('Código: use letras, números, ponto, hífen ou barra (ex.: POP-MAN-001).');
    $dup = $db->valor("SELECT id FROM sgi_documentos WHERE codigo=? AND id<>?", array($c['codigo'], $id));
    if($dup) sgi_erro('Já existe um documento com o código '.$c['codigo'].'.');
    if($id){
      $d = sgi_doc_carregar($db, $me, $id);
      if($d['empresa_id'] === null) sgi_exige($me, 'gestor');
      if($d['status'] === 'obsoleto') sgi_erro('Documento obsoleto não é editado.');
      $db->atualizar('sgi_documentos', $c, $id);
      sgi_hist($db, 'documento', $id, $me, 'Dados do documento atualizados.');
    } else {
      $id = $db->inserir('sgi_documentos', $c + array('status'=>'rascunho', 'criado_por'=>$me['id'], 'criado_em'=>sgi_agora()));
      sgi_hist($db, 'documento', $id, $me, 'Documento cadastrado.');
    }
    return array('id'=>$id);
  }

  case 'doc_versao_enviar': {
    sgi_exige($me, 'membro');
    $d = sgi_doc_carregar($db, $me, $b['documento_id'] ?? 0);
    if($d['empresa_id'] === null) sgi_exige($me, 'gestor');
    if($d['status'] === 'obsoleto') sgi_erro('Documento obsoleto.');
    if($db->valor("SELECT id FROM sgi_doc_versoes WHERE documento_id=? AND status='pendente'", array((int)$d['id'])))
      sgi_erro('Já existe uma versão aguardando aprovação. Aprove ou reprove antes de enviar outra.');
    $alt = sgi_txt($b, 'alteracao', 2000, true, 'o que mudou nesta versão');
    $f = sgi_guardar_arquivo($arquivos['arquivo'] ?? null);
    $v = 1 + (int)$db->valor("SELECT MAX(versao) FROM sgi_doc_versoes WHERE documento_id=?", array((int)$d['id']));
    $db->inserir('sgi_doc_versoes', $f + array('documento_id'=>(int)$d['id'], 'versao'=>$v, 'alteracao'=>$alt,
      'status'=>'pendente', 'enviado_por'=>$me['id'], 'enviado_em'=>sgi_agora()));
    sgi_hist($db, 'documento', $d['id'], $me, 'Revisão '.$v.' enviada para aprovação: '.$alt);
    return array('versao'=>$v);
  }

  case 'doc_versao_decidir': {
    sgi_exige($me, 'gestor');
    $v = $db->linha("SELECT * FROM sgi_doc_versoes WHERE id=?", array((int)($b['id'] ?? 0)));
    if(!$v || $v['status'] !== 'pendente') sgi_erro('Versão não encontrada ou já decidida.');
    $d = sgi_doc_carregar($db, $me, $v['documento_id']);
    $aprovar = !empty($b['aprovar']);
    $parecer = sgi_txt($b, 'parecer', 2000, !$aprovar, 'motivo da reprovação');
    $db->inicio();
    try {
      if($aprovar){
        $db->q("UPDATE sgi_doc_versoes SET status='substituida' WHERE documento_id=? AND status='aprovada'", array((int)$d['id']));
        $db->atualizar('sgi_doc_versoes', array('status'=>'aprovada', 'decidido_por'=>$me['id'],
          'decidido_em'=>sgi_agora(), 'parecer'=>$parecer), $v['id']);
        $db->atualizar('sgi_documentos', array('status'=>'vigente', 'versao_vigente'=>(int)$v['versao'],
          'revisar_em'=>date('Y-m-d', strtotime('+'.(int)$d['revisao_meses'].' months')),
          'atualizado_em'=>sgi_agora()), $d['id']);
        sgi_hist($db, 'documento', $d['id'], $me, 'Revisão '.$v['versao'].' aprovada e vigente.'.($parecer ? ' '.$parecer : ''));
      } else {
        $db->atualizar('sgi_doc_versoes', array('status'=>'reprovada', 'decidido_por'=>$me['id'],
          'decidido_em'=>sgi_agora(), 'parecer'=>$parecer), $v['id']);
        sgi_hist($db, 'documento', $d['id'], $me, 'Revisão '.$v['versao'].' reprovada: '.$parecer);
      }
      $db->fim();
    } catch(Exception $e){ $db->desfaz(); throw $e; }
    return array();
  }

  case 'doc_obsoletar': {
    sgi_exige($me, 'gestor');
    $d = sgi_doc_carregar($db, $me, $b['id'] ?? 0);
    $motivo = sgi_txt($b, 'motivo', 1000, true, 'motivo');
    $db->atualizar('sgi_documentos', array('status'=>'obsoleto', 'atualizado_em'=>sgi_agora()), $d['id']);
    sgi_hist($db, 'documento', $d['id'], $me, 'Documento tornado obsoleto: '.$motivo);
    return array();
  }

  case 'doc_ciencia': {
    $d = sgi_doc_carregar($db, $me, $b['id'] ?? 0);
    if($d['status'] !== 'vigente') sgi_erro('Só se dá ciência de documento vigente.');
    if(!$db->valor("SELECT 1 FROM sgi_doc_ciencias WHERE documento_id=? AND versao=? AND usuario_id=?",
                   array((int)$d['id'], (int)$d['versao_vigente'], $me['id'])))
      $db->inserir('sgi_doc_ciencias', array('documento_id'=>(int)$d['id'], 'versao'=>(int)$d['versao_vigente'],
        'usuario_id'=>$me['id'], 'em'=>sgi_agora()));
    return array();
  }

  /* ─────────────── INDICADORES ─────────────── */
  case 'ind_lista': {
    $ano = (int)($b['ano'] ?? date('Y'));
    if($ano < 2000 || $ano > 2100) sgi_erro('Ano inválido.');
    $ids = $me['empresas'];
    if(!empty($b['empresa'])){ sgi_empresa_ok($me, $b['empresa']); $ids = array((int)$b['empresa']); }
    list($in, $p) = sgi_in($ids);
    $inds = $db->q("SELECT * FROM sgi_indicadores ".(empty($b['inativos']) ? 'WHERE ativo=1 ' : '')."ORDER BY ordem, nome");
    $vals = $db->q("SELECT indicador_id, empresa_id, competencia, valor, obs FROM sgi_ind_valores
      WHERE competencia LIKE ? AND empresa_id IN $in", array_merge(array($ano.'-%'), $p));
    return array('indicadores'=>$inds, 'valores'=>$vals, 'ano'=>$ano);
  }

  case 'ind_lancar': {
    sgi_exige($me, 'membro');
    $emp = (int)($b['empresa_id'] ?? 0);
    sgi_empresa_ok($me, $emp);
    $comp = (string)($b['competencia'] ?? '');
    if(!preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $comp)) sgi_erro('Competência inválida (use AAAA-MM).');
    if($comp > date('Y-m')) sgi_erro('Não dá para lançar mês que ainda não começou.');
    $n = 0;
    foreach((array)($b['valores'] ?? array()) as $iid => $val){
      $iid = (int)$iid;
      if(!$db->valor("SELECT id FROM sgi_indicadores WHERE id=?", array($iid))) continue;
      $atual = $db->linha("SELECT id FROM sgi_ind_valores WHERE indicador_id=? AND empresa_id=? AND competencia=?", array($iid, $emp, $comp));
      $val = is_string($val) ? str_replace(',', '.', trim($val)) : $val;
      if($val === '' || $val === null){
        if($atual){ $db->q("DELETE FROM sgi_ind_valores WHERE id=?", array((int)$atual['id'])); $n++; }
        continue;
      }
      if(!is_numeric($val)) sgi_erro('Valor inválido em um dos indicadores.');
      $dados = array('valor'=>(float)$val, 'lancado_por'=>$me['id'], 'lancado_em'=>sgi_agora());
      if($atual) $db->atualizar('sgi_ind_valores', $dados, $atual['id']);
      else $db->inserir('sgi_ind_valores', $dados + array('indicador_id'=>$iid, 'empresa_id'=>$emp, 'competencia'=>$comp));
      $n++;
    }
    return array('gravados'=>$n);
  }

  case 'ind_salvar': {
    sgi_exige($me, 'gestor');
    $id = (int)($b['id'] ?? 0);
    $meta = isset($b['meta']) && $b['meta'] !== '' ? str_replace(',', '.', (string)$b['meta']) : null;
    if($meta !== null && !is_numeric($meta)) sgi_erro('Meta inválida.');
    $c = array(
      'nome'=>sgi_txt($b, 'nome', 150, true, 'nome'),
      'descricao'=>sgi_txt($b, 'descricao', 1000),
      'unidade'=>sgi_txt($b, 'unidade', 20),
      'sentido'=>sgi_escolha($b, 'sentido', array('maior','menor'), 'maior'),
      'meta'=>$meta === null ? null : (float)$meta,
      'casas'=>max(0, min(4, (int)($b['casas'] ?? 1))),
      'norma'=>sgi_escolha($b, 'norma', array_map('strval', array_keys($L['normas']))),
      'processo'=>sgi_txt($b, 'processo', 80),
      'ativo'=>!isset($b['ativo']) || !empty($b['ativo']) ? 1 : 0,
    );
    if($id){
      if(!$db->valor("SELECT id FROM sgi_indicadores WHERE id=?", array($id))) sgi_erro('Indicador não encontrado.');
      $db->atualizar('sgi_indicadores', $c, $id);
    } else {
      $c['ordem'] = 1 + (int)$db->valor("SELECT MAX(ordem) FROM sgi_indicadores");
      $id = $db->inserir('sgi_indicadores', $c);
    }
    return array('id'=>$id);
  }

  /* ─────────────── CONFIGURAÇÃO ─────────────── */
  case 'empresa_salvar': {
    sgi_exige($me, 'gestor');
    $id = (int)($b['id'] ?? 0);
    $c = array('nome'=>sgi_txt($b, 'nome', 120, true, 'nome'), 'sigla'=>sgi_txt($b, 'sigla', 20),
               'ativo'=>!isset($b['ativo']) || !empty($b['ativo']) ? 1 : 0);
    if($id){
      if(!$db->valor("SELECT id FROM sgi_empresas WHERE id=?", array($id))) sgi_erro('Empresa não encontrada.');
      $db->atualizar('sgi_empresas', $c, $id);
    } else {
      $c['ordem'] = 1 + (int)$db->valor("SELECT MAX(ordem) FROM sgi_empresas");
      $id = $db->inserir('sgi_empresas', $c);
    }
    return array('id'=>$id);
  }

  case 'acessos_lista': {
    sgi_exige($me, 'gestor');
    $r = array();
    foreach($db->q("SELECT u.id, u.name, u.username, u.role, a.papel, a.empresas FROM portal_usuarios u
                    LEFT JOIN sgi_acessos a ON a.usuario_id=u.id ORDER BY u.name") as $u){
      $r[] = array('id'=>(int)$u['id'], 'nome'=>$u['name'] ?: $u['username'], 'usuario'=>$u['username'],
        'admin_hub'=>$u['role'] === 'admin', 'papel'=>$u['role'] === 'admin' ? 'gestor' : $u['papel'],
        'empresas'=>array_map('intval', (array)json_decode($u['empresas'] ?: '[]', true)));
    }
    return array('usuarios'=>$r);
  }

  case 'acesso_salvar': {
    sgi_exige($me, 'gestor');
    $uid2 = (int)($b['usuario_id'] ?? 0);
    $u = $db->linha("SELECT id, role FROM portal_usuarios WHERE id=?", array($uid2));
    if(!$u) sgi_erro('Usuário não encontrado.');
    if($u['role'] === 'admin') sgi_erro('Administrador do Hub já é gestor do SGI.');
    $papel = sgi_escolha($b, 'papel', array('gestor','membro','leitor',''), '');
    $db->q("DELETE FROM sgi_acessos WHERE usuario_id=?", array($uid2));
    if($papel !== ''){
      $emp = array_values(array_unique(array_map('intval', (array)($b['empresas'] ?? array()))));
      if($papel !== 'gestor' && !$emp) sgi_erro('Escolha pelo menos uma empresa para este usuário.');
      $db->inserir('sgi_acessos', array('usuario_id'=>$uid2, 'papel'=>$papel,
        'empresas'=>json_encode($emp), 'atualizado_em'=>sgi_agora()));
    }
    return array();
  }

  }
  sgi_erro('Ação desconhecida: '.$acao);
}

function sgi_doc_carregar(SgiDb $db, $me, $id){
  $d = $db->linha("SELECT * FROM sgi_documentos WHERE id=?", array((int)$id));
  if(!$d) sgi_erro('Documento não encontrado.');
  if($d['empresa_id'] !== null) sgi_empresa_ok($me, $d['empresa_id']);
  if(sgi_nivel($me['papel']) < 2 && $d['status'] !== 'vigente') sgi_erro('Documento não disponível.');
  return $d;
}

/* Arquivo pedido para download: confere o acesso e devolve o caminho. */
function sgi_arquivo_permitido(SgiDb $db, $uid, $tipo, $id){
  $me = sgi_eu($db, $uid);
  if(!$me['papel']) sgi_erro('Sem acesso ao SGI.');
  if($tipo === 'versao'){
    $v = $db->linha("SELECT * FROM sgi_doc_versoes WHERE id=?", array((int)$id));
    if(!$v) sgi_erro('Arquivo não encontrado.');
    $d = sgi_doc_carregar($db, $me, $v['documento_id']);
    if(sgi_nivel($me['papel']) < 2 && (int)$v['versao'] !== (int)$d['versao_vigente']) sgi_erro('Arquivo não disponível.');
    return $v;
  }
  $a = $db->linha("SELECT * FROM sgi_anexos WHERE id=?", array((int)$id));
  if(!$a) sgi_erro('Arquivo não encontrado.');
  $ocId = $a['ref_id'];
  if($a['ref_tipo'] === 'acao') $ocId = $db->valor("SELECT ocorrencia_id FROM sgi_acoes WHERE id=?", array((int)$a['ref_id']));
  sgi_oc_carregar($db, $me, $ocId);
  return $a;
}

/* Resumo de pendências para o n8n avisar cada responsável. */
function sgi_alertas(SgiDb $db){
  $hoje = sgi_hoje(); $em3 = date('Y-m-d', strtotime('+3 days'));
  $cols = array_column($db->drv === 'sqlite' ? $db->q("PRAGMA table_info(portal_usuarios)") : $db->q("SHOW COLUMNS FROM portal_usuarios"),
                       $db->drv === 'sqlite' ? 'name' : 'Field');
  $tel = in_array('telefone', $cols, true) ? 'u.telefone' : "''";
  $por = array();
  foreach($db->q("SELECT a.id, a.descricao, a.prazo, o.numero, u.id uid, u.name, $tel telefone
                  FROM sgi_acoes a JOIN sgi_ocorrencias o ON o.id=a.ocorrencia_id
                  JOIN portal_usuarios u ON u.id=a.responsavel_id
                  WHERE a.status='pendente' AND a.prazo <= ? ORDER BY a.prazo", array($em3)) as $r){
    $k = (int)$r['uid'];
    if(!isset($por[$k])) $por[$k] = array('nome'=>$r['name'], 'telefone'=>$r['telefone'], 'vencidas'=>array(), 'vencendo'=>array());
    $por[$k][$r['prazo'] < $hoje ? 'vencidas' : 'vencendo'][] = array('numero'=>$r['numero'], 'acao'=>$r['descricao'], 'prazo'=>$r['prazo']);
  }
  return array('gerado_em'=>sgi_agora(), 'pessoas'=>array_values($por));
}

/* ══════════════ ENTRADA HTTP ══════════════ */

if(!defined('SGI_TESTE')){
  error_reporting(E_ALL);
  ini_set('display_errors', '0');
  if(function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

  $acao = isset($_GET['action']) ? (string)$_GET['action'] : '';
  $json = function($a){
    if(!headers_sent()){ header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff'); header('Cache-Control: no-store'); }
    echo json_encode($a, JSON_UNESCAPED_UNICODE); exit;
  };
  register_shutdown_function(function() use ($json){
    $e = error_get_last();
    if($e && in_array($e['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR)))
      $json(array('ok'=>false, 'erro'=>'PHP: '.$e['message'].' (linha '.$e['line'].')'));
  });

  require __DIR__.'/db_config.php';
  $cx = portal_db();
  if(!$cx) $json(array('ok'=>false, 'erro'=>'Sem conexão com o banco.'));
  $cx->set_charset('utf8mb4');
  $db = new SgiDb($cx);

  try {
    sgi_estrutura($db);

    /* O n8n chama sem sessão, com a chave. */
    if($acao === 'alertas'){
      $sec = @include __DIR__.'/cron_secrets.php';
      $chave = getenv('SGI_CRON_CHAVE') ?: (is_array($sec) ? ($sec['sgi_alertas'] ?? ($sec['lembrete_backup'] ?? '')) : '');
      $veio = (string)($_GET['chave'] ?? ($_SERVER['HTTP_X_CRON_CHAVE'] ?? ''));
      if($chave === '' || !hash_equals($chave, $veio)){ http_response_code(403); $json(array('ok'=>false, 'erro'=>'Chave inválida.')); }
      $json(array('ok'=>true) + sgi_alertas($db));
    }

    if(session_status() !== PHP_SESSION_ACTIVE){
      $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
      session_set_cookie_params(array('lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$secure,'samesite'=>'Lax'));
      session_start();
    }
    if(empty($_SESSION['uid'])) $json(array('ok'=>false, 'erro'=>'Sessão expirada. Entre novamente.', 'sessao'=>false));
    $uid = (int)$_SESSION['uid'];
    session_write_close(); /* libera a sessão: o Hub faz vários pedidos em paralelo */

    if($acao === 'baixar'){
      $f = sgi_arquivo_permitido($db, $uid, (string)($_GET['tipo'] ?? ''), (int)($_GET['id'] ?? 0));
      $caminho = sgi_pasta().'/'.basename($f['arquivo']);
      if(!is_file($caminho)){ http_response_code(404); $json(array('ok'=>false, 'erro'=>'O arquivo sumiu do servidor.')); }
      $mime = $f['mime'] ?: 'application/octet-stream';
      $emLinha = preg_match('#^(application/pdf|image/(png|jpeg|webp|gif))$#', $mime) && empty($_GET['baixar']);
      $nome = str_replace(array('"', "\r", "\n"), '', $f['nome_original']);
      header('Content-Type: '.$mime);
      header('X-Content-Type-Options: nosniff');
      /* Só PDF e imagem abrem na tela; o resto sempre baixa. Sem "sandbox"
         na CSP: o Chrome recusa abrir PDF em página isolada. */
      if(strpos($mime, 'image/') === 0) header("Content-Security-Policy: default-src 'none'; img-src 'self'");
      header('Content-Length: '.filesize($caminho));
      header('Content-Disposition: '.($emLinha ? 'inline' : 'attachment').'; filename="'.
        preg_replace('/[^\x20-\x7E]/', '_', $nome).'"; filename*=UTF-8\'\''.rawurlencode($nome));
      header('Cache-Control: private, no-store');
      readfile($caminho);
      exit;
    }

    $corpo = $_POST;
    if(!$corpo){
      $j = json_decode(file_get_contents('php://input'), true);
      $corpo = is_array($j) ? $j : array();
    }
    if($acao !== 'eu' && $acao !== 'painel' && substr($acao, -6) !== '_lista' && substr($acao, -8) !== '_detalhe'
       && $_SERVER['REQUEST_METHOD'] !== 'POST') $json(array('ok'=>false, 'erro'=>'Use POST.'));

    $json(array('ok'=>true) + sgi_executar($db, $uid, $acao, $corpo, $_FILES));
  } catch(SgiErro $e){
    $json(array('ok'=>false, 'erro'=>$e->getMessage()));
  } catch(Throwable $e){
    error_log('[sgi] '.$e->getMessage().' em '.$e->getFile().':'.$e->getLine());
    $json(array('ok'=>false, 'erro'=>'Erro interno do SGI: '.$e->getMessage()));
  }
}
