// The API report of the component kit (panel extension architecture, sections 2.2 and 6: each
// export carries a TSDoc stability tag, and an api-extractor report is committed as a golden file).
//
// The report, js/ui-kit/api/cms-ui-kit.api.md, lists every name the kit's entry exports with its
// declaration, so a change to what the kit offers shows up in a review as a change of that file.
// It is made in three steps: TypeScript writes the declarations of js/ui-kit/src to
// .cache/ui-kit-api/types, API Extractor reads them from index.d.ts and writes its report, and
// the release tag API Extractor gives every export, @public, is replaced by the export's own
// stability tag, @stable or @experimental, which API Extractor does not know.
//
// `npm run api:report` writes the report anew after an intended change. Without --update the
// script only compares, and exits 1 when the report it makes differs from the committed one, which
// a test of gate 5 does too (js/ui-kit/tests/api.test.js). The same run checks that every export
// carries exactly one stability tag, @stable or @experimental (stabilities).
//
// `node js/ui-kit/scripts/api-report.js [--update] [--root=<dir>]`

import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { basename, dirname, join, relative, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { Extractor, ExtractorConfig, ExtractorLogLevel } from '@microsoft/api-extractor';
import ts from 'typescript';

/** The report, relative to the root. */
export const REPORT = 'js/ui-kit/api/cms-ui-kit.api.md';

/** The kit's entry, relative to the root. */
export const ENTRY = 'js/ui-kit/src/index.ts';

/** Where the declarations and API Extractor's own files go, relative to the root; git ignores it. */
export const WORK = '.cache/ui-kit-api';

/** What the kit's stylesheet imports are to TypeScript, relative to the root. */
const CSS_DECLARATIONS = 'js/ui-kit/src/css.d.ts';

/** The stability tags; an export carries exactly one. */
export const STABILITY_TAGS = ['@stable', '@experimental'];

/**
 * @typedef {object} ApiReport
 * @property {boolean} changed whether the report made differs from the committed one
 * @property {string[]} problems what kept the report from being made, and exports without a tag
 */

/**
 * The compiler options the declarations are written with: the repository's, so the declarations
 * are what the gates check, with declaration output into the work directory.
 *
 * @param {string} root
 * @param {string} work the absolute work directory
 * @returns {ts.CompilerOptions}
 */
function compilerOptions(root, work) {
  const path = join(root, 'tsconfig.json');
  const read = ts.readConfigFile(path, (file) => ts.sys.readFile(file));

  if (read.error !== undefined) {
    throw new Error(ts.flattenDiagnosticMessageText(read.error.messageText, '\n'));
  }

  const parsed = ts.parseJsonConfigFileContent(read.config, ts.sys, root);

  return {
    ...parsed.options,
    noEmit: false,
    allowJs: false,
    checkJs: false,
    declaration: true,
    emitDeclarationOnly: true,
    rootDir: join(root, 'js/ui-kit/src'),
    outDir: join(work, 'types'),
    types: [],
  };
}

/**
 * Writes the declarations of the kit's entry and everything it imports, and copies the JSON
 * catalogues they import.
 *
 * @param {string} root
 * @param {string} work the absolute work directory
 * @returns {string[]} what kept the declarations from being written
 */
function writeDeclarations(root, work) {
  const options = compilerOptions(root, work);
  const program = ts.createProgram([join(root, ENTRY), join(root, CSS_DECLARATIONS)], options);
  const result = program.emit();
  const diagnostics = [...ts.getPreEmitDiagnostics(program), ...result.diagnostics];
  const source = join(root, 'js/ui-kit/src/i18n/catalogues');
  const target = join(work, 'types/i18n/catalogues');

  mkdirSync(target, { recursive: true });

  for (const file of readdirSync(source).filter((name) => name.endsWith('.json'))) {
    copyFileSync(join(source, file), join(target, file));
  }

  copyFileSync(join(root, CSS_DECLARATIONS), join(work, 'types/css.d.ts'));

  return diagnostics.map((diagnostic) => {
    const message = ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n');

    if (diagnostic.file === undefined || diagnostic.start === undefined) {
      return message;
    }

    const { line } = diagnostic.file.getLineAndCharacterOfPosition(diagnostic.start);

    return `${relative(root, diagnostic.file.fileName)}:${String(line + 1)}: ${message}`;
  });
}

/**
 * The tags of a declaration's TSDoc: the names of the block and modifier tags, such as @stable.
 *
 * @param {ts.Node} node
 * @returns {string[]}
 */
function tagsOf(node) {
  return ts.getJSDocTags(node).map((tag) => `@${tag.tagName.text}`);
}

/**
 * The stability tag of each export of the kit's entry, and each export that does not carry exactly
 * one in the TSDoc of its declaration, as `<file>:<line>: <name> ...`.
 *
 * @param {string} root
 * @param {string} entryPath the absolute path of the entry; the kit's by default
 * @returns {{ tags: Map<string, string>, problems: string[] }}
 */
export function stabilities(root, entryPath = join(root, ENTRY)) {
  const program = ts.createProgram([entryPath, join(root, CSS_DECLARATIONS)], {
    ...compilerOptions(root, join(root, WORK)),
    noEmit: true,
  });
  const checker = program.getTypeChecker();
  const entry = program.getSourceFile(entryPath);

  /** @type {Map<string, string>} */
  const found = new Map();

  if (entry === undefined) {
    return { tags: found, problems: [`${relative(root, entryPath)}: cannot be read`] };
  }

  const module = checker.getSymbolAtLocation(entry);
  /** @type {string[]} */
  const problems = [];

  for (const exported of module === undefined ? [] : checker.getExportsOfModule(module)) {
    const symbol =
      (exported.flags & ts.SymbolFlags.Alias) !== 0 ? checker.getAliasedSymbol(exported) : exported;

    for (const declaration of symbol.declarations ?? []) {
      const tags = tagsOf(declaration).filter((tag) => STABILITY_TAGS.includes(tag));
      const file = declaration.getSourceFile();
      const { line } = file.getLineAndCharacterOfPosition(declaration.getStart(file));
      const at = `${relative(root, file.fileName)}:${String(line + 1)}`;

      if (tags.length === 1) {
        found.set(exported.name, tags[0] ?? '');
      } else {
        problems.push(
          `${at}: ${exported.name} carries ${tags.length === 0 ? 'no stability tag' : tags.join(' and ')}; give it exactly one of ${STABILITY_TAGS.join(' and ')}`,
        );
      }
    }
  }

  return { tags: found, problems: problems.sort() };
}

/**
 * The report with the release tag of each export replaced by its stability tag: API Extractor
 * writes `// @public` above every export it does not find a release tag of its own on.
 *
 * @param {string} report
 * @param {ReadonlyMap<string, string>} tags
 * @returns {string}
 */
export function stamp(report, tags) {
  const lines = report.split('\n');

  return lines
    .map((line, index) => {
      if (!line.startsWith('// @public')) {
        return line;
      }

      const declaration = lines
        .slice(index + 1)
        .find((next) => next.startsWith('export ') || next.startsWith('function '));
      const name = /^export (?:declare )?(?:function|interface|type|const|class|enum) (\w+)/.exec(
        declaration ?? '',
      )?.[1];
      const tag = name === undefined ? undefined : tags.get(name);

      return tag === undefined ? line : line.replace('@public', tag);
    })
    .join('\n');
}

/**
 * @typedef {object} ApiReportOptions
 * @property {boolean} [update] whether to write the report instead of comparing with it
 * @property {string} [report] the absolute path of the committed report; the kit's by default
 * @property {string} [work] the absolute work directory; .cache/ui-kit-api by default
 */

/**
 * Makes the report and compares it with the committed one, or, with update, writes it.
 *
 * @param {string} root
 * @param {ApiReportOptions} [options]
 * @returns {ApiReport}
 */
export function apiReport(root, options = {}) {
  const update = options.update ?? false;
  const reportPath = options.report ?? join(root, REPORT);
  const work = options.work ?? join(root, WORK);

  rmSync(work, { recursive: true, force: true });
  const stability = stabilities(root);
  const problems = [...writeDeclarations(root, work), ...stability.problems];

  if (problems.length > 0) {
    return { changed: false, problems };
  }

  mkdirSync(join(work, 'out'), { recursive: true });
  // The configuration is the object below, not a file: API Extractor only resolves paths from
  // configObjectFullPath, and reads the @stable tag's definition from js/ui-kit/tsdoc.json.
  const config = ExtractorConfig.prepare({
    configObjectFullPath: join(root, 'js/ui-kit/api-extractor.json'),
    packageJsonFullPath: join(root, 'js/ui-kit/package.json'),
    configObject: {
      projectFolder: join(root, 'js/ui-kit'),
      newlineKind: 'lf',
      mainEntryPointFilePath: join(work, 'types/index.d.ts'),
      compiler: {
        overrideTsconfig: {
          compilerOptions: {
            target: 'ES2023',
            lib: ['ES2023', 'DOM', 'DOM.Iterable'],
            module: 'ESNext',
            moduleResolution: 'Bundler',
            jsx: 'react-jsx',
            strict: true,
            resolveJsonModule: true,
            types: [],
          },
          files: [join(work, 'types/index.d.ts'), join(work, 'types/css.d.ts')],
        },
      },
      apiReport: {
        enabled: true,
        reportFileName: 'cms-ui-kit.api.md',
        reportFolder: join(work, 'out'),
        reportTempFolder: join(work, 'report'),
      },
      docModel: { enabled: false },
      dtsRollup: { enabled: false },
      tsdocMetadata: { enabled: false },
      messages: {
        compilerMessageReporting: { default: { logLevel: ExtractorLogLevel.Error } },
        extractorMessageReporting: {
          default: { logLevel: ExtractorLogLevel.Error },
          // The stability tags, @stable and @experimental, are the kit's own check
          // (stabilityProblems); the props are described where the kit's docs need it.
          'ae-missing-release-tag': { logLevel: ExtractorLogLevel.None },
          'ae-undocumented': { logLevel: ExtractorLogLevel.None },
        },
        tsdocMessageReporting: { default: { logLevel: ExtractorLogLevel.Error } },
      },
    },
  });
  /** @type {string[]} */
  const messages = [];
  Extractor.invoke(config, {
    localBuild: true,
    showVerboseMessages: false,
    messageCallback: (message) => {
      // API Extractor's own notes about the files it writes, such as that it created the report,
      // are not problems of the kit.
      if (
        (message.logLevel === ExtractorLogLevel.Error ||
          message.logLevel === ExtractorLogLevel.Warning) &&
        !message.messageId.startsWith('console-')
      ) {
        messages.push(message.formatMessageWithLocation(root));
      }

      message.handled = true;
    },
  });

  const made = join(work, 'out', basename(REPORT));

  if (messages.length > 0 || !existsSync(made)) {
    return { changed: false, problems: messages };
  }

  const report = stamp(readFileSync(made, 'utf8'), stability.tags);
  const committed = existsSync(reportPath) ? readFileSync(reportPath, 'utf8') : null;

  if (update) {
    mkdirSync(dirname(reportPath), { recursive: true });
    writeFileSync(reportPath, report);
  }

  return { changed: !update && report !== committed, problems: [] };
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const option = process.argv.slice(2).find((argument) => argument.startsWith('--root='));
  const root = resolve(
    option === undefined ? join(import.meta.dirname, '../../..') : option.slice('--root='.length),
  );
  const update = process.argv.includes('--update');
  const report = apiReport(root, { update });

  if (report.problems.length > 0) {
    process.stderr.write(report.problems.map((problem) => `${problem}\n`).join(''));
    process.exit(1);
  }

  if (report.changed) {
    process.stderr.write(
      `${REPORT} is not what the kit exports now. If the change of the API is intended, write it anew with npm run api:report and review the diff.\n`,
    );
    process.exit(1);
  }

  process.stdout.write(update ? `wrote ${REPORT}\n` : `${REPORT} is up to date\n`);
}
