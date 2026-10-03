// The tests of the component kit, in the unit project of the repository's Vitest suite
// (vitest.config.ts, which `npm run test:js` runs whole in gate 5): `npm run test:kit` runs every
// file js/ui-kit/tests/*.test.js, and `npm run test:kit -- <name>...` the files named, such as
// `npm run test:kit -- tokens` for tokens.test.js. A name without a file exits 64 and lists the
// names there are. Each test is printed by name, and the exit code is Vitest's: 0 when every test
// passed.

import { spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join, relative } from 'node:path';
import process from 'node:process';

const directory = import.meta.dirname;
const root = join(directory, '../../..');
const SUFFIX = '.test.js';
const names = readdirSync(directory)
  .filter((file) => file.endsWith(SUFFIX))
  .map((file) => file.slice(0, -SUFFIX.length))
  .sort();
const asked = process.argv.slice(2);
const unknown = asked.filter((name) => !names.includes(name));

if (unknown.length > 0) {
  process.stderr.write(
    `No kit test named ${unknown.join(', ')}. The kit's tests are: ${names.join(', ')}.\n`,
  );
  process.exit(64);
}

const vitest = join(
  dirname(createRequire(import.meta.url).resolve('vitest/package.json')),
  'vitest.mjs',
);

const run = spawnSync(
  process.execPath,
  [
    vitest,
    'run',
    '--project=unit',
    '--reporter=verbose',
    ...(asked.length > 0 ? asked : names).map((name) =>
      relative(root, join(directory, name + SUFFIX)),
    ),
  ],
  { cwd: root, stdio: 'inherit' },
);

process.exit(run.status ?? 1);
