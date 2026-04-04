#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="tavox-menu-api"
ARTIFACTS_DIR="${ROOT_DIR}/artifacts"
VERSION="$(sed -n 's/^ \* Version: //p' "${ROOT_DIR}/tavox-menu-api.php" | head -n 1)"

if [[ -z "${VERSION}" ]]; then
  echo "No se pudo resolver la versión desde tavox-menu-api.php" >&2
  exit 1
fi

mkdir -p "${ARTIFACTS_DIR}"

TMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

mkdir -p "${TMP_DIR}/${PLUGIN_SLUG}"

rsync -a \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.DS_Store' \
  --exclude '*.bak.*' \
  --exclude 'artifacts/' \
  --exclude 'docs/' \
  --exclude 'ops/' \
  "${ROOT_DIR}/" "${TMP_DIR}/${PLUGIN_SLUG}/"

OUTPUT_ZIP="${ARTIFACTS_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
rm -f "${OUTPUT_ZIP}"

(
  cd "${TMP_DIR}"
  zip -qr "${OUTPUT_ZIP}" "${PLUGIN_SLUG}"
)

echo "${OUTPUT_ZIP}"
