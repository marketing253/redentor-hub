<?php

/* Versão do player. Entra na URL dos widgets para o navegador da TV ser
   obrigado a buscar de novo quando algo muda. Sobe a cada alteração
   visual: é o único jeito de a parede atualizar sem alguém ir até lá. */
define('VERSAO', '79.1.0');
header('X-TVIndoor-Versao: '.VERSAO);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* Funções comuns. O @ é deliberado: se comum.php faltar, o player ainda
   tem as próprias cópias e continua no ar. Peça de parede não pode cair
   por causa de um include. */
@include_once __DIR__.'/comum.php';
/* ============================================================
   player.php — a página que fica aberta na TV.

   Abra na televisão:  https://seudominio.com.br/player.php?t=TOKEN
   O token sai do cadastro da TV, dentro do app TV Indoor.

   Sem sessão, sem login: a TV não tem teclado nem quem digite senha.
   A credencial é o token, revogável individualmente pelo painel.

   POR QUE NÃO TEM SERVICE WORKER AQUI
   O Hub já registra o /sw.js no escopo da raiz. Um segundo service
   worker no mesmo escopo brigaria com ele e quebraria o portal inteiro.
   O cache offline usa a Cache Storage API direto da página e serve a
   mídia por blob URL — mesmo efeito, sem invadir o escopo do Hub.
   ============================================================ */
$token = isset($_GET['t']) ? preg_replace('/[^A-Za-z0-9_]/', '', $_GET['t']) : '';

/* Modo prévia: o painel abre o MESMO player apontando para uma lista, em
   vez de um dispositivo. Sem heartbeat, sem registro de exibição, sem
   cache — só a reprodução, para conferir tempo, ordem e zonas antes de
   mandar para a parede. Exige sessão do Hub. */
$previa = isset($_GET['prev']) ? (int)$_GET['prev'] : 0;
if($previa){
  if(session_status() !== PHP_SESSION_ACTIVE){
    $sec = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if(PHP_VERSION_ID >= 70300){
      session_set_cookie_params(array('lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$sec,'samesite'=>'Lax'));
    } else { session_set_cookie_params(0,'/','',$sec,true); }
    session_start();
  }
  if(empty($_SESSION['uid'])){
    http_response_code(403);
    exit('<!DOCTYPE html><meta charset="utf-8"><body style="background:#0C0E1C;color:#F1EFE7;'
       . 'font-family:system-ui;display:flex;align-items:center;justify-content:center;height:100vh">'
       . 'Sessão do Hub expirada. Recarregue o portal e tente de novo.</body>');
  }
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- O token vive na URL. Sem isto, todo conteúdo externo que a TV exibir
     (uma página, um vídeo do YouTube) recebe o endereço completo no
     cabeçalho Referer, token incluído, e passa a poder controlar a tela.
     É o furo mais sério de um player de signage e o mais fácil de esquecer. -->
<meta name="referrer" content="no-referrer">
<title>TV Indoor</title>
<style>
:root{--bg:#000;--ink:#F1EFE7;--dim:#9EA2C0;--ok:#57C98B;--warn:#D9A83F;--err:#E0576E;
  --azul:#3B4192;--ouro:#C08A28;--ouro-pl:#ECDBAE}
*{margin:0;padding:0;box-sizing:border-box}
html,body{height:100%;background:var(--bg);overflow:hidden;color:var(--ink);
  font-family:"Segoe UI Variable Text","Segoe UI",system-ui,-apple-system,Arial,sans-serif;
  font-variant-numeric:tabular-nums slashed-zero;
  -webkit-user-select:none;user-select:none;cursor:none}
/* Zonas. Sem layout definido, tudo cai em tela cheia — o padrão antigo. */
.palco{position:fixed;top:0;left:0;right:0;bottom:0;display:flex}
.stage{position:relative;flex:1;min-width:0}
.lateral{display:none;width:24%;min-width:230px;flex-direction:column;background:#0C0E1C;
  border-left:1px solid rgba(236,219,174,.16)}
/* Cada zona ganhou um contêiner próprio: é ele que permite trocar o iframe
   ao vivo pelo da cópia guardada sem desmontar a outra zona ao lado. */
.zona{flex:1;min-height:0;display:flex}
.zona+.zona{border-top:1px solid rgba(236,219,174,.16)}
.lateral iframe{flex:1;width:100%;height:100%;border:0;display:block}
/* Filete dourado separando a faixa do conteúdo: é a marca aparecendo
   sem ocupar espaço. */
.rodape{display:none;position:fixed;left:0;right:0;bottom:0;height:9vh;min-height:56px;
  background:#0C0E1C;border-top:2px solid #C08A28;overflow:hidden;
  white-space:nowrap;z-index:5}
.rolar{display:flex;height:100%;align-items:center;width:max-content;
  animation:rolar linear infinite}
.rolar span{padding:0 3vw;font-size:3.2vh;color:#F1EFE7;font-weight:300}
@keyframes rolar{from{transform:translateX(0)}to{transform:translateX(-50%)}}
body.lay-rodape .palco,body.lay-completo .palco{bottom:9vh}
@media (prefers-reduced-motion:reduce){.rolar{animation:none}}
.layer{position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;
  /* .55s e curva de saída: em TV o linear parece corte seco, porque o painel
     tem resposta lenta e o começo do esmaecimento se perde. A curva
     concentra a mudança no meio, onde o olho pega.
     will-change avisa o navegador para promover a camada antes da troca —
     sem isso o Android monta a textura NO momento da transição, e é aí que
     aparece o engasgo. */
  transition:opacity .55s cubic-bezier(.4,0,.2,1);background:#000;
  will-change:opacity}
.layer.on{opacity:1}
.layer img,.layer video{width:100%;height:100%;display:block}
.layer iframe{width:100%;height:100%;border:0;display:block}
.fit-cover img,.fit-cover video{object-fit:cover}
.fit-contain img,.fit-contain video{object-fit:contain}
.fit-fill img,.fit-fill video{object-fit:fill}

.card{position:fixed;top:0;left:0;right:0;bottom:0;display:flex;align-items:center;
  justify-content:center;
  background:radial-gradient(1400px 700px at 78% -12%,#2A2F6C 0,transparent 62%),#0C0E1C}
.card.oculto{display:none}
.inner{width:80%;max-width:620px}
.eyebrow{font-size:.72rem;letter-spacing:.22em;text-transform:uppercase;color:var(--ouro-pl)}
/* Serifa no título, como na marca. Um cartaz de estado que aparece uma
   vez por mês merece parecer intencional. */
.headline{font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,serif;
  font-size:2.2rem;font-weight:400;line-height:1.18;margin:.7rem 0 1.3rem;
  letter-spacing:-.01em}
.hint{font-size:1rem;color:var(--dim);line-height:1.6}
.bar{height:2px;background:rgba(236,219,174,.16);margin:1.6rem 0 .7rem;overflow:hidden}
.bar i{display:block;height:100%;width:0;background:var(--ouro);transition:width .25s}
.bar.oculto,.hint.oculto{display:none}

/* A barra de diagnóstico fica FORA da tela por padrão: quem olha aquela
   parede é passageiro ou visitante, não operador. Continua a um toque de
   distância para manutenção — qualquer tecla numérica do controle remoto,
   ou a tecla D no teclado, mostra e esconde. */
.diag{position:fixed;left:0;right:0;bottom:0;padding:.55rem 1rem;display:flex;gap:1.4rem;
  background:rgba(16,18,38,.92);font-size:.78rem;color:var(--dim);
  font-family:ui-monospace,Consolas,monospace;border-top:1px solid rgba(236,219,174,.16)}
.diag[hidden]{display:none}
.diag b{color:var(--ink);font-weight:400}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:.45rem;background:#5c6673}
.dot.on{background:var(--ok)} .dot.off{background:var(--err)} .dot.up{background:var(--warn)}
</style>
</head>
<body>

<div class="palco">
  <div class="stage" id="stage"><div class="layer" id="la"></div><div class="layer" id="lb"></div></div>
  <aside class="lateral" id="lateral"></aside>
</div>
<div class="rodape" id="rodape"></div>

<section class="card" id="card">
  <div class="inner">
    <p class="eyebrow" id="cEyebrow">Iniciando</p>
    <h1 class="headline" id="cTitle">Conectando ao servidor</h1>
    <div class="bar oculto" id="cBar"><i id="cFill"></i></div>
    <p class="hint" id="cHint"></p>
  </div>
</section>

<footer class="diag" id="diag" hidden>
  <span><i class="dot" id="dDot"></i><b id="dStatus">–</b></span>
  <span>TV <b id="dCode">–</b></span>
  <span>Sinal <b id="dBeat">–</b></span>
  <span>Cache <b id="dCache">–</b></span>
  <span>Rel&oacute;gio <b id="dRel">–</b></span>
  <span>v<b id="dVer">–</b></span>
</footer>

<script>
/* ES5 por decisão: isto roda em webOS 3 (Chromium 38), Tizen 4 e Android TV 7.
   Sem arrow function, sem fetch, sem template literal. Tela preta em campo
   custa uma visita técnica. */
(function () {
  'use strict';

  /* A versão que o player informa ao painel é a do ARQUIVO, não um número
     escrito à mão que ninguém lembrava de mexer. Ficou parada em '1.0.0'
     desde o começo: a coluna "player" do painel mostrava 1.0.0 em todas as
     TVs, e depois de publicar não havia como saber quais já tinham pegado a
     versão nova — que é exatamente a pergunta daquele momento. */
  var VERSION = <?php echo json_encode(VERSAO); ?>;
  var TOKEN = <?php echo json_encode($token); ?>;
  /* Versão do player na URL do widget. Navegador de TV guarda página com
     unhas e dentes — foi o que segurou a atualização de tamanho. Mudando a
     URL, ele é obrigado a buscar de novo, sem ninguém precisar ir na TV
     limpar cache. */
  var VER = <?php echo json_encode(VERSAO); ?>;
  var PREVIA = <?php echo (int)$previa; ?>;
  var API = location.origin + '/tvindoor.php';
  var CACHE = 'tvindoor-midia-v1';
  var LS = { man: 'tvi.manifesto', ver: 'tvi.versao' };

  /* Identidade desta aba. Fica em sessionStorage e não em localStorage de
     propósito: recarregar mantém a mesma sessão, mas abrir uma SEGUNDA aba
     gera outra identidade — que é exatamente o caso que queremos pegar. */
  var INSTANCIA = (function(){
    try {
      var v = sessionStorage.getItem('tvi.inst');
      if(!v){
        v = 'i' + Date.now().toString(36) + Math.random().toString(36).substring(2, 8);
        sessionStorage.setItem('tvi.inst', v);
      }
      return v.substring(0, 16);
    } catch(e){
      return 'i' + Math.random().toString(36).substring(2, 12);
    }
  })();

  var S = { layoutAtual:null, bloqueado:false, manifesto:null, versao:null, pool:[], cursor:0, atual:null,
            estado:'idle', ultimoSinal:0, sinalOk:false, cacheBytes:0,
            desvio:0, relogioAviso:0,
            logs:[], camadas:null, ativa:0, timer:null, sincronizando:false,
            blobs:{} };

  function el(id){ return document.getElementById(id); }
  function pad(n){ return n < 10 ? '0'+n : ''+n; }

  /* ── O RELÓGIO DA TV ────────────────────────────────────────
     Toda a programação é resolvida AQUI, no relógio do aparelho: dias da
     semana, faixas de horário, validade. É o que faz a TV sem internet
     continuar exibindo a coisa certa — e é a decisão acertada.

     O que faltava era conferir se esse relógio está certo. Um box que
     perdeu a hora, ou que veio de fábrica em UTC, exibe a peça da manhã à
     noite e mantém no ar a promoção que venceu. E o painel mostra essa TV
     verde, online, tocando: para ele está tudo bem. O defeito é invisível
     exatamente para quem poderia corrigi-lo.

     O servidor já mandava a hora certa em toda batida e ninguém lia. Agora
     lemos, guardamos o desvio, e passamos a resolver a programação pela
     hora do servidor. A TV com relógio torto passa a exibir CERTO mesmo
     assim, e o aviso no painel vira manutenção sem pressa.

     Comparamos hora de PAREDE, não epoch: assim uma medida só pega os dois
     defeitos — relógio errado e fuso errado. */
  function relogio(){ return new Date(Date.now() + (S.desvio || 0)); }

  function duracaoHumana(ms){
    var s = Math.round(ms / 1000);
    var d = Math.floor(s / 86400); s -= d * 86400;
    var h = Math.floor(s / 3600);  s -= h * 3600;
    var m = Math.floor(s / 60);
    var p = [];
    if(d) p.push(d + (d === 1 ? ' dia' : ' dias'));
    if(h) p.push(h + 'h');
    if(m) p.push(m + 'min');
    return p.length ? p.join(' ') : 'menos de 1min';
  }

  function medirRelogio(txt){
    var m = String(txt).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
    if(!m) return;
    /* Montado com os componentes soltos, e não com Date.parse: assim a data
       nasce no fuso da própria TV, e a subtração abaixo devolve a diferença
       de hora de parede — que é o que interessa. */
    var servidor = new Date(+m[1], +m[2]-1, +m[3], +m[4], +m[5], +m[6]).getTime();
    S.desvio = servidor - Date.now();

    var fora = Math.abs(S.desvio);
    if(fora < 120000) return;   // dois minutos de folga: relógio de TV oscila

    /* Uma vez por hora, não a cada batida. Isto é manutenção — alguém
       precisa ir até a TV acertar a hora —, não emergência. */
    if(S.relogioAviso && (Date.now() - S.relogioAviso) < 3600000) return;
    S.relogioAviso = Date.now();
    reportar('relogio_torto', null,
      'relógio ' + (S.desvio > 0 ? 'atrasado' : 'adiantado') + ' ' + duracaoHumana(fora) +
      '; a programação está sendo corrigida pela hora do servidor');
  }

  function req(metodo, url, corpo, done){
    var x = new XMLHttpRequest();
    x.open(metodo, url, true);
    x.timeout = 20000;
    if(corpo) x.setRequestHeader('Content-Type','application/json');
    x.onload = function(){
      var d = null;
      try { d = x.responseText ? JSON.parse(x.responseText) : null; } catch(e){}
      done(null, x.status, d);
    };
    x.onerror = function(){ done(new Error('rede'), 0, null); };
    x.ontimeout = function(){ done(new Error('timeout'), 0, null); };
    x.send(corpo ? JSON.stringify(corpo) : null);
  }

  function guardar(k,v){ try{ localStorage.setItem(k,v); }catch(e){} }
  function ler(k){ try{ return localStorage.getItem(k); }catch(e){ return null; } }

  /* ── Cópia das peças, guardada na própria TV ─────────────────
     Vídeo e imagem a TV já guarda: estão na Cache Storage e tocam com o
     cabo de rede fora. As peças do servidor — notícias, clima,
     aniversariantes — não tinham nada disso. Sem internet o iframe não
     carregava, a peça era pulada, e quando TODAS as peças eram destas a
     parede caía no cartão "Aguardando conteúdo". Tela apagada, portanto.

     Agora, toda vez que uma peça abre direito, o HTML dela fica guardado
     aqui. Quando o servidor não responde, é esta cópia que vai ao ar: a
     última notícia continua na parede em vez de tela vazia.

     Só o HTML. As fotos das notícias vêm por endereço e, sem rede, não
     aparecem — mas a manchete, a data e o veículo continuam lá, que é o
     que alguém lê de longe. */
  /* Teto baixo de propósito. O localStorage de uma TV costuma ter 5 MB no
     total, e nele mora também o MANIFESTO — que é o dado que a TV não pode
     perder: sem ele, ela não volta a exibir sozinha depois de uma queda de
     energia. Guardar seis peças de 400 KB era ocupar quase metade do espaço
     com conveniência e arriscar o essencial. Três de 250 KB cabem folgado, e
     uma peça de notícias inteira dá 60 a 120 KB. */
  var COPIA_MAX = 250000;   // ~250 KB por peça
  /* Oito, e não três: além das peças da lista, as duas zonas laterais
     (relógio e clima) também guardam cópia. Com poucas vagas, uma
     expulsaria a outra o tempo todo e nenhuma estaria lá na hora que
     precisasse. Oito de 250 KB dão 2 MB no pior caso, e o manifesto tem
     prioridade sobre todas elas — ver guardarManifesto. */
  var COPIA_QTD = 8;

  function chaveDe(url){
    var h = 0;
    for(var i=0;i<url.length;i++) h = (h*31 + url.charCodeAt(i)) % 2147483647;
    return 'tvi.cp.' + h.toString(36);
  }

  function guardarCopia(url, html){
    if(!html || html.length > COPIA_MAX) return;
    var k = chaveDe(url), idx = [], i;
    try { idx = JSON.parse(ler('tvi.copias') || '[]'); } catch(e){ idx = []; }
    var lim = [];
    for(i=0;i<idx.length;i++) if(idx[i] !== k) lim.push(idx[i]);
    lim.push(k);
    idx = lim;
    while(idx.length > COPIA_QTD){
      var velho = idx.shift();
      try { localStorage.removeItem(velho); } catch(e){}
    }
    try {
      localStorage.setItem(k, html);
      guardar('tvi.copias', JSON.stringify(idx));
    } catch(e){
      /* Sem espaço. Joga a mais antiga fora e tenta uma vez — se ainda não
         couber, fica sem cópia desta peça e o resto segue funcionando. */
      try {
        var v = idx.shift();
        if(v) localStorage.removeItem(v);
        localStorage.setItem(k, html);
        guardar('tvi.copias', JSON.stringify(idx));
      } catch(e2){}
    }
  }

  function lerCopia(url){
    try { return localStorage.getItem(chaveDe(url)); } catch(e){ return null; }
  }

  function soltarCopias(){
    var idx = [];
    try { idx = JSON.parse(ler('tvi.copias') || '[]'); } catch(e){ idx = []; }
    for(var i=0;i<idx.length;i++){ try { localStorage.removeItem(idx[i]); } catch(e){} }
    try { localStorage.removeItem('tvi.copias'); } catch(e){}
  }

  /* O MANIFESTO TEM PRIORIDADE SOBRE AS CÓPIAS.
     O guardar() engole erro de espaço em silêncio. Se o localStorage
     enchesse, a gravação do manifesto falharia sem ninguém saber — e a TV
     só descobriria na próxima queda de energia, ficando sem voltar a
     exibir. É o único dado aqui que não pode ser perdido.
     Então: se não couber, as cópias das peças saem, e o manifesto entra. */
  function guardarManifesto(d){
    var txt = JSON.stringify(d);
    try { localStorage.setItem(LS.man, txt); guardar(LS.ver, d.version); return true; }
    catch(e){}
    soltarCopias();
    try { localStorage.setItem(LS.man, txt); guardar(LS.ver, d.version); return true; }
    catch(e){}
    reportar('manifesto_nao_guardado', null,
             'sem espaço no navegador da TV: ela não volta sozinha após queda de energia');
    return false;
  }

  /* document.write num iframe em branco: é o caminho que funciona no
     Chromium 38 do webOS e no WebView do Android. srcdoc não existe lá.
     O iframe vai SEM src de propósito — assim o documento em branco já
     existe quando ele entra na página. */
  function escreverLocal(f, html, pronto){
    function tentar(){
      try{
        var d = f.contentDocument || (f.contentWindow && f.contentWindow.document);
        if(!d) return false;
        d.open(); d.write(html); d.close();
        return true;
      } catch(e){ return false; }
    }
    if(tentar()){ pronto(true); return; }
    // Documento ainda não montado: uma segunda tentativa no quadro seguinte.
    setTimeout(function(){ pronto(tentar()); }, 60);
  }

  /* ── Cartão ─────────────────────────────────────────────────── */
  function cartao(sobre, titulo, dica, prog){
    el('card').className = 'card';
    el('cEyebrow').textContent = sobre;
    el('cTitle').textContent = titulo;
    el('cHint').textContent = dica || '';
    var temBarra = typeof prog === 'number';
    el('cBar').className = 'bar' + (temBarra ? '' : ' oculto');
    if(temBarra) el('cFill').style.width = Math.round(prog*100) + '%';
  }
  /* Alguns avisos não podem ser engolidos pela programação. O aviso de
     atualização, por exemplo, sumia em dois segundos quando a peça em
     exibição estava terminando — quem passasse na frente da TV via um
     borrão. Enquanto S.avisoAte estiver no futuro, o cartão fica. */
  function esconder(forcado){
    if(!forcado && S.avisoAte && Date.now() < S.avisoAte) return;
    el('card').className = 'card oculto';
  }
  function travarAviso(ms){
    S.avisoAte = Date.now() + ms;
    clearTimeout(S.timerAviso);
    S.timerAviso = setTimeout(function(){
      S.avisoAte = 0;
      esconder(true);
      proximo();
    }, ms);
  }

  function diag(){
    if(el('diag').hidden) return;   // escondida: nem calcula
    var st = S.estado === 'syncing' ? 'up' : (S.sinalOk ? 'on' : 'off');
    el('dDot').className = 'dot ' + st;
    el('dStatus').textContent = st === 'on' ? 'Online' : st === 'up' ? 'Atualizando' : 'Sem conexão';
    el('dCode').textContent = S.manifesto && S.manifesto.tv ? S.manifesto.tv.code : '–';
    el('dBeat').textContent = S.ultimoSinal ? Math.round((Date.now()-S.ultimoSinal)/1000)+'s' : '–';
    el('dCache').textContent = S.cacheBytes ? (S.cacheBytes/1048576).toFixed(0)+' MB' : '–';
    el('dVer').textContent = VERSION;
    /* Quem abre esta barra está de pé na frente da TV, para resolver algo.
       "certo" encerra a dúvida; o desvio diz o que ir acertar no aparelho. */
    var dv = S.desvio || 0;
    el('dRel').textContent = !S.sinalOk && !dv ? '–'
      : (Math.abs(dv) < 120000 ? 'certo'
        : (dv > 0 ? 'atrasado ' : 'adiantado ') + duracaoHumana(Math.abs(dv)));
    if(S.bloqueado) el('dStatus').textContent = 'Aberta em outra janela';
  }

  /* ── Heartbeat ──────────────────────────────────────────────── */
  function sinal(){
    if(PREVIA || !TOKEN) return;   // prévia não bate no servidor
    var corpo = {
      status: S.estado,
      player_version: VERSION,
      screen: (window.screen ? screen.width+'x'+screen.height : ''),
      os: navigator.platform || null,
      current_media_id: S.atual ? S.atual.media_id : null,
      manifest_version: S.versao,
      instancia: INSTANCIA
    };
    req('POST', API+'?action=heartbeat&t='+TOKEN, corpo, function(err, st, d){
      // Outra janela já está com esta TV: para de reproduzir e espera a vez.
      if(d && d.ok === false && d.erro === 'em_uso'){ bloquear(d); return; }

      if(err || st !== 200 || !d || !d.ok){ S.sinalOk = false; diag(); return; }

      if(S.bloqueado) liberar();
      S.sinalOk = true;
      S.ultimoSinal = Date.now();
      if(d.server_local) medirRelogio(d.server_local);
      if(d.manifest_version && d.manifest_version !== S.versao) agendarSync(d.rollout_seconds||0);
      if(d.commands && d.commands.length) comandos(d.commands);
      diag();
    });
  }

  /* Bloqueio: a tela para, o vídeo para, e o cartão explica. Continua
     batendo a cada 30s, então volta sozinha quando a outra janela fechar. */
  function bloquear(d){
    if(!S.bloqueado){
      S.bloqueado = true;
      clearTimeout(S.timer);
      S.atual = null;
      // Corta som e imagem de verdade, não só esconde.
      S.camadas[0].innerHTML = ''; S.camadas[1].innerHTML = '';
      S.camadas[0].className = 'layer'; S.camadas[1].className = 'layer';
    }
    var quando = d.desde ? String(d.desde).substring(11, 16) : '';
    var falta = d.libera_em ? Math.ceil(d.libera_em / 60) : 0;
    cartao('Esta TV já está aberta',
           'O conteúdo está tocando em outra janela',
           'Aberta desde ' + quando + (d.ip ? ' pelo endereço ' + d.ip : '') + '. ' +
           'Feche a outra janela e esta assume sozinha' +
           (falta ? ', ou aguarde cerca de ' + falta + ' min.' : '.'));
    S.estado = 'idle';
    diag();
  }

  function liberar(){
    S.bloqueado = false;
    esconder();
    if(!S.atual) proximo();
  }

  function comandos(lista){
    for(var i=0;i<lista.length;i++){
      var c = lista[i];
      if(c.type === 'reload'){ location.reload(); return; }
      if(c.type === 'sync'){ sincronizar(); }
      if(c.type === 'clear_cache'){
        if(window.caches) caches.delete(CACHE);
        soltarBlobs([]);          // limpar o disco sem soltar a memória não limpa nada
        S.cacheBytes = 0;
      }
      if(c.type === 'screenshot'){ capturar(); }
      if(c.type === 'message' && c.payload && c.payload.text){
        cartao('Mensagem', c.payload.text, '');
        setTimeout(esconder, 15000);
      }
      /* Atualização do aplicativo forçada pelo painel. A tela avisa na hora;
         o aplicativo é que instala, e o Android sempre pede confirmação —
         por isso o texto diz o que apertar no controle. */
      /* Atualização do aplicativo pedida pelo painel.
         Este cartão é AVISO, não instalador: uma página web não instala
         aplicativo no Android, e ainda bem. Quem instala é o aplicativo,
         que pergunta na própria tela em até cinco minutos. O texto diz
         isso — prometer "aperte OK" aqui só gera controle apontado para
         uma tela que não responde. */
      /* Atualização do aplicativo: quem pergunta é o aplicativo, na
         abertura dele — no meio da programação, uma caixa cobrindo a parede
         é pior do que esperar. O player não mostra nada aqui; o comando
         continua sendo entregue para o aplicativo saber que há versão nova. */
      if(c.type === 'atualizar_app'){ return; }
    }
  }

  /* Espalha o download: publicar em várias telas do mesmo prédio ao mesmo
     segundo satura o link. O atraso sai do hash do token, sem coordenação. */
  function agendarSync(seg){
    if(S.sincronizando) return;
    var h = 0;
    for(var i=0;i<TOKEN.length;i++) h = (h*31 + TOKEN.charCodeAt(i)) % 100000;
    setTimeout(sincronizar, seg ? (h % seg) * 1000 : 0);
  }

  function sincronizar(){
    if(S.bloqueado || S.sincronizando || !TOKEN) return;
    S.sincronizando = true; S.estado = 'syncing'; diag();

    var alvo = PREVIA ? (API+'?action=manifest_preview&pl='+PREVIA)
                      : (API+'?action=manifest&t='+TOKEN);
    req('GET', alvo, null, function(err, st, d){
      if(S.bloqueado){ S.sincronizando = false; return; }
      if(err || st !== 200 || !d || !d.playlists){
        S.sincronizando = false;
        S.estado = S.manifesto ? 'playing' : 'error';
        if(!S.manifesto) cartao('Sem conexão','Não consegui falar com o servidor',
                                'A TV tenta de novo sozinha a cada 30 segundos.');
        else reportar('sync_falhou', null, 'manifesto não baixou');
        diag(); return;
      }
      // Baixa ANTES de trocar. A programação atual continua no ar durante o
      // download; a troca só acontece com tudo em cache.
      prebaixar(d, function(){
        // Chegou tarde: o heartbeat já barrou esta janela enquanto baixava.
        if(S.bloqueado){ S.sincronizando = false; return; }
        S.manifesto = d; S.versao = d.version;
        // Programação nova: o que saiu dela pode devolver a memória.
        soltarBlobs(urlsDo(d));
        guardarManifesto(d);
        S.sincronizando = false; S.estado = 'playing'; S.cursor = 0;
        aplicarLayout();
        // Só esconde o cartão se ele era nosso. Se nada está tocando, o
        // proximo() decide o que mostrar.
        if(S.atual) esconder();
        diag();
        if(!S.atual) proximo();
      });
    });
  }

  function urlsDo(m){
    var u = [], vistos = {}, pl = m.playlists || [];
    for(var i=0;i<pl.length;i++){
      var it = pl[i].items || [];
      for(var j=0;j<it.length;j++){
        var x = it[j];
        if(!x.url || (x.type !== 'video' && x.type !== 'image')) continue;
        // A mesma peça em duas listas é um arquivo só: não baixa duas vezes.
        if(vistos[x.url]) continue;
        vistos[x.url] = 1;
        u.push(x.url);
      }
    }
    return u;
  }

  function prebaixar(m, done){
    var urls = urlsDo(m);
    // Prévia não enche o cache do navegador de quem está só conferindo.
    if(PREVIA || !window.caches || !urls.length){ done(); return; }

    /* Se já tem conteúdo no ar, o download acontece em silêncio. Cobrir uma
       tela que está funcionando com "baixando 3 de 12" não ajuda ninguém —
       quem olha é cliente ou passageiro, não operador. O aviso só aparece
       na primeira carga, quando a alternativa seria tela preta. */
    var mudo = !!S.atual;
    if(!mudo) cartao('Atualizando','Baixando conteúdo','Primeira carga desta TV.',0);

    caches.open(CACHE).then(function(c){
      /* Fila de dois por vez, e não todos de uma vez.
         O laço antigo disparava um download para CADA arquivo no mesmo
         instante: uma lista com quinze vídeos abria quinze conexões no
         wi-fi da TV, e todas ficavam lentas juntas. Dois por vez terminam
         antes e não sufocam o resto da rede do prédio. */
      var LIMITE = 2;
      var i = 0, feitos = 0, ativos = 0, acabou = false;

      function fim(){
        if(acabou) return;
        acabou = true;
        limpar(c, urls);
        done();
      }
      function passo(){
        feitos++;
        ativos--;
        if(!mudo){
          cartao('Atualizando','Baixando conteúdo',
                 feitos+' de '+urls.length+' arquivos.', feitos/urls.length);
        }
        if(feitos >= urls.length){ fim(); return; }
        puxar();
      }
      function puxar(){
        while(ativos < LIMITE && i < urls.length){
          ativos++;
          (function(u){
            c.match(u).then(function(hit){
              if(hit){ passo(); return; }
              c.add(u).then(passo, passo);
            }, passo);   /* <- o match também pode falhar */
          })(urls[i++]);
        }
      }
      puxar();

      /* O match não tinha tratador de erro. Quando ele falhava, o passo()
         nunca rodava, o done() nunca era chamado e o S.sincronizando ficava
         preso em true — a TV parava de sincronizar de vez, até alguém
         recarregar. Agora a falha conta como passo, e este prazo é a última
         rede: com tudo dando errado, a programação segue mesmo assim. */
      setTimeout(fim, 180000);
    }, function(){ done(); });
  }

  /* Remove o que saiu do manifesto: a TV tem disco limitado. */
  function limpar(c, manter){
    c.keys().then(function(reqs){
      var q = {};
      for(var i=0;i<manter.length;i++) q[manter[i]] = 1;
      for(var j=0;j<reqs.length;j++) if(!q[reqs[j].url]) c.delete(reqs[j]);
    });
  }

  /* Serve do cache por blob URL. É o que faz a TV continuar tocando com o
     cabo de rede fora — sem service worker, sem brigar com o /sw.js do Hub. */
  function resolver(url, done){
    if(PREVIA){ done(url); return; }
    if(S.blobs[url]){ done(S.blobs[url].u); return; }
    if(!window.caches){ done(url); return; }
    caches.open(CACHE).then(function(c){
      c.match(url).then(function(r){
        if(!r){ done(url); return; }
        r.blob().then(function(b){
          /* Guarda o tamanho junto com o endereço. Sem isso não há como
             descontar do total quando a peça for solta lá embaixo. */
          S.blobs[url] = { u: URL.createObjectURL(b), n: b.size };
          S.cacheBytes += b.size;
          done(S.blobs[url].u);
        }, function(){ done(url); });
      }, function(){ done(url); });
    }, function(){ done(url); });
  }

  /* Devolve a memória das peças que saíram da programação.
     Cada createObjectURL prende o arquivo inteiro na memória do navegador
     até alguém revogar — e não havia um revokeObjectURL no player inteiro.
     Todo vídeo e toda imagem que a TV já exibiu ficava lá, inclusive os que
     saíram do manifesto há semanas. Num aparelho de 1 GB é exatamente o que
     aperta, e era o que o reload das quatro da manhã vinha disfarçando. */
  function soltarBlobs(manter){
    var q = {}, i;
    for(i = 0; i < manter.length; i++) q[manter[i]] = 1;
    for(var u in S.blobs){
      if(!S.blobs.hasOwnProperty(u) || q[u]) continue;
      // O que está no ar agora fica: revogar debaixo do próprio vídeo não.
      if(S.atual && S.atual.url === u) continue;
      try { URL.revokeObjectURL(S.blobs[u].u); } catch(e){}
      S.cacheBytes -= (S.blobs[u].n || 0);
      delete S.blobs[u];
    }
    if(S.cacheBytes < 0) S.cacheBytes = 0;
  }

  /* ── Zonas ──────────────────────────────────────────────────
     A lista escolhe o layout. 'cheia' é o comportamento antigo e continua
     sendo o padrão — TV com player velho não quebra, e lista sem layout
     definido também não. As zonas laterais são iframes do widget.php, que
     já se atualizam sozinhos; o player não precisa saber o que tem dentro. */

  /* A primeira lista QUE TEM CONTEÚDO manda no layout.
     Era playlists[0] direto. Só que uma lista pode chegar vazia — um
     comunicado urgente que venceu, por exemplo, some do manifesto item a
     item mas a lista continua lá. Se ela caísse em primeiro, a parede
     adotava o layout dela: sem lateral, sem faixa, sem clima. */
  function listaBase(){
    var pl = (S.manifesto && S.manifesto.playlists) || [];
    for(var i=0;i<pl.length;i++) if(pl[i].items && pl[i].items.length) return pl[i];
    return pl[0] || {};
  }

  /* ── ZONA LATERAL, TAMBÉM SEM INTERNET ──────────────────────
     A cópia guardada resolveu as peças da lista de reprodução, mas o
     relógio e o clima entram por outro caminho: dois iframes fixos
     apontando direto para o servidor. Sem rede, eles não carregavam e um
     quarto da tela ficava preto — do lado do palco tocando normalmente do
     cache, o que é pior do que se tudo estivesse fora.

     O relógio é o mais visível, e o mais bobo: ele conta a hora sozinho no
     navegador. Só o carregamento da página é que dependia da rede.

     Agora cada zona guarda a própria cópia quando carrega bem, e recorre a
     ela quando o servidor não responde. E quem manda renovar passou a ser
     o player, de quinze em quinze minutos: assim que a internet voltar, a
     zona volta ao vivo sozinha, sem ninguém subir na escada. */
  var ZONAS = [];

  function pararZonas(){
    for(var i=0;i<ZONAS.length;i++) clearInterval(ZONAS[i]);
    ZONAS = [];
  }

  function montarLateral(elLat, base, cidade){
    pararZonas();
    elLat.innerHTML = '';
    montarZona(elLat, base + '/widget.php?tipo=relogio&v=' + VER);
    montarZona(elLat, base + '/widget.php?tipo=clima&v=' + VER +
                      '&cidade=' + encodeURIComponent(cidade));
  }

  function montarZona(elLat, url){
    var cx = document.createElement('div');
    cx.className = 'zona';
    elLat.appendChild(cx);

    function novoIframe(){
      cx.innerHTML = '';
      var f = document.createElement('iframe');
      f.setAttribute('referrerpolicy','no-referrer');
      cx.appendChild(f);
      return f;
    }

    var aoVivoOk = false;   // a última tentativa ao vivo deu certo?

    function aoVivo(){
      var f = novoIframe();
      var respondeu = false;
      f.onload = function(){
        respondeu = true;
        aoVivoOk = true;
        /* Guarda o que acabou de carregar. Mesma origem, então dá para ler
           o documento montado — é esta cópia que segura a zona depois. */
        try {
          var d = f.contentDocument;
          if(d && d.documentElement){
            guardarCopia(url, '<!DOCTYPE html>' + d.documentElement.outerHTML);
          }
        } catch(e){}
      };
      f.src = url;
      setTimeout(function(){
        if(respondeu) return;
        aoVivoOk = false;
        porCopia();
      }, 9000);
    }

    function porCopia(){
      var salvo = lerCopia(url);
      if(!salvo) return;   // nunca chegou a carregar: não há cópia para pôr
      var g = novoIframe();
      /* Tira os recarregamentos que a própria peça agenda. Num iframe
         escrito à mão, recarregar leva para about:blank — ou seja, para o
         branco que estamos justamente evitando. Aqui quem decide quando
         renovar é o player, no intervalo abaixo. */
      escreverLocal(g, salvo.replace(/location\.reload\(\)/g, 'void 0'), function(){});
    }

    aoVivo();
    /* Só insiste enquanto NÃO está ao vivo. Com a peça carregada do
       servidor, ela já se renova sozinha; insistir aqui seria recarregar a
       mesma coisa duas vezes e piscar na parede à toa.
       Cinco minutos, e não quinze: este intervalo é a volta da internet,
       e a zona deve voltar ao vivo assim que puder. */
    ZONAS.push(setInterval(function(){ if(!aoVivoOk) aoVivo(); }, 300000));
  }

  function aplicarLayout(){
    var pl = listaBase();
    var lay = pl.layout || 'cheia';
    var base = pl.base || location.origin;

    if(lay === S.layoutAtual) return;
    S.layoutAtual = lay;

    var lateral = (lay === 'lateral' || lay === 'completo');
    var rodape  = (lay === 'rodape'  || lay === 'completo');

    document.body.className = 'lay-' + lay;

    var elLat = el('lateral'), elRod = el('rodape');

    if(lateral){
      elLat.style.display = 'flex';
      // Recria só quando o layout muda, não a cada item: iframe recarregando
      // sem parar pisca e come banda.
      montarLateral(elLat, base, pl.clima || 'Curitiba');
    } else {
      elLat.style.display = 'none';
      pararZonas();
      elLat.innerHTML = '';
    }

    if(rodape && pl.ticker){
      elRod.style.display = 'block';
      var txt = pl.ticker;
      // Duplicar o texto é o que faz a emenda do laço não deixar buraco.
      elRod.innerHTML = '<div class="rolar"><span>' + txt + '</span><span>' + txt + '</span></div>';
      // Velocidade pelo tamanho: texto curto não pode passar voando.
      var seg = Math.max(14, Math.round(txt.length / 6));
      elRod.firstChild.style.animationDuration = seg + 's';
    } else {
      elRod.style.display = 'none';
      elRod.innerHTML = '';
    }
  }

  /* ── Programação, resolvida aqui e não no servidor ───────────
     É isto que faz a TV sem internet continuar certa e parar de exibir a
     promoção que venceu ontem. */
  function agora(){
    var d = relogio();          // hora do servidor, não a do aparelho
    return {
      data: d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate()),
      hora: pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds()),
      bit: 1 << d.getDay()
    };
  }

  function vale(it, t){
    var r = it.rules || {};
    if(r.starts_on && t.data < r.starts_on) return false;
    if(r.ends_on && t.data > r.ends_on) return false;
    var wd = typeof r.weekdays === 'number' ? r.weekdays : 127;
    if(!(wd & t.bit)) return false;
    if(r.starts_at && r.ends_at){
      if(r.starts_at <= r.ends_at){
        if(t.hora < r.starts_at || t.hora > r.ends_at) return false;
      } else {
        // janela que cruza a meia-noite
        if(t.hora < r.starts_at && t.hora > r.ends_at) return false;
      }
    }
    return true;
  }

  function montarPool(){
    var t = agora(), pool = [], pl = (S.manifesto && S.manifesto.playlists) || [];
    for(var i=0;i<pl.length;i++){
      var it = pl[i].items || [];
      for(var j=0;j<it.length;j++){
        if(vale(it[j], t)){ it[j]._pl = pl[i].name; it[j]._plId = pl[i].id; pool.push(it[j]); }
      }
    }
    // Prioridade: se houver item urgente válido, só ele vai ao ar.
    var topo = 0;
    for(var k=0;k<pool.length;k++) if(pool[k].priority > topo) topo = pool[k].priority;
    if(topo > 0){
      var f = [];
      for(var m=0;m<pool.length;m++) if(pool[m].priority === topo) f.push(pool[m]);
      pool = f;
    }
    return pool;
  }

  /* ── Reprodução ─────────────────────────────────────────────── */
  /* Vigia da tela preta.
     Mesmo com todas as redes de segurança, uma peça pode travar de um jeito
     não previsto (TV que dorme e acorda, memória cheia, iframe congelado) e
     a programação para com a tela apagada. Este vigia confere de 5 em 5
     segundos duas coisas simples: se a camada visível está vazia e se o
     item já passou muito do tempo dele. Em qualquer dos casos, segue. */
  function vigiar(){
    if(S.bloqueado || !S.manifesto) return;
    /* Aviso travado no ar não é tela parada: é assim de propósito. */
    if(S.avisoAte && Date.now() < S.avisoAte) return;
    var camada = S.camadas[S.ativa];
    var vazia  = !camada || !camada.firstChild;
    var estourou = S.desde && (Date.now() - S.desde) > ((S.previsto || 20000) + 20000);
    var semItem  = !S.atual && !S.timer;
    if(vazia || estourou || semItem){
      try{ reportar('tela_parada', S.atual || {}, vazia ? 'camada vazia'
             : (estourou ? 'item passou do tempo' : 'sem item em exibição')); }catch(e){}
      S.desde = Date.now();
      proximo();
    }
  }
  setInterval(vigiar, 5000);

  function proximo(){
    if(S.bloqueado) return;
    clearTimeout(S.timer);
    S.pool = montarPool();

    if(!S.pool.length){
      S.atual = null;
      cartao('Sem programação','Nenhum conteúdo para este horário',
             'A tela volta sozinha quando a programação iniciar.');
      S.timer = setTimeout(proximo, 30000);
      diag(); return;
    }

    /* Aviso travado no ar: segura a vez em vez de trocar de peça por
       baixo dele. Volta a andar sozinho quando o tempo do aviso acaba. */
    if(S.avisoAte && Date.now() < S.avisoAte){
      clearTimeout(S.timer);
      S.timer = setTimeout(proximo, 1000);
      return;
    }
    esconder();
    if(S.cursor >= S.pool.length) S.cursor = 0;
    var it = S.pool[S.cursor];
    S.cursor++;
    conferirAntes(it, function(bom, motivo){
      if(bom) { exibir(it); return; }
      /* Peça sem conteúdo não vai para a parede. Era o que produzia a tela
         azul parada: o widget de notícias abria, não tinha o que mostrar e
         ficava o tempo inteiro da exibição em branco. */
      reportar('peca_vazia', it, motivo || 'sem conteúdo');
      S.vazias = (S.vazias || 0) + 1;
      if(S.vazias >= S.pool.length){
        /* Todas falharam: parar de girar em vazio e esperar melhorar. */
        S.vazias = 0;
        cartao('Aguardando conteúdo', 'As fontes não responderam agora',
               'A programação volta sozinha assim que houver o que exibir.');
        S.timer = setTimeout(proximo, 60000);
        return;
      }
      proximo();
    });
  }

  /* ── Conferência antes de exibir ──────────────────────────────────────
     Só vale para as peças do próprio servidor (notícias, futebol,
     aniversariantes, clima). Elas dizem em data-peca-ok se conseguiram
     carregar algo; aqui isso é lido por uma consulta rápida, sem colocar
     nada na tela. Página de fora não é conferida: não há como ler o que
     ela devolve, e a tolerância de 9 segundos já cuida desse caso. */
  var _cacheConf = {};
  function conferirAntes(it, pronto){
    if(!it || it.type !== 'web' || !it.url){ pronto(true); return; }
    var url = String(it.url);
    var mesmoServidor = url.indexOf('/') === 0 ||
                        url.indexOf(location.origin) === 0 ||
                        url.indexOf('widget.php') > -1;
    if(!mesmoServidor){ pronto(true); return; }

    /* Guarda o veredito por 2 minutos: a mesma peça volta a cada rodada e
       não se deve pedir a página de novo a cada vez. */
    var c = _cacheConf[url];
    if(c && (Date.now() - c.t) < 120000){
      it._local = c.local ? lerCopia(url) : null;
      pronto(c.ok, c.motivo); return;
    }

    var abortou = false;
    var t = setTimeout(function(){
      abortou = true;
      /* Demorou demais para responder: deixa passar e o limite de 9s da
         exibição resolve. Melhor um risco do que segurar a programação.
         Mas se há cópia guardada, ela entra já — cinco segundos calado é
         servidor fora do ar para quem está olhando a parede, e esperar
         mais nove em branco não melhora nada. */
      var salvo = lerCopia(url);
      if(salvo) it._local = salvo;
      pronto(true);
    }, 5000);

    /* Consulta própria: o req() do player devolve JSON, e aqui o que
       interessa é o HTML cru da peça. */
    var x = new XMLHttpRequest();
    x.open('GET', url + (url.indexOf('?') > -1 ? '&' : '?') + 'conferindo=1', true);
    x.timeout = 5000;
    x.onload = function(){
      if(abortou) return;
      clearTimeout(t);
      var txt = x.responseText || '', ok = true, motivo = '', local = false;
      if(txt.indexOf('data-peca-ok') > -1){
        ok = txt.indexOf('data-peca-ok="0"') === -1;
        if(!ok){
          var m = txt.match(/data-peca-motivo="([^"]*)"/);
          motivo = m ? m[1] : 'sem conteúdo';
        }
      }
      if(ok){
        /* Peça boa: fica guardada na TV. É esta cópia que segura a parede
           quando a internet cair. */
        guardarCopia(url, txt);
        it._local = null;
      } else {
        /* O servidor respondeu, mas sem conteúdo — a fonte caiu do lado de
           lá. A última boa continua valendo mais que peça vazia. */
        var salvo = lerCopia(url);
        if(salvo){ it._local = salvo; ok = true; motivo = ''; local = true; }
      }
      _cacheConf[url] = {t: Date.now(), ok: ok, motivo: motivo, local: local};
      pronto(ok, motivo);
    };
    /* Falha na conferência não é motivo para pular: a peça pode estar boa
       e o problema ser da consulta. Mas se nem falar com o servidor deu, é
       sinal de rede fora — e aí a cópia da TV entra direto, sem esperar o
       iframe tentar e falhar. */
    x.onerror = x.ontimeout = function(){
      if(abortou) return;
      clearTimeout(t);
      var salvo = lerCopia(url);
      if(salvo) it._local = salvo;
      pronto(true);
    };
    try { x.send(); } catch(e){ clearTimeout(t); pronto(true); }
  }

  function exibir(it){
    var prox = S.camadas[1 - S.ativa];
    var inicio = Date.now(), passou = false, trocou = false;

    prox.innerHTML = '';
    prox.className = 'layer fit-' + (it.fit || 'cover');

    function avancar(ok){
      if(passou) return;
      passou = true;
      clearTimeout(S.timer);
      registrar(it, Date.now()-inicio, ok !== false);
      proximo();
    }
    function trocar(){
      /* Uma peça troca UMA vez. Vários caminhos podem chamar isto — o
         onload do iframe, a folga de 4s, a cópia local entrando aos 9s — e
         chamar duas vezes inverte as camadas de novo: apaga a que está no
         ar e acende a que já foi esvaziada. Era assim que a tela ficava
         preta. O guarda abaixo é o que garante que isso não volte a
         acontecer por nenhum caminho, hoje ou depois. */
      if(trocou) return;
      trocou = true;
      var saindo = S.camadas[S.ativa];
      saindo.className = saindo.className.replace(' on','');
      prox.className += ' on';
      S.ativa = 1 - S.ativa;
      S.atual = it; S.estado = 'playing';
      S.vazias = 0;
      S.desde = Date.now();
      S.previsto = (it.duration || 20000);
      diag();

      /* Libera a camada que saiu DEPOIS da transição terminar.
         Antes ela era esvaziada no começo do próximo item — ou seja, ficava
         viva na memória enquanto a peça nova carregava. Em Android de TV,
         com 1 GB de RAM, isso significa dois documentos, dois conjuntos de
         imagens e duas animações concorrendo no mesmo instante: é o
         travamento que aparece justamente na troca.

         O atraso de 600ms cobre a transição de 350ms com folga. Esvaziar
         antes disso cortaria o esmaecimento pela metade. */
      setTimeout(function(){
        if(saindo === S.camadas[S.ativa]) return;   // já voltou a ser usada
        var v = saindo.querySelector('video');
        if(v){ try { v.pause(); v.removeAttribute('src'); v.load(); } catch(e){} }
        var f = saindo.querySelector('iframe');
        // about:blank antes de remover: descarrega o documento de dentro,
        // que é o que realmente ocupa memória.
        if(f){ try { f.src = 'about:blank'; } catch(e){} }
        saindo.innerHTML = '';
      }, 600);
    }

    if(it.type === 'video'){
      resolver(it.url, function(src){
        var v = document.createElement('video');
        v.src = src; v.autoplay = true; v.muted = it.mute !== false;
        v.setAttribute('playsinline',''); v.controls = false;
        v.onended = function(){ avancar(true); };
        v.onerror = function(){ reportar('midia_falhou', it, 'vídeo não carregou'); avancar(false); };
        v.oncanplay = function(){
          trocar();
          // webOS às vezes não dispara 'ended'. Rede de segurança.
          S.timer = setTimeout(function(){ avancar(true); }, (it.duration||15000)+5000);
        };
        prox.appendChild(v);
        var p = v.play(); if(p && p['catch']) p['catch'](function(){});
        /* Se o vídeo não ficar pronto (arquivo pesado, rede ruim, autoplay
           barrado), 'canplay' nunca chega e a tela fica preta esperando.
           Depois de 10s, pula a peça em vez de segurar a programação. */
        setTimeout(function(){
          if(S.atual !== it && !passou){
            reportar('midia_falhou', it, 'vídeo não começou em 10s');
            avancar(false);
          }
        }, 10000);
      });
      return;
    }

    if(it.type === 'image'){
      resolver(it.url, function(src){
        var i = new Image();
        i.onload = function(){
          trocar();
          S.timer = setTimeout(function(){ avancar(true); }, it.duration||10000);
        };
        i.onerror = function(){ reportar('midia_falhou', it, 'imagem não carregou'); avancar(false); };
        i.src = src;
        prox.appendChild(i);
      });
      return;
    }

    if(it.type === 'pdf' && it.pages && it.pages.length){
      // Convertido no servidor: uma imagem por página. O leitor de PDF da TV
      // não entra nessa história.
      var idx = 0;
      /* Tempo por página vindo do manifesto. Antes eu dividia a duração do
         item pelo número de páginas, com piso de 3s: um PDF de 30 páginas
         num item de 20s passava 90 segundos no ar e atrasava tudo o que
         vinha depois. Agora quem manda é o valor definido no painel. */
      var porPag = Math.max(2000, it.page_ms || Math.round((it.duration || 15000) / it.pages.length));
      var img2 = new Image();
      function mostrarPag(){
        if(idx >= it.pages.length){ avancar(true); return; }
        img2.src = it.pages[idx];
        idx++;
        S.timer = setTimeout(mostrarPag, porPag);
      }
      img2.onload = function(){ if(idx === 1) trocar(); };
      img2.onerror = function(){ reportar('midia_falhou', it, 'página do PDF'); avancar(false); };
      prox.appendChild(img2);
      mostrarPag();
      return;
    }

    // web, youtube e PDF sem conversão: iframe.
    var f = document.createElement('iframe');
    f.setAttribute('referrerpolicy','no-referrer');   // reforço além da meta
    f.setAttribute('allow','autoplay; encrypted-media');
    // loading=eager: o Android às vezes adia o carregamento de iframe fora
    // de vista, e aí ele começa a montar tudo NO instante da transição.
    f.setAttribute('loading','eager');

    var pintou = false;

    /* Monta a peça a partir da cópia guardada na TV, dentro de um iframe
       em branco. Usada nos dois casos em que o servidor não entrega: rede
       fora e fonte sem conteúdo. */
    function porCopia(html, aoTerminar){
      prox.innerHTML = '';
      var g = document.createElement('iframe');
      g.setAttribute('referrerpolicy','no-referrer');
      prox.appendChild(g);
      escreverLocal(g, html, aoTerminar);
    }

    /* A conferência já sabia que não ia adiantar pedir ao servidor: entra
       direto a última versão boa desta peça, sem os nove segundos de
       espera no meio. */
    if(it._local){
      S.timer = setTimeout(function(){ avancar(true); }, it.duration||20000);
      porCopia(it._local, function(ok){
        if(passou) return;
        if(ok){ pintou = true; trocar(); return; }
        reportar('midia_falhou', it, 'cópia da TV não abriu');
        avancar(false);
      });
      return;
    }

    f.src = it.type === 'youtube' ? ytEmbed(it.url) : it.url;

    /* onload dispara quando o HTML terminou, não quando a primeira pintura
       aconteceu. Trocar nesse instante mostra a peça enquanto ela ainda
       está montando fontes, imagens e animações — que é exatamente o
       engasgo visível na troca.
       Dois quadros de folga (requestAnimationFrame aninhado) deixam o
       navegador terminar a primeira pintura antes de a camada aparecer. */
    f.onload = function(){
      pintou = true;
      if(window.requestAnimationFrame){
        requestAnimationFrame(function(){ requestAnimationFrame(trocar); });
      } else {
        setTimeout(trocar, 32);
      }
    };
    prox.appendChild(f);
    S.timer = setTimeout(function(){ avancar(true); }, it.duration||20000);
    /* Rede de segurança: página que nunca dispara onload não pode travar a
       programação. Mostra assim mesmo aos 4s — mas se aos 9s continuar sem
       carregar, pula. Antes ficava preto até a duração inteira acabar. */
    /* O !passou é o que faltava, e era a TELA PRETA.
       Este temporizador não é cancelado pelo avancar() — o avancar só limpa
       o S.timer. Numa peça web com duração menor que 4 segundos (o painel
       aceita 1s, e o comunicado urgente aceita 3s), a peça já tinha
       terminado quando ele disparava: via S.atual diferente, chamava
       trocar() de novo e apagava a camada do item SEGUINTE, acendendo no
       lugar uma camada que já havia sido esvaziada. Resultado: preto até o
       vigia perceber, cinco segundos depois, e um 'tela_parada' no
       relatório a cada volta da lista. */
    setTimeout(function(){ if(!passou && S.atual !== it) trocar(); }, 4000);
    /* Nove segundos sem carregar. Antes: pulava a peça. Se todas as peças
       da lista fossem do servidor — notícias, clima, aniversariantes — a
       parede pulava todas e caía no cartão "Aguardando conteúdo": tela
       apagada por causa da internet, e não por falta de conteúdo.

       Agora entra a cópia guardada na TV. A notícia é a de meia hora
       atrás, e a peça mostra isso; mas a parede continua com conteúdo, que
       é o ponto. Só pula quando não há nem cópia. */
    setTimeout(function(){
      if(pintou || passou) return;
      var salvo = lerCopia(it.url);
      if(!salvo){
        reportar('midia_falhou', it, 'página não carregou em 9s');
        avancar(false);
        return;
      }
      porCopia(salvo, function(ok){
        if(passou) return;
        if(ok){
          pintou = true;
          reportar('peca_local', it, 'servidor sem resposta: entrou a cópia da TV');
          trocar();          // não faz nada se a camada já estiver no ar
          return;
        }
        reportar('midia_falhou', it, 'página não carregou em 9s');
        avancar(false);
      });
    }, 9000);
  }

  function ytEmbed(u){
    var m = u.match(/[?&]v=([\w-]{6,})/) || u.match(/youtu\.be\/([\w-]{6,})/) || u.match(/embed\/([\w-]{6,})/);
    if(!m) return u;
    return 'https://www.youtube.com/embed/'+m[1]+'?autoplay=1&mute=1&controls=0&rel=0&modestbranding=1&playsinline=1';
  }

  /* ── Comprovação de exibição ────────────────────────────────── */
  /* Erro reportado, não engolido. Antes a TV pulava o conteúdo em silêncio
     e o relatório mostrava menos exibições sem explicar por quê. */
  function reportar(codigo, it, detalhe){
    if(PREVIA || !TOKEN) return;
    req('POST', API+'?action=erro&t='+TOKEN, {
      codigo: codigo,
      midia_id: it ? it.media_id : null,
      detalhe: (it ? it.name + ': ' : '') + (detalhe || '')
    }, function(){});
  }

  /* Captura da tela: desenha a camada atual num canvas. Funciona para imagem
     e vídeo, que é o que quase sempre está no ar. Iframe de outro domínio o
     navegador não deixa capturar — nesses casos o painel mostra o nome do
     que está tocando, que responde a mesma pergunta. */
  function capturar(){
    try {
      var cam = S.camadas[S.ativa];
      var el2 = cam.querySelector('video') || cam.querySelector('img');
      if(!el2){ return; }
      var w = 480;
      var natW = el2.videoWidth || el2.naturalWidth || 1920;
      var natH = el2.videoHeight || el2.naturalHeight || 1080;
      var c = document.createElement('canvas');
      c.width = w; c.height = Math.round(w * natH / natW);
      c.getContext('2d').drawImage(el2, 0, 0, c.width, c.height);
      var dataUrl = c.toDataURL('image/jpeg', 0.6);
      req('POST', API+'?action=captura&t='+TOKEN, { imagem: dataUrl }, function(){});
    } catch(e){ /* canvas sujo por conteúdo de outro domínio: sem captura */ }
  }

  function registrar(it, ms, ok){
    /* Também pela hora corrigida: sem isto, uma TV com relógio torto
       lançaria o relatório de veiculação no dia ou na hora errada, e o
       número ficaria errado sem ninguém entender por quê. */
    var d = relogio();
    S.logs.push({
      media_id: it.media_id, playlist_id: it._plId,
      played_at: d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+' '+
                 pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds()),
      duration_ms: ms, completed: ok ? 1 : 0
    });
    if(S.logs.length >= 40) enviarLogs();
  }

  function enviarLogs(){
    if(PREVIA) { S.logs = []; return; }
    if(!S.logs.length) return;
    var lote = S.logs.slice(0);
    S.logs = [];
    req('POST', API+'?action=log&t='+TOKEN, { entries: lote }, function(err, st){
      if(err || st >= 300) S.logs = lote.concat(S.logs).slice(-300);
    });
  }

  /* ── Início ─────────────────────────────────────────────────── */
  function iniciar(){
    S.camadas = [el('la'), el('lb')];

    if(PREVIA){
      cartao('Prévia','Carregando a lista','Esta janela não afeta nenhuma TV.');
      sincronizar();
      setInterval(diag, 1000);
      document.addEventListener('keydown', function(e){
        var k = e.keyCode || e.which;
        if((k >= 48 && k <= 57) || k === 68 || k === 100){
          var d = el('diag'); d.hidden = !d.hidden; if(!d.hidden) diag();
        }
      });
      return;
    }

    if(!TOKEN){
      cartao('Token ausente','Esta TV não foi vinculada',
             'Abra o link exclusivo da TV, que aparece no cadastro dentro do app TV Indoor.');
      return;
    }

    // Sobe com o que está em disco: depois de queda de energia a TV volta a
    // exibir em segundos, mesmo sem rede.
    var salvo = ler(LS.man);
    if(salvo){
      try { S.manifesto = JSON.parse(salvo); S.versao = ler(LS.ver); } catch(e){ S.manifesto = null; }
    }

    diag();
    if(S.manifesto){ aplicarLayout(); esconder(); proximo(); }
    else cartao('Iniciando','Conectando ao servidor','Isso leva alguns segundos no primeiro acesso.');

    sinal();

    /* Sincroniza só depois de saber se esta janela tem a vaga. O heartbeat
       já pede sincronização quando a versão do manifesto difere, então no
       caso normal isto nem dispara; é rede de segurança para quando o
       servidor não responder a tempo. */
    setTimeout(function(){
      if(!S.bloqueado && !S.manifesto) sincronizar();
    }, 4000);

    setInterval(sinal, 30000);
    setInterval(enviarLogs, 300000);
    setInterval(diag, 1000);

    // Recarrega de madrugada: navegador de TV aberto por semanas acumula
    // vazamento de memória. Reiniciar é mais barato que caçar.
    setInterval(function(){ if(relogio().getHours() === 4) location.reload(); }, 3600000);

    if(window.addEventListener) window.addEventListener('online', function(){ sincronizar(); sinal(); });

    // Mostra e esconde o diagnóstico. Números funcionam no controle remoto
    // da TV; D funciona em teclado, para quem testa no computador.
    document.addEventListener('keydown', function(e){
      var k = e.keyCode || e.which;
      if((k >= 48 && k <= 57) || k === 68 || k === 100){
        var d = el('diag');
        d.hidden = !d.hidden;
        if(!d.hidden) diag();
      }
    });
  }

  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
  else iniciar();
})();
</script>
</body>
</html>
