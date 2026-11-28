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

echo "Importação\n";
confere('conversão de número BR', imp_num('1.234,56') === 1234.56 && imp_num('98,5%') === 98.5 && imp_num('2.41') === 2.41 && imp_num('') === null && imp_num('abc') === false);
confere('conversão de data', imp_data('31/12/2026') === '2026-12-31' && imp_data('2026-03-05') === '2026-03-05' && imp_data(46000) === '2025-12-09' && imp_data('31/02/2026') === false);
confere('conversão de mês', imp_competencia('03/2026') === '2026-03' && imp_competencia('mar/2026') === '2026-03'
  && imp_competencia('Março de 2026') === '2026-03' && imp_competencia('01/03/2026') === '2026-03' && imp_competencia('2026-03') === '2026-03');
confere('membro não importa', erro(3, 'importar', array('tipo'=>'indicadores', 'empresa_id'=>$A, 'linhas'=>array(array('nome'=>'X')))) !== null);
$r = api(2, 'importar', array('tipo'=>'indicadores', 'empresa_id'=>$A, 'simular'=>1, 'linhas'=>array(
  array('nome'=>'Km por litro', 'unidade'=>'km/L', 'meta'=>'2,5', 'sentido'=>'Quanto maior melhor', 'casas'=>'2'),
  array('nome'=>'Acidentes com afastamento', 'meta'=>'0', 'sentido'=>'Quanto menor melhor'))));
confere('simulação conta e não grava', $r['criados'] === 2 && !$db->valor("SELECT id FROM sgi_indicadores WHERE nome='Km por litro'"));
api(2, 'importar', array('tipo'=>'indicadores', 'empresa_id'=>$A, 'linhas'=>array(
  array('nome'=>'Km por litro', 'unidade'=>'km/L', 'meta'=>'2,5', 'sentido'=>'Quanto maior melhor', 'casas'=>'2'),
  array('nome'=>'Acidentes com afastamento', 'meta'=>'0', 'sentido'=>'Quanto menor melhor'))));
$km = $db->linha("SELECT * FROM sgi_indicadores WHERE nome='Km por litro'");
confere('indicador importado', $km && (float)$km['meta'] === 2.5 && $km['sentido'] === 'maior' && (int)$km['casas'] === 2);
confere('sentido menor reconhecido', $db->valor("SELECT sentido FROM sgi_indicadores WHERE nome='Acidentes com afastamento'") === 'menor');
$r = api(2, 'importar', array('tipo'=>'indicadores', 'empresa_id'=>$A, 'linhas'=>array(array('nome'=>'KM POR LITRO', 'meta'=>'2,6'))));
api(2, 'importar', array('tipo'=>'indicadores', 'empresa_id'=>$A, 'linhas'=>array(array('nome'=>'Índice SAU', 'meta'=>'0.00007', 'sentido'=>'menor', 'casas'=>'5'))));
api(2, 'importar', array('tipo'=>'valores', 'empresa_id'=>$A, 'linhas'=>array(array('indicador'=>'Índice SAU', 'competencia'=>'2026-01', 'valor'=>'0.00002'))));
confere('seis casas decimais', abs((float)$db->valor("SELECT v.valor FROM sgi_ind_valores v JOIN sgi_indicadores i ON i.id=v.indicador_id WHERE i.nome='Índice SAU'") - 0.00002) < 1e-9
  && (int)$db->valor("SELECT casas FROM sgi_indicadores WHERE nome='Índice SAU'") === 5);
confere('reimportar atualiza pelo nome', $r['atualizados'] === 1 && (float)$db->valor("SELECT meta FROM sgi_indicadores WHERE id=?", array($km['id'])) === 2.6);
$r = api(2, 'importar', array('tipo'=>'valores', 'empresa_id'=>$A, 'linhas'=>array(
  array('indicador'=>'Km por litro', 'competencia'=>'jan/2026', 'valor'=>'2,48'),
  array('indicador'=>'Km por litro', 'competencia'=>'02/2026', 'valor'=>'2,61'),
  array('indicador'=>'Km por litro', 'competencia'=>'03/2026', 'valor'=>''),
  array('indicador'=>'Inexistente', 'competencia'=>'03/2026', 'valor'=>'1'))));
confere('valores importados', $r['criados'] === 2 && $r['ignorados'] === 1 && count($r['erros']) === 1 && $r['erros'][0]['linha'] === 4);
$r = api(2, 'importar', array('tipo'=>'valores', 'empresa_id'=>$A, 'linhas'=>array(array('indicador'=>'Km por litro', 'competencia'=>'2026-01', 'valor'=>'2,50'))));
confere('valor reimportado atualiza', $r['atualizados'] === 1);
confere('valores exigem empresa', erro(2, 'importar', array('tipo'=>'valores', 'linhas'=>array(array('indicador'=>'Km por litro')))) !== null);
$r = api(2, 'importar', array('tipo'=>'documentos', 'linhas'=>array(
  array('codigo'=>'PO-MAN-001', 'titulo'=>'Manutenção preventiva', 'revisao'=>'3', 'revisar_em'=>'10/06/2027', 'status'=>'Publicado'),
  array('codigo'=>'NBR-15570', 'titulo'=>'Ônibus urbano', 'externo'=>1, 'status'=>'Inativo'),
  array('codigo'=>'', 'titulo'=>'Sem código'))));
confere('documentos importados', $r['criados'] === 2 && count($r['erros']) === 1);
$d = $db->linha("SELECT * FROM sgi_documentos WHERE codigo='PO-MAN-001'");
confere('documento com revisão e validade', (int)$d['versao_vigente'] === 3 && $d['revisar_em'] === '2027-06-10' && $d['status'] === 'vigente');
confere('externo inativo vira obsoleto', $db->valor("SELECT status FROM sgi_documentos WHERE codigo='NBR-15570'") === 'obsoleto'
  && $db->valor("SELECT tipo FROM sgi_documentos WHERE codigo='NBR-15570'") === 'Documento externo');
confere('documento importado abre', api(2, 'doc_detalhe', array('id'=>$d['id']))['documento']['codigo'] === 'PO-MAN-001');
$r = api(2, 'importar', array('tipo'=>'ocorrencias', 'empresa_id'=>$A, 'linhas'=>array(
  array('numero'=>'NC 0457', 'titulo'=>'Ônibus saiu sem extintor', 'status'=>'Concluída', 'data_fato'=>'12/03/2025', 'responsavel'=>'Fulano'),
  array('numero'=>'', 'titulo'=>'Falta de limpeza', 'status'=>'Em análise de causa', 'tipo'=>'Não conformidade'),
  array('numero'=>'REC 12', 'titulo'=>'Motorista não parou', 'tipo'=>'Reclamação de cliente'))));
confere('ocorrências importadas', $r['criados'] === 3);
$o = $db->linha("SELECT * FROM sgi_ocorrencias WHERE numero='NC 0457'");
confere('mantém número e situação', $o && $o['status'] === 'encerrada' && (int)$o['seq'] === 0 && (int)$o['ano'] === 2025 && strpos($o['descricao'], 'Fulano') !== false);
confere('sem número ganha numeração do SGI', $db->valor("SELECT status FROM sgi_ocorrencias WHERE titulo='Falta de limpeza'") === 'analise'
  && strpos($db->valor("SELECT numero FROM sgi_ocorrencias WHERE titulo='Falta de limpeza'"), 'RNC-') === 0);
confere('reclamação reconhecida', $db->valor("SELECT tipo FROM sgi_ocorrencias WHERE numero='REC 12'") === 'reclamacao');
$r = api(2, 'importar', array('tipo'=>'ocorrencias', 'empresa_id'=>$A, 'linhas'=>array(array('numero'=>'NC 0457', 'titulo'=>'Ônibus saiu sem extintor'))));
confere('ocorrência repetida é ignorada', $r['ignorados'] === 1 && $r['criados'] === 0);
$n = api(2, 'oc_salvar', array('tipo'=>'nc', 'empresa_id'=>$A, 'titulo'=>'Depois da importação', 'descricao'=>'Teste', 'gravidade'=>'media'));
confere('numeração segue depois da importação', strpos($n['numero'], 'RNC-'.date('Y').'-') === 0);

echo "Níveis de acesso\n";
/* Os 5 níveis da Redentor, com as marcações trazidas do sistema anterior. */
$NV = array(
  'Gestor Sistema' => sgi_todas_perms(),
  'Gestor de Área' => array('doc._modulo','doc.internos','doc.externos','doc.registros','doc.categorias','doc.revisoes','doc.imprimir_nao_controlada',
    'doc.substituir_arquivo','doc.categoria_registro','doc.so_minhas_pendencias','doc.pendencia_outros','ind._modulo','ind.objetivos','ind.indicadores',
    'ind.pendencia_outros','nc._modulo','nc.nova','nc.analise','nc.pendencia_outros','nc.acompanhar','nc.acoes_iniciais','nc.consulta','nc.planejamento'),
  'Diretoria' => array('geral._modulo','doc._modulo','doc.internos','doc.externos','doc.registros','doc.revisoes','doc.pendencia_outros','ind._modulo',
    'nc._modulo','nc.analise','nc.validar_planos','nc.pendencia_outros','nc.acompanhar','nc.acoes_iniciais','nc.consulta','nc.visao_geral','nc.planejamento','nc.alterar_plano'),
  'Usuário' => array('doc._modulo','doc.internos','doc.externos','doc.registros','doc.imprimir_nao_controlada','doc.so_minhas_pendencias','ind._modulo',
    'ind.objetivos','ind.indicadores','nc._modulo','nc.nova','nc.analise','nc.origem','nc.acompanhar','nc.acoes_iniciais','nc.consulta','nc.planejamento','nc.alterar_plano'),
  'Consulta' => array('doc._modulo','doc.internos','doc.externos','doc.categorias','ind._modulo'),
);
$nid = array();
foreach($NV as $nome => $pp) $nid[$nome] = api(2, 'nivel_salvar', array('nome'=>$nome, 'permissoes'=>array_merge($pp, array('lixo.qualquer'))))['id'];
confere('cinco níveis', count(api(2, 'niveis_lista')['niveis']) === 5);
confere('permissão desconhecida descartada', !in_array('lixo.qualquer', api(2, 'niveis_lista')['niveis'][0]['permissoes'], true));
confere('nome de nível repetido barrado', erro(2, 'nivel_salvar', array('nome'=>'diretoria')) !== null);
confere('membro antigo não cria nível', erro(3, 'nivel_salvar', array('nome'=>'X')) !== null);
$pdo->exec("INSERT INTO portal_usuarios VALUES (7,'dir','Diretor Teste','user',''),(8,'area','Gestor Área','user',''),(9,'usu','Usuário Teste','user',''),(10,'cons','Consulta Teste','user','')");
api(2, 'acesso_salvar', array('usuario_id'=>7, 'nivel_id'=>$nid['Diretoria'], 'empresas'=>array($A)));
api(2, 'acesso_salvar', array('usuario_id'=>8, 'nivel_id'=>$nid['Gestor de Área'], 'empresas'=>array($A), 'superior_id'=>7));
api(2, 'acesso_salvar', array('usuario_id'=>9, 'nivel_id'=>$nid['Usuário'], 'empresas'=>array($A), 'superior_id'=>8));
api(2, 'acesso_salvar', array('usuario_id'=>10, 'nivel_id'=>$nid['Consulta'], 'empresas'=>array($A)));
$eu9 = api(9, 'eu');
confere('nível vem no eu', $eu9['eu']['nivel'] === 'Usuário' && $eu9['eu']['papel'] === 'membro' && in_array('nc.nova', $eu9['eu']['perms'], true));
confere('papel resumido da diretoria', api(7, 'eu')['eu']['papel'] === 'membro');
confere('superior gravado', (int)$db->valor("SELECT superior_id FROM sgi_acessos WHERE usuario_id=8") === 7);
confere('ninguém é superior de si', erro(2, 'acesso_salvar', array('usuario_id'=>9, 'nivel_id'=>$nid['Usuário'], 'empresas'=>array($A), 'superior_id'=>9)) !== null);
$oc9 = api(9, 'oc_salvar', array('tipo'=>'nc', 'empresa_id'=>$A, 'titulo'=>'NC do usuário', 'descricao'=>'x', 'gravidade'=>'media'))['id'];
confere('usuário registra ocorrência', $oc9 > 0);
confere('diretoria não registra ocorrência', strpos((string)erro(7, 'oc_salvar', array('tipo'=>'nc', 'empresa_id'=>$A, 'titulo'=>'x', 'descricao'=>'x', 'gravidade'=>'media')), 'nível de acesso') !== false);
confere('consulta não vê não conformidades', strpos((string)erro(10, 'oc_lista'), 'Não conformidades') !== false);
confere('consulta vê documentos', erro(10, 'doc_lista') === null);
confere('usuário não cancela', erro(9, 'oc_status', array('id'=>$oc9, 'para'=>'cancelada', 'obs'=>'x')) !== null);
confere('gestor de área analisa', erro(8, 'oc_status', array('id'=>$oc9, 'para'=>'analise')) === null);
confere('gestor de área não cadastra empresa', erro(8, 'empresa_salvar', array('nome'=>'Z')) !== null);
confere('usuário lança indicador mas consulta não', erro(10, 'ind_lancar', array('empresa_id'=>$A, 'competencia'=>date('Y-m'), 'valores'=>array())) !== null);
api(2, 'nivel_salvar', array('id'=>$nid['Consulta'], 'nome'=>'Consulta', 'permissoes'=>array_merge($NV['Consulta'], array('nc._modulo','nc.consulta'))));
confere('mudar o nível vale na hora', erro(10, 'oc_lista') === null);
confere('nível com gente não é excluído', erro(2, 'nivel_excluir', array('id'=>$nid['Consulta'])) !== null);
api(2, 'processo_salvar', array('nome'=>'Manutenção'));
confere('processo repetido barrado', erro(2, 'processo_salvar', array('nome'=>'manutenção')) !== null);
confere('processos no eu', count(api(2, 'eu')['processos']) === 1);
$cf = api(2, 'config_salvar', array('cfg'=>array('nc_validar_plano'=>'1', 'nc_dias_etapas'=>'7', 'ishikawa'=>'Método|Máquina|Medida|Meio Ambiente|Mão de Obra|Material', 'xpto'=>'1')))['cfg'];
confere('configuração gravada', $cf['nc_validar_plano'] === '1' && strpos($cf['ishikawa'], 'Medida') !== false && !isset($cf['xpto']));
confere('ishikawa precisa de 6', erro(2, 'config_salvar', array('cfg'=>array('ishikawa'=>'A|B'))) !== null);

echo "Convites\n";
confere('gestor que não é admin do Hub não convida', erro(2, 'convite_criar', array('nome'=>'X')) !== null);
$cv1 = api(1, 'convite_criar', array('nome'=>'Chefe Novo', 'nivel_id'=>$nid['Diretoria'], 'empresas'=>array($A)));
$cv2 = api(1, 'convite_criar', array('nome'=>'Subordinado Novo', 'nivel_id'=>$nid['Usuário'], 'empresas'=>array($A), 'superior_nome'=>'Chefe Novo'));
confere('convite gera token', strlen($cv1['token']) === 32 && count(api(1, 'convites_lista')['convites']) === 2);
/* o Hub cria a conta quando a pessoa aceita o convite (simulado aqui) */
$pdo->exec("INSERT INTO portal_usuarios VALUES (20,'sub','Subordinado Novo','user','')");
$db->q("UPDATE portal_convites SET usado_por=20 WHERE token=?", array($cv2['token']));
$e20 = api(20, 'eu')['eu'];
confere('convite aplica nível e empresa', $e20['nivel'] === 'Usuário' && $e20['empresas'] === array($A));
confere('superior ainda sem conta fica pendente', $db->valor("SELECT superior_id FROM sgi_acessos WHERE usuario_id=20") === null);
$pdo->exec("INSERT INTO portal_usuarios VALUES (21,'chefe','Chefe Novo','user','')");
$db->q("UPDATE portal_convites SET usado_por=21 WHERE token=?", array($cv1['token']));
confere('chefe entra com o nível dele', api(21, 'eu')['eu']['nivel'] === 'Diretoria');
confere('subordinado passa a apontar para o chefe', (int)$db->valor("SELECT superior_id FROM sgi_acessos WHERE usuario_id=20") === 21);

echo "Pessoas\n";
$cv3 = api(1, 'convite_criar', array('nome'=>'Desistente', 'nivel_id'=>$nid['Usuário'], 'empresas'=>array($A)));
confere('gestor não-admin não cancela convite', erro(2, 'convite_cancelar', array('token'=>$cv3['token'])) !== null);
api(1, 'convite_cancelar', array('token'=>$cv3['token']));
confere('convite cancelado some', !$db->valor("SELECT token FROM portal_convites WHERE token=?", array($cv3['token'])));
confere('convite usado não é cancelado', erro(1, 'convite_cancelar', array('token'=>$cv2['token'])) !== null);
api(2, 'pessoa_remover', array('usuario_id'=>21));
confere('remover tira o acesso e desliga o superior', !$db->valor("SELECT usuario_id FROM sgi_acessos WHERE usuario_id=21")
  && $db->valor("SELECT superior_id FROM sgi_acessos WHERE usuario_id=20") === null && $db->valor("SELECT id FROM portal_usuarios WHERE id=21"));
confere('não remove admin', erro(2, 'pessoa_remover', array('usuario_id'=>1)) !== null);
confere('gestor não-admin não exclui conta', erro(2, 'pessoa_excluir', array('usuario_id'=>20, 'confirmar'=>'sub')) !== null);
confere('exclusão exige digitar o usuário', erro(1, 'pessoa_excluir', array('usuario_id'=>20, 'confirmar'=>'x')) !== null);
confere('admin não se exclui', erro(1, 'pessoa_excluir', array('usuario_id'=>1, 'confirmar'=>'admin')) !== null);
api(1, 'pessoa_excluir', array('usuario_id'=>20, 'confirmar'=>'sub'));
confere('conta excluída', !$db->valor("SELECT id FROM portal_usuarios WHERE id=20") && !$db->valor("SELECT usuario_id FROM sgi_acessos WHERE usuario_id=20"));

echo "Fluxo de documentos\n";
$base = array('tipo'=>'Procedimento (POP)', 'empresa_id'=>$A);
$fd = api(8, 'doc_salvar', array('codigo'=>'PO-FLX-001', 'titulo'=>'Fluxo completo', 'elaborador_id'=>9,
  'consensadores'=>array(7, 3), 'aprovador_id'=>8) + $base)['id'];
$x = api(9, 'doc_detalhe', array('id'=>$fd));
confere('elaboração começa com prazo de 30 dias', $x['documento']['etapa'] === 'elaboracao'
  && $x['documento']['etapa_prazo'] === date('Y-m-d', strtotime('+30 days')) && $x['fluxo']['minha_vez'] === 'Elaborar');
confere('elaborador vê o rascunho na lista', in_array('PO-FLX-001', array_column(api(9, 'doc_lista')['itens'], 'codigo'), true));
confere('pendência do elaborador no painel', in_array('Elaborar', array_column(api(9, 'painel')['minhas']['aprovar'], 'acao'), true));
confere('consulta não anexa no lugar do elaborador', erro(10, 'doc_versao_enviar', array('documento_id'=>$fd, 'alteracao'=>'x'), arquivo('z.pdf')) !== null);
$r = api(9, 'doc_versao_enviar', array('documento_id'=>$fd, 'alteracao'=>'Emissão inicial'), arquivo('flx.pdf'));
confere('elaborador sem permissão geral anexa e vai a consenso', $r['etapa'] === 'consenso');
$vid = api(8, 'doc_detalhe', array('id'=>$fd))['fluxo']['versao_pendente'];
confere('aprovador não aprova antes do consenso', erro(8, 'doc_versao_decidir', array('id'=>$vid, 'aprovar'=>1)) !== null);
confere('quem não é consensador não opina', erro(9, 'doc_consenso', array('id'=>$vid, 'aceitar'=>1)) !== null);
confere('devolver exige dizer o que muda', erro(7, 'doc_consenso', array('id'=>$vid)) !== null);
confere('consensador vê a pendência', in_array('Dar consenso', array_column(api(7, 'painel')['minhas']['aprovar'], 'acao'), true));
api(7, 'doc_consenso', array('id'=>$vid, 'aceitar'=>0, 'texto'=>'Falta o item 3'));
$x = api(9, 'doc_detalhe', array('id'=>$fd));
confere('devolvido volta para a elaboração', $x['documento']['etapa'] === 'elaboracao' && $x['versoes'][0]['status'] === 'reprovada');
api(9, 'doc_versao_enviar', array('documento_id'=>$fd, 'alteracao'=>'Inclui o item 3'), arquivo('flx2.pdf'));
$vid = api(8, 'doc_detalhe', array('id'=>$fd))['fluxo']['versao_pendente'];
api(7, 'doc_consenso', array('id'=>$vid, 'aceitar'=>1));
confere('parecer não se repete', erro(7, 'doc_consenso', array('id'=>$vid, 'aceitar'=>1)) !== null);
confere('espera todos os consensadores', api(8, 'doc_detalhe', array('id'=>$fd))['documento']['etapa'] === 'consenso');
api(3, 'doc_consenso', array('id'=>$vid, 'aceitar'=>1, 'texto'=>'De acordo'));
confere('consenso completo segue para aprovação', api(8, 'doc_detalhe', array('id'=>$fd))['documento']['etapa'] === 'aprovacao');
confere('gestor não aprova no lugar do aprovador', erro(2, 'doc_versao_decidir', array('id'=>$vid, 'aprovar'=>1)) !== null);
api(8, 'doc_versao_decidir', array('id'=>$vid, 'aprovar'=>1));
$x = api(10, 'doc_detalhe', array('id'=>$fd));
confere('aprovado fica vigente e sai do fluxo', $x['documento']['status'] === 'vigente' && $x['documento']['etapa'] === null && (int)$x['documento']['versao_vigente'] === 2);
confere('pareceres guardados', count(api(8, 'doc_detalhe', array('id'=>$fd))['pareceres']) === 4);

echo "Documento restrito\n";
$rest = array('codigo'=>'PO-RST-001', 'titulo'=>'Restrito', 'restrito'=>1) + $base;
confere('restrito exige quem consulta', erro(2, 'doc_salvar', $rest) !== null);
$rs = api(2, 'doc_salvar', $rest + array('acesso'=>array('ver'=>array('niveis'=>array($nid['Diretoria']), 'pessoas'=>array(4)),
  'baixar'=>array('pessoas'=>array(7)))))['id'];
api(2, 'doc_versao_enviar', array('documento_id'=>$rs, 'alteracao'=>'Emissão'), arquivo('rst.pdf'));
$vr = api(2, 'doc_detalhe', array('id'=>$rs))['fluxo']['versao_pendente'];
api(2, 'doc_versao_decidir', array('id'=>$vr, 'aprovar'=>1));
$baixa = function($uid, $modo) use ($db, $vr){ try { sgi_arquivo_permitido($db, $uid, 'versao', $vr, $modo); return true; } catch(SgiErro $e){ return false; } };
confere('fora da lista não vê na lista', !in_array('PO-RST-001', array_column(api(9, 'doc_lista')['itens'], 'codigo'), true));
confere('fora da lista não abre', strpos((string)erro(9, 'doc_detalhe', array('id'=>$rs)), 'restrito') !== false);
confere('fora da lista não baixa', !$baixa(9, 'ver'));
confere('nível na lista vê', in_array('PO-RST-001', array_column(api(7, 'doc_lista')['itens'], 'codigo'), true));
confere('pessoa na lista consulta mas não baixa', $baixa(4, 'ver') && !$baixa(4, 'baixar'));
confere('quem pode baixar baixa, impressão vazia = ninguém', $baixa(7, 'baixar') && !$baixa(7, 'imprimir'));
confere('gestor sempre vê', $baixa(2, 'imprimir'));
$x = api(4, 'doc_detalhe', array('id'=>$rs));
confere('tela sabe o que a pessoa pode', $x['pode']['baixar'] === false && $x['pode']['imprimir'] === false);
api(2, 'doc_salvar', array('id'=>$rs, 'codigo'=>'PO-RST-001', 'titulo'=>'Restrito', 'restrito'=>0) + $base);
confere('tirar a restrição libera', erro(9, 'doc_detalhe', array('id'=>$rs)) === null && $baixa(9, 'baixar'));

echo "Casos-limite\n";
confere('título com 201 caracteres barrado', erro(2, 'doc_salvar', array('codigo'=>'PO-LIM-001', 'titulo'=>str_repeat('a', 201)) + $base) !== null);
$emo = api(2, 'doc_salvar', array('codigo'=>'PO-LIM-002', 'titulo'=>'Ação de manutenção 🚌 — revisão nº 2') + $base)['id'];
confere('acentos e emoji voltam iguais', api(2, 'doc_detalhe', array('id'=>$emo))['documento']['titulo'] === 'Ação de manutenção 🚌 — revisão nº 2');
confere('documento inexistente dá erro claro', strpos((string)erro(2, 'doc_detalhe', array('id'=>999999)), 'não encontrado') !== false);
confere('ocorrência inexistente dá erro claro', erro(2, 'oc_detalhe', array('id'=>999999)) !== null);
confere('consenso de versão inexistente barrado', erro(7, 'doc_consenso', array('id'=>999999, 'aceitar'=>1)) !== null);
confere('aprovar duas vezes a mesma versão barrado', erro(8, 'doc_versao_decidir', array('id'=>$vid, 'aprovar'=>1)) !== null);
confere('ação desconhecida barrada', strpos((string)erro(2, 'xpto'), 'desconhecida') !== false);
confere('consensador inexistente barrado', erro(2, 'doc_salvar', array('codigo'=>'PO-LIM-003', 'titulo'=>'x', 'consensadores'=>array(999)) + $base) !== null);
confere('código com aspas barrado', erro(2, 'doc_salvar', array('codigo'=>"PO'1", 'titulo'=>'x') + $base) !== null);

echo "Calibração\n";
$ins = api(2, 'reg_salvar', array('modulo'=>'cal', 'empresa_id'=>$A, 'titulo'=>'Manômetro de pneus', 'responsavel_id'=>3,
  'dados'=>array('tag'=>'MAN-01', 'periodicidade'=>'6', 'local'=>'Borracharia')));
confere('instrumento numerado', strpos($ins['numero'], 'INS-'.date('Y').'-001') === 0);
confere('periodicidade precisa ser número', erro(2, 'reg_salvar', array('modulo'=>'cal', 'empresa_id'=>$A, 'titulo'=>'X', 'dados'=>array('periodicidade'=>'seis'))) !== null);
confere('calibração no futuro barrada', erro(2, 'reg_item_salvar', array('reg_id'=>$ins['id'], 'data'=>date('Y-m-d', strtotime('+2 days')), 'dados'=>array('resultado'=>'aprovado'))) !== null);
api(3, 'reg_item_salvar', array('reg_id'=>$ins['id'], 'data'=>date('Y-m-d', strtotime('-7 months')), 'dados'=>array('resultado'=>'aprovado', 'certificado'=>'C-123')));
$ri = api(3, 'reg_detalhe', array('id'=>$ins['id']))['registro'];
confere('vencimento = calibração + periodicidade', $ri['data_ref'] === date('Y-m-d', strtotime(date('Y-m-d', strtotime('-7 months')).' +6 months')) && $ri['situacao'] === 'vencido');
confere('calibração vencida na pendência do responsável', in_array('Calibrar', array_column(api(3, 'painel')['minhas']['registros'], 'acao'), true));
api(3, 'reg_item_salvar', array('reg_id'=>$ins['id'], 'data'=>date('Y-m-d'), 'dados'=>array('resultado'=>'reprovado')));
confere('reprovado muda a situação', api(3, 'reg_detalhe', array('id'=>$ins['id']))['registro']['status'] === 'reprovado');
confere('outra empresa não vê o instrumento', count(api(5, 'reg_lista', array('modulo'=>'cal'))['itens']) === 0 && erro(5, 'reg_detalhe', array('id'=>$ins['id'])) !== null);

echo "Riscos e oportunidades\n";
$rs = api(2, 'reg_salvar', array('modulo'=>'risco', 'empresa_id'=>$A, 'titulo'=>'Falta de diesel', 'dados'=>array('probabilidade'=>'3', 'impacto'=>'5', 'origem'=>'Externo')));
$rr = api(2, 'reg_detalhe', array('id'=>$rs['id']))['registro'];
confere('nota = probabilidade × impacto', (float)$rr['nota'] === 15.0 && $rr['nivel'] === 'alto');
confere('escala fora de 1 a 5 barrada', erro(2, 'reg_salvar', array('modulo'=>'risco', 'empresa_id'=>$A, 'titulo'=>'X', 'dados'=>array('probabilidade'=>'7'))) !== null);
confere('opção inválida barrada', erro(2, 'reg_salvar', array('modulo'=>'risco', 'empresa_id'=>$A, 'titulo'=>'X', 'dados'=>array('origem'=>'Marte'))) !== null);
api(2, 'reg_item_salvar', array('reg_id'=>$rs['id'], 'dados'=>array('probabilidade'=>'2', 'impacto'=>'2'), 'texto'=>'Contrato com 2º fornecedor'));
$rr = api(2, 'reg_detalhe', array('id'=>$rs['id']))['registro'];
confere('reavaliação atualiza a nota e a próxima data', (float)$rr['nota'] === 4.0 && $rr['nivel'] === 'baixo' && $rr['data_ref'] === date('Y-m-d', strtotime('+12 months')));
confere('fluxo de situação respeitado', erro(2, 'reg_status', array('id'=>$rs['id'], 'para'=>'xpto')) !== null);
confere('encerrar exige motivo', erro(2, 'reg_status', array('id'=>$rs['id'], 'para'=>'encerrado')) !== null);
api(2, 'reg_status', array('id'=>$rs['id'], 'para'=>'tratamento'));
$op = api(2, 'reg_salvar', array('modulo'=>'oport', 'empresa_id'=>$A, 'titulo'=>'Telemetria em toda a frota', 'dados'=>array('probabilidade'=>'4', 'impacto'=>'4')))['id'];
$opi = api(2, 'reg_item_salvar', array('reg_id'=>$op, 'dados'=>array('probabilidade'=>'4', 'impacto'=>'5')))['id'];
$oco = api(2, 'reg_abrir_oc', array('item_id'=>$opi));
confere('oportunidade abre ocorrência de melhoria', $db->valor("SELECT tipo FROM sgi_ocorrencias WHERE id=?", array($oco['id'])) === 'melhoria');
confere('não abre duas ocorrências do mesmo item', erro(2, 'reg_abrir_oc', array('item_id'=>$opi)) !== null);

echo "Auditorias\n";
$au = api(2, 'reg_salvar', array('modulo'=>'aud', 'empresa_id'=>$A, 'titulo'=>'Auditoria interna da manutenção', 'responsavel_id'=>2,
  'data_ref'=>date('Y-m-d', strtotime('-1 day')), 'dados'=>array('tipo'=>'Interna', 'normas'=>'ISO 9001')))['id'];
confere('auditoria atrasada aparece', api(2, 'reg_detalhe', array('id'=>$au))['registro']['situacao'] === 'vencido');
confere('constatação só depois de iniciar', erro(2, 'reg_item_salvar', array('reg_id'=>$au, 'texto'=>'x', 'dados'=>array('classe'=>'nc_menor'))) !== null);
api(2, 'reg_status', array('id'=>$au, 'para'=>'execucao'));
confere('constatação exige descrição', erro(2, 'reg_item_salvar', array('reg_id'=>$au, 'dados'=>array('classe'=>'nc_menor'))) !== null);
$ci = api(2, 'reg_item_salvar', array('reg_id'=>$au, 'texto'=>'Torquímetro sem etiqueta de calibração',
  'dados'=>array('classe'=>'nc_maior', 'requisito'=>'7.1.5', 'evidencia'=>'Box 3')))['id'];
$nc = api(2, 'reg_abrir_oc', array('item_id'=>$ci));
$ocA = $db->linha("SELECT tipo, origem, gravidade FROM sgi_ocorrencias WHERE id=?", array($nc['id']));
confere('NC maior vira RNC grave com origem auditoria', $ocA['tipo'] === 'nc' && $ocA['origem'] === 'Auditoria interna' && $ocA['gravidade'] === 'alta');
confere('constatação mostra a NC ligada', api(2, 'reg_detalhe', array('id'=>$au))['itens'][0]['oc']['numero'] === $nc['numero']);
confere('membro antigo não exclui auditoria', erro(3, 'reg_excluir', array('id'=>$au, 'confirmar'=>'x')) !== null);

echo "Fornecedores\n";
$fo = api(2, 'reg_salvar', array('modulo'=>'forn', 'empresa_id'=>$A, 'titulo'=>'Pneus Sul Ltda', 'dados'=>array('criticidade'=>'Crítico')))['id'];
confere('nota acima de 10 barrada', erro(2, 'reg_item_salvar', array('reg_id'=>$fo, 'dados'=>array('qualidade'=>'11', 'prazo'=>'8', 'atendimento'=>'8', 'preco'=>'8', 'documentacao'=>'8'))) !== null);
confere('todas as notas obrigatórias', erro(2, 'reg_item_salvar', array('reg_id'=>$fo, 'dados'=>array('qualidade'=>'8'))) !== null);
$av = api(2, 'reg_item_salvar', array('reg_id'=>$fo, 'dados'=>array('qualidade'=>'9', 'prazo'=>'6', 'atendimento'=>'8', 'preco'=>'7', 'documentacao'=>'5')));
$rf = api(2, 'reg_detalhe', array('id'=>$fo))['registro'];
confere('nota do fornecedor é a média', (float)$av['nota'] === 7.0 && (float)$rf['nota'] === 7.0);
confere('crítico reavalia em 6 meses', $rf['data_ref'] === date('Y-m-d', strtotime('+6 months')));
api(2, 'reg_status', array('id'=>$fo, 'para'=>'homologado'));
confere('bloquear exige motivo', erro(2, 'reg_status', array('id'=>$fo, 'para'=>'bloqueado')) !== null);

echo "Liga/desliga e níveis\n";
confere('níveis listam os módulos novos', isset(api(2, 'niveis_lista')['catalogo']['aud']['itens']['abrir_oc']));
confere('consulta sem o módulo não vê auditorias', strpos((string)erro(10, 'reg_lista', array('modulo'=>'aud')), 'Auditorias') !== false);
confere('gestor não-admin não desliga módulo', erro(2, 'config_salvar', array('cfg'=>array('mod_aud'=>'0'))) !== null);
api(1, 'config_salvar', array('cfg'=>array('mod_aud'=>'0')));
confere('módulo desligado some para todos', strpos((string)erro(2, 'reg_lista', array('modulo'=>'aud')), 'desligado') !== false
  && in_array('aud', api(2, 'eu')['eu']['mods_off'], true));
api(1, 'config_salvar', array('cfg'=>array('mod_aud'=>'1')));
confere('religado volta', erro(2, 'reg_lista', array('modulo'=>'aud')) === null);
api(1, 'reg_excluir', array('id'=>$au, 'confirmar'=>api(1, 'reg_detalhe', array('id'=>$au))['registro']['numero']));
confere('excluir com confirmação', !$db->valor("SELECT id FROM sgi_reg WHERE id=?", array($au)));

echo "\n$ok ok, ".count($falhas)." falha(s)\n";
array_map('unlink', glob(SGI_PASTA.'/*') ?: array()); @unlink(SGI_PASTA.'/.htaccess'); @rmdir(SGI_PASTA);
exit($falhas ? 1 : 0);
