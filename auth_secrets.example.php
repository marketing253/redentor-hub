<?php
/* Modelo de auth_secrets.php — copie para auth_secrets.php e preencha
   com as chaves reais do Cloudflare Turnstile.
   auth_secrets.php nunca é commitado no GitHub.

   Onde pegar as chaves:
     1. dash.cloudflare.com  →  Turnstile  →  Add widget
     2. Nome: Redentor Hub
     3. Hostnames: o domínio do portal (e localhost, se for testar)
     4. Widget mode: Managed
     5. Copie a Site Key e a Secret Key para baixo

   A site key é pública — ela chega ao navegador de qualquer forma.
   A secret NUNCA sai deste arquivo.

   Trocou a chave e quer conferir? Abra no navegador:
     /auth.php?action=captcha_diag
   Com a secret certa, a resposta traz invalid-input-response — que é o
   veredito BOM: significa que a Cloudflare aceitou a chave e recusou só
   o token de teste. */
return array(
  'turnstile_site'   => 'AJUSTE_site_key_do_turnstile',
  'turnstile_secret' => 'AJUSTE_secret_key_do_turnstile',
);
