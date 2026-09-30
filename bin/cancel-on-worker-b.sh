#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 1 ]]; then
	echo "Usage: $0 ORDER_ID" >&2
	exit 1
fi

curl --fail --silent --show-error \
	-X POST \
	"http://localhost:8092/wp-json/wooplug-7655/v1/cancel/$1"
printf '\n'
