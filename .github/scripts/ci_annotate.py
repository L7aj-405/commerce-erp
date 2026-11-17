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


def _failure_detail(node, case_name: str):
    """PHPUnit's failure text is: <test id>, <message lines...>, <blank>, <file:line>.
    Returns (message, location, cause). `cause` is the underlying application exception that Laravel
    prints for a failed HTTP assertion ("The following exception occurred during the last request"),
    which is what explains a 500 / "Not a valid Inertia response"."""
    lines = [l.rstrip() for l in (node.text or node.get('message') or '').strip().splitlines()]
    body = [l for l in lines if l.strip()]
    if body and ('::' in body[0] or body[0].strip() == case_name):
        body = body[1:]  # drop the repeated test id
    location = body[-1] if body and re.search(r'\.php:\d+', body[-1]) else ''
    message = next((l for l in body if l != location), '')
    cause = ''
    for i, l in enumerate(body):
        if 'exception occurred during the last request' in l.lower():
            cause = next((x.strip() for x in body[i + 1:] if x.strip()), '')
            break
    if not cause:
        cause = next((l.strip() for l in body if re.match(r'^[\w\\]+(Exception|Error)\b[: ]', l.strip())), '')
    return message.strip(), location.strip(), cause


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
                name = f"{case.get('classname', '')}::{case.get('name', '')}"
                message, location, cause = _failure_detail(node, name)
                failures.append((name, kind, (node.get('type') or '').split(chr(92))[-1], message, location, cause))
    print(f'PHPUnit report: {total} test cases, {len(failures)} failing')
    if not failures:
        emit('notice', 'PHPUnit report', f'{total} test cases, 0 failing')
        return
    norm = lambda text, n: re.sub(r'\d+', 'N', text)[:n]
    grouped = Counter((t, norm(m, 90), norm(c, 200)) for _, _, t, m, _, c in failures)
    lines = [f'{len(failures)} failing of {total}. Most common causes (type: message | underlying exception):']
    lines += [f'  {n}x {t}: {m} | {c or "-"}' for (t, m, c), n in grouped.most_common(10)]
    lines.append('Examples (test -> message @ location):')
    seen = set()
    for name, kind, t, msg, loc, cause in failures:
        key = (name,) if len(failures) <= 30 else (t, norm(msg, 60), norm(cause, 80))
        if key in seen:
            continue
        seen.add(key)
        lines.append(f'  {name.split(chr(92))[-1]} -> {msg[:(200 if len(failures) <= 30 else 110)]} @ {loc[-60:]}')
        if len(seen) >= (30 if len(failures) <= 30 else 8):
            break
    emit('error', f'PHPUnit: {len(failures)} failing tests', chr(10).join(lines))


def pint(path: str) -> None:
    """Accepts both JSON shapes Pint can emit: {"files":[{"path","fixers"}]} and PHP-CS-Fixer's
    {"files":[{"name"}]} (what the CI runner produces); unknown shapes degrade to a count, never a crash."""
    try:
        data = json.load(open(path, encoding='utf-8'))
    except Exception as exc:
        emit('warning', 'Pint report unreadable', f'{path}: {exc}')
        return
    files = [f for f in (data.get('files') or []) if isinstance(f, dict)]
    print(f'Pint: {len(files)} files need formatting')
    if not files:
        return
    names = [str(f.get('path') or f.get('name') or '?').replace(chr(92), '/') for f in files]
    rules = Counter(rule for f in files for rule in (f.get('fixers') or []))
    lines = [f'{len(files)} PHP files do not match the Laravel Pint preset (legacy style debt; advisory).']
    if rules:
        lines.append('Most frequent rules: ' + ', '.join(f'{r} ({n})' for r, n in rules.most_common(8)))
    lines.append('Examples:')
    lines += [f'  {n}' for n in names[:15]]
    emit('warning', f'Pint: {len(files)} files need formatting', chr(10).join(lines))


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
    """Blocking decision for `composer audit --locked`. Exit status of THIS script is the job result:
      0  clean (abandoned packages only produce a warning)
      1  known vulnerabilities, OR the audit itself failed, OR the exit code and the report disagree.
    Composer's exit code is a bitmask: 1 = vulnerabilities, 2 = abandoned packages; anything else, or an
    unparsable report, is a command/infrastructure failure and must never be read as "clean"."""
    code = int(code)
    try:
        data = json.load(open(json_path, encoding='utf-8'))
    except Exception:
        data = None
    if not isinstance(data, dict) or code not in (0, 1, 2, 3):
        err = ''
        try:
            err = open(err_path, encoding='utf-8', errors='replace').read()[-1500:]
        except Exception:
            pass
        emit('error', 'composer audit COULD NOT RUN (command/infrastructure error, not a vulnerability result)',
             f'exit code {code}' + chr(10) + err)
        sys.exit(1)
    advisories = data.get('advisories') or {}
    if isinstance(advisories, list):
        advisories = {}
    abandoned = data.get('abandoned') or {}
    rows, severity = [], Counter()
    for package, items in advisories.items():
        for a in (items if isinstance(items, list) else [items]):
            severity[(a.get('severity') or 'unrated')] += 1
            rows.append(f"{package} | {a.get('severity') or 'unrated'} | {a.get('cve') or a.get('advisoryId')} | {(a.get('title') or '')[:110]}")
    print(f'composer audit: exit={code}, {len(rows)} advisories in {len(advisories)} packages, {len(abandoned)} abandoned')
    if abandoned:
        emit('warning', f'composer audit: {len(abandoned)} abandoned packages', ', '.join(sorted(abandoned)))
    if rows:
        head = f"{len(rows)} known vulnerabilities in {len(advisories)} locked packages ({', '.join(f'{n} {k}' for k, n in severity.most_common())}). Update the affected packages."
        emit('error', f'composer audit: {len(rows)} known vulnerabilities', chr(10).join([head] + rows[:30]))
        sys.exit(1)
    if code & 1:
        emit('error', 'composer audit reported vulnerabilities (exit code) but the report lists none', f'exit code {code}')
        sys.exit(1)
    emit('notice', 'composer audit: no known vulnerabilities', 'composer audit --locked reported no advisories.')


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
    try:
        {'junit': junit, 'pint': pint, 'tail': tail, 'composer': composer, 'npm': npm, 'npm-gate': npm_gate}[sys.argv[1]](*sys.argv[2:])
    except SystemExit:
        raise  # npm-gate decides the job result through its exit status
    except Exception as exc:  # the helper must never hide a result behind a traceback
        emit('error', f'ci_annotate {sys.argv[1]} failed', f'{type(exc).__name__}: {exc}')
