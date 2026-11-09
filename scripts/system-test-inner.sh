#!/usr/bin/env bash
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1

ROOT_DIR="/app"
BASELINE_OK_LINE="$(php -r 'echo json_decode(file_get_contents("tests/baseline.json"), true)["phpunit_ok_line"];')"

fail_deprecation_output() {
    local label="$1"
    local file="$2"
    local stripped
    stripped="$(mktemp)"
    sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "${file}" >"${stripped}"
    if grep -E '^(PHP[[:space:]]+)?(Warning|Deprecated|Notice):' "${stripped}" >/dev/null; then
        echo "${label}: output contains Warning, Deprecated, or Notice lines" >&2
        grep -E '^(PHP[[:space:]]+)?(Warning|Deprecated|Notice):' "${stripped}" >&2 || true
        rm -f "${stripped}"
        exit 1
    fi
    rm -f "${stripped}"
}

php -v | tee /tmp/php-version.txt
php -r '
if (PHP_VERSION_ID !== 70234) {
    fwrite(STDERR, "Expected PHP 7.2.34, got ".PHP_VERSION."\n");
    exit(1);
}
echo "php: PHP ".PHP_VERSION."\n";
'

for ext in openssl pcre dom filter json libxml mbstring xml xmlwriter phar tokenizer; do
    if ! php -m | grep -qi "^${ext}$"; then
        echo "Missing required PHP extension: ${ext}" >&2
        exit 1
    fi
done

mkdir -p "${ROOT_DIR}/.system-test"

UNZIP_DEB_URL="http://archive.ubuntu.com/ubuntu/pool/main/u/unzip/unzip_6.0-25ubuntu1.1_amd64.deb"
UNZIP_DEB_SHA256="c73b06a481b70edddac0f62918fca91db721322600424c7fae7995753cc7423b"
curl -fsSL -o "${ROOT_DIR}/.system-test/unzip.deb" "${UNZIP_DEB_URL}"
echo "${UNZIP_DEB_SHA256}  ${ROOT_DIR}/.system-test/unzip.deb" | sha256sum -c -
UNZIP_WORK="$(mktemp -d)"
(
    cd "${UNZIP_WORK}"
    ar x "${ROOT_DIR}/.system-test/unzip.deb"
    tar -xf data.tar.xz ./usr/bin/unzip
    install -m 0755 usr/bin/unzip "${ROOT_DIR}/.system-test/unzip"
)
rm -rf "${UNZIP_WORK}" "${ROOT_DIR}/.system-test/unzip.deb"
export PATH="${ROOT_DIR}/.system-test:${PATH}"

COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
curl -fsSL -o "${ROOT_DIR}/.system-test/composer.phar" "${COMPOSER_URL}"
echo "${COMPOSER_SHA256}  ${ROOT_DIR}/.system-test/composer.phar" | sha256sum -c -

composer_out="$(mktemp)"
set +e
php "${ROOT_DIR}/.system-test/composer.phar" update --no-interaction --prefer-dist --prefer-stable --no-progress >"${composer_out}" 2>&1
composer_status=$?
set -e
cat "${composer_out}"
fail_deprecation_output "composer update" "${composer_out}"
if [[ ${composer_status} -ne 0 ]]; then
    rm -f "${composer_out}"
    exit "${composer_status}"
fi
rm -f "${composer_out}"

php scripts/check-cluster-pins.php | tee /tmp/pins-ok.txt
if ! grep -Fxq 'check-cluster-pins: all cluster packages match expected SHAs and aliases.' /tmp/pins-ok.txt; then
    echo 'pin check: missing exact success line' >&2
    exit 1
fi

audit_out="$(mktemp)"
set +e
php "${ROOT_DIR}/.system-test/composer.phar" audit >"${audit_out}" 2>&1
audit_status=$?
set -e
cat "${audit_out}"
fail_deprecation_output "composer audit" "${audit_out}"
if [[ ${audit_status} -ne 0 ]]; then
    rm -f "${audit_out}"
    exit "${audit_status}"
fi
if ! grep -Fxq 'No security vulnerability advisories found.' "${audit_out}"; then
    echo 'composer audit: missing exact success line' >&2
    exit 1
fi
rm -f "${audit_out}"

lint_out="$(mktemp)"
bash scripts/lint-src.sh >"${lint_out}" 2>&1
lint_status=$?
cat "${lint_out}"
fail_deprecation_output "php -l" "${lint_out}"
if [[ ${lint_status} -ne 0 ]]; then
    rm -f "${lint_out}"
    exit "${lint_status}"
fi
if ! grep -Eq '^php -l: No syntax errors detected in [0-9]+ file$' "${lint_out}"; then
    echo 'php -l: missing summary success line' >&2
    exit 1
fi
rm -f "${lint_out}"

phpunit_out="$(mktemp)"
set +e
vendor/bin/phpunit --colors=never --no-coverage >"${phpunit_out}" 2>&1
phpunit_status=$?
set -e
cat "${phpunit_out}"
fail_deprecation_output "phpunit" "${phpunit_out}"
if [[ ${phpunit_status} -ne 0 ]]; then
    rm -f "${phpunit_out}"
    exit "${phpunit_status}"
fi
if ! grep -Fxq "${BASELINE_OK_LINE}" "${phpunit_out}"; then
    echo "phpunit: missing strict success line ${BASELINE_OK_LINE}" >&2
    exit 1
fi
rm -f "${phpunit_out}"

phpstan_out="$(mktemp)"
set +e
vendor/bin/phpstan analyse --no-progress --no-ansi --memory-limit=1G >"${phpstan_out}" 2>&1
phpstan_status=$?
set -e
cat "${phpstan_out}"
fail_deprecation_output "phpstan" "${phpstan_out}"
if [[ ${phpstan_status} -ne 0 ]]; then
    rm -f "${phpstan_out}"
    exit "${phpstan_status}"
fi
if ! grep -Eq '^\s+\[OK\] No errors\s*$' "${phpstan_out}"; then
    echo 'phpstan: missing [OK] No errors' >&2
    exit 1
fi
rm -f "${phpstan_out}"

rename_out="$(mktemp)"
set +e
php scripts/check-rename.php >"${rename_out}" 2>&1
rename_status=$?
set -e
cat "${rename_out}"
fail_deprecation_output "rename-check" "${rename_out}"
if [[ ${rename_status} -ne 0 ]]; then
    rm -f "${rename_out}"
    exit "${rename_status}"
fi
if ! grep -Fxq 'rename-check: no stale repo-owned names.' "${rename_out}"; then
    echo 'rename-check: missing exact success line' >&2
    exit 1
fi
rm -f "${rename_out}"

echo 'system-test: all steps passed.'
