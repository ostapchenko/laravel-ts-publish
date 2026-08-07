#!/usr/bin/env bash
# Standing gate: the generated TypeScript must not gain any new "Cannot find
# name" (TS2304) error — that is precisely the signature of a type token
# emitted without its import.
#
# The unknown-regression gate cannot catch these: a leaked token is a NEW
# property with a bogus type, not an existing property degrading to `unknown`.
#
# There is a pre-existing TS2304 baseline (config-driven `custom_ts_mappings`
# names like CustomObject / ExtendableInterface, which the consuming app is
# expected to declare). The gate compares against that baseline rather than
# demanding zero.
#
# Usage: unimportable-token-gate.sh [BASELINE_COUNT]
#        With no argument it prints the current count and the offending names.
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

errs=$(npx tsc --noEmit -p tsconfig.json 2>&1 | grep -E "error TS(2304|2552)" || true)
count=$(printf '%s' "$errs" | grep -c . || true)

echo "TS2304/TS2552 (cannot find name) in generated tree: $count"
printf '%s\n' "$errs" | sed -E "s/.*Cannot find name '([^']+)'.*/  \1/" | sort | uniq -c | sort -rn

if [ $# -ge 1 ]; then
  baseline=$1
  if [ "$count" -gt "$baseline" ]; then
    echo "FAIL - TS2304 count rose from $baseline to $count: a token was emitted without its import"
    printf '%s\n' "$errs"
    exit 1
  fi
  echo "PASS - no new unimportable tokens (baseline $baseline)"
fi
