// Runs the TypeScript validators that cms:generate writes against JSON documents, for the Codecs
// suite (GUARDRAILS 9): the modules of a generated directory are compiled as they are, with the
// TypeScript the JS toolchain pins, to CommonJS in a directory of their own, the same modules a
// browser loads through a bundler, and each case's validator is called on its document.
//
// Usage: node --experimental-strip-types run-validators.ts <generated directory>, with a JSON list
// of cases on stdin, each {"module": "records/AppFixtureArticleV1", "validator":
// "validateAppFixtureArticleV1", "document": "<JSON text>"}. It prints a JSON list of verdicts in
// the same order, each {"valid": true} or {"valid": false, "path": <string or null>, "reason":
// <string>}, and exits 0, or exits 1 with the reason on stderr when a module does not compile or
// has no such validator.

import { mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import process from 'node:process';
import ts from 'typescript';

interface ValidatorCase {
  readonly module: string;
  readonly validator: string;
  readonly document: string;
}

type Verdict =
  | { readonly valid: true }
  | { readonly valid: false; readonly path: string | null; readonly reason: string };

type Validation =
  | { readonly valid: true }
  | {
      readonly valid: false;
      readonly issue: { readonly path: string | null; readonly reason: string };
    };

type Validator = (value: unknown) => Validation;

/** Compiles each module below the directory to CommonJS below the output directory. */
function compile(directory: string, output: string): void {
  const paths = readdirSync(directory, { recursive: true, encoding: 'utf8' })
    .filter((path) => path.endsWith('.ts'))
    .sort();

  for (const path of paths) {
    const result = ts.transpileModule(readFileSync(join(directory, path), 'utf8'), {
      compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2023 },
      fileName: path,
      reportDiagnostics: true,
    });

    if (result.diagnostics !== undefined && result.diagnostics.length > 0) {
      throw new Error(`${path} does not compile.`);
    }

    const target = join(output, path.replace(/\.ts$/, '.js'));
    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, result.outputText);
  }
}

function run(load: (id: string) => unknown, validatorCase: ValidatorCase): Verdict {
  const loaded = load(`./${validatorCase.module}.js`);
  const validator =
    typeof loaded === 'object' && loaded !== null
      ? (loaded as Record<string, unknown>)[validatorCase.validator]
      : undefined;

  if (typeof validator !== 'function') {
    throw new Error(`${validatorCase.module} has no function ${validatorCase.validator}.`);
  }

  const validation = (validator as Validator)(JSON.parse(validatorCase.document) as unknown);

  return validation.valid
    ? { valid: true }
    : { valid: false, path: validation.issue.path, reason: validation.issue.reason };
}

const directory = process.argv[2];

if (directory === undefined) {
  process.stderr.write('Usage: node run-validators.ts <generated directory> < cases.json\n');
  process.exit(2);
}

const cases = JSON.parse(readFileSync(0, 'utf8')) as ValidatorCase[];
const output = mkdtempSync(join(tmpdir(), 'cms-validators-'));

try {
  compile(directory, output);
  const load = createRequire(join(output, 'index.js'));
  process.stdout.write(JSON.stringify(cases.map((validatorCase) => run(load, validatorCase))));
} catch (error) {
  process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
  process.exitCode = 1;
} finally {
  rmSync(output, { recursive: true, force: true });
}
