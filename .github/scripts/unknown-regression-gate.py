#!/usr/bin/env python3
"""Standing gate: no generated property may go from a real type to `unknown`.

Compares the committed workbench type trees at two revisions, so it also
requires that a change regenerating them was committed.

Keys every property by (file, enclosing interface/namespace path, property name)
so identically-named properties in different files -- or in different interfaces
within the same file, e.g. the nested namespaces in laravel-ts-global.ts -- are
never conflated. Matches any indent depth.

Usage: unknown-regression-gate.py [BASE_REV] [HEAD_REV]
Exit 1 if any property regressed. Self-test: --selftest RANGE expects a FAIL.
"""
import re
import subprocess
import sys

ROOT = "workbench/resources/js/types/data"
PROP = re.compile(r"^\s*([A-Za-z_$][\w$]*)\??:\s*(.+?);\s*$")
BLOCK = re.compile(r"^\s*(?:export\s+)?(?:declare\s+)?(interface|namespace|module)\s+([\w$.]+)")


def git(*args: str) -> str:
    return subprocess.run(["git", *args], capture_output=True, text=True, check=True).stdout


def snapshot(rev: str) -> dict[tuple[str, str, str], str]:
    files = [
        f for f in git("ls-tree", "-r", "--name-only", rev, ROOT).splitlines()
        if f.endswith(".ts")
    ]
    out: dict[tuple[str, str, str], str] = {}
    for path in files:
        try:
            body = git("show", f"{rev}:{path}")
        except subprocess.CalledProcessError:
            continue
        stack: list[tuple[str, int]] = []  # (name, brace depth at which it opened)
        depth = 0
        for raw in body.splitlines():
            line = raw.split("//")[0]
            block = BLOCK.match(line)
            if block:
                stack.append((block.group(2), depth))
            m = PROP.match(raw)
            if m and not block:
                scope = ".".join(n for n, _ in stack) or "<root>"
                out[(path, scope, m.group(1))] = m.group(2).strip()
            depth += line.count("{") - line.count("}")
            while stack and depth <= stack[-1][1]:
                stack.pop()
    return out


def main() -> int:
    args = [a for a in sys.argv[1:] if a != "--selftest"]
    selftest = "--selftest" in sys.argv
    base = args[0] if args else "a6c268da05b814e76b1dad9faba6e71a9cb91b92"
    head = args[1] if len(args) > 1 else "HEAD"

    b, h = snapshot(base), snapshot(head)
    print(f"base properties: {len(b)}   head properties: {len(h)}")

    bad = [
        (k, b[k], h[k])
        for k in h
        if k in b and "unknown" not in b[k] and "unknown" in h[k]
    ]
    if bad:
        print(f"FAIL - {len(bad)} propert{'y' if len(bad) == 1 else 'ies'} regressed to unknown:")
        for (path, scope, prop), was, now in sorted(bad)[:40]:
            print(f"  {path} :: {scope}.{prop}\n     base: {was}\n     head: {now}")
        return 0 if selftest else 1

    print("PASS - no property regressed to unknown")
    if selftest:
        print("SELFTEST FAILED: expected this range to report a regression")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
