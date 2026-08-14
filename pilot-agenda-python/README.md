# Agenda — piloto em Python

Primeiro módulo da migração do Redentor Hub pra uma stack nova (Python
+ FastAPI), publicado automaticamente a cada `git push`, sem precisar
subir arquivo manualmente em nenhum painel.

## O que este piloto prova

- Um serviço em Python de verdade, com banco de dados de verdade
  (não `localStorage` como o `agenda.html` original).
- Deploy automático: você muda o código, dá `git push`, e a versão
  no ar atualiza sozinha em minutos.
- Ainda **não** está ligado ao login real do Hub — é um teste
  isolado, sem risco pro que já está em produção.

## Como publicar (VPS — caminho escolhido)

Este projeto vive dentro do repositório `redentor-hub`, na pasta
`pilot-agenda-python/`. No VPS, depois de clonar o repositório
inteiro, tudo roda de dentro desta pasta.

Resumo dos passos (o passo a passo completo com os comandos exatos
está sendo feito em conversa, um de cada vez):

1. Acessar o VPS por SSH.
2. Instalar Python 3, pip e venv (se ainda não tiver).
3. Clonar o repositório `redentor-hub` (ou dar `git pull` se já
   estiver clonado).
4. Entrar em `pilot-agenda-python/`, criar um ambiente virtual e
   instalar as dependências do `requirements.txt`.
5. Rodar o serviço (primeiro direto, depois como serviço permanente
   com `systemd`, pra sobreviver a reinícios do servidor).
6. Testar em `http://SEU_IP:8000` antes de configurar domínio/HTTPS.

Sem `DATABASE_URL` configurada, o projeto usa SQLite (um arquivo só,
sem precisar instalar banco de dados separado) — suficiente pro
piloto.

## Como publicar (Railway/Render — alternativa mais simples)

Se um dia quiser trocar pra deploy automático sem mexer no servidor:
railway.app ou render.com → conectar o GitHub → apontar pra pasta
`pilot-agenda-python/` (ambos suportam monorepo, escolhendo a
subpasta como raiz do serviço) → adicionar um banco PostgreSQL pelo
próprio painel.

## Testando

1. Abra o endereço público, digite um nome de usuário qualquer
   (ainda não tem senha — é só pra separar a agenda de cada pessoa).
2. Adicione um compromisso, veja se aparece na lista.
3. Recarregue a página — o compromisso tem que continuar lá (prova
   que está salvando no banco de verdade, não só na memória).

## Próximos passos (depois que este piloto estiver validado)

- Ligar o campo "usuario" à sessão real do Hub (mesma tabela
  `portal_usuarios`/`portal_tokens` que o PHP usa hoje), em vez do
  login sem senha de agora.
- Decidir se esse serviço passa a ser aberto de dentro do Hub (num
  iframe, como os apps de hoje) ou se o Hub inteiro migra também.
- Repetir esse mesmo processo pro próximo módulo pequeno.
