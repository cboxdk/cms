#!/usr/bin/env node

// The bin of @cboxdk/cms-panel for an addon's repository: `cms-panel-addon verify` holds the
// committed bundle to what the build writes (section 4.5 of the panel extension architecture,
// decision D7). The logic is in ../verify.js, which the SDK's tests run.

import process from 'node:process';

import { main } from '../verify.js';

process.exitCode = await main(process.argv.slice(2), process.cwd(), {
  out: (line) => {
    process.stdout.write(`${line}\n`);
  },
  error: (line) => {
    process.stderr.write(`${line}\n`);
  },
});
