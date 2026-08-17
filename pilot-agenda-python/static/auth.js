/* auth.js — sessão compartilhada por todas as ferramentas do piloto.
   Uma sessão só (token de /api/login), guardada no localStorage,
   usada em toda ferramenta pra chamar a API e pra saber quem tá
   logado. */
const AUTH_CHAVE = 'redentor_sessao';

function sessaoAtual(){
  try { return JSON.parse(localStorage.getItem(AUTH_CHAVE) || 'null'); }
  catch(e){ return null; }
}

/** Chama no topo de cada ferramenta: manda pro login se não tiver sessão. */
function exigirLogin(){
  const s = sessaoAtual();
  if(!s || !s.token){ location.href = '/'; return null; }
  return s;
}

/** fetch() que já manda o token e trata sessão expirada/derrubada. */
async function authFetch(url, opcoes){
  opcoes = opcoes || {};
  const s = sessaoAtual();
  opcoes.headers = Object.assign({}, opcoes.headers, s ? {Authorization: 'Bearer ' + s.token} : {});
  const r = await fetch(url, opcoes);
  if(r.status === 401){
    localStorage.removeItem(AUTH_CHAVE);
    location.href = '/';
    throw new Error('Sessão expirada.');
  }
  return r;
}

function sairSessao(){
  const s = sessaoAtual();
  if(s && s.token){
    fetch('/api/logout', {method: 'POST', headers: {Authorization: 'Bearer ' + s.token}}).catch(function(){});
  }
  localStorage.removeItem(AUTH_CHAVE);
  location.href = '/';
}

/** HTML pronto (usuário logado + sair, e link de config se for admin) pra
    colocar dentro do .marca-acoes de cada ferramenta. */
function barraUsuario(){
  const s = sessaoAtual();
  if(!s) return '';
  return (s.role === 'admin' ? '<a class="link-trocar" href="/config/">⚙ configurações</a>' : '')
    + '<span style="color:#c7cbee;font-size:12px">' + escHtmlAuth(s.nome || s.usuario) + (s.role === 'admin' ? ' · admin' : '') + '</span>'
    + '<a class="link-trocar" href="#" onclick="sairSessao();return false">sair</a>';
}

function escHtmlAuth(s){ const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
