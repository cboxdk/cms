// Runs the client validation of the component kit's EntryForm against the fields of entries, for
// the Codecs suite (GUARDRAILS 9, PRD 11.8): the modules of the kit's entry form that hold no
// React, js/ui-kit/src/components/entry-form/*.ts, and the TypeScript of the kernel contracts they
// read, js/ui-kit/src/generated/protocol, are compiled as they are, with the TypeScript the JS
// toolchain pins, to CommonJS in a directory of their own, and validateEntry() is called with a
// type editor descriptor on the fields of each case.
//
// Usage: node --experimental-strip-types run-entry-form.ts, with {"descriptor": <the descriptor
// as type-editor.v1.json writes it>, "cases": [{"fields": <the fields>, "stage": "write" or
// "release"}, ...]} on stdin. It prints a JSON list of the issues of each case, in order, each a
// list of {"path": <string or null>, "code": <the error catalog's code>}, and exits 0, or exits 1
// with the reason on stderr when a module does not compile.

import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import process from 'node:process';
import ts from 'typescript';

/** The kit's sources, relative to the root, and the directories of them that are compiled. */
const KIT = 'js/ui-kit/src';
const DIRECTORIES = ['generated/protocol', 'components/entry-form'];

interface EntryCase {
  readonly fields: unknown;
  readonly stage: 'write' | 'release';
}

interface Issue {
  readonly path: string | null;
  readonly code: string;
}

type ValidateEntry = (descriptor: unknown, fields: unknown, stage: string) => readonly Issue[];

/** Compiles each module without React below the directories to CommonJS below the output. */
function compile(root: string, output: string): void {
  for (const directory of DIRECTORIES) {
    const source = join(root, KIT, directory);
    const paths = readdirSync(source)
      .filter((path) => path.endsWith('.ts'))
      .sort();

    for (const path of paths) {
      const result = ts.transpileModule(readFileSync(join(source, path), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2023 },
        fileName: path,
        reportDiagnostics: true,
      });

      if (result.diagnostics !== undefined && result.diagnostics.length > 0) {
        throw new Error(`${directory}/${path} does not compile.`);
      }

      const target = join(output, directory, path.replace(/\.ts$/, '.js'));
      mkdirSync(dirname(target), { recursive: true });
      writeFileSync(target, result.outputText);
    }
  }
}

const root = join(import.meta.dirname, '../../..');
/**
 * Standard input, read to its end: a large input does not arrive in one read, and a synchronous
 * read of a pipe that has no data yet fails with EAGAIN.
 */
async function standardInput(): Promise<string> {
  const chunks: Buffer[] = [];

  for await (const chunk of process.stdin) {
    chunks.push(Buffer.isBuffer(chunk) ? chunk : Buffer.from(String(chunk)));
  }

  return Buffer.concat(chunks).toString('utf8');
}

const input = JSON.parse(await standardInput()) as {
  readonly descriptor: unknown;
  readonly cases: readonly EntryCase[];
};
const output = mkdtempSync(join(tmpdir(), 'cms-entry-form-'));

try {
  compile(root, output);
  const loaded = createRequire(join(output, 'index.js'))('./components/entry-form/rules.js') as {
    readonly validateEntry?: unknown;
  };

  if (typeof loaded.validateEntry !== 'function') {
    throw new Error('components/entry-form/rules has no function validateEntry.');
  }

  const validateEntry = loaded.validateEntry as ValidateEntry;
  process.stdout.write(
    JSON.stringify(
      input.cases.map((entryCase) =>
        validateEntry(input.descriptor, entryCase.fields, entryCase.stage).map((issue) => ({
          path: issue.path,
          code: issue.code,
        })),
      ),
    ),
  );
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exitCode = 1;
} finally {
  rmSync(output, { recursive: true, force: true });
}
