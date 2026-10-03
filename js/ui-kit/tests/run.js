// The tests of the component kit, on Node's own test runner: `npm run test:kit` runs every file
// js/ui-kit/tests/*.test.js, and `npm run test:kit -- <name>...` the files named, such as
// `npm run test:kit -- tokens` for tokens.test.js. A name without a file exits 64 and lists the
// names there are. The exit code is the test runner's: 0 when every test passed.

import { spawnSync } from 'node:child_process';
import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import process from 'node:process';

const directory = import.meta.dirname;
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

const run = spawnSync(
  process.execPath,
  [
    '--test',
    '--test-reporter=spec',
    ...(asked.length > 0 ? asked : names).map((name) => join(directory, name + SUFFIX)),
  ],
  { stdio: 'inherit' },
);

process.exit(run.status ?? 1);
