# ============================================================
#  Redentor Hub — imagem de produção
#
#  Por que Apache e não nginx:
#  o sistema protege pastas com .htaccess — auxilio/uploads_auxilio/
#  guarda documento de colaborador e tem "Require all denied";
#  midias_tv/ bloqueia execução de PHP. O nginx ignora .htaccess,
#  então trocar de servidor aqui não seria só uma questão de gosto:
#  abriria as duas pastas para a internet. Fica Apache.
#
#  Build e deploy: EasyPanel aponta para este arquivo e reconstrói
#  a cada push na main.
# ============================================================
FROM php:8.2-apache

# Fuso horário. Sem isto o container roda em UTC e todo horário do
# sistema aparece 3 horas adiantado — o mesmo bug que o atualizar.php
# já teve que corrigir na mão.
ENV TZ=America/Sao_Paulo
RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Extensões. A imagem oficial já traz curl, mbstring, iconv, simplexml,
# openssl e json compilados — só faltam estas cinco.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        default-mysql-client; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli zip gd opcache; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# mod_rewrite (endereço curto das TVs), mod_headers e mod_expires:
# os .htaccess do projeto usam os três.
RUN a2enmod rewrite headers expires

# AllowOverride All — é isto que faz os .htaccess valerem. A imagem
# oficial vem com "None", que silenciosamente desliga as proteções.
COPY docker/apache-hub.conf /etc/apache2/conf-available/hub.conf
RUN a2enconf hub

COPY docker/php.ini /usr/local/etc/php/conf.d/hub.ini

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://localhost/index.html > /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
