#!/bin/bash
# MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
# On a Proxmox VE host, as root: makes an unprivileged Debian 12 LXC container and installs MuDeskClient in it with
# deploy/lxc/install.sh (Apache, PHP, HTTPS with a self-signed certificate; see that file). Run it from an unpacked
# MuDeskClient (the release zip, or a clone of the repository), so that ../lxc/install.sh is beside it: that MuDeskClient
# is the one installed (with --version, that release from GitHub instead).
#   bash create-lxc.sh [--id N] [--hostname mudesk] [--storage local-lvm] [--template-storage local] [--bridge vmbr0]
#                      [--ip dhcp | --ip 192.168.1.50/24 --gw 192.168.1.1] [--memory 2048] [--cores 2] [--disk 8]
#                      [--version X.Y.Z] [--workers N]
# Memory and cores: see docs/HOSTING.md, Capacity (each RDP session is one PHP process).
set -euo pipefail

ID='' NAME=mudesk STORAGE=local-lvm TSTORAGE=local BRIDGE=vmbr0 IP=dhcp GW='' MEMORY=2048 CORES=2 DISK=8 VERSION='' WORKERS=''
while [ $# -gt 0 ]; do
    case "$1" in
        --id) ID="${2:?}"; shift 2 ;;
        --hostname) NAME="${2:?}"; shift 2 ;;
        --storage) STORAGE="${2:?}"; shift 2 ;;
        --template-storage) TSTORAGE="${2:?}"; shift 2 ;;
        --bridge) BRIDGE="${2:?}"; shift 2 ;;
        --ip) IP="${2:?}"; shift 2 ;;
        --gw) GW="${2:?}"; shift 2 ;;
        --memory) MEMORY="${2:?}"; shift 2 ;;
        --cores) CORES="${2:?}"; shift 2 ;;
        --disk) DISK="${2:?}"; shift 2 ;;
        --version) VERSION="${2:?}"; shift 2 ;;
        --workers) WORKERS="${2:?}"; shift 2 ;;
        *) echo "Unknown option: $1 (see the top of this file)" >&2; exit 2 ;;
    esac
done
fail() { echo "MuDeskClient: $*" >&2; exit 1; }
command -v pct > /dev/null || fail "run it on a Proxmox VE host (pct not found)"
[ "$(id -u)" -eq 0 ] || fail "run it as root"
INSTALL="$(cd "$(dirname "$0")/.." && pwd)/lxc/install.sh"
[ -f "$INSTALL" ] || fail "$INSTALL not found: run this from an unpacked MuDeskClient"
for n in "$MEMORY" "$CORES" "$DISK"; do
    case "$n" in ''|*[!0-9]*) fail "--memory, --cores and --disk take whole numbers" ;; esac
done
[ -n "$ID" ] || ID="$(pvesh get /cluster/nextid)"
NET="name=eth0,bridge=$BRIDGE,ip=$IP"
[ "$IP" = dhcp ] || [ -n "$GW" ] || fail "a fixed --ip needs its --gw"
[ -z "$GW" ] || NET="$NET,gw=$GW"

echo "MuDeskClient: finding the Debian 12 template"
pveam update > /dev/null
TEMPLATE="$(pveam available --section system | awk '{print $2}' | grep -E '^debian-12-standard_.*_amd64\.tar\.(zst|gz)$' | sort -V | tail -1)"
[ -n "$TEMPLATE" ] || fail "no Debian 12 template offered by pveam"
pveam list "$TSTORAGE" | grep -q "$TEMPLATE" || pveam download "$TSTORAGE" "$TEMPLATE"

echo "MuDeskClient: making container $ID ($NAME: $CORES cores, $MEMORY MB, $DISK GB on $STORAGE)"
pct create "$ID" "$TSTORAGE:vztmpl/$TEMPLATE" --hostname "$NAME" --memory "$MEMORY" --swap 512 --cores "$CORES" \
    --rootfs "$STORAGE:$DISK" --net0 "$NET" --unprivileged 1 --features nesting=1 --onboot 1
pct start "$ID"
for i in $(seq 1 60); do
    pct exec "$ID" -- sh -c 'getent hosts deb.debian.org > /dev/null' && break
    [ "$i" -lt 60 ] || fail "container $ID has no network (check --bridge, --ip and --gw)"
    sleep 2
done

pct push "$ID" "$INSTALL" /root/mudesk-install.sh
# this MuDeskClient, the one the script came with (before, install.sh downloaded the latest release: maybe another
# version, and nothing when GitHub cannot be reached); its data/ only the protective files, no config.php
FROM=''
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ -z "$VERSION" ] && [ -f "$ROOT/public/index.php" ] && [ -f "$ROOT/src/bootstrap.php" ]; then
    TAR="$(mktemp)"
    trap 'rm -f -- "${TAR:?}"' EXIT
    tar -C "$ROOT" --exclude=./data --exclude=./config.php --exclude=./.git -cf "$TAR" .
    for f in .htaccess web.config .gitignore; do
        [ -f "$ROOT/data/$f" ] && tar -C "$ROOT" -rf "$TAR" "./data/$f"
    done
    pct push "$ID" "$TAR" /root/mudesk-src.tar
    pct exec "$ID" -- sh -c 'rm -rf /root/mudesk-src && mkdir /root/mudesk-src && tar -C /root/mudesk-src -xf /root/mudesk-src.tar && rm -f /root/mudesk-src.tar'
    FROM=/root/mudesk-src
fi
pct exec "$ID" -- bash /root/mudesk-install.sh --hostname "$NAME" ${FROM:+--from "$FROM"} ${VERSION:+--version "$VERSION"} ${WORKERS:+--workers "$WORKERS"}
echo "MuDeskClient: container $ID is ready; it starts with the host (onboot). To update later: pct exec $ID -- bash /root/mudesk-install.sh"
