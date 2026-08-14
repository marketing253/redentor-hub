<?php
/* ============================================================
   tv.php — forma antiga do endereço curto, mantida só para TVs
   já instaladas com ela gravada (https://seudominio/tv.php?c=RF7K2M).

   Toda a lógica (validar o código, achar o token, redirecionar)
   agora mora só em t.php — ele já sabe ler o "?c=" sozinho, então
   este arquivo só precisa chamá-lo. Antes tela() e as regras de
   redirecionamento estavam copiadas nos dois arquivos; a correção
   do redirecionamento (usar endereço absoluto, não relativo, para
   não quebrar dentro do WebView do aplicativo) tinha sido feita só
   aqui em t.php e nunca chegou a este arquivo. Chamando t.php
   diretamente, tv.php ganha a mesma correção automaticamente e os
   dois nunca mais podem ficar diferentes por descuido.
   ============================================================ */
require __DIR__.'/t.php';
