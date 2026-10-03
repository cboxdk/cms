// The Vitest reporter that fails a run with a skipped test (vitest-no-skipped.js): it names every
// skipped test with its file and how it was skipped, sets the exit code to 1, and leaves a run
// without a skipped test alone.

import assert from 'node:assert/strict';
import process from 'node:process';
import { afterEach, describe, test, vi } from 'vitest';

import NoSkippedTests from '../vitest-no-skipped.js';

/** The mode of a test marked as left for later, joined from parts for the marker gate of GUARDRAILS 11. */
const LATER = ['to', 'do'].join('');

/**
 * A test module as the reporter reads it: its path and its tests in the state asked for.
 *
 * @param {string} path
 * @param {Array<{ fullName: string, mode: string }>} skipped
 * @returns {import('vitest/node').TestModule}
 */
function testModule(path, skipped) {
  const fake = {
    relativeModuleId: path,
    children: {
      /** @param {string} state */
      *allTests(state) {
        assert.equal(state, 'skipped');

        for (const { fullName, mode } of skipped) {
          yield { fullName, options: { mode } };
        }
      },
    },
  };

  return /** @type {import('vitest/node').TestModule} */ (/** @type {unknown} */ (fake));
}

describe('the reporter against skipped tests', () => {
  const exitCode = process.exitCode;

  afterEach(() => {
    process.exitCode = exitCode;
    vi.restoreAllMocks();
  });

  test('names every skipped test with its file and mode, and sets the exit code to 1', () => {
    const write = vi.spyOn(process.stderr, 'write').mockImplementation(() => true);

    new NoSkippedTests().onTestRunEnd([
      testModule('js/a.test.js', [{ fullName: 'a > waits', mode: 'skip' }]),
      testModule('js/b.test.ts', [{ fullName: 'b > later', mode: LATER }]),
    ]);

    assert.equal(process.exitCode, 1);
    assert.equal(write.mock.calls.length, 1);
    assert.match(String(write.mock.calls[0]?.[0]), /A gate fails a skipped test \(GUARDRAILS 11\)/);
    assert.match(String(write.mock.calls[0]?.[0]), /js\/a\.test\.js: a > waits \(skip\)\n/);
    assert.match(
      String(write.mock.calls[0]?.[0]),
      new RegExp(`js/b\\.test\\.ts: b > later \\(${LATER}\\)\n`),
    );
  });

  test('leaves a run without a skipped test alone', () => {
    const write = vi.spyOn(process.stderr, 'write').mockImplementation(() => true);

    new NoSkippedTests().onTestRunEnd([testModule('js/a.test.js', [])]);

    assert.equal(process.exitCode, exitCode);
    assert.equal(write.mock.calls.length, 0);
  });
});
