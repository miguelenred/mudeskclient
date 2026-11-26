# MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
# An image to try MuDeskClient with one command: Apache and PHP 8.4 with HTTPS (the certificate mounted in /etc/mudesk/tls,
# or a self-signed one made at the first start), only public/ served, the data in the volume /var/www/mudesk/data; pcntl
# for the WebSocket service (tools/ws-server.php), which then makes each session a copy of itself, its code shared.
#   docker run -d --name mudesk -p 8443:443 -v mudesk-data:/var/www/mudesk/data ghcr.io/miguelenred/mudeskclient
# then open https://localhost:8443 and type the setup code: docker exec mudesk cat /var/www/mudesk/data/setup-code.php
# (the base by its digest, not only its tag: the image built is the one checked; .github/dependabot.yml proposes each new one)
FROM php:8.4-apache@sha256:95c1a1dfaaffef4bcfee563838c50620457a3c00196c4bf5ddb66934d707fea2

RUN docker-php-ext-install pcntl \
 && docker-php-ext-enable opcache \
 && a2enmod ssl headers setenvif mime proxy proxy_http proxy_wstunnel \
 && a2dissite 000-default \
 && sed -i 's/^Listen 80$//' /etc/apache2/ports.conf \
 && mkdir -p /etc/mudesk/tls
COPY docker/apache.conf /etc/apache2/sites-available/mudesk.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/mudesk.ini
COPY docker/entrypoint.sh /usr/local/bin/mudesk-entrypoint
COPY . /var/www/mudesk
RUN a2ensite mudesk \
 && chmod 755 /usr/local/bin/mudesk-entrypoint \
 && rm -rf /var/www/mudesk/docker /var/www/mudesk/Dockerfile /var/www/mudesk/.dockerignore \
 && chown -R www-data:www-data /var/www/mudesk/data   # only the data is the web server's to write (not the code)

VOLUME /var/www/mudesk/data /etc/mudesk/tls
EXPOSE 443
# its health, for `docker ps` and orchestrators: health.php asked over HTTPS from inside (200 while the gateway works; 503
# when its data folder cannot be written or its users or settings read). With a list of allowed clients, list 127.0.0.1.
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD php -r '$c = stream_context_create(["ssl" => ["verify_peer" => false, "verify_peer_name" => false], "http" => ["timeout" => 4]]); exit(@file_get_contents("https://127.0.0.1/health.php", false, $c) === false ? 1 : 0);'
ENTRYPOINT ["mudesk-entrypoint"]
CMD ["apache2-foreground"]
