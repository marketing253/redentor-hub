<?php
/* ============================================================
   Lembretes da contabilidade — funções comuns.

   Usado por três arquivos, e por isso mora aqui:
     · tvindoor.php       — o painel lança, edita e marca como feito;
     · widget.php         — a peça da TV (tipo=lembretes);
     · lembretes_email.php — o aviso diário das 08:30.

   Um lembrete é uma tarefa com prazo. Enquanto não é marcado como
   feito, aparece na TV com o tempo de atraso; marcado, some da TV e
   do e-mail, mas fica no banco (quem fez e quando) para consulta.
   ============================================================ */

if(!function_exists('lembretes_tabela')){

function lembretes_tabela($db){
  $db->query("CREATE TABLE IF NOT EXISTS tvi_lembretes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setor VARCHAR(30) NOT NULL DEFAULT 'contabilidade',
    titulo VARCHAR(100) NOT NULL,
    descricao VARCHAR(500) NULL,
    prazo DATE NOT NULL,
    feito TINYINT(1) NOT NULL DEFAULT 0,
    feito_em DATETIME NULL,
    feito_por VARCHAR(80) NULL,
    criado_por VARCHAR(80) NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_lb (setor, feito, prazo)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Pendentes primeiro pelo mais atrasado: o que está vencido há mais tempo
   é o que mais precisa ser visto. */
function lembretes_pendentes($db, $setor = 'contabilidade'){
  $se = $db->real_escape_string($setor);
  $lista = array();
  $r = $db->query("SELECT id, titulo, descricao, prazo FROM tvi_lembretes
                   WHERE setor='$se' AND feito=0 ORDER BY prazo, id");
  while($r && $x = $r->fetch_assoc()) $lista[] = $x;
  return $lista;
}

/* "1 mês e 3 dias", "2 anos, 1 mês e 3 dias", "5 dias".
   Conta em calendário (DateTime::diff), não em blocos de 30 dias: de 28/08
   a 01/10 é "1 mês e 3 dias", que é como as pessoas falam. */
function lembrete_duracao($de, $ate){
  $d = (new DateTime($de))->diff(new DateTime($ate));
  $p = array();
  if($d->y) $p[] = $d->y . ($d->y === 1 ? ' ano'  : ' anos');
  if($d->m) $p[] = $d->m . ($d->m === 1 ? ' mês'  : ' meses');
  if($d->d) $p[] = $d->d . ($d->d === 1 ? ' dia'  : ' dias');
  if(!$p) return '0 dias';
  $ult = array_pop($p);
  return $p ? implode(', ', $p) . ' e ' . $ult : $ult;
}

/* Situação do prazo em relação a hoje.
   Devolve ['estado' => atrasado|hoje|futuro, 'texto' => ..., 'dias' => n]
   — dias negativos = atrasado. */
function lembrete_situacao($prazo, $hoje = null){
  $hoje = $hoje ?: date('Y-m-d');
  $dias = (int)round((strtotime($prazo) - strtotime($hoje)) / 86400);
  if($dias < 0) return array('estado'=>'atrasado', 'dias'=>$dias,
                             'texto'=>'Atrasado há ' . lembrete_duracao($prazo, $hoje));
  if($dias === 0) return array('estado'=>'hoje', 'dias'=>0, 'texto'=>'Vence hoje');
  return array('estado'=>'futuro', 'dias'=>$dias,
               'texto'=>'Vence em ' . lembrete_duracao($hoje, $prazo));
}

function lembretes_cfg($db, $chave, $padrao = ''){
  $k = $db->real_escape_string($chave);
  $r = $db->query("SELECT valor FROM tvi_config WHERE chave='$k' LIMIT 1");
  $v = ($r && $r->num_rows) ? (string)$r->fetch_assoc()['valor'] : '';
  return $v !== '' ? $v : $padrao;
}

/* Para quem vai o aviso. O que a contabilidade digitou no painel vale;
   sem isso, o e-mail da contabilidade que o portal já usa no Auxílio. */
function lembretes_destinatarios($db, $cfgAux){
  $txt = lembretes_cfg($db, 'lembretes_email_para', '');
  $lista = $txt !== '' ? preg_split('/[\s,;]+/', $txt)
                       : (array)($cfgAux['email_contabilidade'] ?? array());
  return array_values(array_filter(array_map('trim', $lista), function($e){
    return filter_var($e, FILTER_VALIDATE_EMAIL);
  }));
}

/* Monta e envia o aviso diário.
   $forcar = true ignora a trava de "já enviado hoje" (botão de teste).
   Devolve ['ok'=>bool, 'msg'=>string, 'enviados'=>n]. */
function lembretes_enviar_email($db, $forcar = false){
  $raiz = __DIR__;
  if(!is_file($raiz.'/auxilio/config.php') || !is_file($raiz.'/auxilio/email.php'))
    return array('ok'=>false, 'msg'=>'Falta auxilio/config.php ou auxilio/email.php no servidor.', 'enviados'=>0);

  $cfgAux = require $raiz.'/auxilio/config.php';
  require_once $raiz.'/auxilio/email.php';

  lembretes_tabela($db);
  $hoje = date('Y-m-d');

  /* Trava: o cron pode disparar duas vezes (ou alguém abrir a URL), e a
     contabilidade não deve receber o mesmo aviso repetido no mesmo dia. */
  if(!$forcar && lembretes_cfg($db, 'lembretes_email_ultimo', '') === $hoje)
    return array('ok'=>true, 'msg'=>'Aviso de hoje já tinha sido enviado.', 'enviados'=>0);

  $itens = lembretes_pendentes($db);
  if(!$itens) return array('ok'=>true, 'msg'=>'Nenhum lembrete pendente: nada a enviar.', 'enviados'=>0);

  $para = lembretes_destinatarios($db, $cfgAux);
  if(!$para) return array('ok'=>false, 'msg'=>'Nenhum e-mail de destino configurado.', 'enviados'=>0);

  $atrasados = 0;
  $linhasTxt = array();
  $linhasHtml = '';
  foreach($itens as $it){
    $s = lembrete_situacao($it['prazo'], $hoje);
    if($s['estado'] === 'atrasado') $atrasados++;
    $prazoBr = date('d/m/Y', strtotime($it['prazo']));
    $cor = $s['estado'] === 'atrasado' ? '#B03A50' : ($s['estado'] === 'hoje' ? '#C08A28' : '#2F8F63');

    $linhasTxt[] = '• ' . $it['titulo'] . ' — prazo ' . $prazoBr . ' (' . $s['texto'] . ')'
                 . ($it['descricao'] ? "\n  " . $it['descricao'] : '');

    $linhasHtml .= '<tr>
      <td style="padding:12px 14px;border-bottom:1px solid #e4e7ef;border-left:4px solid ' . $cor . '">
        <b style="color:#16202c;font-size:15px">' . htmlspecialchars($it['titulo']) . '</b>'
        . ($it['descricao'] ? '<div style="color:#5b6775;font-size:13px;margin-top:3px">'
                              . nl2br(htmlspecialchars($it['descricao'])) . '</div>' : '') . '
      </td>
      <td style="padding:12px 14px;border-bottom:1px solid #e4e7ef;white-space:nowrap;text-align:right">
        <div style="color:#16202c;font-size:14px;font-weight:600">' . $prazoBr . '</div>
        <div style="color:' . $cor . ';font-size:12px;font-weight:700">' . htmlspecialchars($s['texto']) . '</div>
      </td></tr>';
  }

  $n = count($itens);
  $assunto = 'Lembretes da contabilidade — ' . date('d/m/Y') . ' — ' . $n . ' pendente' . ($n > 1 ? 's' : '')
           . ($atrasados ? ' (' . $atrasados . ' atrasado' . ($atrasados > 1 ? 's' : '') . ')' : '');

  $url = rtrim((string)($cfgAux['url_sistema'] ?? ''), '/');
  $botao = $url === '' ? '' :
    '<p style="margin:22px 0 0"><a href="' . htmlspecialchars($url) . '/"
      style="background:#3B4192;color:#fff;text-decoration:none;padding:12px 22px;
      border-radius:8px;display:inline-block;font-weight:600">Abrir o Redentor Hub</a></p>';

  $html = '<div style="font-family:Segoe UI,Arial,sans-serif;background:#f4f6f9;padding:24px">
    <div style="max-width:620px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #dde3ea">
      <div style="background:#3B4192;border-bottom:3px solid #C08A28;padding:16px 22px;color:#fff">
        <b style="font-size:16px;letter-spacing:.4px">Auto Viação Redentor</b><br>
        <span style="font-size:12px;color:#c6cbee">Lembretes da contabilidade</span>
      </div>
      <div style="padding:22px;color:#16202c;font-size:15px;line-height:1.5">
        <h2 style="margin:0 0 6px;font-size:17px;color:#3B4192">Bom dia! ' . $n . ' lembrete' . ($n > 1 ? 's' : '')
          . ' em aberto</h2>
        <p style="margin:0 0 16px;color:#5b6775;font-size:13px">Ao concluir, marque como feito no
          TV Indoor &rarr; Agenda contábil &rarr; Lembretes. Ele sai da TV e deste aviso.</p>
        <table style="width:100%;border-collapse:collapse;border:1px solid #e4e7ef;border-radius:8px">'
          . $linhasHtml . '</table>' . $botao . '
      </div>
      <div style="padding:14px 22px;background:#f4f6f9;color:#5b6775;font-size:12px">
        Mensagem automática do Redentor Hub, enviada todo dia às 08:30.
      </div>
    </div></div>';

  $texto = "Bom dia! Lembretes da contabilidade em aberto ($n):\n\n" . implode("\n\n", $linhasTxt)
         . "\n\nAo concluir, marque como feito no TV Indoor > Agenda contábil > Lembretes.\n— Redentor Hub";

  /* Remetente com nome próprio: o padrão do config é "Auxílio Graduação",
     que confundiria quem recebe. */
  $cfgEnvio = $cfgAux;
  $cfgEnvio['smtp']['nome'] = 'Redentor Hub — Lembretes';

  $ok = 0; $erros = array();
  foreach($para as $e){
    list($foi, $erro) = enviaEmail($cfgEnvio, $e, $assunto, $texto, $html);
    if($foi) $ok++; else $erros[] = $e . ': ' . $erro;
  }

  if($ok && !$forcar){
    $h = $db->real_escape_string($hoje);
    $db->query("INSERT INTO tvi_config (chave,valor) VALUES ('lembretes_email_ultimo','$h')
                ON DUPLICATE KEY UPDATE valor='$h'");
  }

  return array('ok'=>$ok > 0,
    'msg'=>$ok ? "Enviado para $ok destinatário(s): " . implode(', ', $para) . ($erros ? '. Falhou: ' . implode('; ', $erros) : '')
               : 'Falha no envio: ' . implode('; ', $erros),
    'enviados'=>$ok);
}

}
