// Run: node --test tests/Frontend/business-date-format.test.ts
import assert from 'node:assert/strict';
import { afterEach, test } from 'node:test';
import { formatBusinessDate } from '../../resources/js/utils/format.ts';

const setLang = (lang: string) => {
    (globalThis as { document?: unknown }).document = { documentElement: { lang } };
};
afterEach(() => {
    delete (globalThis as { document?: unknown }).document;
});

test('a technical ISO timestamp is shown as the calendar day, never with time or timezone', () => {
    setLang('fr');
    assert.equal(formatBusinessDate('2026-11-24T00:00:00.000000Z'), '24/11/2026');
    assert.equal(formatBusinessDate('2026-11-24'), '24/11/2026');
});

test('the day never shifts with the process timezone', () => {
    setLang('fr');
    const previous = process.env.TZ;
    process.env.TZ = 'America/Los_Angeles';
    try {
        assert.equal(formatBusinessDate('2026-11-24T00:00:00.000000Z'), '24/11/2026');
    } finally {
        if (previous === undefined) delete process.env.TZ;
        else process.env.TZ = previous;
    }
});

test('missing or malformed values render the fallback instead of throwing', () => {
    setLang('fr');
    assert.equal(formatBusinessDate(null), '—');
    assert.equal(formatBusinessDate(''), '—');
    assert.equal(formatBusinessDate('Unverified'), '—');
});
