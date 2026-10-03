// What the SDK's tests share: the root of the repository, and a TypeScript program over probe
// files written below .cache/ with the repository's compiler options, so a test can name
// the error tsc gives a line of an addon's code.

import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import process from 'node:process';

import ts from 'typescript';

/** The root of the repository. */
export const ROOT = join(import.meta.dirname, '../../..');

/** How many probes this process compiled, so each has a directory of its own. */
let probes = 0;

/**
 * @typedef {object} ProbeError
 * @property {number} line the probe's line, from 1
 * @property {number} code TypeScript's code of the error, such as 2353
 * @property {string} message the whole message chain, joined by newlines
 */

/**
 * Compiles the probe files, written into a directory of their own below .cache/, with the
 * repository's compiler options, and gives the errors of the first file, then removes them.
 *
 * @param {Record<string, string>} files the probes by file name; the first is the one checked
 * @returns {ProbeError[]}
 */
export function compileProbe(files) {
  const directory = join(
    ROOT,
    '.cache/panel-sdk-probes',
    `${String(process.pid)}-${String(++probes)}`,
  );
  const read = ts.readConfigFile(join(ROOT, 'tsconfig.json'), (file) => ts.sys.readFile(file));
  const options = ts.parseJsonConfigFileContent(read.config, ts.sys, ROOT).options;
  const names = Object.keys(files);

  mkdirSync(directory, { recursive: true });

  try {
    for (const [name, text] of Object.entries(files)) {
      writeFileSync(join(directory, name), text);
    }

    const checked = join(directory, names[0] ?? '');
    const program = ts.createProgram([checked], { ...options, noEmit: true });
    const source = program.getSourceFile(checked);

    return ts
      .getPreEmitDiagnostics(program, source)
      .filter(
        (diagnostic) =>
          diagnostic.file !== undefined &&
          relative(directory, diagnostic.file.fileName) === names[0],
      )
      .map((diagnostic) => ({
        line:
          diagnostic.file === undefined || diagnostic.start === undefined
            ? 0
            : diagnostic.file.getLineAndCharacterOfPosition(diagnostic.start).line + 1,
        code: diagnostic.code,
        message: ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n'),
      }));
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
}
