#!/usr/bin/env python3
"""Turn CI tool output into GitHub workflow annotations (readable in the run page and via the API).

Usage:
  ci_annotate.py junit <phpunit-junit.xml>   failing/erroring PHPUnit tests, grouped by message
  ci_annotate.py pint <pint-json-file>       Pint style violations (files + rules)
  ci_annotate.py tail <output-file> [title]  last lines of raw tool output (crashes / fatal errors)
  ci_annotate.py composer <json> <stderr> <exit-code>   composer audit: vulnerabilities vs. command failure
  ci_annotate.py npm <json> <exit-code>                 npm audit: vulnerabilities vs. command failure

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


def composer(json_path: str, err_path: str, code: str) -> None:
    """Exit bitmask of `composer audit`: 1 = vulnerabilities, 2 = abandoned packages. Anything else, or
    unparsable output, means the audit itself failed (network / registry / lockfile error)."""
    code = int(code)
    try:
        data = json.load(open(json_path, encoding='utf-8'))
    except Exception:
        data = None
    if data is None or code not in (0, 1, 2, 3):
        err = ''
        try:
            err = open(err_path, encoding='utf-8', errors='replace').read()[-1500:]
        except Exception:
            pass
        emit('error', 'composer audit COULD NOT RUN (command/infrastructure error, not a vulnerability result)',
             f'exit code {code}\n{err}')
        return
    advisories = data.get('advisories') or {}
    if isinstance(advisories, list):
        advisories = {}
    abandoned = data.get('abandoned') or {}
    rows, severity = [], Counter()
    for package, items in advisories.items():
        for a in (items if isinstance(items, list) else [items]):
            severity[(a.get('severity') or 'unrated')] += 1
            rows.append(f"{package} | {a.get('severity') or 'unrated'} | {a.get('cve') or a.get('advisoryId')} | {(a.get('title') or '')[:110]}")
    print(f'composer audit: {len(rows)} advisories in {len(advisories)} packages, {len(abandoned)} abandoned')
    if rows:
        head = f"{len(rows)} known vulnerabilities in {len(advisories)} locked packages ({', '.join(f'{n} {k}' for k, n in severity.most_common())}). Update the affected packages."
        emit('error', f'composer audit: {len(rows)} known vulnerabilities', '\n'.join([head] + rows[:30]))
    if abandoned:
        emit('warning', f'composer audit: {len(abandoned)} abandoned packages', ', '.join(sorted(abandoned)))


def npm(json_path: str, code: str) -> None:
    code = int(code)
    try:
        data = json.load(open(json_path, encoding='utf-8'))
    except Exception:
        data = None
    if data is None or 'error' in data or 'metadata' not in data:
        detail = json.dumps(data.get('error'))[:1200] if isinstance(data, dict) and 'error' in data else 'no parsable audit report'
        emit('error', 'npm audit COULD NOT RUN (command/infrastructure error, not a vulnerability result)', f'exit code {code}\n{detail}')
        return
    counts = data['metadata'].get('vulnerabilities', {})
    total = counts.get('total', 0)
    print(f'npm audit: {counts}')
    if total:
        rows = []
        for name, v in (data.get('vulnerabilities') or {}).items():
            via = [x['title'] for x in v.get('via', []) if isinstance(x, dict) and x.get('title')]
            rows.append(f"{name} | {v.get('severity')} | {(via[0] if via else 'transitive')[:110]} | fix: {'yes' if v.get('fixAvailable') else 'no'}")
        emit('error', f'npm audit: {total} known vulnerabilities', '\n'.join([json.dumps(counts)] + rows[:30]))


def npm_gate(json_path: str) -> None:
    """Blocking decision: exit 1 on high/critical vulnerabilities, 2 when the audit could not run."""
    try:
        v = json.load(open(json_path, encoding='utf-8'))['metadata']['vulnerabilities']
    except Exception:
        print('npm audit report missing or unreadable: failing the job')
        sys.exit(2)
    blocking = v.get('high', 0) + v.get('critical', 0)
    print(f'npm audit gate: {blocking} high/critical, {v.get("moderate", 0)} moderate, {v.get("low", 0)} low')
    sys.exit(1 if blocking else 0)


if __name__ == '__main__':
    if len(sys.argv) < 3 or sys.argv[1] not in ('junit', 'pint', 'tail', 'composer', 'npm', 'npm-gate'):
        print(__doc__)
        sys.exit(0)
    {'junit': junit, 'pint': pint, 'tail': tail, 'composer': composer, 'npm': npm, 'npm-gate': npm_gate}[sys.argv[1]](*sys.argv[2:])
