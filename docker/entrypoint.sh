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
#  Tudo aqui é idempotente: rodar de novo não estraga nada e
#  nunca sobrescreve arquivo que já existe.
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
# Só escreve se o arquivo não existir — assim, se você preferir
# montar o arquivo direto no EasyPanel, o montado ganha.
# ------------------------------------------------------------
php_str() { printf "%s" "$1" | sed "s/\\/\\\\/g; s/'/\\'/g"; }

if [ ! -f "$RAIZ/db_secrets.php" ]; then
    if [ -n "$DB_NAME" ]; then
        cat > "$RAIZ/db_secrets.php" <<EOF
<?php /* gerado pelo entrypoint a partir das variáveis do EasyPanel */
return array(
  'host' => '$(php_str "${DB_HOST:-mysql}")',
  'name' => '$(php_str "$DB_NAME")',
  'user' => '$(php_str "$DB_USER")',
  'pass' => '$(php_str "$DB_PASS")',
);
EOF
        echo "  criado: db_secrets.php  (host=${DB_HOST:-mysql} banco=$DB_NAME usuario=$DB_USER)"
    else
        echo "  AVISO: DB_NAME não definido e db_secrets.php não existe — o Hub não vai conectar no banco."
    fi
else
    echo "  db_secrets.php já existia — variáveis DB_* ignoradas"
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

if [ ! -f "$RAIZ/auth_secrets.php" ] && [ -n "$RECAPTCHA_SITE" ]; then
    cat > "$RAIZ/auth_secrets.php" <<EOF
<?php /* gerado pelo entrypoint */
return array(
  'recaptcha_site'   => '$(php_str "$RECAPTCHA_SITE")',
  'recaptcha_secret' => '$(php_str "$RECAPTCHA_SECRET")',
);
EOF
    echo "  criado: auth_secrets.php"
fi

if [ ! -f "$RAIZ/cron_secrets.php" ] && [ -n "$CRON_LEMBRETE" ]; then
    cat > "$RAIZ/cron_secrets.php" <<EOF
<?php /* gerado pelo entrypoint */
return array(
  'lembrete_backup' => '$(php_str "$CRON_LEMBRETE")',
  'painel_sala'     => '$(php_str "${CRON_PAINEL_SALA:-$CRON_LEMBRETE}")',
  'tvi_saude'       => '$(php_str "${CRON_TVI_SAUDE:-$CRON_LEMBRETE}")',
);
EOF
    echo "  criado: cron_secrets.php"
fi

if [ ! -f "$RAIZ/push_secrets.php" ] && [ -n "$VAPID_PUBLIC" ]; then
    cat > "$RAIZ/push_secrets.php" <<EOF
<?php /* gerado pelo entrypoint */
return array(
  'public'  => '$(php_str "$VAPID_PUBLIC")',
  'private' => '$(php_str "$VAPID_PRIVATE")',
  'subject' => '$(php_str "${VAPID_SUBJECT:-mailto:marketing@avredentor.com.br}")',
);
EOF
    echo "  criado: push_secrets.php"
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
