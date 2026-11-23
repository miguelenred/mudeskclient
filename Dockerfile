# MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
# An image to try MuDeskClient with one command: Apache and PHP 8.4 with HTTPS (the certificate mounted in /etc/mudesk/tls,
# or a self-signed one made at the first start), only public/ served, the data in the volume /var/www/mudesk/data.
#   docker run -d --name mudesk -p 8443:443 -v mudesk-data:/var/www/mudesk/data ghcr.io/miguelenred/mudeskclient
# then open https://localhost:8443 and type the setup code: docker exec mudesk cat /var/www/mudesk/data/setup-code.php
FROM php:8.4-apache

RUN docker-php-ext-enable opcache \
 && a2enmod ssl headers setenvif mime \
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
ENTRYPOINT ["mudesk-entrypoint"]
CMD ["apache2-foreground"]
