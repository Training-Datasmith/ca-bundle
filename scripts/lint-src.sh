#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

count=0
for f in src/*.php; do
    if [[ ! -f "$f" ]]; then
        continue
    fi
    php -l -f "$f" >/tmp/lint-one.txt 2>&1
    line="$(tail -n 1 /tmp/lint-one.txt)"
    if ! grep -Fxq "$line" /tmp/lint-one.txt; then
        cat /tmp/lint-one.txt
        exit 1
    fi
    if ! grep -Eq '^No syntax errors detected in .+\.php$' /tmp/lint-one.txt; then
        cat /tmp/lint-one.txt
        exit 1
    fi
    count=$((count + 1))
done

if [[ "$count" -eq 0 ]]; then
    echo "php -l: no src PHP files found" >&2
    exit 1
fi

echo "php -l: No syntax errors detected in ${count} file"
