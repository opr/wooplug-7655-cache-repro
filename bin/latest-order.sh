#!/usr/bin/env bash
set -euo pipefail
curl --fail --silent --show-error http://localhost:8091/wp-json/wooplug-7655/v1/latest
printf '\n'
