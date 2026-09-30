#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
	echo "Usage: $0 ORDER_ID" >&2
	exit 1
fi

echo "Worker A (the deliberately stale cache):"
curl --fail --silent --show-error "http://localhost:8091/wp-json/wooplug-7655/v1/inspect/$1"
printf '\n\nWorker B:\n'
curl --fail --silent --show-error "http://localhost:8092/wp-json/wooplug-7655/v1/inspect/$1"
printf '\n'
