<?php

/* Versão do player. Entra na URL dos widgets para o navegador da TV ser
   obrigado a buscar de novo quando algo muda. Sobe a cada alteração
   visual: é o único jeito de a parede atualizar sem alguém ir até lá. */
define('VERSAO', '75.7.1');
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
.lateral iframe{flex:1;width:100%;border:0;display:block}
.lateral iframe+iframe{border-top:1px solid rgba(236,219,174,.16)}
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
  <span>v<b>1.0.0</b></span>
</footer>

<script>
/* ES5 por decisão: isto roda em webOS 3 (Chromium 38), Tizen 4 e Android TV 7.
   Sem arrow function, sem fetch, sem template literal. Tela preta em campo
   custa uma visita técnica. */
(function () {
  'use strict';

  var VERSION = '1.0.0';
  var TOKEN = <?php echo json_encode($token); ?>;
  /* Versão do player na URL do widget. Navegador de TV guarda página com
     unhas e dentes — foi o que segurou a atualização de tamanho. Mudando a
     URL, ele é obrigado a buscar de novo, sem ninguém precisar ir na TV
     limpar cache. */
  var VER = <?php echo json_encode(VERSAO); ?>;
  var PREVIA = <?php echo (int)$previa; ?>;
  var API = location.origin + '/tvindoor.php';
  var CACHE = 'tvindoor-midia-v1';
  /* Cache separado (não some quando limpar() varre o que saiu do
     manifesto de vídeo/imagem) só para a última versão boa de cada
     peça "web" (clima, notícias, aniversariantes...). Essas peças se
     atualizam sozinhas a cada troca — isso aqui não é a fonte normal,
     é só a rede de segurança para quando a internet da TV falhar bem
     na hora da troca. */
  var CACHE_WEB = 'tvindoor-web-v1';
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
            logs:[], camadas:null, ativa:0, timer:null, sincronizando:false,
            blobs:{}, paradas:[] };

  function el(id){ return document.getElementById(id); }
  function pad(n){ return n < 10 ? '0'+n : ''+n; }

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
      if(c.type === 'clear_cache'){ if(window.caches) caches.delete(CACHE); S.cacheBytes = 0; }
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
        guardar(LS.man, JSON.stringify(d)); guardar(LS.ver, d.version);
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
    var u = [], pl = m.playlists || [];
    for(var i=0;i<pl.length;i++){
      var it = pl[i].items || [];
      for(var j=0;j<it.length;j++)
        if(it[j].url && (it[j].type === 'video' || it[j].type === 'image')) u.push(it[j].url);
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
      var feitos = 0;
      function passo(){
        feitos++;
        if(!mudo){
          cartao('Atualizando','Baixando conteúdo',
                 feitos+' de '+urls.length+' arquivos.', feitos/urls.length);
        }
        if(feitos >= urls.length){ limpar(c, urls); done(); }
      }
      for(var i=0;i<urls.length;i++){
        (function(u){
          c.match(u).then(function(hit){
            if(hit){ passo(); return; }
            c.add(u).then(passo, passo);
          });
        })(urls[i]);
      }
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
    if(S.blobs[url]){ done(S.blobs[url]); return; }
    if(!window.caches){ done(url); return; }
    caches.open(CACHE).then(function(c){
      c.match(url).then(function(r){
        if(!r){ done(url); return; }
        r.blob().then(function(b){
          S.blobs[url] = URL.createObjectURL(b);
          S.cacheBytes += b.size;
          done(S.blobs[url]);
        }, function(){ done(url); });
      }, function(){ done(url); });
    }, function(){ done(url); });
  }

  /* ── Zonas ──────────────────────────────────────────────────
     A lista escolhe o layout. 'cheia' é o comportamento antigo e continua
     sendo o padrão — TV com player velho não quebra, e lista sem layout
     definido também não. As zonas laterais são iframes do widget.php, que
     já se atualizam sozinhos; o player não precisa saber o que tem dentro. */

  function aplicarLayout(){
    var pl = (S.manifesto && S.manifesto.playlists && S.manifesto.playlists[0]) || {};
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
      elLat.innerHTML =
        '<iframe referrerpolicy="no-referrer" src="' + base + '/widget.php?tipo=relogio&v=' + VER + '"></iframe>' +
        '<iframe referrerpolicy="no-referrer" src="' + base + '/widget.php?tipo=clima&v=' + VER + '&cidade=' +
          encodeURIComponent(pl.clima || 'Curitiba') + '"></iframe>';
    } else {
      elLat.style.display = 'none';
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
    var d = new Date();
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

      /* Travar uma vez é normal (rede, aparelho dormiu). Travar 3 vezes em
         10 minutos não é mais "pular pra próxima peça" que resolve — é
         sinal de memória acumulada ou o navegador preso num jeito que só
         um recarregamento completo desempaca. Recarregar tudo é mais caro
         (perde alguns segundos de tela), mas é mais barato que ficar
         travando de peça em peça o resto do dia até o reinício das 4h. */
      var agora = Date.now();
      S.paradas.push(agora);
      S.paradas = S.paradas.filter(function(t){ return agora - t < 600000; });
      if(S.paradas.length >= 3){
        try{ reportar('tela_parada', S.atual || {}, 'recarregando: 3ª trava em menos de 10min'); }catch(e){}
        location.reload();
        return;
      }
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
    if(c && (Date.now() - c.t) < 120000){ pronto(c.ok, c.motivo); return; }

    var abortou = false;
    var t = setTimeout(function(){
      abortou = true;
      /* Demorou demais para responder: deixa passar e o limite de 9s da
         exibição resolve. Melhor um risco do que segurar a programação. */
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
      var txt = x.responseText || '', ok = true, motivo = '';
      if(txt.indexOf('data-peca-ok') > -1){
        ok = txt.indexOf('data-peca-ok="0"') === -1;
        if(!ok){
          var m = txt.match(/data-peca-motivo="([^"]*)"/);
          motivo = m ? m[1] : 'sem conteúdo';
        }
      }
      _cacheConf[url] = {t: Date.now(), ok: ok, motivo: motivo};
      pronto(ok, motivo);
    };
    /* Falha na conferência não é motivo para pular: a peça pode estar boa
       e o problema ser da consulta. */
    x.onerror = x.ontimeout = function(){ if(!abortou){ clearTimeout(t); pronto(true); } };
    try { x.send(); } catch(e){ clearTimeout(t); pronto(true); }
  }

  function exibir(it){
    var prox = S.camadas[1 - S.ativa];
    var inicio = Date.now(), passou = false;

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
    f.src = it.type === 'youtube' ? ytEmbed(it.url) : it.url;

    /* onload dispara quando o HTML terminou, não quando a primeira pintura
       aconteceu. Trocar nesse instante mostra a peça enquanto ela ainda
       está montando fontes, imagens e animações — que é exatamente o
       engasgo visível na troca.
       Dois quadros de folga (requestAnimationFrame aninhado) deixam o
       navegador terminar a primeira pintura antes de a camada aparecer. */
    f.onload = function(){
      if(window.requestAnimationFrame){
        requestAnimationFrame(function(){ requestAnimationFrame(trocar); });
      } else {
        setTimeout(trocar, 32);
      }
    };
    var pintou = false;
    var onloadOriginal = f.onload;
    f.onload = function(){ pintou = true; if(onloadOriginal) onloadOriginal(); };
    prox.appendChild(f);
    S.timer = setTimeout(function(){ avancar(true); }, it.duration||20000);

    /* Peça "web" (clima, notícias, aniversariantes...): guarda em silêncio
       uma cópia do último HTML que carregou com sucesso. Não é a fonte
       normal — a peça continua vindo direto do widget.php, sempre
       atualizada. Isso só entra em ação se a internet da TV falhar bem
       na hora da troca (ver rede de segurança abaixo). */
    if(it.type === 'web' && !PREVIA && window.caches){
      f.addEventListener('load', function(){
        fetch(it.url, {cache:'no-store'}).then(function(r){
          if(r && r.ok) caches.open(CACHE_WEB).then(function(c){ c.put(it.url, r); });
        }).catch(function(){});
      }, {once:true});
    }

    /* Rede de segurança: página que nunca dispara onload não pode travar a
       programação. Mostra assim mesmo aos 4s — mas se aos 9s continuar sem
       carregar, pula. Antes ficava preto até a duração inteira acabar.
       Antes de pular, para peça "web" tenta a última cópia salva: uma
       notícia de uma hora atrás é melhor que a tela pular sozinha. */
    setTimeout(function(){ if(S.atual !== it) trocar(); }, 4000);
    setTimeout(function(){
      if(pintou || passou) return;
      if(it.type === 'web' && window.caches){
        caches.open(CACHE_WEB).then(function(c){ return c.match(it.url); }).then(function(r){
          if(!r){ reportar('midia_falhou', it, 'página não carregou em 9s'); avancar(false); return; }
          r.text().then(function(html){
            if(pintou || passou) return;
            f.removeAttribute('src');
            f.srcdoc = html;
            pintou = true;
            trocar();
          });
        }, function(){ reportar('midia_falhou', it, 'página não carregou em 9s'); avancar(false); });
      } else {
        reportar('midia_falhou', it, 'página não carregou em 9s');
        avancar(false);
      }
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
    var d = new Date();
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
    setInterval(function(){ if(new Date().getHours() === 4) location.reload(); }, 3600000);

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
