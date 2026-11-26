#!/bin/sh
# MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
# The certificate for HTTPS: cert.pem and key.pem mounted in /etc/mudesk/tls, or a self-signed one made now (for
# MUDESK_HOSTNAME, localhost by default; the browser warns once), with a minimal OpenSSL configuration of its own (the image
# may have none). A new data volume belongs to root: given to Apache's user.
set -e
if [ ! -f /etc/mudesk/tls/cert.pem ] || [ ! -f /etc/mudesk/tls/key.pem ]; then
    # (the name also as subjectAltName: browsers check that one; the folder is a volume, so the certificate stays with updates)
    trap 'rm -f /tmp/mudesk-openssl.cnf' EXIT          # (also when making it fails: set -e ends the script before the rm)
    printf '[req]\ndistinguished_name = dn\n[dn]\n[san]\nsubjectAltName = DNS:%s\n' "$(printf '%s' "${MUDESK_HOSTNAME:-localhost}" | tr -cd 'A-Za-z0-9.-')" > /tmp/mudesk-openssl.cnf
    php -r '$o = ["config" => "/tmp/mudesk-openssl.cnf", "private_key_bits" => 2048, "private_key_type" => OPENSSL_KEYTYPE_RSA, "digest_alg" => "sha256"];
        $k = openssl_pkey_new($o);
        $n = substr(getenv("MUDESK_HOSTNAME") ?: "localhost", 0, 64);
        $r = openssl_csr_new(["commonName" => $n], $k, $o);
        $c = $r ? openssl_csr_sign($r, null, $k, 3650, $o + ["x509_extensions" => "san"]) : false;
        if (!$c || !openssl_x509_export_to_file($c, "/etc/mudesk/tls/cert.pem") || !openssl_pkey_export_to_file($k, "/etc/mudesk/tls/key.pem", null, $o)) {
            fwrite(STDERR, "MuDeskClient: the certificate could not be made\n"); exit(1);
        }'
    rm -f /tmp/mudesk-openssl.cnf
    trap - EXIT
    chmod 600 /etc/mudesk/tls/key.pem
    echo "MuDeskClient: self-signed certificate made for ${MUDESK_HOSTNAME:-localhost}"
fi
chown -R www-data:www-data /var/www/mudesk/data
# Apache processes at once (docker/apache.conf): a whole number from 10 to 20000, else 256
case "${MUDESK_WORKERS:-}" in
    ''|*[!0-9]*) MUDESK_WORKERS=256 ;;
esac
if [ "${#MUDESK_WORKERS}" -gt 5 ] || [ "$MUDESK_WORKERS" -lt 10 ] || [ "$MUDESK_WORKERS" -gt 20000 ]; then
    echo "MuDeskClient: MUDESK_WORKERS must be from 10 to 20000; 256 taken"
    MUDESK_WORKERS=256
fi
export MUDESK_WORKERS
echo "MuDeskClient: up to $MUDESK_WORKERS Apache processes at once"
# the optional WebSocket service (tools/ws-server.php), as Apache's user, behind Apache (docker/apache.conf, /ws): started
# again when it stops; config.php's websocket => false ends it at once, for good
(
    while :; do
        runuser -u www-data -- php /var/www/mudesk/tools/ws-server.php && break
        sleep 5
    done
) &
if [ -f /var/www/mudesk/data/setup-code.php ]; then
    echo "MuDeskClient: first run; the setup code is the second line of /var/www/mudesk/data/setup-code.php"
fi
exec docker-php-entrypoint "$@"
