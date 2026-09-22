#!/bin/sh
# ============================================================
#  entrypoint.sh — o que precisa acontecer toda vez que o
#  container sobe, antes do Apache atender o primeiro pedido.
#
#  Três trabalhos, nesta ordem:
#    1) recriar os .htaccess das pastas de dados
#    2) preparar as pastas graváveis (volumes nascem vazios)
#    3) escrever os arquivos de segredo a partir das variáveis
#
#  Tudo aqui é idempotente: rodar de novo não estraga nada.
#
# ============================================================
set -e

RAIZ=/var/www/html

# ------------------------------------------------------------
# 1) .htaccess — a parte que mais importa
#
# Os .htaccess NÃO estão no Git (as pastas deles são ignoradas,
# e o arquivo vai junto). Num deploy a partir do GitHub eles
# simplesmente não existem: sem isto, uploads_auxilio/ — que
# guarda documento de colaborador — nasceria como pasta pública.
# Por isso são recriados aqui, e não copiados do repositório.
# ------------------------------------------------------------
escreve_se_faltar() {
    [ -f "$1" ] && return 0
    mkdir -p "$(dirname "$1")"
    cat > "$1"
    echo "  criado: ${1#$RAIZ/}"
}

echo "[hub] conferindo proteções de pasta…"

escreve_se_faltar "$RAIZ/auxilio/uploads_auxilio/.htaccess" <<'EOF'
Require all denied
<IfModule !mod_authz_core.c>
  Order deny,allow
  Deny from all
</IfModule>
EOF

escreve_se_faltar "$RAIZ/midias_tv/.htaccess" <<'EOF'
Options -Indexes
<FilesMatch "\.(php|phtml|phar|cgi|pl|py|sh)$">
  Require all denied
</FilesMatch>
EOF

# Backups não podem ser baixados por quem descobrir o nome do arquivo:
# um .zip desses tem o banco inteiro dentro.
for pasta in auxilio/backups auxilio/.atualizacoes backups_hub; do
    escreve_se_faltar "$RAIZ/$pasta/.htaccess" <<'EOF'
Require all denied
<IfModule !mod_authz_core.c>
  Order deny,allow
  Deny from all
</IfModule>
EOF
done

# O .htaccess da raiz faz o endereço curto das TVs funcionar
# (/t/COP2T3). Ele vive no repositório como .txt porque a Hostinger
# nunca extraía arquivo começando com ponto.
if [ ! -f "$RAIZ/.htaccess" ] && [ -f "$RAIZ/htaccess-para-a-raiz.txt" ]; then
    cp "$RAIZ/htaccess-para-a-raiz.txt" "$RAIZ/.htaccess"
    echo "  criado: .htaccess (raiz, endereço curto das TVs)"
fi

# ------------------------------------------------------------
# 2) Pastas graváveis
#
# Cada uma destas deve ser um volume no EasyPanel. Sem volume, o
# conteúdo some no próximo deploy — some upload, some mídia da TV,
# some backup.
# ------------------------------------------------------------
echo "[hub] preparando pastas graváveis…"
for pasta in uploads midias_tv app anexos \
             auxilio/uploads_auxilio auxilio/backups auxilio/.atualizacoes \
             backups_hub; do
    mkdir -p "$RAIZ/$pasta"
    chown -R www-data:www-data "$RAIZ/$pasta" 2>/dev/null || true
done

# ------------------------------------------------------------
# 3) Segredos vindos das variáveis do EasyPanel
#
# Arquivo que você montou no EasyPanel sempre ganha; arquivo escrito
# por um deploy anterior é refeito, para trocar uma variável no painel
# valer no deploy seguinte. Quem distingue os dois é a marca no
# cabeçalho — veja deve_gerar() logo abaixo.
#
# Quem monta o PHP é o próprio PHP, com var_export. A primeira
# versão escapava aspas e barras na mão, com sed, e o sed falhava
# ("unknown option to `s'"): devolvia string vazia, o arquivo
# nascia com 'host' => '' e o mysqli ia procurar socket Unix,
# falhando com "No such file or directory" na tela de login.
# Escapar string de uma linguagem usando a sintaxe de outra é o
# tipo de esperteza que sempre cobra depois.
#
# Uso: escreve_segredos ARQUIVO chave1 chave2 …
#      com o valor de cada chave em SEC_<chave>.
# ------------------------------------------------------------
# Gera quando o arquivo não existe, ou quando existe mas foi gerado aqui
# — trocar DB_NAME no EasyPanel tem de valer no próximo deploy, e não
# ficar preso a um arquivo escrito por um deploy anterior. Arquivo
# montado por você não tem essa marca e é respeitado como está.
deve_gerar() {
    [ ! -f "$1" ] && return 0
    grep -q "gerado pelo entrypoint" "$1" 2>/dev/null && return 0
    return 1
}

escreve_segredos() {
    SEC_ARQUIVO="$1"; shift
    SEC_CHAVES="$*"
    export SEC_ARQUIVO SEC_CHAVES

    php -r '
        $linhas = array();
        foreach (preg_split("/[[:space:]]+/", trim(getenv("SEC_CHAVES"))) as $chave) {
            if ($chave === "") { continue; }
            $valor = getenv("SEC_" . $chave);
            if ($valor === false) { $valor = ""; }
            $linhas[] = "  " . var_export($chave, true) . " => " . var_export($valor, true) . ",";
        }
        file_put_contents(getenv("SEC_ARQUIVO"),
            "<?php /* gerado pelo entrypoint a partir das variaveis do EasyPanel */" . PHP_EOL
            . "return array(" . PHP_EOL . implode(PHP_EOL, $linhas) . PHP_EOL . ");" . PHP_EOL);
    ' || true

    if php -l "$SEC_ARQUIVO" > /dev/null 2>&1; then
        echo "  criado: ${SEC_ARQUIVO#$RAIZ/}"
    else
        echo "  ERRO: ${SEC_ARQUIVO#$RAIZ/} saiu com PHP inválido — o Hub não vai conseguir lê-lo."
    fi

    unset SEC_ARQUIVO SEC_CHAVES
}

if [ -n "$DB_NAME" ] && deve_gerar "$RAIZ/db_secrets.php"; then
    export SEC_host="${DB_HOST:-mysql}"
    export SEC_name="$DB_NAME"
    export SEC_user="$DB_USER"
    export SEC_pass="$DB_PASS"
    escreve_segredos "$RAIZ/db_secrets.php" host name user pass
    echo "         host=${DB_HOST:-mysql}  banco=$DB_NAME  usuario=$DB_USER"
    unset SEC_host SEC_name SEC_user SEC_pass
elif [ -f "$RAIZ/db_secrets.php" ]; then
    echo "  db_secrets.php montado por você — variáveis DB_* ignoradas"
else
    echo "  AVISO: DB_NAME não definido e db_secrets.php não existe — o Hub não vai conectar no banco."
fi

# Um host vazio ou 'localhost' faz o mysqli procurar socket Unix e falhar
# com "No such file or directory", que não diz nada a quem lê. Aqui o
# banco está em outro container: tem de ser o nome do serviço.
case "${DB_HOST:-}" in
    ''|localhost|127.0.0.1)
        echo "  AVISO: DB_HOST='${DB_HOST:-vazio}' aponta para a própria máquina."
        echo "         O MySQL roda em OUTRO container — use o nome do serviço"
        echo "         (algo como academy_mysql). Do contrário o login falha com"
        echo "         'No such file or directory'."
        ;;
esac

if [ -n "$RECAPTCHA_SITE" ] && deve_gerar "$RAIZ/auth_secrets.php"; then
    export SEC_recaptcha_site="$RECAPTCHA_SITE"
    export SEC_recaptcha_secret="$RECAPTCHA_SECRET"
    escreve_segredos "$RAIZ/auth_secrets.php" recaptcha_site recaptcha_secret
    unset SEC_recaptcha_site SEC_recaptcha_secret
fi

if [ -n "$CRON_LEMBRETE" ] && deve_gerar "$RAIZ/cron_secrets.php"; then
    export SEC_lembrete_backup="$CRON_LEMBRETE"
    export SEC_painel_sala="${CRON_PAINEL_SALA:-$CRON_LEMBRETE}"
    export SEC_tvi_saude="${CRON_TVI_SAUDE:-$CRON_LEMBRETE}"
    escreve_segredos "$RAIZ/cron_secrets.php" lembrete_backup painel_sala tvi_saude
    unset SEC_lembrete_backup SEC_painel_sala SEC_tvi_saude
fi

if [ -n "$VAPID_PUBLIC" ] && deve_gerar "$RAIZ/push_secrets.php"; then
    export SEC_public="$VAPID_PUBLIC"
    export SEC_private="$VAPID_PRIVATE"
    export SEC_subject="${VAPID_SUBJECT:-mailto:marketing@avredentor.com.br}"
    escreve_segredos "$RAIZ/push_secrets.php" public private subject
    unset SEC_public SEC_private SEC_subject
fi

# Segredo nenhum pode ser lido pelo Apache como arquivo estático,
# nem por outro usuário do container.
for s in db_secrets.php auth_secrets.php cron_secrets.php push_secrets.php auxilio/config.php; do
    [ -f "$RAIZ/$s" ] && chmod 640 "$RAIZ/$s" && chown root:www-data "$RAIZ/$s" 2>/dev/null || true
done

if [ ! -f "$RAIZ/auxilio/config.php" ]; then
    echo "  AVISO: auxilio/config.php não existe. Monte-o como arquivo no EasyPanel"
    echo "         (modelo em auxilio/config.example.php) — o módulo Auxílio não roda sem ele."
fi

echo "[hub] pronto."
exec "$@"
