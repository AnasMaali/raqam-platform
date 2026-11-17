// Writes the counts of the last query-budget run (test-results/query-counts.jsonl) as the budgets in
// qa/query-budgets.json: after a deliberate change only (docs/qa/performance.md). Run from e2e/: node qa/query-budgets.mjs
import { readFileSync, writeFileSync } from 'node:fs';

const counts = new URL('../test-results/query-counts.jsonl', import.meta.url);
const budgets = new URL('./query-budgets.json', import.meta.url);
const measured = Object.fromEntries(
    readFileSync(counts, 'utf8')
        .split('\n')
        .filter(Boolean)
        .map((line) => JSON.parse(line))
        .map(({ key, count }) => [key, count]),
);
const sorted = Object.fromEntries(Object.entries(measured).sort(([a], [b]) => a.localeCompare(b, 'en')));
writeFileSync(budgets, JSON.stringify(sorted, null, 4) + '\n');
console.log(`${Object.keys(sorted).length} budgets written to qa/query-budgets.json`);
