// Prints the full drift detail. `npm run drift` — separate from `generate` so
// the generator's output stays short and this can be read when someone is
// actually planning the client migration.
import { readFileSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';
import { checkDrift } from './drift.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const operations = JSON.parse(readFileSync(resolve(root, 'dist/operations.json'), 'utf8'));
const drift = checkDrift(operations);

const short = (controller) => String(controller).split('\\').pop();

console.log(`\ncalls found in web/src/api: ${drift.calls.length}`);
console.log(`endpoints called that do not exist: ${drift.unknown.length}`);
console.log(`calls using the wrong HTTP method:  ${drift.methodMismatch.length}`);
console.log(`API operations with no client:      ${drift.uncovered.length}\n`);

if (drift.unknown.length) {
  console.log('CALLS TO ENDPOINTS THAT DO NOT EXIST');
  for (const c of drift.unknown) console.log(`  ${c.method.padEnd(6)} ${c.rawPath}   (${c.file})`);
  console.log('');
}

if (drift.methodMismatch.length) {
  console.log('WRONG HTTP METHOD');
  for (const c of drift.methodMismatch) {
    console.log(`  ${c.method.padEnd(6)} ${c.rawPath}   (${c.file}) — API allows ${c.apiAllows}`);
  }
  console.log('');
}

if (drift.uncovered.length) {
  console.log('API OPERATIONS NO SPA CLIENT CALLS');
  for (const o of drift.uncovered) {
    console.log(`  ${o.method.padEnd(6)} ${o.path.padEnd(52)} ${short(o.controller)}`);
  }
  console.log('');
}
