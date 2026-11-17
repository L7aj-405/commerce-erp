#!/usr/bin/env python3
"""Turn CI tool output into GitHub workflow annotations (readable in the run page and via the API).

Usage:
  ci_annotate.py junit <phpunit-junit.xml>   failing/erroring PHPUnit tests, grouped by message
  ci_annotate.py pint <pint-json-file>       Pint style violations (files + rules)
  ci_annotate.py tail <output-file> [title]  last lines of raw tool output (crashes / fatal errors)

Read-only: never modifies the repository. Exits 0 always (annotation helper, not a gate).
"""
import json
import re
import sys
import xml.etree.ElementTree as ET
from collections import Counter

LIMIT = 6000  # keep each annotation well inside GitHub's size limit


def escape(text: str) -> str:
    return text.replace('%', '%25').replace('\r', '%0D').replace('\n', '%0A')


def emit(level: str, title: str, body: str) -> None:
    print(f"::{level} title={escape(title)}::{escape(body[:LIMIT])}")


def junit(path: str) -> None:
    try:
        root = ET.parse(path).getroot()
    except Exception as exc:  # missing/partial report
        emit('error', 'PHPUnit report unreadable', f'{path}: {exc}')
        return
    failures = []
    total = 0
    for case in root.iter('testcase'):
        total += 1
        for kind in ('failure', 'error'):
            node = case.find(kind)
            if node is not None:
                message = (node.get('type') or '') + ': ' + ((node.text or node.get('message') or '').strip().splitlines() or [''])[0]
                failures.append((f"{case.get('classname', '')}::{case.get('name', '')}", kind, message[:300]))
    print(f'PHPUnit report: {total} test cases, {len(failures)} failing')
    if not failures:
        return
    grouped = Counter(re.sub(r'\d+', 'N', m) for _, _, m in failures)
    lines = [f'{len(failures)} failing of {total}. Most common causes:']
    lines += [f'  {n}x {msg[:200]}' for msg, n in grouped.most_common(8)]
    lines.append('First failures:')
    lines += [f'  [{k}] {name} -> {msg[:240]}' for name, k, msg in failures[:25]]
    emit('error', f'PHPUnit: {len(failures)} failing tests', '\n'.join(lines))


def pint(path: str) -> None:
    try:
        data = json.load(open(path, encoding='utf-8'))
    except Exception as exc:
        emit('warning', 'Pint report unreadable', f'{path}: {exc}')
        return
    files = data.get('files', [])
    print(f'Pint: {len(files)} files need formatting')
    if not files:
        return
    rules = Counter(rule for f in files for rule in f.get('fixers', []))
    lines = [f'{len(files)} PHP files do not match the Laravel Pint preset (legacy style debt; advisory).',
             'Most frequent rules: ' + ', '.join(f'{r} ({n})' for r, n in rules.most_common(8)),
             'Examples:'] + [f"  {f['path']}" for f in files[:15]]
    emit('warning', f'Pint: {len(files)} files need formatting', '\n'.join(lines))


def tail(path: str, title: str = 'Tool output (last lines)') -> None:
    try:
        lines = open(path, encoding='utf-8', errors='replace').read().splitlines()
    except Exception as exc:
        emit('error', title, f'{path}: {exc}')
        return
    interesting = [l for l in lines if re.search(r'Fatal|FAIL|ERROR|Error|Exception|Tests:|Assertions|OK \(', l)]
    body = ['Last 40 lines:'] + lines[-40:] + ['', 'Lines mentioning errors (last 25):'] + interesting[-25:]
    emit('error', title, '\n'.join(body))


if __name__ == '__main__':
    if len(sys.argv) < 3 or sys.argv[1] not in ('junit', 'pint', 'tail'):
        print(__doc__)
        sys.exit(0)
    {'junit': junit, 'pint': pint, 'tail': tail}[sys.argv[1]](*sys.argv[2:])
