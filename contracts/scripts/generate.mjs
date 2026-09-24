// Generates TypeScript from the YAML schemas.
// The schemas are the source of truth. dist/ is disposable output.
import { compile } from 'json-schema-to-typescript';
import { load as yamlLoad } from 'js-yaml';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'fs';
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';
import { generateOperationTypes } from './openapi-types.mjs';
import { checkDrift, reportDrift } from './drift.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const targets = [
  ['eso/eso.schema.yaml',            'ESOContract'],
  ['taxonomy/root-cause.schema.yaml', 'RootCauseFamily'],
];

mkdirSync(resolve(root, 'dist'), { recursive: true });
const exports = [];

for (const [src, name] of targets) {
  const schema = yamlLoad(readFileSync(resolve(root, src), 'utf8'));
  schema.title = name; // force the interface name; the prose title is docs, not an identifier
  const ts = await compile(schema, name, {
    bannerComment:
      `/* eslint-disable */\n` +
      `/**\n * AUTO-GENERATED from ${src}\n * DO NOT EDIT BY HAND.\n` +
      ` * Change the schema, then run: npm run generate\n */`,
    additionalProperties: false,
  });
  writeFileSync(resolve(root, `dist/${name}.d.ts`), ts);
  exports.push(`export * from './${name}';`);
  console.log(`  [OK] ${src} -> dist/${name}.d.ts`);
}

// The API surface, generated from the OpenAPI file that `php artisan
// brain:openapi` generated from routes/api.php. Skipped rather than fatal when
// the schema is absent, so `npm run generate` still works in a checkout where
// PHP has not been run — CI runs the artisan command first.
if (existsSync(resolve(root, 'openapi/hpbrain.openapi.yaml'))) {
  const { operations } = generateOperationTypes();
  exports.push(`export * from './api';`);

  reportDrift(checkDrift(operations));
} else {
  console.log('  [SKIP] openapi/hpbrain.openapi.yaml absent — run: php artisan brain:openapi');
}

writeFileSync(resolve(root, 'dist/index.d.ts'),
  `/* AUTO-GENERATED. DO NOT EDIT. */\n${exports.join('\n')}\n`);
console.log('  [OK] dist/index.d.ts');
