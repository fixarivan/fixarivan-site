#!/usr/bin/env bash
#
# Восстановить каталог storage/ (в т.ч. fixarivan.sqlite) из полного бэкапа deploy.sh.
# Запуск на сервере от пользователя с правами на запись в каталог сайта.
#
# Usage:
#   ./tools/restore_storage_from_backup.sh /home/fixawcab/backups/fixarivan_2026-04-12_12-00-00.tar.gz
#
set -euo pipefail

LIVE_DEFAULT="/home/fixawcab/public_html/fixarivan.space"

usage() {
  echo "Usage: $0 /path/to/fixarivan_YYYY-MM-DD_HH-MM-SS.tar.gz [LIVE_SITE_DIR]" >&2
  echo "  LIVE_SITE_DIR default: ${LIVE_DEFAULT}" >&2
  exit 1
}

[[ $# -ge 1 ]] || usage
ARCH="$1"
LIVE="${2:-$LIVE_DEFAULT}"

[[ -f "$ARCH" ]] || {
  echo "ERROR: not a file: $ARCH" >&2
  exit 1
}
[[ "$ARCH" =~ ^/home/fixawcab/backups/fixarivan_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.tar\.gz$ ]] || {
  echo "ERROR: unexpected archive path (safety): $ARCH" >&2
  exit 1
}
[[ "$LIVE" == "/home/fixawcab/public_html/fixarivan.space" ]] || {
  echo "ERROR: LIVE path safety check failed: $LIVE" >&2
  exit 1
}

TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

tar -xzf "$ARCH" -C "$TMP"

SRC="${TMP}/fixarivan.space/storage"
if [[ ! -d "$SRC" ]]; then
  echo "ERROR: backup has no fixarivan.space/storage (tree may be wrong)" >&2
  exit 1
fi

mkdir -p "${LIVE}/storage"
echo "Restoring from: $ARCH"
echo "Into: ${LIVE}/storage/"
cp -a "${SRC}/." "${LIVE}/storage/"
echo "Done. Check site; adjust chmod/chown if needed (storage writable by PHP)."
