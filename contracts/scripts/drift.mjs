// Reports how far the hand-written SPA clients have drifted from the API.
//
// WHY THIS IS A STRUCTURAL CHECK AND NOT A TYPE CHECK.
//
// The brief asked for a type-check asserting web/src/api/*.ts is assignable to
// the generated types. That check would pass unconditionally and prove nothing:
// every client module goes through `request()` in web/src/api/client.ts, whose
// signature is `Promise<any>`. Everything is assignable to `any` and `any` is
// assignable to everything, so a green result would mean "the clients are
// untyped", not "the clients agree with the API". Reporting that as a pass
// would be exactly the kind of green board this project keeps finding.
//
// So the comparison is made on the thing that IS knowable from both sides: the
// method and path of every call the SPA makes, against the operations the API
// actually exposes. That finds the failures that matter — a client calling an
// endpoint that does not exist, or reaching one with the wrong verb — which is
// the class of defect that had the Event Store screen 404ing and
// `approveDecision` posting to a path with a backslash in it.
import { readFileSync, readdirSync, existsSync } from 'fs';
import { resolve, dirname } from 'path';
import { fileURLToPath } from 'url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const webApiDir = resolve(root, '../web/src/api');

/**
 * Replaces every `${...}` interpolation with `{}`, matching braces properly.
 *
 * A regex cannot do this: the SPA writes `${status ? `?status=${status}` : ''}`,
 * where the interpolation contains quotes, a nested template literal and its
 * own braces. A `[^}]*` pattern stops at the first inner `}` and reports a
 * perfectly good call as an endpoint that does not exist — which is worse than
 * useless in a drift report, because a false positive here costs someone an
 * afternoon proving the API is fine.
 */
function collapseInterpolations(path) {
  let out = '';

  for (let i = 0; i < path.length; i++) {
    if (path[i] === '$' && path[i + 1] === '{') {
      let depth = 1;
      i += 2;
      while (i < path.length && depth > 0) {
        if (path[i] === '{') depth++;
        else if (path[i] === '}') depth--;
        i++;
      }
      i--;
      out += '{}';
      continue;
    }
    out += path[i];
  }

  return out;
}

/** Turns `/decisions/${tenantId}/${id}/approve` into `/decisions/{}/{}/approve`. */
function normalise(path) {
  let normalised = collapseInterpolations(path)
    .replace(/\{[a-zA-Z0-9_]+\}/g, '{}') // OpenAPI path parameters
    .replace(/\?.*$/, '');               // literal query string

  // A placeholder that does NOT follow a slash is a query-string suffix, not a
  // path segment: `/signals/${tenantId}${qs}` addresses /signals/{tenantId}.
  // Distinguishing the two by the separator is what keeps a conditional
  // `?status=` from looking like an extra path parameter.
  let previous;
  do {
    previous = normalised;
    normalised = normalised.replace(/([^/]){}/g, '$1');
  } while (normalised !== previous);

  return normalised.replace(/\/+$/, '') || '/';
}

export function checkDrift(operations) {
  if (!existsSync(webApiDir)) {
    console.log('  [SKIP] web/src/api not present — drift check needs the SPA checked out.');
    return { calls: [], unknown: [], uncovered: [], methodMismatch: [] };
  }

  const apiPaths = new Map();
  for (const op of operations) {
    const key = normalise(op.path);
    if (!apiPaths.has(key)) apiPaths.set(key, new Set());
    apiPaths.get(key).add(op.method);
  }

  const calls = [];

  for (const file of readdirSync(webApiDir).filter((f) => f.endsWith('.ts'))) {
    const source = readFileSync(resolve(webApiDir, file), 'utf8');

    // request('/path', { method: 'POST' }) and request(`/path/${x}`).
    //
    // Two things this pattern has to get right. A backtick string may contain
    // quotes and braces freely, so only an unescaped backtick may end it — a
    // shared [^`'"]* class truncates the path at the first apostrophe inside an
    // interpolation. And the options object is read through a LOOKAHEAD rather
    // than captured: a consuming pattern for `{ ... }` spans newlines and
    // swallows the next request() call along with it, which silently halved the
    // number of calls this file thought existed.
    const pattern = /request\(\s*(?:`((?:[^`\\]|\\.)*)`|'([^']*)'|"([^"]*)")(?=([\s\S]{0,300}))/g;

    for (const match of source.matchAll(pattern)) {
      const rawPath = match[1] ?? match[2] ?? match[3] ?? '';
      // Only the remainder of THIS call: stop at the first `)` that closes it.
      const window = (match[4] ?? '').split(/\)\s*[,;\n]/)[0];
      const methodMatch = window.match(/method:\s*['"](\w+)['"]/);

      calls.push({
        file,
        rawPath,
        path: normalise(rawPath),
        method: methodMatch ? methodMatch[1].toUpperCase() : 'GET',
      });
    }
  }

  const unknown = [];
  const methodMismatch = [];
  const called = new Set();

  for (const call of calls) {
    const methods = apiPaths.get(call.path);

    if (!methods) {
      unknown.push(call);
      continue;
    }

    if (!methods.has(call.method)) {
      methodMismatch.push({ ...call, apiAllows: [...methods].join(', ') });
      continue;
    }

    called.add(`${call.method} ${call.path}`);
  }

  const uncovered = operations
    .filter((op) => !called.has(`${op.method} ${normalise(op.path)}`))
    .map((op) => ({ method: op.method, path: op.path, controller: op.controller }));

  return { calls, unknown, uncovered, methodMismatch };
}

export function reportDrift(drift) {
  console.log('');
  console.log('  ---- SPA client drift ----------------------------------------');
  console.log(`  calls found in web/src/api:        ${drift.calls.length}`);
  console.log(`  calling an endpoint that does not exist: ${drift.unknown.length}`);
  console.log(`  calling with the wrong HTTP method:      ${drift.methodMismatch.length}`);
  console.log(`  API operations no client calls:          ${drift.uncovered.length}`);

  for (const call of drift.unknown) {
    console.log(`    [NO SUCH ENDPOINT] ${call.method} ${call.rawPath}  (${call.file})`);
  }

  for (const call of drift.methodMismatch) {
    console.log(`    [WRONG METHOD]     ${call.method} ${call.rawPath}  (${call.file}) — API allows ${call.apiAllows}`);
  }

  console.log('  ----------------------------------------------------------------');
  console.log('');
}
