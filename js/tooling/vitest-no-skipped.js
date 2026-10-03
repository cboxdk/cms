// A Vitest reporter that fails a run with a skipped test (GUARDRAILS 11: no skipped tests; the
// Pest suites of gate 5 run with --fail-on-skipped for the same reason). Vitest itself passes a run
// whose tests were skipped with skip, only or the to-do flag, so the JS suites of the gates add this
// reporter next to Vitest's own: `vitest run --reporter=default --reporter=./js/tooling/vitest-no-skipped.js`.
// It prints each skipped test with its file on standard error and sets the exit code to 1.

import process from 'node:process';

/** @typedef {import('vitest/reporters').Reporter} Reporter */

/** @implements {Reporter} */
export default class NoSkippedTests {
  /** @param {ReadonlyArray<import('vitest/node').TestModule>} testModules */
  onTestRunEnd(testModules) {
    /** @type {string[]} */
    const skipped = [];

    for (const testModule of testModules) {
      for (const test of testModule.children.allTests('skipped')) {
        skipped.push(`${testModule.relativeModuleId}: ${test.fullName} (${test.options.mode})`);
      }
    }

    if (skipped.length > 0) {
      process.stderr.write(
        `\nA gate fails a skipped test (GUARDRAILS 11), and these were skipped:\n${skipped.map((line) => `  ${line}\n`).join('')}`,
      );
      process.exitCode = 1;
    }
  }
}
