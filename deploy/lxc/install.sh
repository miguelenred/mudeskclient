#!/bin/bash
# MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
# Installs MuDeskClient on Debian 12 or later or Ubuntu 22.04 or later (a Proxmox LXC container, a virtual machine or a
# server), as root: Apache with PHP, HTTPS with a self-signed certificate (or yours in /etc/mudesk/tls), only public/
# served, the data in /var/www/mudesk/data. The Apache and PHP settings are the Docker image's (docker/apache.conf and
# docker/php.ini), plus plain HTTP sent to HTTPS. Run it again to update: the code is replaced, data/ and config.php stay.
#   bash install.sh [--from DIR] [--version X.Y.Z] [--hostname NAME] [--workers N]
#     --from DIR     an unpacked MuDeskClient (its folder with public/ and src/); without it, a release from GitHub
#     --version      the release to download (without it, the latest)
#     --hostname     the name in the self-signed certificate (without it, this machine's name)
#     --workers      Apache processes at once, from 10 to 20000 (256; see docs/HOSTING.md, Capacity)
set -euo pipefail

FROM='' VERSION='' NAME='' WORKERS=256
while [ $# -gt 0 ]; do
    case "$1" in
        --from) FROM="${2:?--from needs a folder}"; shift 2 ;;
        --version) VERSION="${2:?--version needs a number}"; shift 2 ;;
        --hostname) NAME="${2:?--hostname needs a name}"; shift 2 ;;
        --workers) WORKERS="${2:?--workers needs a number}"; shift 2 ;;
        *) echo "Unknown option: $1 (see the top of this file)" >&2; exit 2 ;;
    esac
done
fail() { echo "MuDeskClient: $*" >&2; exit 1; }
[ "$(id -u)" -eq 0 ] || fail "run it as root"
command -v apt-get > /dev/null || fail "Debian or Ubuntu needed (apt-get not found)"
case "$WORKERS" in ''|*[!0-9]*) fail "--workers must be a whole number" ;; esac
{ [ "${#WORKERS}" -le 5 ] && [ "$WORKERS" -ge 10 ] && [ "$WORKERS" -le 20000 ]; } || fail "--workers must be from 10 to 20000"
[ -n "$NAME" ] || NAME="$( { hostname -f || hostname || cat /etc/hostname; } 2> /dev/null || true)"
NAME="$(printf '%s' "$NAME" | tr -cd 'A-Za-z0-9.-' | cut -c1-64)"
[ -n "$NAME" ] || NAME=localhost
DEST=/var/www/mudesk
TLS=/etc/mudesk/tls

echo "MuDeskClient: installing Apache and PHP"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q --no-install-recommends apache2 libapache2-mod-php php-cli ca-certificates curl unzip openssl
php -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("openssl") ? 0 : 1);' \
    || fail "PHP 8.1 or later with OpenSSL needed: this system has $(php -r 'echo PHP_VERSION;') (Debian 12 or Ubuntu 22.04 or later)"
# OPcache: built into PHP 8.5; up to 8.4 the package of that version (php8.x-opcache). Before, php-opcache was asked for,
# a name systems with PHP 8.5 no longer have: the install stopped there
PHPV="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
if ! php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);'; then
    case "$(apt-cache policy "php$PHPV-opcache" 2> /dev/null | awk '/Candidate:/ {print $2}')" in
        ''|'(none)') echo "MuDeskClient: no OPcache package for PHP $PHPV: it works without it, a little slower" ;;
        *) apt-get install -y -q --no-install-recommends "php$PHPV-opcache" ;;
    esac
fi

WORK="$(mktemp -d)"
trap 'rm -rf -- "${WORK:?}"' EXIT
if [ -z "$FROM" ]; then
    if [ -z "$VERSION" ]; then
        VERSION="$(curl -fsSL https://api.github.com/repos/miguelenred/mudeskclient/releases/latest | sed -n 's/.*"tag_name": *"v\([0-9][0-9.]*\)".*/\1/p' | head -1)"
        [ -n "$VERSION" ] || fail "the latest version could not be found on GitHub (give one with --version)"
    fi
    echo "MuDeskClient: downloading version $VERSION"
    curl -fsSL -o "$WORK/mudesk.zip" "https://github.com/miguelenred/mudeskclient/releases/download/v$VERSION/MuDeskClient-$VERSION.zip" \
        || fail "MuDeskClient-$VERSION.zip could not be downloaded"
    # its SHA-256 checked against the SHA256SUMS published with the release (before, installed as downloaded)
    curl -fsSL -o "$WORK/SHA256SUMS" "https://github.com/miguelenred/mudeskclient/releases/download/v$VERSION/SHA256SUMS" \
        || fail "the SHA256SUMS of version $VERSION could not be downloaded"
    WANT="$(sed -n "s/^\([0-9a-f]\{64\}\)[ *]*MuDeskClient-$VERSION\.zip\$/\1/p" "$WORK/SHA256SUMS" | head -1)"
    [ -n "$WANT" ] && [ "$(sha256sum "$WORK/mudesk.zip" | cut -d' ' -f1)" = "$WANT" ] \
        || fail "MuDeskClient-$VERSION.zip does not match the SHA-256 published with it"
    unzip -q "$WORK/mudesk.zip" -d "$WORK/zip"
    FROM="$(dirname "$(dirname "$(find "$WORK/zip" -path '*/public/index.php' | head -1)")")"
fi
[ -f "$FROM/public/index.php" ] && [ -f "$FROM/src/bootstrap.php" ] && [ -f "$FROM/docker/apache.conf" ] \
    || fail "$FROM is not an unpacked MuDeskClient (public/, src/ and docker/ expected)"

echo "MuDeskClient: putting the code in $DEST (data/ and config.php kept)"
mkdir -p "$DEST"
for item in "$DEST"/* "$DEST"/.[!.]*; do
    [ -e "$item" ] || continue
    case "$(basename "$item")" in
        data|config.php) ;;
        *) rm -rf -- "${DEST:?}/$(basename "${item:?}")" ;;
    esac
done
tar -C "$FROM" --exclude=./config.php --exclude=./data --exclude=./.git -cf - . | tar -C "$DEST" -xf -
mkdir -p "$DEST/data"
for f in .htaccess web.config .gitignore; do
    [ -f "$FROM/data/$f" ] && cp "$FROM/data/$f" "$DEST/data/$f"
done
chown -R root:root "$DEST"
chown -R www-data:www-data "$DEST/data"
chmod 700 "$DEST/data"
if [ -f "$DEST/config.php" ]; then
    chown root:www-data "$DEST/config.php"
    chmod 640 "$DEST/config.php"
fi

if [ ! -f "$TLS/cert.pem" ] || [ ! -f "$TLS/key.pem" ]; then
    echo "MuDeskClient: making a self-signed certificate for $NAME"
    mkdir -p "$TLS"
    openssl req -x509 -newkey rsa:2048 -sha256 -days 3650 -nodes -subj "/CN=$NAME" -addext "subjectAltName=DNS:$NAME" \
        -keyout "$TLS/key.pem" -out "$TLS/cert.pem" 2> /dev/null || fail "the certificate could not be made"
fi
chmod 600 "$TLS/key.pem"

echo "MuDeskClient: setting up PHP and Apache ($WORKERS processes at once)"
for d in /etc/php/*/apache2/conf.d; do
    [ -d "$d" ] && cp "$FROM/docker/php.ini" "$d/99-mudesk.ini"
done
{
    sed "s/\${MUDESK_WORKERS}/$WORKERS/" "$FROM/docker/apache.conf"
    printf '\n# plain HTTP: sent to HTTPS\n<VirtualHost *:80>\n    RewriteEngine On\n    RewriteRule ^ https://%%{HTTP_HOST}%%{REQUEST_URI} [R=301,L]\n</VirtualHost>\n'
} > /etc/apache2/sites-available/mudesk.conf
a2enmod -q ssl headers setenvif mime rewrite proxy proxy_http proxy_wstunnel > /dev/null
a2dissite -q 000-default > /dev/null 2>&1 || true
a2ensite -q mudesk > /dev/null
apache2ctl configtest
# the optional WebSocket service (tools/ws-server.php, as Apache's user; Apache passes /ws to it): the service mudesk-ws,
# in Apache's temporary folder (Debian's and Ubuntu's Apache has one of its own), started again when it fails; stopped,
# it alone is told (KillMode=mixed): it closes its sessions' input, and they end as when their browser goes (audited, their
# files removed), within 15 s (then they are stopped); 143 is its way of saying it was stopped
PHP="$(command -v php)"
if [ -d /run/systemd/system ]; then
    printf '%s\n' '# MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0' '[Unit]' \
        'Description=MuDeskClient WebSocket service' 'After=network.target apache2.service' 'JoinsNamespaceOf=apache2.service' '' \
        '[Service]' 'User=www-data' 'Group=www-data' "ExecStart=$PHP $DEST/tools/ws-server.php" 'Restart=on-failure' 'RestartSec=5' \
        'KillMode=mixed' 'TimeoutStopSec=30' 'SuccessExitStatus=143' 'PrivateTmp=true' 'NoNewPrivileges=true' '' '[Install]' 'WantedBy=multi-user.target' \
        > /etc/systemd/system/mudesk-ws.service
    systemctl daemon-reload
    systemctl enable -q apache2 mudesk-ws
    systemctl restart apache2 mudesk-ws
else
    apache2ctl restart                       # (a container without systemd: Apache and the service started here; the
    OLD='' SERVICE=''                        # earlier service told to stop, it alone, so that its sessions end as theirs
    for p in /proc/[0-9]*; do                # do, and waited for with them; such images may lack pkill)
        case "$(tr '\0' ' ' < "$p/cmdline" 2> /dev/null || true)" in
            *"$DEST/tools/ws-server.php"*) ;;
            *) continue ;;
        esac
        OLD="$OLD ${p#/proc/}"
        COMM="$(cat "$p/comm" 2> /dev/null || true)"
        PARENT="$(awk '{print $4}' "$p/stat" 2> /dev/null || true)"
        case "$COMM" in php*) [ "$(cat "/proc/$PARENT/comm" 2> /dev/null || true)" = "$COMM" ] || SERVICE="$SERVICE ${p#/proc/}" ;; esac
    done
    for p in $SERVICE; do kill "$p" 2> /dev/null || true; done
    for i in $(seq 1 250); do
        GONE=1
        for p in $OLD; do [ -d "/proc/$p" ] && GONE=0; done
        [ "$GONE" -eq 1 ] && break
        sleep 0.1
    done
    setsid runuser -u www-data -- "$PHP" "$DEST/tools/ws-server.php" > /dev/null 2>&1 < /dev/null &
fi

# the setup code made as the page's first request makes it, to be shown below (before, the page alone was asked for, which
# does not make it: the code was never shown)
curl -sk -o /dev/null -H 'Content-Type: application/json' -d '{"action":"state"}' https://127.0.0.1/api.php || true
ADDR="$( { hostname -I 2> /dev/null || true; } | awk '{print $1}')"
echo
echo "MuDeskClient is installed: open https://${ADDR:-$NAME}/ (the browser warns once about the self-signed certificate)"
if [ -f "$DEST/data/setup-code.php" ]; then
    echo "Setup code: $(sed -n 2p "$DEST/data/setup-code.php")"
fi
