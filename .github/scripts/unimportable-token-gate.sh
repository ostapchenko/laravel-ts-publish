#!/usr/bin/env bash
# Standing gate: the generated TypeScript must not gain any new "Cannot find
# name" (TS2304, or TS2552 when a similarly-named global exists) error — that
# is precisely the signature of a type token emitted without its import. It
# also counts TS2300 "Duplicate identifier" errors: two different imports
# resolving to the same local name, e.g. two unrelated MailPrice models both
# aliased to MailPriceMailPrice (see docs/components/import-name-registry.md).
# TS2344 rounds out the set: a token that IS imported but named where its own
# type rejects it, e.g. Pick<Model, K> over a key the interface never declares.
#
# The unknown-regression gate cannot catch either shape: a leaked or colliding
# token is a NEW property with a plausible-looking type, not an existing
# property degrading to `unknown`.
#
# There is a pre-existing TS2304 baseline (config-driven `custom_ts_mappings`
# names like CustomObject / ExtendableInterface, which the consuming app is
# expected to declare). The gate compares against that baseline rather than
# demanding zero.
#
# Usage: unimportable-token-gate.sh [BASELINE_COUNT] [TS2307_BASELINE]
#        With no argument it prints the current count and the offending names. TS2307_BASELINE
#        gates every "Cannot find module" (TS2307) diagnostic - both a relative specifier (a module
#        this package itself writes) and a bare one (an app-side alias, e.g. @/types/geo, that the
#        consuming app is expected to declare).
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

out=$(npx tsc --noEmit -p tsconfig.json 2>&1)
status=$?

# Without these the gate fails OPEN: a tsc that never type-checked anything emits no TS2304 lines,
# which reads as a pass. Config and CLI failures print unanchored, while a real diagnostic always
# carries its `file(line,col):` prefix.
setup_errs=$(printf '%s\n' "$out" | grep -E "^error TS[0-9]+" || true)

if [ -n "$setup_errs" ] || { [ "$status" -ne 0 ] && ! printf '%s\n' "$out" | grep -qE "error TS[0-9]+"; }; then
  echo "FAIL - tsc exited $status without type-checking the generated tree, so there is nothing to gate on"
  printf '%s\n' "$out"
  exit 1
fi

errs=$(printf '%s\n' "$out" | grep -E "error TS(2300|2304|2344|2552)" || true)
count=$(printf '%s' "$errs" | grep -c . || true)

echo "TS2300/TS2304/TS2344/TS2552 (duplicate identifier / cannot find name / bad type argument) in generated tree: $count"
printf '%s\n' "$errs" | sed -E "s/.*(Cannot find name|Duplicate identifier) '([^']+)'.*/  \2/" | sort | uniq -c | sort -rn

# Every TS2307 counts here now, relative or bare: a relative specifier (./ or ../) only ever
# resolves against a file this package itself writes, and a bare one (e.g. @/types/geo) is the
# same kind of app-side escape hatch as the TS2304 baseline above - the consuming app is expected
# to declare it, and this package has no file of its own to point the import at either way.
ts2307_errs=$(printf '%s\n' "$out" | grep -E "error TS2307" || true)
ts2307_count=$(printf '%s' "$ts2307_errs" | grep -c . || true)

echo "TS2307 (cannot find module) in generated tree: $ts2307_count"
printf '%s\n' "$ts2307_errs" | sed -E "s/.*Cannot find module '([^']+)'.*/  \1/"

if [ $# -ge 1 ]; then
  baseline=$1
  if [ "$count" -gt "$baseline" ]; then
    echo "FAIL - token count rose from $baseline to $count: a token was emitted without its import, or two imports collided on one name"
    printf '%s\n' "$errs"
    exit 1
  fi
  echo "PASS - no new unimportable or colliding tokens (baseline $baseline)"

  if [ $# -ge 2 ]; then
    ts2307_baseline=$2
    if [ "$ts2307_count" -gt "$ts2307_baseline" ]; then
      echo "FAIL - TS2307 count rose from $ts2307_baseline to $ts2307_count: an import points at a module nothing declares"
      printf '%s\n' "$ts2307_errs"
      exit 1
    fi
    echo "PASS - no new TS2307s (baseline $ts2307_baseline)"
  fi
fi
