# Subir o Redentor Hub na VPS com EasyPanel

Hoje o Hub roda na Hostinger compartilhada e se atualiza por zip, na mão,
pela tela *Configurações → Manutenção*. Depois deste roteiro, atualizar
vira `git push`: o EasyPanel percebe, reconstrói e troca a versão no ar.

O repositório é `marketing253/redentor-hub` (privado).

---

## Antes de começar

Deixe em mãos, do painel da Hostinger:

- **Dump do banco** — hPanel → Bancos de Dados → phpMyAdmin → Exportar (SQL)
- **A pasta `uploads/`** — 272 MB hoje, baixe por FTP ou pelo gerenciador
- **`auxilio/config.php`** — o arquivo real, não o `.example`. Ele tem SMTP,
  credenciais do Drive e a senha padrão de convite
- **Os quatro `*_secrets.php`** — `db`, `auth`, `cron`, `push`

Nenhum desses está no Git, e é assim que tem de ser. Eles entram no
EasyPanel como variável de ambiente ou arquivo montado.

---

## 1. Serviço de banco

No projeto do EasyPanel: **+ Service → MySQL**.

- Versão **8.0**
- Anote usuário, senha e nome do banco — vão virar variáveis no passo 3

Importe o dump depois que o serviço subir, pela aba *Console* do MySQL ou
por um cliente apontando na porta que o EasyPanel expõe.

> O Hub usa `utf8mb4` e `SET time_zone='-03:00'`. Confira que o dump veio
> em `utf8mb4` — dump em `latin1` estraga acento em nome de colaborador.

---

## 2. Serviço da aplicação

**+ Service → App**, e na aba *Source*:

| Campo | Valor |
|---|---|
| Fonte | GitHub |
| Repositório | `marketing253/redentor-hub` |
| Branch | `main` |
| Build method | **Dockerfile** |
| Dockerfile path | `Dockerfile` |

Autorize o EasyPanel no GitHub — o repositório é privado, sem isso o
clone falha. Deixe o **Auto Deploy ligado**: é ele que faz o push virar
deploy.

---

## 3. Variáveis de ambiente

Aba *Environment*. As de banco são obrigatórias; o resto liga cada recurso.

```
DB_HOST=redentor_mysql        # nome interno do serviço MySQL no EasyPanel
DB_NAME=
DB_USER=
DB_PASS=

RECAPTCHA_SITE=               # do auth_secrets.php atual
RECAPTCHA_SECRET=

CRON_LEMBRETE=                # do cron_secrets.php atual
CRON_PAINEL_SALA=
CRON_TVI_SAUDE=

VAPID_PUBLIC=                 # do push_secrets.php atual
VAPID_PRIVATE=
VAPID_SUBJECT=mailto:marketing@avredentor.com.br
```

O `entrypoint.sh` monta os arquivos `*_secrets.php` a partir disso quando
o container sobe — e **só se o arquivo ainda não existir**. Se você
preferir montar o arquivo direto, o montado ganha.

> Reaproveite as chaves VAPID atuais. Gerar um par novo invalida todas as
> inscrições de push já feitas, e cada pessoa precisa entrar no Hub de
> novo para voltar a receber notificação.

---

## 4. Volumes — o passo que não dá para pular

Sem volume, **tudo isto some no próximo deploy**: cada build gera um
container novo, e o que foi gravado dentro do anterior vai junto.

Aba *Mounts* → *Volume*, um para cada linha:

| Caminho no container | O que guarda |
|---|---|
| `/var/www/html/uploads` | anexos do portal — 272 MB hoje |
| `/var/www/html/midias_tv` | vídeos e imagens das TVs |
| `/var/www/html/app` | os `.apk` do app TV Indoor |
| `/var/www/html/anexos` | anexos de chamados |
| `/var/www/html/auxilio/uploads_auxilio` | **boletos e documentos de colaborador** |
| `/var/www/html/auxilio/backups` | backups do módulo Auxílio |
| `/var/www/html/auxilio/.atualizacoes` | trabalho em andamento do atualizador |
| `/var/www/html/backups_hub` | backups do portal |

Depois de criados, copie o conteúdo da Hostinger para dentro deles.

### E o `auxilio/config.php`

Esse é arquivo, não pasta — *Mounts* → **File**, em
`/var/www/html/auxilio/config.php`, e cole o conteúdo do arquivo real.
Tem setting demais (SMTP, Drive, feriados, papéis, cards) para virar
variável de ambiente sem virar bagunça.

Sem ele o módulo Auxílio não sobe — o entrypoint avisa no log.

---

## 5. Domínio e HTTPS

Aba *Domains* → adicione `redentorhub.com.br`, porta **80**, e ligue o
**Let's Encrypt**. Aponte o DNS para o IP da VPS.

Deixe o domínio antigo respondendo até confirmar que tudo funciona na VPS.

---

## 6. Cron — o que vinha do painel da Hostinger

Hoje são quatro agendamentos. No EasyPanel, aba *Scheduled Tasks* do
serviço da app (ou um serviço Cron separado, se preferir):

| Quando | Comando | Para quê |
|---|---|---|
| a cada 5 min | `php /var/www/html/tvi_saude.php` | vigia se alguma TV caiu |
| 07:00, seg–sáb | `php /var/www/html/lembrete.php?k=$CRON_LEMBRETE` | compromissos do dia via n8n |
| 1× por dia, madrugada | `php /var/www/html/backup_auto.php` | backup do portal, guarda os últimos 14 |
| 1× por dia, 08:00 | `php /var/www/html/auxilio/avisos.php` | avisos do Auxílio Graduação |

O `painel_sala.php` é chamado pelas próprias telas das salas, não por cron.

---

## 7. E-mail

O SMTP continua sendo `smtp.hostinger.com` — é a caixa da empresa, não
depende de onde o site roda. **Não mexa nisso durante a migração.** Se um
dia cancelar a hospedagem, aí sim troque o bloco `smtp` do
`auxilio/config.php`.

---

## Depois de tudo no ar

Atualizar o Hub passa a ser:

```
git add -A
git commit -m "o que mudou"
git push
```

O EasyPanel reconstrói e troca. Se der ruim, *Deployments* → **Rollback**
volta para a versão anterior em segundos — coisa que o atualizador por zip
nunca conseguiu fazer direito.

### O que fazer com a tela de atualizar por zip

Ela continua funcionando, mas **não use mais**: o que ela gravar no
container é perdido no próximo deploy, porque o código vem do Git. Vale
tirar o botão de *Configurações → Manutenção* depois da migração, para
ninguém cair na armadilha. (`auxilio/restaurar.php` e a lista de versões
podem ficar — são só leitura de histórico.)

---

## Conferir se subiu certo

Com o container no ar, na aba *Console*:

```
curl -I http://localhost/auxilio/uploads_auxilio/
```

Tem de responder **403**. Se vier 200 ou listagem de arquivos, os
`.htaccess` não pegaram — confira no log se o entrypoint imprimiu
`[hub] conferindo proteções de pasta…` e se o `AllowOverride All` está
valendo.

Depois, no navegador:

- login no portal
- uma TV pegando a playlist
- upload de mídia no TV Indoor (o limite foi para 512 MB)
- o endereço curto `redentorhub.com.br/t/CODIGO`
