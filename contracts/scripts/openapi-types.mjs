// Emits TypeScript request/response types from the OpenAPI document.
//
// The OpenAPI file is generated from routes/api.php by `php artisan brain:openapi`,
// so this is a second-order generator: routes -> schema -> types. Nothing here is
// hand-authored, and dist/ is disposable output.
//
// THE ONE RULE: an operation whose shape the PHP generator could not derive is
// marked `x-unverified: true`, and it is emitted here as `unknown` — never as
// `any`, and never as an invented interface. `unknown` forces the consumer to
// narrow before use; `any` would let unverified data flow through the SPA
// wearing a type it never earned, which is exactly the lie this seam exists to
// prevent.
import { load as yamlLoad } from 'js-yaml';
import { readFileSync, writeFileSync, mkdirSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

export function generateOperationTypes() {
  const doc = yamlLoad(readFileSync(resolve(root, 'openapi/hpbrain.openapi.yaml'), 'utf8'));

  const lines = [
    '/* eslint-disable */',
    '/**',
    ' * AUTO-GENERATED from openapi/hpbrain.openapi.yaml — DO NOT EDIT BY HAND.',
    ' * Regenerate with: php artisan brain:openapi && npm run generate',
    ' *',
    ' * `unknown` marks a shape the API could not verify. Narrow it before use.',
    ' */',
    '',
  ];

  const operations = [];
  let unverifiedResponses = 0;
  let unverifiedRequests = 0;

  for (const [path, methods] of Object.entries(doc.paths)) {
    for (const [method, op] of Object.entries(methods)) {
      const name = pascal(op.operationId);

      const requestBody = op.requestBody;
      let requestType = 'void';

      if (requestBody) {
        if (requestBody['x-unverified']) {
          unverifiedRequests++;
          requestType = 'unknown';
          lines.push(`/** UNVERIFIED: no validate() in ${op['x-controller']}; body shape not derivable. */`);
        } else {
          requestType = tsFromSchema(requestBody.content['application/json'].schema, 1);
        }
        lines.push(`export type ${name}Request = ${requestType};`);
      }

      const success = op.responses['200'] ?? op.responses['201'];
      let responseType = 'unknown';

      if (success?.['x-unverified']) {
        unverifiedResponses++;
        lines.push(`/** UNVERIFIED: ${op['x-controller']} returns a raw database row. */`);
      } else if (success?.content?.['application/json']?.schema) {
        responseType = tsFromSchema(success.content['application/json'].schema, 1);
      }

      lines.push(`export type ${name}Response = ${responseType};`);

      // The error codes ARE derived, so they are worth a real union: a client
      // that switches on them cannot invent a code the API never returns.
      const errorCodes = Object.entries(op.responses)
        .filter(([status]) => status.startsWith('4') || status.startsWith('5'))
        .flatMap(([, r]) => r?.content?.['application/json']?.schema?.properties?.error?.enum ?? []);

      if (errorCodes.length) {
        lines.push(`export type ${name}Error = ${[...new Set(errorCodes)].map((c) => `'${c}'`).join(' | ')};`);
      }

      lines.push('');

      operations.push({
        name,
        method: method.toUpperCase(),
        path,
        permissions: op['x-permissions'] ?? [],
        controller: op['x-controller'],
        unverifiedResponse: Boolean(success?.['x-unverified']),
      });
    }
  }

  // The operation table is data, not types: the drift checker reads it to
  // compare what the SPA calls against what the API actually exposes.
  lines.push('/** Every operation the API exposes, for tooling. */');
  lines.push(`export const OPERATIONS = ${JSON.stringify(operations, null, 2)} as const;`);
  lines.push('');

  mkdirSync(resolve(root, 'dist'), { recursive: true });
  writeFileSync(resolve(root, 'dist/api.d.ts'), lines.join('\n'));
  writeFileSync(resolve(root, 'dist/operations.json'), JSON.stringify(operations, null, 2) + '\n');

  console.log(`  [OK] openapi -> dist/api.d.ts (${operations.length} operations)`);
  console.log(`       unverified request bodies:   ${unverifiedRequests}`);
  console.log(`       unverified response bodies:  ${unverifiedResponses}`);

  return { operations, unverifiedRequests, unverifiedResponses };
}

function tsFromSchema(schema, depth) {
  if (!schema || schema['x-unverified']) return 'unknown';

  const pad = '  '.repeat(depth);
  const padEnd = '  '.repeat(depth - 1);

  switch (schema.type) {
    case 'object': {
      if (!schema.properties) return 'Record<string, unknown>';
      const required = new Set(schema.required ?? []);
      const fields = Object.entries(schema.properties).map(([key, value]) => {
        const optional = required.has(key) ? '' : '?';
        return `${pad}${key}${optional}: ${tsFromSchema(value, depth + 1)};`;
      });
      return `{\n${fields.join('\n')}\n${padEnd}}`;
    }
    case 'array':
      return `Array<${tsFromSchema(schema.items, depth)}>`;
    case 'integer':
    case 'number':
      return schema.enum ? schema.enum.join(' | ') : 'number';
    case 'boolean':
      return 'boolean';
    default:
      if (schema.enum) return schema.enum.map((v) => `'${v}'`).join(' | ');
      return 'string';
  }
}

function pascal(id) {
  return id
    .split(/[^a-zA-Z0-9]/)
    .filter(Boolean)
    .map((part) => part[0].toUpperCase() + part.slice(1))
    .join('');
}
