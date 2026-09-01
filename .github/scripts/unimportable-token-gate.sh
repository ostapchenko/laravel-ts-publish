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
# Usage: unimportable-token-gate.sh [BASELINE_COUNT] [RELATIVE_BASELINE] [BARE_BASELINE]
#        With no argument it prints the current counts and the offending names. RELATIVE_BASELINE
#        gates TS2307s whose specifier is relative (./ or ../) - a module this package itself writes,
#        and should always be passed as 0: unlike the other two baselines, it has no legitimate
#        non-zero cause, so it is never appropriate to raise it. BARE_BASELINE gates every other
#        TS2307 - a bare specifier such as @/types/geo, an app-side alias the consuming app is
#        expected to declare, the same kind of escape hatch as the BASELINE_COUNT family below.
#        Each argument activates its own gate; passing fewer leaves the rest report-only, same as
#        BASELINE_COUNT and RELATIVE_BASELINE already did before BARE_BASELINE existed.
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

# The other fail-open shape, and the one that actually happened: a SYNTAX error in a generated file.
# tsc emits no semantic diagnostics for a program it could not parse, so every count below reads 0
# and all three sub-gates PASS on a tree nothing checked. TS1xxx is the syntactic range.
syntax_errs=$(printf '%s\n' "$out" | grep -E "error TS1[0-9]{3}:" || true)

if [ -n "$syntax_errs" ]; then
  echo "FAIL - the generated tree does not parse, so nothing after the parse error was type-checked"
  printf '%s\n' "$syntax_errs"
  exit 1
fi

errs=$(printf '%s\n' "$out" | grep -E "error TS(2300|2304|2344|2552)" || true)
count=$(printf '%s' "$errs" | grep -c . || true)

echo "TS2300/TS2304/TS2344/TS2552 (duplicate identifier / cannot find name / bad type argument) in generated tree: $count"
printf '%s\n' "$errs" | sed -E "s/.*(Cannot find name|Duplicate identifier) '([^']+)'.*/  \2/" | sort | uniq -c | sort -rn

# A relative specifier (./ or ../) only ever resolves against a file this package itself writes,
# so an unresolved one is never the app-side escape hatch behind the baselines above/below - it is
# always the failure mode PublishedResourceRegistry exists to prevent. Kept as its own zero-tolerance
# count rather than folded into the bare one below: pooling the two would let a new broken relative
# import hide inside ordinary bare-alias churn, invisible until the combined total crossed baseline.
rel_errs=$(printf '%s\n' "$out" | grep -E "error TS2307" | grep -E "Cannot find module '\.{1,2}/" || true)
rel_count=$(printf '%s' "$rel_errs" | grep -c . || true)

echo "TS2307 (cannot find module) with a relative specifier in generated tree: $rel_count"
printf '%s\n' "$rel_errs" | sed -E "s/.*Cannot find module '([^']+)'.*/  \1/"

# Everything else with TS2307: a bare specifier such as @/types/geo. The module lives in the
# consuming app, not in this package or its workbench, so this package cannot make it resolve -
# the same kind of app-side escape hatch as the TS2300/2304/2344/2552 baseline below, just reached
# through an import rather than a bare name.
bare_errs=$(printf '%s\n' "$out" | grep -E "error TS2307" | grep -vE "Cannot find module '\.{1,2}/" || true)
bare_count=$(printf '%s' "$bare_errs" | grep -c . || true)

echo "TS2307 (cannot find module) with a bare specifier in generated tree: $bare_count"
printf '%s\n' "$bare_errs" | sed -E "s/.*Cannot find module '([^']+)'.*/  \1/"

if [ $# -ge 1 ]; then
  baseline=$1
  if [ "$count" -gt "$baseline" ]; then
    echo "FAIL - token count rose from $baseline to $count: a token was emitted without its import, or two imports collided on one name"
    printf '%s\n' "$errs"
    exit 1
  fi
  echo "PASS - no new unimportable or colliding tokens (baseline $baseline)"

  if [ $# -ge 2 ]; then
    relative_baseline=$2
    if [ "$rel_count" -gt "$relative_baseline" ]; then
      echo "FAIL - relative-specifier TS2307 count rose from $relative_baseline to $rel_count: an import points at a file this package never writes"
      printf '%s\n' "$rel_errs"
      exit 1
    fi
    echo "PASS - no new relative-specifier TS2307s (baseline $relative_baseline)"

    if [ $# -ge 3 ]; then
      bare_baseline=$3
      if [ "$bare_count" -gt "$bare_baseline" ]; then
        echo "FAIL - bare-specifier TS2307 count rose from $bare_baseline to $bare_count: an import points at a module nothing declares"
        printf '%s\n' "$bare_errs"
        exit 1
      fi
      echo "PASS - no new bare-specifier TS2307s (baseline $bare_baseline)"
    fi
  fi
fi
