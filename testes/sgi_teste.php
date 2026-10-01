<?php
/* ============================================================
   testes/sgi_teste.php — testa a API do SGI inteira em SQLite.

   Rodar (precisa das extensões pdo_sqlite, mbstring e fileinfo):
     php testes/sgi_teste.php

   Não roda no servidor: a pasta testes/ fica fora da imagem
   (.dockerignore).
   ============================================================ */

if(PHP_SAPI !== 'cli') exit('Só pela linha de comando.');
define('SGI_TESTE', 1);
define('SGI_PASTA', sys_get_temp_dir().'/sgi_teste_'.getmypid());
require __DIR__.'/../sgi.php';

$pdo = new PDO('sqlite::memory:');
$db = new SgiDb($pdo);
$pdo->exec("CREATE TABLE portal_usuarios (id INTEGER PRIMARY KEY, username TEXT, name TEXT, role TEXT, telefone TEXT)");
$pdo->exec("INSERT INTO portal_usuarios VALUES
  (1,'admin','Admin do Hub','admin','41999990001'),
  (2,'ana','Ana Gestora','user','41999990002'),
  (3,'bruno','Bruno Membro A','user',''),
  (4,'carla','Carla Leitora A','user',''),
  (5,'davi','Davi Membro B','user',''),
  (6,'eva','Eva sem acesso','user','')");
sgi_estrutura($db);

$ok = 0; $falhas = array();
function confere($nome, $cond){ global $ok, $falhas; if($cond) $ok++; else { $falhas[] = $nome; echo "  FALHOU: $nome\n"; } }
function api($uid, $acao, $b = array(), $arq = array()){ global $db; return sgi_executar($db, $uid, $acao, $b, $arq); }
function erro($uid, $acao, $b = array(), $arq = array()){
  try { api($uid, $acao, $b, $arq); return null; } catch(SgiErro $e){ return $e->getMessage(); }
}
function arquivo($nome, $conteudo = "%PDF-1.4 teste"){
  $t = tempnam(sys_get_temp_dir(), 'sgi'); file_put_contents($t, $conteudo);
  return array('arquivo'=>array('name'=>$nome, 'tmp_name'=>$t, 'size'=>strlen($conteudo), 'error'=>0));
}

echo "Estrutura\n";
confere('indicadores de partida', (int)$db->valor("SELECT COUNT(*) FROM sgi_indicadores") === 8);
sgi_estrutura($db);
confere('estrutura idempotente', (int)$db->valor("SELECT COUNT(*) FROM sgi_indicadores") === 8);

echo "Acesso\n";
$e = api(6, 'eu');
confere('sem acesso: eu responde, papel nulo', $e['eu']['papel'] === null);
confere('sem acesso: painel barrado', strpos((string)erro(6, 'painel'), 'acesso') !== false);
confere('admin do hub é gestor', api(1, 'eu')['eu']['papel'] === 'gestor');

$A = api(1, 'empresa_salvar', array('nome'=>'Empresa A', 'sigla'=>'EA'))['id'];
$B = api(1, 'empresa_salvar', array('nome'=>'Empresa B', 'sigla'=>'EB'))['id'];
confere('gestor via config', erro(1, 'acesso_salvar', array('usuario_id'=>2, 'papel'=>'gestor')) === null);
api(1, 'acesso_salvar', array('usuario_id'=>3, 'papel'=>'membro', 'empresas'=>array($A)));
api(1, 'acesso_salvar', array('usuario_id'=>4, 'papel'=>'leitor', 'empresas'=>array($A)));
api(2, 'acesso_salvar', array('usuario_id'=>5, 'papel'=>'membro', 'empresas'=>array($B)));
confere('membro precisa de empresa', erro(1, 'acesso_salvar', array('usuario_id'=>6, 'papel'=>'membro')) !== null);
confere('membro não configura', erro(3, 'empresa_salvar', array('nome'=>'X')) !== null);
confere('não rebaixa admin', erro(2, 'acesso_salvar', array('usuario_id'=>1, 'papel'=>'leitor')) !== null);
confere('gestor vê as duas', count(api(2, 'eu')['eu']['empresas']) === 2);
confere('membro vê só a dele', api(3, 'eu')['eu']['empresas'] === array($A));
confere('lista de usuários exclui quem não tem acesso',
  !in_array(6, array_column(api(3, 'eu')['usuarios'], 'id'), true));

echo "Ocorrências\n";
$nc = array('tipo'=>'nc', 'empresa_id'=>$A, 'titulo'=>'Ônibus saiu sem extintor',
  'descricao'=>'Inspeção de saída encontrou veículo 1234 sem extintor.', 'origem'=>'Inspeção / fiscalização',
  'norma'=>'45001', 'gravidade'=>'alta', 'data_fato'=>date('Y-m-d'));
$r = api(3, 'oc_salvar', $nc);
$idNc = $r['id'];
confere('numeração RNC', $r['numero'] === 'RNC-'.date('Y').'-0001');
confere('segunda RNC numera 0002', api(3, 'oc_salvar', $nc)['numero'] === 'RNC-'.date('Y').'-0002');
confere('membro B não registra na A', erro(5, 'oc_salvar', $nc) !== null);
confere('membro B não vê a RNC da A', erro(5, 'oc_detalhe', array('id'=>$idNc)) !== null);
confere('lista do membro B vazia', count(api(5, 'oc_lista')['itens']) === 0);
confere('lista do gestor tem 2', count(api(2, 'oc_lista')['itens']) === 2);
confere('data futura barrada', erro(3, 'oc_salvar', array('data_fato'=>date('Y-m-d', strtotime('+2 days'))) + $nc) !== null);
confere('título obrigatório', erro(3, 'oc_salvar', array('titulo'=>'') + $nc) !== null);

$rl = api(4, 'oc_salvar', array('tipo'=>'melhoria', 'empresa_id'=>$A, 'titulo'=>'Sugestão', 'descricao'=>'Trocar o quadro de avisos',
  'responsavel_id'=>3, 'prazo'=>date('Y-m-d', strtotime('+5 days'))));
$oLeitor = api(4, 'oc_detalhe', array('id'=>$rl['id']))['ocorrencia'];
confere('leitor registra, mas sem definir responsável', $oLeitor['responsavel_id'] === null && $rl['numero'] === 'OM-'.date('Y').'-0001');
confere('leitor não muda fase', erro(4, 'oc_status', array('id'=>$rl['id'], 'para'=>'acao')) !== null);
confere('leitor não edita RNC alheia', erro(4, 'oc_salvar', array('id'=>$idNc) + $nc) !== null);

echo "Fluxo da RNC\n";
confere('não pula para verificação', erro(3, 'oc_status', array('id'=>$idNc, 'para'=>'verificacao')) !== null);
api(3, 'oc_status', array('id'=>$idNc, 'para'=>'analise'));
confere('plano exige causa raiz', strpos((string)erro(3, 'oc_status', array('id'=>$idNc, 'para'=>'acao')), 'causa raiz') !== false);
api(3, 'oc_analise', array('id'=>$idNc, 'causa_metodo'=>'5porques',
  'causa_dados'=>array('porques'=>array('Faltou extintor', 'Checklist não cobre', 'POP desatualizado'), '<script>'=>'x'),
  'causa_raiz'=>'Checklist de saída não inclui extintor.', 'acao_imediata'=>'Veículo recolhido.'));
$d = api(3, 'oc_detalhe', array('id'=>$idNc));
confere('5 porquês gravado', count($d['ocorrencia']['causa_dados']['porques']) === 3);
confere('chave estranha descartada', !isset($d['ocorrencia']['causa_dados']['<script>']));
api(3, 'oc_analise', array('id'=>$idNc, 'resposta'=>'x'));
confere('gravação parcial não apaga causa', api(3, 'oc_detalhe', array('id'=>$idNc))['ocorrencia']['causa_raiz'] !== null);

api(3, 'oc_status', array('id'=>$idNc, 'para'=>'acao'));
confere('verificação exige ação', erro(3, 'oc_status', array('id'=>$idNc, 'para'=>'verificacao')) !== null);
confere('ação com prazo no passado barrada', erro(3, 'acao_salvar', array('ocorrencia_id'=>$idNc, 'descricao'=>'x',
  'responsavel_id'=>3, 'prazo'=>date('Y-m-d', strtotime('-1 day')))) !== null);
confere('ação exige responsável', erro(3, 'acao_salvar', array('ocorrencia_id'=>$idNc, 'descricao'=>'x',
  'prazo'=>date('Y-m-d'))) !== null);
$a1 = api(3, 'acao_salvar', array('ocorrencia_id'=>$idNc, 'tipo'=>'corretiva', 'descricao'=>'Incluir extintor no checklist',
  'responsavel_id'=>4, 'prazo'=>date('Y-m-d', strtotime('+10 days'))))['id'];
$a2 = api(3, 'acao_salvar', array('ocorrencia_id'=>$idNc, 'tipo'=>'preventiva', 'descricao'=>'Treinar fiscais',
  'responsavel_id'=>3, 'prazo'=>date('Y-m-d')))['id'];
confere('leitor não cria ação', erro(4, 'acao_salvar', array('ocorrencia_id'=>$idNc, 'descricao'=>'x', 'responsavel_id'=>4, 'prazo'=>date('Y-m-d'))) !== null);
confere('com ação pendente não verifica', strpos((string)erro(3, 'oc_status', array('id'=>$idNc, 'para'=>'verificacao')), 'pendente') !== false);
confere('conclusão exige evidência', erro(4, 'acao_concluir', array('id'=>$a1)) !== null);
confere('responsável leitor conclui a própria', erro(4, 'acao_concluir', array('id'=>$a1, 'evidencia'=>'Checklist rev. 3')) === null);
confere('leitor não conclui a de outro', erro(4, 'acao_concluir', array('id'=>$a2, 'evidencia'=>'x')) !== null);
confere('leitor não cancela', erro(4, 'acao_concluir', array('id'=>$a2, 'evidencia'=>'x', 'cancelar'=>1)) !== null);
confere('responsável anexa evidência', erro(4, 'anexo_enviar', array('ref_tipo'=>'acao', 'ref_id'=>$a1), arquivo('checklist.pdf')) === null);
confere('extensão perigosa barrada', strpos((string)erro(3, 'anexo_enviar', array('ref_tipo'=>'ocorrencia', 'ref_id'=>$idNc), arquivo('x.php', '<?php')), 'não aceito') !== false);
$p = api(3, 'painel');
confere('painel: minha ação aparece', in_array($a2, array_map('intval', array_column($p['minhas']['acoes'], 'id')), true));
api(3, 'acao_concluir', array('id'=>$a2, 'evidencia'=>'Lista de presença'));
confere('ação concluída não é editada', erro(3, 'acao_salvar', array('id'=>$a2, 'descricao'=>'y', 'responsavel_id'=>3, 'prazo'=>date('Y-m-d'))) !== null);
api(3, 'oc_status', array('id'=>$idNc, 'para'=>'verificacao'));
confere('plano fecha na verificação', erro(3, 'acao_salvar', array('ocorrencia_id'=>$idNc, 'descricao'=>'x', 'responsavel_id'=>3, 'prazo'=>date('Y-m-d'))) !== null);
confere('membro não encerra', erro(3, 'oc_status', array('id'=>$idNc, 'para'=>'encerrada', 'eficacia'=>'eficaz', 'obs'=>'ok')) !== null);
confere('encerrar exige evidência de eficácia', erro(2, 'oc_status', array('id'=>$idNc, 'para'=>'encerrada', 'eficacia'=>'eficaz')) !== null);
$s = api(2, 'oc_status', array('id'=>$idNc, 'para'=>'encerrada', 'eficacia'=>'ineficaz', 'obs'=>'Voltou a acontecer no 1250'));
confere('ineficaz volta para análise', $s['status'] === 'analise');
api(3, 'oc_status', array('id'=>$idNc, 'para'=>'acao'));
$a3 = api(3, 'acao_salvar', array('ocorrencia_id'=>$idNc, 'descricao'=>'Lacre no suporte', 'responsavel_id'=>3, 'prazo'=>date('Y-m-d')))['id'];
api(3, 'acao_concluir', array('id'=>$a3, 'evidencia'=>'Fotos'));
api(3, 'oc_status', array('id'=>$idNc, 'para'=>'verificacao'));
$s = api(2, 'oc_status', array('id'=>$idNc, 'para'=>'encerrada', 'eficacia'=>'eficaz', 'obs'=>'30 dias sem reincidência'));
$d = api(2, 'oc_detalhe', array('id'=>$idNc));
confere('encerrada com eficácia', $s['status'] === 'encerrada' && $d['ocorrencia']['eficacia'] === 'eficaz' && $d['ocorrencia']['encerrada_em']);
confere('editar encerrada barrado', erro(3, 'oc_salvar', array('id'=>$idNc) + $nc) !== null);
confere('reabrir exige gestor', erro(3, 'oc_status', array('id'=>$idNc, 'para'=>'analise', 'obs'=>'x')) !== null);
confere('reabrir exige motivo', erro(2, 'oc_status', array('id'=>$idNc, 'para'=>'analise')) !== null);
confere('histórico registrado', count($d['historico']) >= 10);
confere('anexo da ação no detalhe', count($d['acoes'][0]['anexos']) === 1);

echo "Reclamação\n";
$rec = api(5, 'oc_salvar', array('tipo'=>'reclamacao', 'empresa_id'=>$B, 'titulo'=>'Motorista não parou no ponto',
  'descricao'=>'Passageira esperou no ponto e o ônibus passou direto.', 'canal'=>'156 / Prefeitura',
  'linha'=>'203', 'veiculo'=>'BC-201', 'reclamante'=>'Maria', 'protocolo_externo'=>'156-99'));
confere('numeração REC', $rec['numero'] === 'REC-'.date('Y').'-0001');
$o = api(5, 'oc_detalhe', array('id'=>$rec['id']))['ocorrencia'];
confere('origem padrão da reclamação', $o['origem'] === 'Reclamação de cliente' && $o['linha'] === '203');
confere('encerrar sem resposta barrado', strpos((string)erro(5, 'oc_status', array('id'=>$rec['id'], 'para'=>'encerrada')), 'resposta') !== false);
$g = api(5, 'oc_gerar_nc', array('id'=>$rec['id']));
confere('reclamação gera RNC', strpos($g['numero'], 'RNC-') === 0);
confere('não gera duas vezes', erro(5, 'oc_gerar_nc', array('id'=>$rec['id'])) !== null);
confere('RNC gerada aponta a origem', count(api(5, 'oc_detalhe', array('id'=>$g['id']))['ligadas']) === 1);
api(5, 'oc_analise', array('id'=>$rec['id'], 'resposta'=>'Pedimos desculpas; motorista reorientado.'));
confere('reclamação encerra com resposta', api(5, 'oc_status', array('id'=>$rec['id'], 'para'=>'encerrada'))['status'] === 'encerrada');
confere('busca por linha', count(api(2, 'oc_lista', array('texto'=>'203', 'status'=>'todas'))['itens']) === 1);
confere('filtro tipo', count(api(2, 'oc_lista', array('tipo'=>'reclamacao', 'status'=>'todas'))['itens']) === 1);
confere('cancelar exige gestor', erro(5, 'oc_status', array('id'=>$g['id'], 'para'=>'cancelada', 'obs'=>'dup')) !== null);
confere('gestor cancela com motivo', api(2, 'oc_status', array('id'=>$g['id'], 'para'=>'cancelada', 'obs'=>'Duplicada'))['status'] === 'cancelada');

echo "Documentos\n";
$doc = array('codigo'=>'pop-man-001', 'titulo'=>'Inspeção de saída', 'tipo'=>'Procedimento (POP)', 'empresa_id'=>$A,
  'normas'=>array('9001', '45001', 'xx'), 'exige_ciencia'=>1, 'revisao_meses'=>12);
$idDoc = api(3, 'doc_salvar', $doc)['id'];
$dd = api(3, 'doc_detalhe', array('id'=>$idDoc))['documento'];
confere('código em maiúsculas e normas filtradas', $dd['codigo'] === 'POP-MAN-001' && $dd['normas'] === '9001,45001');
confere('código duplicado barrado', erro(3, 'doc_salvar', $doc) !== null);
confere('código com espaço barrado', erro(3, 'doc_salvar', array('codigo'=>'POP 1') + $doc) !== null);
confere('membro não cria corporativo', erro(3, 'doc_salvar', array('codigo'=>'MAN-001', 'empresa_id'=>'') + $doc) !== null);
confere('leitor não cria documento', erro(4, 'doc_salvar', array('codigo'=>'X-1') + $doc) !== null);
confere('leitor não vê rascunho', erro(4, 'doc_detalhe', array('id'=>$idDoc)) !== null);
confere('versão exige descrição da mudança', erro(3, 'doc_versao_enviar', array('documento_id'=>$idDoc), arquivo('pop.pdf')) !== null);
api(3, 'doc_versao_enviar', array('documento_id'=>$idDoc, 'alteracao'=>'Emissão inicial'), arquivo('pop.pdf'));
confere('só uma versão pendente', erro(3, 'doc_versao_enviar', array('documento_id'=>$idDoc, 'alteracao'=>'x'), arquivo('pop.pdf')) !== null);
$v1 = api(2, 'doc_detalhe', array('id'=>$idDoc))['versoes'][0];
confere('membro não aprova', erro(3, 'doc_versao_decidir', array('id'=>$v1['id'], 'aprovar'=>1)) !== null);
confere('painel do gestor mostra aprovação', count(api(2, 'painel')['minhas']['aprovar']) === 1);
api(2, 'doc_versao_decidir', array('id'=>$v1['id'], 'aprovar'=>1));
$dd = api(4, 'doc_detalhe', array('id'=>$idDoc));
confere('vigente para o leitor', $dd['documento']['status'] === 'vigente' && (int)$dd['documento']['versao_vigente'] === 1);
confere('revisão agendada', $dd['documento']['revisar_em'] === date('Y-m-d', strtotime('+12 months')));
confere('leitor tem ciência pendente', count(api(4, 'painel')['minhas']['ciencia']) === 1);
api(4, 'doc_ciencia', array('id'=>$idDoc));
api(4, 'doc_ciencia', array('id'=>$idDoc));
confere('ciência sem duplicar', (int)$db->valor("SELECT COUNT(*) FROM sgi_doc_ciencias") === 1);
confere('ciência sai do painel', count(api(4, 'painel')['minhas']['ciencia']) === 0);
api(3, 'doc_versao_enviar', array('documento_id'=>$idDoc, 'alteracao'=>'Inclui extintor'), arquivo('pop2.pdf'));
$v2 = api(2, 'doc_detalhe', array('id'=>$idDoc))['versoes'][0];
confere('reprovar exige motivo', erro(2, 'doc_versao_decidir', array('id'=>$v2['id'])) !== null);
api(2, 'doc_versao_decidir', array('id'=>$v2['id'], 'aprovar'=>0, 'parecer'=>'Falta o item 4'));
api(3, 'doc_versao_enviar', array('documento_id'=>$idDoc, 'alteracao'=>'Inclui extintor e item 4'), arquivo('pop3.pdf'));
$v3 = api(2, 'doc_detalhe', array('id'=>$idDoc))['versoes'][0];
api(2, 'doc_versao_decidir', array('id'=>$v3['id'], 'aprovar'=>1));
$vs = api(2, 'doc_detalhe', array('id'=>$idDoc))['versoes'];
confere('v3 vigente, v1 substituída, v2 reprovada', $vs[0]['status'] === 'aprovada' && (int)$vs[0]['versao'] === 3
  && $vs[1]['status'] === 'reprovada' && $vs[2]['status'] === 'substituida');
confere('nova versão pede nova ciência', count(api(4, 'painel')['minhas']['ciencia']) === 1);
confere('leitor só vê a versão vigente', count(api(4, 'doc_detalhe', array('id'=>$idDoc))['versoes']) === 1);
confere('leitor não baixa versão antiga', strpos((string)(function() use ($db, $v1){ try { sgi_arquivo_permitido($db, 4, 'versao', $v1['id']); } catch(SgiErro $e){ return $e->getMessage(); } })(), 'não disponível') !== false);
confere('leitor baixa a vigente', sgi_arquivo_permitido($db, 4, 'versao', $v3['id'])['nome_original'] === 'pop3.pdf');
confere('membro B não baixa doc da A', (function() use ($db, $v3){ try { sgi_arquivo_permitido($db, 5, 'versao', $v3['id']); return false; } catch(SgiErro $e){ return true; } })());
confere('arquivo gravado fora da web e .htaccess criado', is_file(SGI_PASTA.'/.htaccess'));
confere('membro B não vê doc da A na lista', count(api(5, 'doc_lista')['itens']) === 0);
$corp = api(2, 'doc_salvar', array('codigo'=>'MAN-SGI-001', 'titulo'=>'Manual do SGI', 'tipo'=>'Manual', 'empresa_id'=>'') + $doc)['id'];
api(2, 'doc_versao_enviar', array('documento_id'=>$corp, 'alteracao'=>'Emissão'), arquivo('manual.pdf'));
api(2, 'doc_versao_decidir', array('id'=>api(2, 'doc_detalhe', array('id'=>$corp))['versoes'][0]['id'], 'aprovar'=>1));
confere('corporativo aparece para todas', count(api(5, 'doc_lista')['itens']) === 1);
confere('obsoletar exige motivo', erro(2, 'doc_obsoletar', array('id'=>$corp)) !== null);
api(2, 'doc_obsoletar', array('id'=>$corp, 'motivo'=>'Substituído'));
confere('obsoleto some da lista ativa', count(api(5, 'doc_lista')['itens']) === 0);
$db->q("UPDATE sgi_documentos SET revisar_em=? WHERE id=?", array(date('Y-m-d', strtotime('-1 day')), $idDoc));
confere('painel conta revisão vencida', api(2, 'painel')['docs']['revisao_vencida'] === 1);

echo "Indicadores\n";
$inds = api(3, 'ind_lista')['indicadores'];
$pont = (int)$inds[0]['id']; $recl = (int)$inds[2]['id'];
$mesAnt = date('Y-m', strtotime('first day of last month'));
confere('mês futuro barrado', erro(3, 'ind_lancar', array('empresa_id'=>$A, 'competencia'=>date('Y-m', strtotime('first day of +1 month')), 'valores'=>array($pont=>90))) !== null);
confere('leitor não lança', erro(4, 'ind_lancar', array('empresa_id'=>$A, 'competencia'=>$mesAnt, 'valores'=>array($pont=>90))) !== null);
confere('membro não lança em outra empresa', erro(3, 'ind_lancar', array('empresa_id'=>$B, 'competencia'=>$mesAnt, 'valores'=>array($pont=>90))) !== null);
confere('valor não numérico barrado', erro(3, 'ind_lancar', array('empresa_id'=>$A, 'competencia'=>$mesAnt, 'valores'=>array($pont=>'abc'))) !== null);
api(3, 'ind_lancar', array('empresa_id'=>$A, 'competencia'=>$mesAnt, 'valores'=>array($pont=>'93,5', $recl=>'3')));
api(3, 'ind_lancar', array('empresa_id'=>$A, 'competencia'=>$mesAnt, 'valores'=>array($pont=>'92')));
$vals = api(2, 'ind_lista', array('ano'=>substr($mesAnt, 0, 4)))['valores'];
confere('relançar sobrescreve', count($vals) === 2 && in_array(92.0, array_map('floatval', array_column($vals, 'valor')), true));
$pi = api(2, 'painel')['indicadores'];
confere('painel: 1 fora (pontualidade), 1 dentro (reclamação menor é melhor)', $pi['fora'] === 1 && $pi['dentro'] === 1);
api(3, 'ind_lancar', array('empresa_id'=>$A, 'competencia'=>$mesAnt, 'valores'=>array($recl=>'')));
confere('valor vazio apaga', count(api(2, 'ind_lista', array('ano'=>substr($mesAnt, 0, 4)))['valores']) === 1);
confere('membro não edita meta', erro(3, 'ind_salvar', array('id'=>$pont, 'nome'=>'x')) !== null);
api(2, 'ind_salvar', array('id'=>$pont, 'nome'=>'Pontualidade', 'meta'=>'90', 'sentido'=>'maior', 'casas'=>1));
confere('meta nova vale no painel', api(2, 'painel')['indicadores']['fora'] === 0);
$novo = api(2, 'ind_salvar', array('nome'=>'Satisfação', 'unidade'=>'%', 'meta'=>'80'))['id'];
confere('indicador novo', $novo > 8);
api(2, 'ind_salvar', array('id'=>$novo, 'nome'=>'Satisfação', 'ativo'=>0));
confere('inativo some da lista', !in_array($novo, array_map('intval', array_column(api(2, 'ind_lista')['indicadores'], 'id')), true));

echo "Painel e alertas\n";
$db->q("UPDATE sgi_acoes SET status='pendente', prazo=? WHERE id=?", array(date('Y-m-d', strtotime('-3 days')), $a1));
$p = api(2, 'painel');
confere('ação vencida contada', $p['acoes_vencidas'] === 1);
confere('tabela por empresa para o gestor', count($p['por_empresa']) === 2);
confere('membro não tem tabela por empresa', count(api(3, 'painel')['por_empresa']) === 0);
confere('painel filtrado por empresa', api(2, 'painel', array('empresa'=>$B))['acoes_vencidas'] === 0);
confere('membro não filtra empresa alheia', erro(3, 'painel', array('empresa'=>$B)) !== null);
$al = sgi_alertas($db);
confere('alerta para a responsável com telefone', count($al['pessoas']) === 1 && $al['pessoas'][0]['nome'] === 'Carla Leitora A'
  && count($al['pessoas'][0]['vencidas']) === 1);
confere('ação desconhecida', erro(2, 'xpto') !== null);

echo "\n$ok ok, ".count($falhas)." falha(s)\n";
array_map('unlink', glob(SGI_PASTA.'/*') ?: array()); @unlink(SGI_PASTA.'/.htaccess'); @rmdir(SGI_PASTA);
exit($falhas ? 1 : 0);
