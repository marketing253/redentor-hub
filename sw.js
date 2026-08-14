const CACHE='redentor-hub-v79-1';
const ASSETS=['/','/index.html','/manifest.json'];

self.addEventListener('install',e=>{
  self.skipWaiting();
  e.waitUntil(caches.open(CACHE).then(c=>c.addAll(ASSETS).catch(()=>{})));
});

self.addEventListener('activate',e=>{
  e.waitUntil(caches.keys().then(ks=>Promise.all(ks.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim()));
});

self.addEventListener('fetch',e=>{
  if(e.request.method!=='GET')return;

  /* As chamadas de API NUNCA passam por aqui.
     Sem esta linha, uma falha momentânea de rede fazia o service worker
     responder com o que tivesse em cache — ou com nada. E "nada" vira
     TypeError: Failed to convert value to 'Response', que derruba a
     chamada inteira. O painel mostrava "salvo" e o servidor não tinha
     recebido coisa alguma.
     API é conversa com o servidor: ou vai, ou falha de forma honesta. */
  const u = new URL(e.request.url);
  if(u.pathname.endsWith('.php') || u.search.indexOf('action=') > -1) return;

  /* Navegações HTML: sempre buscar fresco no servidor (bypass do cache HTTP do host) */
  if(e.request.mode==='navigate'||e.request.destination==='document'){
    e.respondWith(
      fetch(new Request(e.request.url,{cache:'no-store',credentials:'same-origin'}))
        .catch(()=>caches.match(e.request).then(r=>r||caches.match('/index.html')))
        /* Se nem o cache tem, devolve um erro de verdade em vez de
           undefined — que era o que quebrava. */
        .then(r=>r||new Response('Sem conexão e sem cópia guardada.',
          {status:503,headers:{'Content-Type':'text/plain;charset=utf-8'}}))
    );
    return;
  }

  e.respondWith(
    fetch(e.request)
      .catch(()=>caches.match(e.request))
      .then(r=>r||new Response('',{status:504}))
  );
});

/* ══════ PUSH NOTIFICATIONS ══════ */
self.addEventListener('push',e=>{
  let data={title:'Redentor Hub',body:'Você tem uma nova notificação.',icon:'/icon-192.png',badge:'/icon-192.png',tag:'hub',url:'/'};
  try{
    if(e.data){
      const d=e.data.json();
      data=Object.assign(data,d);
    }
  }catch(err){}
  const opts={
    body:data.body,
    icon:data.icon||'/icon-192.png',
    badge:data.badge||'/icon-192.png',
    tag:data.tag||'hub-'+Date.now(),
    renotify:true,
    vibrate:[200,100,200],
    data:{url:data.url||'/'}
  };
  e.waitUntil((async()=>{
    try{
      const cs=await clients.matchAll({type:'window',includeUncontrolled:true});
      const vis=cs.find(c=>c.visibilityState==='visible');
      if(vis && String(opts.tag||'').indexOf('chat-')===0){
        /* app na tela: o próprio Hub avisa (ou só atualiza a conversa aberta) — sem notificação do sistema */
        vis.postMessage({hubPush:{title:data.title,body:data.body,tag:opts.tag,url:data.url}});
        return;
      }
    }catch(err){}
    return self.registration.showNotification(data.title, opts);
  })());
});

self.addEventListener('notificationclick',e=>{
  e.notification.close();
  const url=e.notification.data&&e.notification.data.url?e.notification.data.url:'/';
  e.waitUntil(
    clients.matchAll({type:'window',includeUncontrolled:true}).then(cs=>{
      const c=cs.find(w=>w.url.includes(self.location.origin)&&'focus' in w);
      if(c){ c.focus(); if(url&&url!=='/') c.navigate(url); return; }
      return clients.openWindow(url);
    })
  );
});
