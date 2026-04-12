#!/usr/bin/env bash
#
# FixariVan — production deploy (bash, SSH, tar, scp).
# Usage: ./deploy.sh | ./deploy.sh --dry | ./deploy.sh --rollback
#

set -e
set -o pipefail

# =============================================================================
# CONFIG
# =============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REMOTE_USER="fixawcab"
REMOTE_HOST="162.0.217.157"
REMOTE_PORT="21098"
SSH_KEY="/c/Users/user/Downloads/id_rsa"

REMOTE_PATH="/home/fixawcab/public_html/fixarivan.space"
BACKUP_PATH="/home/fixawcab/backups"
TMP_RESTORE="/home/fixawcab/tmp_restore"
SITE_PARENT="/home/fixawcab/public_html"
SITE_NAME="fixarivan.space"

# Remote /tmp free space warning threshold (KB), ~100 MiB
REMOTE_TMP_WARN_KB=102400

DRY_RUN=0
ROLLBACK=0
ARCHIVE=""

# =============================================================================
# Colors (NO_COLOR respected)
# =============================================================================

if [[ -t 1 ]] && [[ -z "${NO_COLOR:-}" ]]; then
  _G='\033[0;32m'
  _R='\033[0;31m'
  _Y='\033[1;33m'
  _N='\033[0m'
else
  _G='' _R='' _Y='' _N=''
fi

ok() { printf '%b%s%b\n' "$_G" "$*" "$_N"; }
err() { printf '%b%s%b\n' "$_R" "$*" "$_N" >&2; }
step() { printf '%b%s%b\n' "$_Y" "$*" "$_N"; }

# =============================================================================
# SSH helper
# =============================================================================

# shellcheck disable=SC2206
SSH_BASE=( ssh -i "$SSH_KEY" -p "$REMOTE_PORT" -o IdentitiesOnly=yes -o BatchMode=yes -o ConnectTimeout=15 )

ssh_run() {
  "${SSH_BASE[@]}" "${REMOTE_USER}@${REMOTE_HOST}" "$@"
}

# Last line only — tolerates MOTD/extra stdout before remote command output
ssh_echo_connected_line() {
  ssh_run "echo connected" 2>/dev/null | tail -n 1 | tr -d '\r' || true
}

# =============================================================================
# Functions
# =============================================================================

parse_args() {
  DRY_RUN=0
  ROLLBACK=0
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --dry)
        DRY_RUN=1
        ;;
      --rollback)
        ROLLBACK=1
        ;;
      -h|--help)
        printf 'Usage: %s [--dry] [--rollback]\n' "$(basename "${0}")" >&2
        exit 0
        ;;
      *)
        err "Unknown option: $1 (use --dry or --rollback)"
        exit 1
        ;;
    esac
    shift
  done
}

validate_remote_dir_constant() {
  [[ "$REMOTE_PATH" == "/home/fixawcab/public_html/fixarivan.space" ]] || {
    err "Refusing: REMOTE_PATH safety check failed."
    return 1
  }
}

validate() {
  step "Validating project..."

  if [[ ! -f "${SCRIPT_DIR}/index.php" ]]; then
    err "Validation failed: index.php not found in ${SCRIPT_DIR}"
    return 1
  fi

  if ! command -v php >/dev/null 2>&1; then
    err "Validation failed: php not found in PATH"
    return 1
  fi

  if [[ ! -d "${SCRIPT_DIR}/api" ]]; then
    err "Validation failed: api/ directory is missing"
    return 1
  fi

  if [[ ! -d "${SCRIPT_DIR}/storage" ]]; then
    err "Warning: storage/ directory is missing locally (optional on dev machine)" >&2
  fi

  local failed=0
  local f out
  while IFS= read -r -d '' f; do
    if ! out="$(php -l "$f" 2>&1)"; then
      err "Validation failed: ${f}"
      printf '%s\n' "$out" >&2
      failed=1
    fi
  done < <(find "$SCRIPT_DIR" -type f -name '*.php' \
    -not -path '*/vendor/*' \
    -not -path '*/node_modules/*' \
    -print0)

  if [[ "$failed" -ne 0 ]]; then
    err "Validation failed: fix PHP syntax errors above."
    return 1
  fi

  ok "Validation OK"
}

check_connection() {
  step "Checking SSH connection..."
  if [[ "$DRY_RUN" -eq 1 ]]; then
    step "[dry-run] would run: ssh … \"echo connected\" (tail -n 1)"
    return 0
  fi

  local out
  out="$(ssh_echo_connected_line)"
  if [[ "$out" != "connected" ]]; then
    err "SSH check failed (expected output \"connected\", got: ${out:-<empty>})"
    return 1
  fi
  ok "SSH connection OK"
}

backup() {
  step "Creating backup..."
  if [[ "$DRY_RUN" -eq 1 ]]; then
    step "[dry-run] would create: ${BACKUP_PATH}/fixarivan_YYYY-MM-DD_HH-MM-SS.tar.gz (full ${SITE_NAME})"
    return 0
  fi

  validate_remote_dir_constant
  local backup_path
  backup_path="$(
    ssh_run bash -s <<'REMOTE_BACKUP'
set -euo pipefail
BACKUP_PATH="/home/fixawcab/backups"
SITE_PARENT="/home/fixawcab/public_html"
SITE_NAME="fixarivan.space"
[[ "$BACKUP_PATH" == "/home/fixawcab/backups" ]] || exit 1
[[ "$SITE_PARENT" == "/home/fixawcab/public_html" ]] || exit 1
mkdir -p "$BACKUP_PATH"
TS="$(date +%Y-%m-%d_%H-%M-%S)"
OUT="${BACKUP_PATH}/fixarivan_${TS}.tar.gz"
if [[ ! -d "${SITE_PARENT}/${SITE_NAME}" ]]; then
  echo "Site directory missing: ${SITE_PARENT}/${SITE_NAME}" >&2
  exit 1
fi
tar -czf "$OUT" -C "$SITE_PARENT" "$SITE_NAME"
printf '%s\n' "$OUT"
REMOTE_BACKUP
  )"
  backup_path="$(printf '%s' "$backup_path" | tail -n 1 | tr -d '\r')"
  ok "Backup created: ${backup_path}"
}

clean() {
  step "Cleaning server..."
  if [[ "$DRY_RUN" -eq 1 ]]; then
    step "[dry-run] would: find inside REMOTE_PATH, delete all except storage/, .env, backups/ (find -exec rm -rf)"
    return 0
  fi

  validate_remote_dir_constant
  ssh_run bash -s <<EOF
set -euo pipefail
TARGET=$(printf '%q' "$REMOTE_PATH")
[[ "\$TARGET" == "/home/fixawcab/public_html/fixarivan.space" ]] || exit 1
[[ -d "\$TARGET" ]] || exit 1
find "\$TARGET" -mindepth 1 -maxdepth 1 ! -name 'storage' ! -name '.env' ! -name 'backups' -exec rm -rf {} +
EOF
}

upload() {
  step "Uploading files..."
  if [[ "$DRY_RUN" -eq 1 ]]; then
    step "[dry-run] would: check remote /tmp space; tar → scp → extract (archive removed only on success)"
    return 0
  fi

  validate_remote_dir_constant

  step "Checking remote /tmp space..."
  ssh_run "df -h /tmp" 2>/dev/null || step "(could not run df -h /tmp on server)"
  local avail_kb
  avail_kb="$(ssh_run "df -P /tmp 2>/dev/null | tail -1 | awk '{print \$4}'" 2>/dev/null || true)"
  if [[ "$avail_kb" =~ ^[0-9]+$ ]] && [[ "$avail_kb" -lt "$REMOTE_TMP_WARN_KB" ]]; then
    step "Warning: low free space on remote /tmp (${avail_kb} KB available; threshold ${REMOTE_TMP_WARN_KB} KB)"
  fi

  ARCHIVE="${SCRIPT_DIR}/.fixarivan_deploy_$$.tar.gz"
  trap 'rm -f "${ARCHIVE}"' EXIT

  tar -czf "$ARCHIVE" \
    --exclude='.git' \
    --exclude='node_modules' \
    --exclude='*.log' \
    --exclude='.DS_Store' \
    --exclude='.fixarivan_deploy_*.tar.gz' \
    -C "$SCRIPT_DIR" .

  REMOTE_ARCHIVE="fixarivan_upload_$(date +%s)_$$.tar.gz"
  scp -i "$SSH_KEY" -P "$REMOTE_PORT" -o IdentitiesOnly=yes -o BatchMode=yes "$ARCHIVE" "${REMOTE_USER}@${REMOTE_HOST}:/tmp/${REMOTE_ARCHIVE}"

  ssh_run bash -s <<EOF
set -euo pipefail
TARGET=$(printf '%q' "$REMOTE_PATH")
ARCH=$(printf '%q' "/tmp/${REMOTE_ARCHIVE}")
[[ "\$TARGET" == "/home/fixawcab/public_html/fixarivan.space" ]] || exit 1
[[ -f "\$ARCH" ]] || exit 1
[[ -d "\$TARGET" ]] || exit 1
tar -xzf "\$ARCH" -C "\$TARGET" && rm -f "\$ARCH"
EOF

  rm -f "$ARCHIVE"
  trap - EXIT
}

finalize() {
  if [[ "$DRY_RUN" -eq 1 ]]; then
    step "[dry-run] would: chmod all dirs 755, all files 644, then chmod 755 storage"
    return 0
  fi

  validate_remote_dir_constant
  ssh_run bash -s <<EOF
set -euo pipefail
TARGET=$(printf '%q' "$REMOTE_PATH")
[[ "\$TARGET" == "/home/fixawcab/public_html/fixarivan.space" ]] || exit 1
[[ -d "\$TARGET" ]] || exit 1
find "\$TARGET" -type d -exec chmod 755 {} +
find "\$TARGET" -type f -exec chmod 644 {} +
if [[ -d "\${TARGET}/storage" ]]; then
  chmod 755 "\${TARGET}/storage"
fi
EOF
  ok "Deploy completed successfully"
}

rollback() {
  step "Rolling back..."

  if [[ "$DRY_RUN" -eq 1 ]]; then
    step "[dry-run] would: check SSH, pick latest backup, extract to ${TMP_RESTORE}, then replace ${REMOTE_PATH} only if extract OK"
    ok "Rollback complete (dry run — no changes)"
    return 0
  fi

  step "Checking SSH connection..."
  local out
  out="$(ssh_echo_connected_line)"
  if [[ "$out" != "connected" ]]; then
    err "SSH check failed — aborting rollback."
    return 1
  fi
  ok "SSH connection OK"

  ssh_run bash -s <<'REMOTE_RB'
set -euo pipefail
BACKUP_PATH="/home/fixawcab/backups"
TMP_RESTORE="/home/fixawcab/tmp_restore"
REMOTE_PATH="/home/fixawcab/public_html/fixarivan.space"
[[ "$BACKUP_PATH" == "/home/fixawcab/backups" ]] || exit 1
[[ "$TMP_RESTORE" == "/home/fixawcab/tmp_restore" ]] || exit 1
[[ "$REMOTE_PATH" == "/home/fixawcab/public_html/fixarivan.space" ]] || exit 1

mkdir -p "$BACKUP_PATH"
shopt -s nullglob
backup_files=( "$BACKUP_PATH"/fixarivan_*.tar.gz )
if [[ ${#backup_files[@]} -eq 0 ]]; then
  echo "No backup archive found in $BACKUP_PATH" >&2
  exit 1
fi
LATEST=$(ls -t "${backup_files[@]}" | head -1)
if [[ -z "$LATEST" || ! -f "$LATEST" ]]; then
  echo "Could not resolve latest backup" >&2
  exit 1
fi
[[ "$LATEST" =~ ^/home/fixawcab/backups/fixarivan_.+\.tar\.gz$ ]] || { echo "Unsafe backup path" >&2; exit 1; }

mkdir -p "$TMP_RESTORE"
find "$TMP_RESTORE" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
if ! tar -xzf "$LATEST" -C "$TMP_RESTORE"; then
  echo "Extraction failed — current site was NOT modified." >&2
  exit 1
fi
if [[ ! -d "$TMP_RESTORE/fixarivan.space" ]]; then
  echo "Extracted tree missing fixarivan.space — aborting, site untouched." >&2
  find "$TMP_RESTORE" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
  exit 1
fi

# Extraction succeeded — replace live site
mkdir -p "$REMOTE_PATH"
find "$REMOTE_PATH" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete "$TMP_RESTORE/fixarivan.space/" "$REMOTE_PATH/"
else
  cp -a "$TMP_RESTORE/fixarivan.space/." "$REMOTE_PATH/"
fi
find "$TMP_RESTORE" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
REMOTE_RB

  ok "Rollback complete"
}

# =============================================================================
# Main
# =============================================================================

main() {
  parse_args "$@"

  if [[ "$ROLLBACK" -eq 1 ]]; then
    validate_remote_dir_constant
    rollback
    return $?
  fi

  validate_remote_dir_constant
  validate

  if [[ "$DRY_RUN" -eq 1 ]]; then
    step "DRY RUN MODE"
  fi

  # GitHub Actions (or similar) already copied the tree to REMOTE_PATH; only chmod/permissions.
  if [[ "${FIXARIVAN_DEPLOY_ON_SERVER:-}" == "1" ]]; then
    step "Server-side deploy: skipping SSH backup/upload (files already on host)"
    finalize
    if [[ "$DRY_RUN" -eq 1 ]]; then
      ok "Dry run finished (no remote changes)."
    fi
    return 0
  fi

  check_connection
  backup
  clean
  upload
  finalize

  if [[ "$DRY_RUN" -eq 1 ]]; then
    ok "Dry run finished (no remote changes)."
  fi
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  if main "$@"; then
    exit 0
  else
    err "Operation failed."
    exit 1
  fi
fi
