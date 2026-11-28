<?php
/* ============================================================
   sgi_extras.php — Auditorias, Calibração, Riscos, Oportunidades
   e Fornecedores. Incluído por sgi.php.

   Os cinco módulos usam o mesmo motor: um registro principal
   (sgi_reg) com campos próprios em JSON, e os acontecimentos dele
   (sgi_reg_itens): constatações de auditoria, calibrações,
   reavaliações de risco/oportunidade e avaliações de fornecedor.
   A definição de cada módulo (campos, situações, cálculo) fica em
   sgi_mods_def() e vai para a tela, que monta formulário e lista
   a partir dela — uma fonte só para servidor e tela.
   ============================================================ */

function sgi_extras_estrutura(SgiDb $db){
  $db->ddl("CREATE TABLE IF NOT EXISTS sgi_reg (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modulo VARCHAR(10) NOT NULL,
    empresa_id INT NOT NULL,
    numero VARCHAR(20) NOT NULL,
    titulo VARCHAR(200) NOT NULL,
    status VARCHAR(20) NOT NULL,
    responsavel_id INT NULL,
    processo VARCHAR(80) NULL,
    data_ref DATE NULL,
    nota DECIMAL(10,2) NULL,
    dados TEXT NULL,
    criado_por INT NULL,
    criado_em DATETIME NULL,
    atualizado_em DATETIME NULL,
    UNIQUE KEY uk_numero (numero),
    KEY idx_mod (modulo, empresa_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $db->ddl("CREATE TABLE IF NOT EXISTS sgi_reg_itens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reg_id INT NOT NULL,
    tipo VARCHAR(20) NOT NULL,
    data DATE NULL,
    nota DECIMAL(10,2) NULL,
    texto TEXT NULL,
    dados TEXT NULL,
    oc_id INT NULL,
    usuario_id INT NULL,
    em DATETIME NULL,
    KEY idx_reg (reg_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ── Definição dos módulos ──
   campo: [rótulo, tipo, opções/extra]. Tipos: texto, longo, data, numero,
   pessoa, escolha, escala (1 a 5), processo. "lista" = aparece na tabela. */
function sgi_mods_def(){
  $escala = array('1'=>'1 · muito baixa', '2'=>'2 · baixa', '3'=>'3 · média', '4'=>'4 · alta', '5'=>'5 · muito alta');
  return array(
    'aud' => array('titulo'=>'Auditorias', 'singular'=>'auditoria', 'prefixo'=>'AUD',
      'sub'=>'Programa de auditorias internas, constatações e NC abertas a partir delas',
      'status'=>array('planejada'=>'Planejada', 'execucao'=>'Em execução', 'concluida'=>'Concluída', 'cancelada'=>'Cancelada'),
      'fluxo'=>array('planejada'=>array('execucao','cancelada'), 'execucao'=>array('concluida','planejada'), 'concluida'=>array('execucao'), 'cancelada'=>array('planejada')),
      'data_ref'=>'Data prevista', 'resp'=>'Auditor líder',
      'campos'=>array(
        'tipo'=>array('Tipo', 'escolha', array('Interna', 'Externa (certificação)', 'De fornecedor', 'De processo')),
        'normas'=>array('Normas', 'texto'),
        'equipe'=>array('Equipe de auditores', 'texto'),
        'auditados'=>array('Auditados', 'texto'),
        'escopo'=>array('Escopo e critérios', 'longo'),
        'conclusao'=>array('Conclusão e pontos fortes', 'longo')),
      'lista'=>array('tipo'),
      'item'=>array('tipo'=>'constatacao', 'titulo'=>'Constatações', 'botao'=>'Registrar constatação',
        'campos'=>array(
          'classe'=>array('Classificação', 'escolha', array('nc_maior'=>'Não conformidade maior', 'nc_menor'=>'Não conformidade menor',
            'observacao'=>'Observação', 'oportunidade'=>'Oportunidade de melhoria', 'conforme'=>'Conforme')),
          'requisito'=>array('Requisito / item da norma', 'texto'),
          'evidencia'=>array('Evidência', 'longo')),
        'texto'=>'Descrição', 'abre_oc'=>true)),

    'cal' => array('titulo'=>'Calibração', 'singular'=>'instrumento', 'prefixo'=>'INS',
      'sub'=>'Instrumentos de medição, calibrações e vencimentos',
      'status'=>array('ativo'=>'Em uso', 'reprovado'=>'Reprovado', 'manutencao'=>'Em manutenção', 'inativo'=>'Fora de uso'),
      'fluxo'=>array('ativo'=>array('manutencao','inativo'), 'reprovado'=>array('manutencao','inativo','ativo'), 'manutencao'=>array('ativo','inativo'), 'inativo'=>array('ativo')),
      'data_ref'=>'Próxima calibração', 'resp'=>'Responsável',
      'campos'=>array(
        'tag'=>array('Identificação (tag)', 'texto'),
        'tipo'=>array('Tipo de instrumento', 'texto'),
        'fabricante'=>array('Fabricante / modelo / série', 'texto'),
        'local'=>array('Local de uso', 'texto'),
        'faixa'=>array('Faixa e resolução', 'texto'),
        'criterio'=>array('Critério de aceitação (erro máximo)', 'texto'),
        'periodicidade'=>array('Calibrar a cada (meses)', 'numero'),
        'laboratorio'=>array('Laboratório habitual', 'texto')),
      'lista'=>array('tag','local'),
      'item'=>array('tipo'=>'calibracao', 'titulo'=>'Calibrações', 'botao'=>'Registrar calibração',
        'campos'=>array(
          'resultado'=>array('Resultado', 'escolha', array('aprovado'=>'Aprovado', 'reprovado'=>'Reprovado')),
          'certificado'=>array('Nº do certificado', 'texto'),
          'laboratorio'=>array('Laboratório', 'texto'),
          'erro'=>array('Erro / incerteza encontrados', 'texto')),
        'texto'=>'Observações', 'data'=>'Data da calibração', 'anexo'=>true)),

    'risco' => array('titulo'=>'Riscos', 'singular'=>'risco', 'prefixo'=>'RSC',
      'sub'=>'Riscos por processo, matriz probabilidade × impacto e tratamento',
      'status'=>array('aberto'=>'Identificado', 'tratamento'=>'Em tratamento', 'monitorado'=>'Monitorado', 'encerrado'=>'Encerrado'),
      'fluxo'=>array('aberto'=>array('tratamento','monitorado','encerrado'), 'tratamento'=>array('monitorado','encerrado'), 'monitorado'=>array('tratamento','encerrado'), 'encerrado'=>array('aberto')),
      'data_ref'=>'Próxima reavaliação', 'resp'=>'Dono do risco', 'matriz'=>true,
      'campos'=>array(
        'origem'=>array('Contexto', 'escolha', array('Interno', 'Externo')),
        'parte'=>array('Parte interessada', 'texto'),
        'causa'=>array('Causa', 'longo'),
        'consequencia'=>array('Consequência', 'longo'),
        'controles'=>array('Controles existentes', 'longo'),
        'probabilidade'=>array('Probabilidade', 'escala', $escala),
        'impacto'=>array('Impacto', 'escala', $escala),
        'tratamento'=>array('Tratamento', 'escolha', array('Mitigar', 'Evitar', 'Transferir', 'Aceitar')),
        'plano'=>array('Plano de tratamento', 'longo')),
      'lista'=>array('origem'),
      'item'=>array('tipo'=>'avaliacao', 'titulo'=>'Reavaliações', 'botao'=>'Reavaliar',
        'campos'=>array('probabilidade'=>array('Probabilidade', 'escala', $escala), 'impacto'=>array('Impacto', 'escala', $escala)),
        'texto'=>'Comentário', 'abre_oc'=>true)),

    'oport' => array('titulo'=>'Oportunidades', 'singular'=>'oportunidade', 'prefixo'=>'OPT',
      'sub'=>'Oportunidades de melhoria, benefício × viabilidade e acompanhamento',
      'status'=>array('aberto'=>'Identificada', 'tratamento'=>'Em implantação', 'monitorado'=>'Implantada', 'encerrado'=>'Descartada'),
      'fluxo'=>array('aberto'=>array('tratamento','encerrado'), 'tratamento'=>array('monitorado','encerrado'), 'monitorado'=>array('tratamento'), 'encerrado'=>array('aberto')),
      'data_ref'=>'Próxima revisão', 'resp'=>'Responsável', 'matriz'=>true,
      'campos'=>array(
        'origem'=>array('Contexto', 'escolha', array('Interno', 'Externo')),
        'parte'=>array('Parte interessada', 'texto'),
        'beneficio'=>array('Benefício esperado', 'longo'),
        'probabilidade'=>array('Viabilidade', 'escala', $escala),
        'impacto'=>array('Benefício', 'escala', $escala),
        'tratamento'=>array('Decisão', 'escolha', array('Aproveitar', 'Estudar', 'Aguardar', 'Não aproveitar')),
        'plano'=>array('Plano de ação', 'longo')),
      'lista'=>array('origem'),
      'item'=>array('tipo'=>'avaliacao', 'titulo'=>'Revisões', 'botao'=>'Revisar',
        'campos'=>array('probabilidade'=>array('Viabilidade', 'escala', $escala), 'impacto'=>array('Benefício', 'escala', $escala)),
        'texto'=>'Comentário', 'abre_oc'=>true)),

    'forn' => array('titulo'=>'Fornecedores', 'singular'=>'fornecedor', 'prefixo'=>'FOR',
      'sub'=>'Qualificação, avaliação periódica e bloqueio de fornecedores',
      'status'=>array('avaliacao'=>'Em qualificação', 'homologado'=>'Homologado', 'restricao'=>'Homologado com restrição', 'bloqueado'=>'Bloqueado', 'inativo'=>'Inativo'),
      'fluxo'=>array('avaliacao'=>array('homologado','restricao','bloqueado'), 'homologado'=>array('restricao','bloqueado','inativo'),
                     'restricao'=>array('homologado','bloqueado','inativo'), 'bloqueado'=>array('avaliacao','homologado','inativo'), 'inativo'=>array('avaliacao')),
      'data_ref'=>'Próxima avaliação', 'resp'=>'Responsável', 'nota'=>true,
      'campos'=>array(
        'cnpj'=>array('CNPJ', 'texto'),
        'fornece'=>array('O que fornece', 'texto'),
        'criticidade'=>array('Criticidade', 'escolha', array('Crítico', 'Não crítico')),
        'contato'=>array('Contato', 'texto'),
        'telefone'=>array('Telefone', 'texto'),
        'email'=>array('E-mail', 'texto'),
        'certificacoes'=>array('Certificações / documentos e validade', 'longo')),
      'lista'=>array('fornece','criticidade'),
      'item'=>array('tipo'=>'avaliacao', 'titulo'=>'Avaliações', 'botao'=>'Avaliar',
        'campos'=>array(
          'qualidade'=>array('Qualidade', 'numero'), 'prazo'=>array('Prazo de entrega', 'numero'),
          'atendimento'=>array('Atendimento', 'numero'), 'preco'=>array('Preço', 'numero'),
          'documentacao'=>array('Documentação', 'numero')),
        'texto'=>'Comentário', 'notas'=>true, 'abre_oc'=>true)),
  );
}

/* Permissões de cada módulo novo (entram em sgi_permissoes). */
function sgi_mods_perms(){
  return array(
    'aud'=>array('titulo'=>'Auditorias', 'itens'=>array('cadastrar'=>'Planejar e editar auditorias', 'itens'=>'Registrar constatações',
      'abrir_oc'=>'Abrir NC a partir de constatação', 'status'=>'Mudar a situação', 'excluir'=>'Excluir auditoria', 'pendencia_outros'=>'Ver pendências dos outros')),
    'cal'=>array('titulo'=>'Calibração', 'itens'=>array('cadastrar'=>'Cadastrar instrumentos', 'itens'=>'Registrar calibração',
      'status'=>'Mudar a situação', 'excluir'=>'Excluir instrumento', 'pendencia_outros'=>'Ver pendências dos outros')),
    'risco'=>array('titulo'=>'Riscos', 'itens'=>array('cadastrar'=>'Cadastrar e editar riscos', 'itens'=>'Reavaliar',
      'abrir_oc'=>'Abrir ocorrência a partir do risco', 'status'=>'Mudar a situação', 'excluir'=>'Excluir risco', 'pendencia_outros'=>'Ver pendências dos outros')),
    'oport'=>array('titulo'=>'Oportunidades', 'itens'=>array('cadastrar'=>'Cadastrar e editar oportunidades', 'itens'=>'Revisar',
      'abrir_oc'=>'Abrir ocorrência a partir da oportunidade', 'status'=>'Mudar a situação', 'excluir'=>'Excluir oportunidade', 'pendencia_outros'=>'Ver pendências dos outros')),
    'forn'=>array('titulo'=>'Fornecedores', 'itens'=>array('cadastrar'=>'Cadastrar e editar fornecedores', 'itens'=>'Avaliar fornecedor',
      'abrir_oc'=>'Abrir NC de fornecedor', 'status'=>'Homologar ou bloquear', 'excluir'=>'Excluir fornecedor', 'pendencia_outros'=>'Ver pendências dos outros')),
  );
}

/* ── Cálculos ── */
function sgi_reg_nivel_matriz($nota){
  if($nota === null) return null;
  $n = (float)$nota;
  return $n >= 17 ? 'critico' : ($n >= 10 ? 'alto' : ($n >= 5 ? 'medio' : 'baixo'));
}
function sgi_reg_media($dados, $campos){
  $s = 0; $q = 0;
  foreach(array_keys($campos) as $k){ if(isset($dados[$k]) && $dados[$k] !== '' && $dados[$k] !== null){ $s += (float)$dados[$k]; $q++; } }
  return $q ? round($s / $q, 2) : null;
}
/* Situação calculada para a lista: em dia, vence em 30 dias, vencido. */
function sgi_reg_situacao($r, $def){
  $hoje = sgi_hoje();
  $parado = array('cancelada','concluida','inativo','encerrado','bloqueado');
  if(in_array($r['status'], $parado, true) || !$r['data_ref']) return null;
  if($r['modulo'] === 'aud' && $r['status'] !== 'planejada') return null;
  if($r['data_ref'] < $hoje) return 'vencido';
  if($r['data_ref'] <= date('Y-m-d', strtotime('+30 days'))) return 'vencendo';
  return 'em_dia';
}

function sgi_reg_def($mod){
  $d = sgi_mods_def();
  if(!isset($d[$mod])) sgi_erro('Módulo desconhecido.');
  return $d[$mod];
}
function sgi_reg_carregar(SgiDb $db, $me, $id, $mod = null){
  $r = $db->linha("SELECT * FROM sgi_reg WHERE id=?", array((int)$id));
  if(!$r || ($mod && $r['modulo'] !== $mod)) sgi_erro('Registro não encontrado.');
  sgi_exige_modulo($me, $r['modulo']);
  sgi_empresa_ok($me, $r['empresa_id']);
  return $r;
}
function sgi_reg_perm($me, $mod, $item){ sgi_exige_perm($me, $mod.'.'.$item, $item === 'pendencia_outros' ? 'leitor' : ($item === 'excluir' ? 'gestor' : 'membro')); }

/* Limpa os campos conforme a definição. */
function sgi_reg_limpar(SgiDb $db, $campos, $entrada){
  $out = array();
  foreach($campos as $k => $c){
    $v = isset($entrada[$k]) ? trim((string)$entrada[$k]) : '';
    if($v === ''){ $out[$k] = null; continue; }
    switch($c[1]){
      case 'longo': $v = mb_substr($v, 0, 4000, 'UTF-8'); break;
      case 'numero':
        $v = str_replace(',', '.', $v);
        if(!is_numeric($v)) sgi_erro($c[0].': use um número.');
        $v = (float)$v; if($v < 0 || $v > 100000) sgi_erro($c[0].': valor fora do limite.'); break;
      case 'escala': if(!in_array($v, array('1','2','3','4','5'), true)) sgi_erro($c[0].': escolha de 1 a 5.'); $v = (int)$v; break;
      case 'escolha':
        $ops = $c[2]; $chaves = array_keys($ops) === range(0, count($ops) - 1) ? $ops : array_keys($ops);
        if(!in_array($v, array_map('strval', $chaves), true)) sgi_erro($c[0].': opção inválida.'); break;
      case 'data':
        $d = DateTime::createFromFormat('Y-m-d', $v); if(!$d || $d->format('Y-m-d') !== $v) sgi_erro('Data inválida em '.$c[0].'.'); break;
      default: $v = mb_substr($v, 0, 300, 'UTF-8');
    }
    $out[$k] = $v;
  }
  return $out;
}

function sgi_reg_numero(SgiDb $db, $def){
  $ano = date('Y'); $pre = $def['prefixo'].'-'.$ano.'-';
  $ult = $db->valor("SELECT MAX(numero) FROM sgi_reg WHERE numero LIKE ?", array($pre.'%'));
  $seq = $ult ? (int)substr($ult, strlen($pre)) + 1 : 1;
  return $pre.str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
}

/* Próxima data a partir de um acontecimento (calibração, avaliação). */
function sgi_reg_proxima($r, $def, $dados, $data){
  $base = $data ?: sgi_hoje();
  if($r['modulo'] === 'cal'){
    $m = (int)($dados['periodicidade'] ?? 0) ?: 12;
    return date('Y-m-d', strtotime($base.' +'.$m.' months'));
  }
  if($r['modulo'] === 'forn'){
    $crit = ($dados['criticidade'] ?? '') === 'Crítico';
    return date('Y-m-d', strtotime($base.' +'.($crit ? 6 : 12).' months'));
  }
  return date('Y-m-d', strtotime($base.' +12 months'));
}

/* ══════════ Ações ══════════ */
function sgi_reg_executar(SgiDb $db, $me, $acao, $b, $arquivos){
  $L = sgi_listas();
  switch($acao){

  case 'reg_lista': {
    $mod = (string)($b['modulo'] ?? ''); $def = sgi_reg_def($mod);
    sgi_exige_modulo($me, $mod);
    if(!empty($me['nivel_id']) && !sgi_pode($me, $mod.'._modulo')) sgi_erro('Sem acesso ao módulo.');
    list($in, $p) = sgi_in($me['empresas']);
    $w = array("modulo=?", "empresa_id IN $in"); array_unshift($p, $mod);
    if(!empty($b['empresa'])){ $w[] = 'empresa_id=?'; $p[] = (int)$b['empresa']; }
    if(!empty($b['status']) && isset($def['status'][(string)$b['status']])){ $w[] = 'status=?'; $p[] = (string)$b['status']; }
    if(!empty($b['texto'])){ $t = '%'.mb_substr((string)$b['texto'], 0, 80, 'UTF-8').'%'; $w[] = '(numero LIKE ? OR titulo LIKE ? OR dados LIKE ?)'; array_push($p, $t, $t, $t); }
    $n = sgi_nomes($db); $itens = array();
    foreach($db->q("SELECT * FROM sgi_reg WHERE ".implode(' AND ', $w)." ORDER BY (data_ref IS NULL), data_ref, numero LIMIT 1000", $p) as $r){
      $r['dados'] = json_decode($r['dados'] ?: '{}', true) ?: array();
      $r['responsavel'] = $r['responsavel_id'] ? ($n[(int)$r['responsavel_id']] ?? '?') : null;
      $r['situacao'] = sgi_reg_situacao($r, $def);
      $r['nivel'] = !empty($def['matriz']) ? sgi_reg_nivel_matriz($r['nota']) : null;
      $itens[] = $r;
    }
    return array('itens'=>$itens);
  }

  case 'reg_detalhe': {
    $r = sgi_reg_carregar($db, $me, $b['id'] ?? 0); $def = sgi_reg_def($r['modulo']);
    $n = sgi_nomes($db);
    $r['dados'] = json_decode($r['dados'] ?: '{}', true) ?: array();
    $r['responsavel'] = $r['responsavel_id'] ? ($n[(int)$r['responsavel_id']] ?? '?') : null;
    $r['situacao'] = sgi_reg_situacao($r, $def);
    $r['nivel'] = !empty($def['matriz']) ? sgi_reg_nivel_matriz($r['nota']) : null;
    $itens = $db->q("SELECT * FROM sgi_reg_itens WHERE reg_id=? ORDER BY (data IS NULL), data DESC, id DESC", array((int)$r['id']));
    $ocs = array();
    foreach($itens as &$i){
      $i['dados'] = json_decode($i['dados'] ?: '{}', true) ?: array();
      $i['usuario'] = $n[(int)$i['usuario_id']] ?? '?';
      if($i['oc_id']) $ocs[] = (int)$i['oc_id'];
      $i['anexos'] = $db->q("SELECT id, nome_original, bytes FROM sgi_anexos WHERE ref_tipo='reg_item' AND ref_id=?", array((int)$i['id']));
    }
    unset($i);
    $num = array();
    if($ocs){ list($inO, $pO) = sgi_in($ocs); foreach($db->q("SELECT id, numero, status FROM sgi_ocorrencias WHERE id IN $inO", $pO) as $o) $num[(int)$o['id']] = $o; }
    foreach($itens as &$i) $i['oc'] = $i['oc_id'] ? ($num[(int)$i['oc_id']] ?? null) : null;
    unset($i);
    $hist = $db->q("SELECT usuario_id, texto, em FROM sgi_historico WHERE ref_tipo='reg' AND ref_id=? ORDER BY id DESC", array((int)$r['id']));
    foreach($hist as &$h) $h['usuario'] = $n[(int)$h['usuario_id']] ?? 'Sistema';
    unset($h);
    return array('registro'=>$r, 'itens'=>$itens, 'historico'=>$hist);
  }

  case 'reg_salvar': {
    $id = (int)($b['id'] ?? 0);
    if($id){ $r = sgi_reg_carregar($db, $me, $id); $mod = $r['modulo']; } else { $mod = (string)($b['modulo'] ?? ''); }
    $def = sgi_reg_def($mod);
    sgi_exige_modulo($me, $mod); sgi_reg_perm($me, $mod, 'cadastrar');
    $emp = (int)($b['empresa_id'] ?? ($id ? $r['empresa_id'] : 0));
    if(!$emp) sgi_erro('Escolha a empresa.');
    sgi_empresa_ok($me, $emp);
    $dados = sgi_reg_limpar($db, $def['campos'], (array)($b['dados'] ?? array()));
    $c = array('empresa_id'=>$emp,
      'titulo'=>sgi_txt($b, 'titulo', 200, true, $mod === 'forn' ? 'razão social' : ($mod === 'cal' ? 'nome do instrumento' : 'título')),
      'responsavel_id'=>sgi_usuario_valido($db, $b['responsavel_id'] ?? null),
      'processo'=>sgi_txt($b, 'processo', 80),
      'data_ref'=>sgi_data($b, 'data_ref', false, $def['data_ref']),
      'dados'=>json_encode($dados, JSON_UNESCAPED_UNICODE), 'atualizado_em'=>sgi_agora());
    if(!empty($def['matriz'])) $c['nota'] = ($dados['probabilidade'] && $dados['impacto']) ? $dados['probabilidade'] * $dados['impacto'] : null;
    if($mod === 'cal' && !$c['data_ref'] && $id === 0) $c['data_ref'] = null;
    if($id){
      $db->atualizar('sgi_reg', $c, $id);
      sgi_hist($db, 'reg', $id, $me, 'Dados atualizados.');
      return array('id'=>$id);
    }
    $c['modulo'] = $mod;
    $c['numero'] = sgi_reg_numero($db, $def);
    $c['status'] = array_keys($def['status'])[0];
    $c['criado_por'] = $me['id']; $c['criado_em'] = sgi_agora();
    $novo = $db->inserir('sgi_reg', $c);
    sgi_hist($db, 'reg', $novo, $me, ucfirst($def['singular']).' cadastrado como '.$c['numero'].'.');
    return array('id'=>$novo, 'numero'=>$c['numero']);
  }

  case 'reg_status': {
    $r = sgi_reg_carregar($db, $me, $b['id'] ?? 0); $def = sgi_reg_def($r['modulo']);
    sgi_reg_perm($me, $r['modulo'], 'status');
    $para = (string)($b['para'] ?? '');
    if(!isset($def['status'][$para]) || !in_array($para, $def['fluxo'][$r['status']] ?? array(), true))
      sgi_erro('Não dá para passar de "'.$def['status'][$r['status']].'" para essa situação.');
    $obs = sgi_txt($b, 'obs', 1000, in_array($para, array('cancelada','bloqueado','reprovado','inativo','encerrado'), true), 'motivo');
    $db->atualizar('sgi_reg', array('status'=>$para, 'atualizado_em'=>sgi_agora()), $r['id']);
    sgi_hist($db, 'reg', $r['id'], $me, 'Situação: '.$def['status'][$r['status']].' → '.$def['status'][$para].($obs ? '. '.$obs : '.'));
    return array();
  }

  case 'reg_item_salvar': {
    $r = sgi_reg_carregar($db, $me, $b['reg_id'] ?? 0); $def = sgi_reg_def($r['modulo']); $it = $def['item'];
    sgi_reg_perm($me, $r['modulo'], 'itens');
    if(in_array($r['status'], array('cancelada','inativo','encerrado'), true)) sgi_erro('Reabra o registro antes.');
    if($r['modulo'] === 'aud' && $r['status'] === 'planejada') sgi_erro('Inicie a auditoria (Em execução) antes de registrar constatações.');
    $dados = sgi_reg_limpar($db, $it['campos'], (array)($b['dados'] ?? array()));
    $texto = sgi_txt($b, 'texto', 4000, $r['modulo'] === 'aud', $it['texto']);
    $data = sgi_data($b, 'data', !empty($it['data']), $it['data'] ?? 'data') ?: sgi_hoje();
    if($data > sgi_hoje()) sgi_erro('A data não pode ser no futuro.');
    $nota = null;
    foreach($it['campos'] as $k => $c) if(($c[1] === 'escolha' || $c[1] === 'escala') && $dados[$k] === null && $k !== 'classe') sgi_erro('Preencha: '.$c[0].'.');
    if($r['modulo'] === 'aud' && !$dados['classe']) sgi_erro('Escolha a classificação da constatação.');
    if(!empty($def['matriz'])) $nota = $dados['probabilidade'] * $dados['impacto'];
    if(!empty($it['notas'])){
      foreach(array_keys($it['campos']) as $k){
        if($dados[$k] === null) sgi_erro('Dê nota para '.$it['campos'][$k][0].'.');
        if($dados[$k] > 10) sgi_erro('As notas vão de 0 a 10.');
      }
      $nota = sgi_reg_media($dados, $it['campos']);
    }
    $db->inicio();
    try {
      $iid = $db->inserir('sgi_reg_itens', array('reg_id'=>(int)$r['id'], 'tipo'=>$it['tipo'], 'data'=>$data, 'nota'=>$nota,
        'texto'=>$texto, 'dados'=>json_encode($dados, JSON_UNESCAPED_UNICODE), 'usuario_id'=>$me['id'], 'em'=>sgi_agora()));
      $rd = json_decode($r['dados'] ?: '{}', true) ?: array();
      $upd = array('atualizado_em'=>sgi_agora());
      $txt = '';
      if($r['modulo'] === 'cal'){
        $upd['data_ref'] = sgi_reg_proxima($r, $def, $rd, $data);
        $upd['status'] = $dados['resultado'] === 'reprovado' ? 'reprovado' : ($r['status'] === 'reprovado' ? 'ativo' : $r['status']);
        $txt = 'Calibração em '.date('d/m/Y', strtotime($data)).': '.($dados['resultado'] === 'reprovado' ? 'reprovado' : 'aprovado').'. Próxima até '.date('d/m/Y', strtotime($upd['data_ref'])).'.';
      } elseif(!empty($def['matriz'])){
        $rd['probabilidade'] = $dados['probabilidade']; $rd['impacto'] = $dados['impacto'];
        $upd['dados'] = json_encode($rd, JSON_UNESCAPED_UNICODE); $upd['nota'] = $nota;
        $upd['data_ref'] = sgi_reg_proxima($r, $def, $rd, $data);
        $txt = 'Reavaliado: '.$dados['probabilidade'].' × '.$dados['impacto'].' = '.$nota.'.';
      } elseif($r['modulo'] === 'forn'){
        $upd['nota'] = $nota; $upd['data_ref'] = sgi_reg_proxima($r, $def, $rd, $data);
        $txt = 'Avaliação com nota '.number_format($nota, 1, ',', '').'. '.($nota >= 7 ? 'Sugestão: homologar.' : ($nota >= 5 ? 'Sugestão: homologar com restrição.' : 'Sugestão: bloquear.'));
      } else {
        $txt = 'Constatação: '.$def['item']['campos']['classe'][2][$dados['classe']].'.';
      }
      $db->atualizar('sgi_reg', $upd, $r['id']);
      sgi_hist($db, 'reg', $r['id'], $me, $txt);
      $db->fim();
    } catch(Exception $e){ $db->desfaz(); throw $e; }
    if(!empty($arquivos['arquivo']) && !empty($it['anexo']) && ($arquivos['arquivo']['error'] ?? 4) !== 4){
      $f = sgi_guardar_arquivo($arquivos['arquivo']);
      $db->inserir('sgi_anexos', $f + array('ref_tipo'=>'reg_item', 'ref_id'=>$iid, 'enviado_por'=>$me['id'], 'enviado_em'=>sgi_agora()));
    }
    return array('id'=>$iid, 'nota'=>$nota);
  }

  /* Abre uma ocorrência (NC ou melhoria) a partir de uma constatação,
     reavaliação ou avaliação, e liga as duas. */
  case 'reg_abrir_oc': {
    $i = $db->linha("SELECT * FROM sgi_reg_itens WHERE id=?", array((int)($b['item_id'] ?? 0)));
    if(!$i) sgi_erro('Item não encontrado.');
    $r = sgi_reg_carregar($db, $me, $i['reg_id']); $def = sgi_reg_def($r['modulo']);
    if(empty($def['item']['abre_oc'])) sgi_erro('Este módulo não abre ocorrência.');
    sgi_reg_perm($me, $r['modulo'], 'abrir_oc');
    sgi_exige_perm($me, 'nc.nova', 'leitor');
    if($i['oc_id']) sgi_erro('Já existe uma ocorrência ligada a este item.');
    $d = json_decode($i['dados'] ?: '{}', true) ?: array();
    $tipo = $r['modulo'] === 'oport' || in_array($d['classe'] ?? '', array('oportunidade','observacao'), true) ? 'melhoria' : 'nc';
    $origem = array('aud'=>'Auditoria interna', 'cal'=>'Processo interno', 'risco'=>'Processo interno', 'oport'=>'Sugestão de colaborador', 'forn'=>'Fornecedor')[$r['modulo']];
    $desc = $r['numero'].' · '.$r['titulo']."\n".($i['texto'] ?: '');
    if($r['modulo'] === 'aud') $desc .= ($d['requisito'] ? "\nRequisito: ".$d['requisito'] : '').($d['evidencia'] ? "\nEvidência: ".$d['evidencia'] : '');
    if($r['modulo'] === 'forn') $desc .= "\nNota da avaliação: ".number_format((float)$i['nota'], 1, ',', '');
    $grav = ($d['classe'] ?? '') === 'nc_maior' ? 'alta' : 'media';
    $titulo = sgi_txt($b, 'titulo', 200) ?: mb_substr(($r['modulo'] === 'aud' ? 'Auditoria '.$r['numero'].': ' : $r['numero'].': ').($i['texto'] ?: $r['titulo']), 0, 200, 'UTF-8');
    $c = sgi_oc_campos($db, array('titulo'=>$titulo, 'descricao'=>$desc, 'origem'=>$origem, 'gravidade'=>$grav, 'processo'=>$r['processo']), $tipo);
    list($novo, $numero) = sgi_oc_criar($db, $me, $r['empresa_id'], $tipo, $c);
    $db->atualizar('sgi_reg_itens', array('oc_id'=>$novo), $i['id']);
    sgi_hist($db, 'ocorrencia', $novo, $me, 'Aberta a partir de '.$r['numero'].'.');
    sgi_hist($db, 'reg', $r['id'], $me, 'Ocorrência '.$numero.' aberta.');
    return array('id'=>$novo, 'numero'=>$numero);
  }

  case 'reg_excluir': {
    $r = sgi_reg_carregar($db, $me, $b['id'] ?? 0);
    sgi_reg_perm($me, $r['modulo'], 'excluir');
    if(trim((string)($b['confirmar'] ?? '')) !== $r['numero']) sgi_erro('Para excluir, digite o número '.$r['numero'].'.');
    foreach($db->q("SELECT id FROM sgi_reg_itens WHERE reg_id=?", array((int)$r['id'])) as $x)
      $db->q("DELETE FROM sgi_anexos WHERE ref_tipo='reg_item' AND ref_id=?", array((int)$x['id']));
    $db->q("DELETE FROM sgi_reg_itens WHERE reg_id=?", array((int)$r['id']));
    $db->q("DELETE FROM sgi_reg WHERE id=?", array((int)$r['id']));
    sgi_hist($db, 'reg', $r['id'], $me, 'Excluído: '.$r['numero'].' · '.$r['titulo']);
    return array();
  }
  }
  sgi_erro('Ação desconhecida: '.$acao);
}

/* Pendências dos módulos novos para o painel ("Com você"). */
function sgi_reg_pendencias(SgiDb $db, $me){
  $out = array(); $def = sgi_mods_def();
  list($in, $p) = sgi_in($me['empresas']);
  $lim = date('Y-m-d', strtotime('+30 days'));
  foreach($db->q("SELECT id, modulo, numero, titulo, status, data_ref FROM sgi_reg WHERE responsavel_id=? AND empresa_id IN $in
                  AND data_ref IS NOT NULL AND data_ref <= ? ORDER BY data_ref", array_merge(array($me['id']), $p, array($lim))) as $r){
    if(!empty($me['mods_off']) && in_array($r['modulo'], $me['mods_off'], true)) continue;
    if(!sgi_pode_modulo($me, $r['modulo'])) continue;
    $r['situacao'] = sgi_reg_situacao($r, $def[$r['modulo']]);
    if(!$r['situacao']) continue;
    $acao = array('aud'=>'Realizar auditoria', 'cal'=>'Calibrar', 'risco'=>'Reavaliar risco', 'oport'=>'Revisar oportunidade', 'forn'=>'Avaliar fornecedor')[$r['modulo']];
    $out[] = array('id'=>(int)$r['id'], 'modulo'=>$r['modulo'], 'numero'=>$r['numero'], 'titulo'=>$r['titulo'], 'acao'=>$acao, 'prazo'=>$r['data_ref']);
  }
  return $out;
}
function sgi_pode_modulo($me, $mod){
  if(!empty($me['mods_off']) && in_array($mod, $me['mods_off'], true)) return false;
  return empty($me['nivel_id']) || sgi_pode($me, $mod.'._modulo');
}
