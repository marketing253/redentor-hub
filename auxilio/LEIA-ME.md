# Auxílio Graduação — módulo do Redentor Hub

```
index.php                  → redireciona para o painel ou para o login
login.php                  → login provisório de teste (APAGAR depois)
sair.php                   → encerra a sessão de teste
instalar.php               → verificação e criação das tabelas (APAGAR depois)
config.php                 → único arquivo que você edita
apps/auxilio.html          → interface (painel do aluno e da contabilidade no mesmo arquivo)
api/auxilio.php            → backend (JSON + uploads)
assets/                    → coloque aqui logo.png
uploads_auxilio/.htaccess  → pasta dos arquivos, bloqueada para acesso direto
db/auxilio_schema.sql      → tabelas
```

## Teste em 4 passos

1. Descompacte o zip em `public_html/`, gerando `public_html/auxilio/`.
2. Abra `config.php` e preencha base, usuário e senha do MySQL. Troque também a `chave_teste`.
3. Acesse `https://seudominio/auxilio/instalar.php` e clique em **Criar tabelas**. Cada item aparece com ✓ ou ✕ e o que fazer.
4. Acesse `https://seudominio/auxilio/login.php`. Marque *Entrar como contabilidade* para cadastrar um aluno; depois entre de novo com o login do aluno (sem marcar) para ver o outro lado. Para trocar de usuário: `sair.php`.

Coloque a logo em `assets/logo.png` — se não existir, a faixa mostra só o nome da empresa.

## Cores

Faixa em **azul Redentor `#3B4192`** com filete dourado `#D9A93F`. No balão 🎨 as duas primeiras opções são Azul Redentor e Dourado; as outras seis ficam disponíveis, e no tema Claro entram as variantes escuras (`#3B4192` e `#885B20`) para manter o contraste de leitura.

## Ajustes em `config.php`

- **`db`** — credenciais do MySQL.
- **`chaves_sessao`** — a chave que o Hub usa para guardar o login. Deixe `aux_usuario` enquanto estiver testando.
- **`papeis_contabilidade` / `admins`** — quem enxerga o painel da contabilidade (papel na sessão ou lista de logins).

O vínculo aluno ↔ pessoa é o **login do Hub**, gravado em `aux_alunos.usuario`. Quem não tem cadastro vê a mensagem de "nenhum auxílio ativo".

## Uploads

`uploads_auxilio/` precisa de permissão de escrita (750 ou 755) e do `.htaccess` dentro. Se o gerenciador da Hostinger não extrair arquivos ocultos, crie o `.htaccess` na mão com uma linha: `Require all denied`. Os arquivos nunca são servidos direto — passam por `api/auxilio.php?a=arquivo`, que confere se quem pede é o dono ou a contabilidade.

## Antes de valer para todo mundo

Apague **`login.php`**, **`instalar.php`** e **`sair.php`**. A partir daí quem manda é a sessão do Hub.

## 4. Registro no Hub

Seguindo o padrão de apps:

```html
<!-- card em #cardsGrid -->
<div class="card" onclick="openApp('auxilio')">
  <div class="ic">🎓</div><div class="tt">Auxílio Graduação</div>
</div>
```

```js
// dentro de openApp()
if (id === 'auxilio') { abrirIframe('apps/auxilio.html'); return; }

// array FIXOS
{ id:'auxilio', nome:'Auxílio Graduação', icone:'🎓', arquivo:'apps/auxilio.html' }
```

Suba o `APP_VER` e a string do service worker antes de publicar; o zip vai sem `.htaccess` da raiz e sem `.user.ini`, como de costume.

## Vários cursos por aluno

A mesma pessoa pode ter mais de um contrato — graduação e pós, por exemplo. Cada curso é uma linha em `aux_alunos`, com o mesmo login. No painel do aluno aparecem abas por curso, com marca de pendência em quem está devendo boleto no mês. A chave Pix é da pessoa: alterou em um curso, vale para todos.

Em instalação já existente, clique em **Permitir vários cursos** no `instalar.php` (ou rode `db/atualizacao_multicurso.sql`). Isso remove a regra que limitava um contrato por login.

## Chave Pix

O aluno não consegue enviar o primeiro boleto sem cadastrar a chave — no primeiro acesso abre uma janela sem botão de cancelar. O sistema valida por tipo: CPF (11 dígitos), e-mail, telefone (grava como +55DDDNÚMERO) e chave aleatória. Toda troca de chave fica registrada em `aux_log`, com data e hora.

Na contabilidade a chave aparece na aba **Alunos**, junto com contato e conformidade de prazos (quantos envios, quantos atrasos, quantas parcelas com prazo vencido sem boleto). Quem não cadastrou aparece com etiqueta vermelha **SEM PIX**, e o Resumo conta quantos estão nessa situação.

Se você já tinha criado as tabelas antes desta versão, rode `db/atualizacao_pix.sql`. Em instalação nova não precisa — o schema já vem com as colunas.

## Atualizar pelo portal (sem gerenciador de arquivos)

Em **Configurações → Atualizar o portal** (ou direto em `auxilio/atualizar.php`, só para admin do Hub):

1. Envie o `.zip` da nova versão — pode ser o pacote completo do Hub ou só a parte alterada.
2. A tela mostra a **prévia**: o que é novo, o que será substituído, o que está idêntico (ignorado)
   e o que ficou protegido. Nada foi gravado ainda.
3. Confirme. Cada arquivo substituído vai antes para `auxilio/backups/<data>/`, e as cinco
   atualizações mais recentes ficam guardadas.
4. Durante a gravação, o portal entra em **modo manutenção**: quem estiver logado vê a tela
   "Sistema em atualização, acesse mais tarde", com a porcentagem. Você, que está atualizando,
   continua com a tela normal e a barra de progresso.
5. No fim ele confere o banco, desliga o aviso e mostra o relatório. Os outros usuários recarregam
   sozinhos assim que o aviso sai.

Se a atualização travar no meio, o modo manutenção se desliga sozinho depois de 30 minutos — o portal
nunca fica preso.

Nunca sobrescreve: `auxilio/config.php`, `auxilio/uploads_auxilio/`, `.htaccess`, `.user.ini`,
`db_config.php` e os backups. O `config.php` só entra se você marcar a opção na tela.

Recusa arquivo com caminho suspeito (`..`, caminho absoluto) e extensão fora da lista permitida —
é a proteção contra pacote adulterado.

Depois de aplicar, dê **Ctrl+Shift+R** no portal: o service worker guarda a versão antiga.

## O banco se atualiza sozinho

`migracoes.php` guarda a estrutura inteira (tabelas, colunas e índices) e um número de versão.
Toda vez que o módulo abre, ele confere a versão gravada em `aux_meta`; se estiver atrasada, cria o
que faltar e regrava. Quando está em dia, o custo é um `SELECT` e nada mais.

Isso vale para instalação nova e para banco que já existia: colunas que chegaram depois (Pix, senha)
entram sem apagar dado nenhum, e o índice antigo que limitava um curso por aluno é removido.

Ao mudar a estrutura no futuro: acrescente a coluna na lista dentro de `migracoes.php` e suba o
`AUX_SCHEMA_VERSAO`. Não precisa rodar SQL na mão nem abrir o phpMyAdmin.

No `instalar.php` o botão **Conferir e atualizar o banco** chama a mesma função, para quando você
quiser forçar na hora e ver o que mudou.

## Acesso do aluno (conta no Hub, criada no cadastro)

Ao cadastrar o aluno **com e-mail preenchido**, o sistema:

1. cria a conta dele no Redentor Hub (`portal_usuarios`) com uma senha temporária;
2. libera **apenas o card 🎓 Auxílio Graduação** — todos os outros entram desligados no `perms_json`,
   e as permissões antigas (`perm_fuel`, `perm_drive`, `perm_biart`, `perm_dash`) ficam em 0;
3. manda o convite por e-mail com login, senha, o link do portal e o passo a passo:
   entrar → ler o QR Code no app autenticador → abrir o card → cadastrar Pix e contrato.

**Se a pessoa já tiver conta no Hub**, nada é alterado: senha e permissões dela continuam como estão,
e o e-mail apenas avisa que o auxílio foi cadastrado e que é para usar o login de sempre. Nesse caso
confira em Configurações se o card do Auxílio está ligado para ela. A tela de cadastro diz qual dos
dois casos aconteceu.

Ao criar um card novo no Hub, acrescente o id dele em `hub.cards` no `config.php` — senão ele nasce
liberado para os alunos, porque a regra do portal é liberar por padrão.

O `entrar.php` continua existindo como porta alternativa para quem não usa o Hub, com a mesma senha
temporária.

## (histórico) Acesso do aluno (login e senha por e-mail)

Quando a contabilidade cadastra o aluno **com e-mail preenchido**, o sistema gera uma senha temporária,
grava só o hash (nunca a senha em texto) e manda para o aluno o login, a senha, o link e o aviso do
dia 3. No primeiro acesso ele é obrigado a trocar a senha, e só depois vem o assistente de Pix e
contrato.

- Tela de entrada do aluno: **`entrar.php`**
- Se o e-mail estiver vazio no cadastro, o envio não acontece e a contabilidade recebe o aviso na tela.
- Botão **Reenviar acesso** na aba Alunos gera uma senha nova e invalida a anterior (pede confirmação).
- A contabilidade continua entrando pela sessão do Hub — não usa esta tela.

### Caixa de e-mail

Configurada em `config.php`, bloco `smtp`, usando a caixa criada na Hostinger. O envio é por SMTP
autenticado (PHPMailer em `lib/`), não pela função `mail()` — que costuma cair em spam.

No `instalar.php` há o botão **Testar envio de e-mail**: ele manda uma mensagem para o primeiro
endereço de `email_contabilidade` e mostra o erro exato se falhar. Se a porta 465 não passar,
troque para 587 no config.

Rode também o botão **Atualizar colunas (Pix e senha)** — ele acrescenta as colunas novas em
instalação que já existia, sem apagar nada.

## Backup automático no Google Drive

`backup.php` monta um zip com o dump das três tabelas e os arquivos gravados desde o último backup,
e envia para uma pasta do Drive. Se o envio falhar, a contabilidade recebe um e-mail avisando —
backup que falha em silêncio é pior que backup nenhum.

### O que você precisa providenciar

Escolha um dos dois modos, no bloco `drive` do `config.php`:

**Modo `oauth` (recomendado)** — usa `client_id`, `client_secret` e `refresh_token` de uma conta Google.
Se o Hub já conversa com o Drive, são as mesmas credenciais: copie de lá e o trabalho acaba aqui.
Os arquivos ficam no Drive dessa conta.

**Modo `servico`** — JSON de conta de serviço do Google Cloud, em `arquivo_credencial`.
Cuidado: conta de serviço **não tem espaço próprio no Drive**. Só funciona se a pasta estiver num
Drive compartilhado (Shared Drive); numa pasta comum o envio falha por cota.

Em qualquer modo, preencha `pasta_id` — é o trecho final da URL da pasta no Drive
(`drive.google.com/drive/folders/ESTE_TRECHO`) — e compartilhe a pasta com a conta usada.
Depois ligue `'ativo' => true`.

### Gerando o refresh_token (modo oauth)

Se você não tiver as credenciais do Hub à mão, use a página `drive_autorizar.php` que vem no pacote:

1. Abra `drive_autorizar.php?chave=SUA_CHAVE_TESTE` — ela mostra o endereço exato de redirecionamento
   que o Google vai exigir.
2. No Google Cloud Console: crie um projeto, ative a **Google Drive API**, crie um
   **ID do cliente OAuth → Aplicativo da Web** e cole aquele endereço em *URIs de redirecionamento*.
   Na tela de consentimento, inclua o seu e-mail como usuário de teste.
3. Volte na página, cole Client ID e Client Secret, clique em **Autorizar no Google** e entre com a
   conta dona da pasta.
4. A página devolve o `refresh_token`. Cole no `config.php` e mude `ativo` para `true`.
5. **Apague o `drive_autorizar.php` do servidor.**

### Testar e agendar

No `instalar.php`, botão **Testar backup no Drive**: manda um arquivo de teste e mostra o erro exato
do Google se falhar. Funcionando, agende no painel da Hostinger, de madrugada:

```
php /home/uXXXXXX/domains/SEUDOMINIO/public_html/backup.php
```

Manual, pelo navegador: `backup.php?chave=SUA_CHAVE_TESTE` — e com `&tudo=1` para um backup completo,
ignorando o que já foi enviado antes. Vale rodar um completo na primeira vez.

O controle do que já subiu fica em `.backup_estado.json`, na raiz do módulo. Apagando esse arquivo,
o próximo backup leva tudo de novo.

## Lembretes por e-mail

O `avisos.php` manda: no dia 1º avisa que a janela abriu; na véspera do prazo lembra quem não enviou;
no dia seguinte avisa o atraso; e toda segunda manda à contabilidade um resumo do que está parado.

Preencha em `config.php` o `email_remetente`, a lista `email_contabilidade` e a `url_sistema`.
Depois, no painel da Hostinger, em **Cron Jobs**, agende uma vez por dia (ex.: 8h):

```
php /home/uXXXXXX/domains/SEUDOMINIO/public_html/avisos.php
```

Para testar sem esperar o cron, abra `avisos.php?chave=SUA_CHAVE_TESTE` no navegador — ele imprime
quantos e-mails saíram. O envio usa a função `mail()` do servidor; se a Hostinger exigir SMTP
autenticado, troque a função `manda()` por PHPMailer.

## Exportação

Na aba Mensalidades há o botão **Exportar CSV**, que baixa a competência filtrada com nome, matrícula,
setor, contato, chave Pix, datas, valores e situação. Abre no Excel com acentuação correta.

## Base de cálculo

Os 70% são calculados sobre a mensalidade contratada. Se o boleto vier maior — multa e juros por atraso
do aluno —, o sistema calcula sobre a mensalidade e grava a diferença na observação da parcela.
A tela avisa em âmbar quando isso acontece.

## Rodar na sua máquina

Com PHP 8 e MySQL instalados (ou XAMPP), dentro da pasta do módulo:

```
php -S localhost:8000
```

Depois abra `http://localhost:8000/instalar.php`, crie as tabelas e entre por `http://localhost:8000/login.php`. Mesmo caminho do servidor, só muda o endereço.

## Fluxo implementado

| Etapa | Quem | O que acontece |
|---|---|---|
| Cadastro | Contabilidade | Login, curso, instituição, valor, percentual (70%), quantidade de mensalidades, dia de vencimento e competência inicial. Ao salvar, as parcelas são geradas automaticamente. |
| Contrato | Contabilidade | Anexa o contrato da faculdade no cadastro do aluno. |
| Dia 1 | Sistema | A parcela da competência aparece liberada no painel do aluno. |
| Até o dia 5 | Aluno | Anexa o boleto e informa o valor. Depois do dia 5 o envio continua aberto e a parcela fica com a marca **atrasado**. |
| Conferência | Contabilidade | Aprova (calcula 70% empresa / 30% aluno) ou recusa com motivo — o aluno lê o motivo e reenvia. |
| Repasse | Contabilidade | Registra a data do repasse. Só então o campo do comprovante abre para o aluno. |
| Comprovante | Aluno | Anexa o comprovante do pagamento à faculdade e a parcela é concluída. |

Antes de tudo isso, no primeiro acesso, o aluno cadastra a chave Pix — sem ela o envio do boleto não abre.

Tudo fica registrado em `aux_log` (quem fez, o quê e quando).

## Regras já embutidas

- Arquivos: PDF, JPG ou PNG, até 5 MB, com conferência de MIME real (não só da extensão).
- Boleto pode ser substituído enquanto não for aprovado; depois disso, só com a contabilidade.
- Recusa exige motivo escrito.
- Repasse só depois da aprovação; comprovante só depois do repasse.
- O aluno enxerga apenas os próprios arquivos.

## Ponto que talvez você queira mudar

O percentual é por aluno (campo `percentual`, padrão 70). Se algum curso tiver regra diferente — teto em reais, por exemplo — dá para trocar o cálculo em `case 'avaliar'`, na linha do `$emp`.
