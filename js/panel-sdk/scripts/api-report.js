// The API report of the SDK, @cboxdk/cms-panel (section 6 of the panel extension architecture: the
// compatibility gate). js/panel-sdk/api/cms-panel.api.md lists, per subpath, every name the
// subpath exports with its declaration and its stability, so a change to what addons may rely on
// shows up in a review as a change of that file, and a test of gate 5
// (js/panel-sdk/tests/api/report.test.js) fails until the report is written anew with it.
//
// It is made in three steps. TypeScript writes the declarations of the SDK's entries, and of the
// kit and tooling they import, to .cache/panel-sdk-api/types. API Extractor reads each subpath's
// declarations and writes its report, in which the kit's and the tooling's packages stay external,
// as they are to an addon. The release tag API Extractor gives each export, @public, is replaced by
// the export's own stability tag, and the reports are joined under a heading per subpath.
//
// The same run holds the subpaths to their stability (section 2.1): every export carries exactly
// one tag, @stable or @experimental; /experimental exports only experimental API and every other
// subpath only stable API. An export generated from a schema into src/generated or from the kit's
// tags into src/kit takes the stability of the barrel that re-exports it, which composer
// generate:protocol and npm run generate:sdk chose by the point's or component's own stability.
//
// `npm run api:report` writes the report of the kit and of the SDK anew after an intended change;
// `node js/panel-sdk/scripts/api-report.js [--update] [--root=<dir>]` writes or compares the SDK's.

import {
  copyFileSync,
  existsSync,
  mkdirSync,
  readdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import { Extractor, ExtractorConfig, ExtractorLogLevel } from '@microsoft/api-extractor';
import ts from 'typescript';

/** The report, relative to the root. */
export const REPORT = 'js/panel-sdk/api/cms-panel.api.md';

/** The SDK's package name. */
export const PACKAGE = '@cboxdk/cms-panel';

/** Where the declarations and API Extractor's own files go, relative to the root; git ignores it. */
export const WORK = '.cache/panel-sdk-api';

/**
 * @typedef {object} Subpath
 * @property {string} name the subpath, such as "/extend"
 * @property {string} entry its source, relative to the root
 * @property {'@stable' | '@experimental'} level the stability of everything it exports
 */

/**
 * Every subpath of package.json's exports with declarations, in the order of the report; the
 * stylesheet /tokens.css has none.
 *
 * @type {readonly Subpath[]}
 */
export const SUBPATHS = Object.freeze([
  { name: '/ui', entry: 'js/panel-sdk/src/ui.ts', level: '@stable' },
  { name: '/extend', entry: 'js/panel-sdk/src/extend.ts', level: '@stable' },
  { name: '/experimental', entry: 'js/panel-sdk/src/experimental.ts', level: '@experimental' },
  { name: '/vite', entry: 'js/panel-sdk/vite.js', level: '@stable' },
  { name: '/testing', entry: 'js/panel-sdk/src/testing.tsx', level: '@stable' },
  { name: '/eslint', entry: 'js/panel-sdk/eslint.js', level: '@stable' },
  { name: '/stylelint', entry: 'js/panel-sdk/stylelint.js', level: '@stable' },
  { name: '/storybook', entry: 'js/panel-sdk/storybook/preset.js', level: '@stable' },
]);

/** The directories whose exports take the stability of the barrel that re-exports them. */
const GENERATED = ['js/panel-sdk/src/generated/', 'js/panel-sdk/src/kit/'];

/** The stability tags; an export carries exactly one. */
export const STABILITY_TAGS = ['@stable', '@experimental'];

/** What the kit's stylesheet imports are to TypeScript, relative to the root. */
const CSS_DECLARATIONS = 'js/ui-kit/src/css.d.ts';

/**
 * @typedef {object} ApiReport
 * @property {boolean} changed whether the report made differs from the committed one
 * @property {string[]} problems what kept the report from being made, and exports of the wrong stability
 */

/**
 * The compiler options of the declarations: the repository's, with JavaScript read and
 * declarations written below the work directory, the source tree from js/.
 *
 * @param {string} root
 * @param {string} work
 * @returns {ts.CompilerOptions}
 */
function compilerOptions(root, work) {
  const read = ts.readConfigFile(join(root, 'tsconfig.json'), (file) => ts.sys.readFile(file));

  if (read.error !== undefined) {
    throw new Error(ts.flattenDiagnosticMessageText(read.error.messageText, '\n'));
  }

  const parsed = ts.parseJsonConfigFileContent(read.config, ts.sys, root);

  return {
    ...parsed.options,
    noEmit: false,
    allowJs: true,
    checkJs: false,
    declaration: true,
    emitDeclarationOnly: true,
    rootDir: join(root, 'js'),
    outDir: join(work, 'types'),
    types: ['node'],
  };
}

/**
 * A diagnostic as `<file>:<line>: <message>`.
 *
 * @param {string} root
 * @param {ts.Diagnostic} diagnostic
 * @returns {string}
 */
function describe(root, diagnostic) {
  const message = ts.flattenDiagnosticMessageText(diagnostic.messageText, '\n');

  if (diagnostic.file === undefined || diagnostic.start === undefined) {
    return message;
  }

  const { line } = diagnostic.file.getLineAndCharacterOfPosition(diagnostic.start);

  return `${relative(root, diagnostic.file.fileName)}:${String(line + 1)}: ${message}`;
}

/**
 * Writes the declarations of every subpath and of what they import, with the kit's catalogues and
 * its stylesheet declarations beside them.
 *
 * @param {string} root
 * @param {string} work
 * @returns {string[]} what kept the declarations from being written
 */
function writeDeclarations(root, work) {
  const entries = SUBPATHS.map((subpath) => join(root, subpath.entry));
  const program = ts.createProgram(
    [...entries, join(root, CSS_DECLARATIONS)],
    compilerOptions(root, work),
  );
  const result = program.emit();
  const diagnostics = [...ts.getPreEmitDiagnostics(program), ...result.diagnostics];
  const catalogues = join(root, 'js/ui-kit/src/i18n/catalogues');
  const target = join(work, 'types/ui-kit/src/i18n/catalogues');

  mkdirSync(target, { recursive: true });

  for (const file of readdirSync(catalogues).filter((name) => name.endsWith('.json'))) {
    copyFileSync(join(catalogues, file), join(target, file));
  }

  copyFileSync(join(root, CSS_DECLARATIONS), join(work, 'types/ui-kit/src/css.d.ts'));

  return diagnostics.map((diagnostic) => describe(root, diagnostic));
}

/**
 * The stability tags in the TSDoc of a declaration; a JSDoc typedef's are those of its comment.
 *
 * @param {ts.Declaration} declaration
 * @returns {string[]}
 */
function tagsOf(declaration) {
  const tags =
    ts.isJSDocTypedefTag(declaration) || ts.isJSDocCallbackTag(declaration)
      ? ts.isJSDoc(declaration.parent)
        ? (declaration.parent.tags ?? [])
        : []
      : ts.getJSDocTags(declaration);

  return [
    ...new Set(
      tags.map((tag) => `@${tag.tagName.text}`).filter((tag) => STABILITY_TAGS.includes(tag)),
    ),
  ];
}

/**
 * The stability of each export of each subpath, by the name of its declaration, and every export
 * that carries no tag, two tags, or another stability than its subpath allows.
 *
 * @param {string} root
 * @param {readonly Subpath[]} [subpaths]
 * @returns {{ tags: Map<string, Map<string, string>>, problems: string[] }}
 */
export function stabilities(root, subpaths = SUBPATHS) {
  const program = ts.createProgram(
    [...subpaths.map((subpath) => join(root, subpath.entry)), join(root, CSS_DECLARATIONS)],
    { ...compilerOptions(root, join(root, WORK)), noEmit: true },
  );
  const checker = program.getTypeChecker();
  /** @type {Map<string, Map<string, string>>} */
  const tags = new Map();
  /** @type {string[]} */
  const problems = [];

  for (const subpath of subpaths) {
    const source = program.getSourceFile(join(root, subpath.entry));
    const module = source === undefined ? undefined : checker.getSymbolAtLocation(source);
    /** @type {Map<string, string>} */
    const found = new Map();

    tags.set(subpath.name, found);

    if (module === undefined) {
      problems.push(`${subpath.entry}: cannot be read as a module`);
      continue;
    }

    for (const exported of checker.getExportsOfModule(module)) {
      const symbol =
        (exported.flags & ts.SymbolFlags.Alias) !== 0
          ? checker.getAliasedSymbol(exported)
          : exported;
      const declarations = symbol.declarations ?? [];
      const [declaration] = declarations;
      const file = declaration?.getSourceFile();
      const path = file === undefined ? '' : relative(root, file.fileName);
      const at =
        declaration === undefined || file === undefined
          ? subpath.entry
          : `${path}:${String(file.getLineAndCharacterOfPosition(declaration.getStart(file)).line + 1)}`;
      const generated = GENERATED.some((directory) => path.startsWith(directory));
      const own = [...new Set(declarations.flatMap((each) => tagsOf(each)))];
      const tag = generated ? subpath.level : own.length === 1 ? own[0] : undefined;
      const declared = declaration === undefined ? undefined : ts.getNameOfDeclaration(declaration);
      const name =
        declared !== undefined && ts.isIdentifier(declared) ? declared.text : exported.name;

      if (tag === undefined) {
        problems.push(
          `${at}: ${subpath.name} exports ${exported.name}, which carries ${own.length === 0 ? 'no stability tag' : own.join(' and ')}; give it exactly one of ${STABILITY_TAGS.join(' and ')}`,
        );
        continue;
      }

      if (tag !== subpath.level) {
        problems.push(
          `${at}: ${subpath.name} exports ${exported.name}, which is ${tag}; ${PACKAGE}${subpath.name} exports only ${subpath.level} API (section 2.1 of the panel extension architecture)`,
        );
        continue;
      }

      found.set(name, tag);
      found.set(exported.name, tag);
    }
  }

  return { tags, problems: problems.sort() };
}

/**
 * The report with the release tag of each export replaced by its stability tag: API Extractor
 * writes `// @public` above every declaration it finds no release tag of its own on.
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
        .find((next) => next !== '' && !next.startsWith('//'));
      const name =
        /^(?:export )?(?:declare )?(?:default )?(?:function|interface|type|const|let|var|class|enum) (\w+)/.exec(
          declaration ?? '',
        )?.[1];
      const tag = name === undefined ? undefined : tags.get(name);

      return tag === undefined ? line : line.replace('@public', tag);
    })
    .join('\n');
}

/**
 * The part of one subpath's report between the code fence's lines: API Extractor's header names
 * the package, not the subpath, so the joined report gives each subpath a heading of its own.
 *
 * @param {string} report
 * @returns {string}
 */
function body(report) {
  const start = report.indexOf('```ts\n');
  const end = report.lastIndexOf('```');

  return start === -1 || end <= start ? report : report.slice(start + '```ts\n'.length, end).trim();
}

/**
 * Runs API Extractor on one subpath's declarations and gives its report, or the messages that
 * kept it from being made.
 *
 * @param {string} root
 * @param {string} work
 * @param {Subpath} subpath
 * @returns {{ report: string | null, problems: string[], borrowed: string[] }}
 */
function extract(root, work, subpath) {
  const name = subpath.name.slice(1);
  const declarations = join(
    work,
    'types',
    subpath.entry.slice('js/'.length).replace(/\.(?:tsx?|js)$/, '.d.ts'),
  );
  const out = join(work, 'out', name);

  mkdirSync(out, { recursive: true });
  const config = ExtractorConfig.prepare({
    configObjectFullPath: join(root, 'js/panel-sdk/api-extractor.json'),
    packageJsonFullPath: join(root, 'js/panel-sdk/package.json'),
    configObject: {
      projectFolder: join(root, 'js/panel-sdk'),
      newlineKind: 'lf',
      mainEntryPointFilePath: declarations,
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
            types: ['node'],
            // The kit and the tooling are private workspaces whose package entries are sources;
            // their declarations were written beside the SDK's. Their specifiers stay bare, so
            // the report keeps them external, as they are to an addon.
            baseUrl: work,
            paths: {
              '@cboxdk/cms-ui-kit': [join(work, 'types/ui-kit/src/index.d.ts')],
              '@cboxdk/cms-tooling/eslint': [join(work, 'types/tooling/eslint.d.ts')],
            },
          },
          files: [declarations, join(work, 'types/ui-kit/src/css.d.ts')],
        },
      },
      apiReport: {
        enabled: true,
        reportFileName: `${name}.api.md`,
        reportFolder: out,
        reportTempFolder: join(work, 'report', name),
      },
      docModel: { enabled: false },
      dtsRollup: { enabled: false },
      tsdocMetadata: { enabled: false },
      messages: {
        compilerMessageReporting: { default: { logLevel: ExtractorLogLevel.Error } },
        extractorMessageReporting: {
          default: { logLevel: ExtractorLogLevel.Error },
          // The stability tags are this script's own check (stabilities); the props are described
          // where the SDK's docs need it.
          'ae-missing-release-tag': { logLevel: ExtractorLogLevel.None },
          'ae-undocumented': { logLevel: ExtractorLogLevel.None },
          // A subpath may name a type another subpath exports, such as an experimental kind of
          // contribution that takes the stable CommandAnswer of /extend. API Extractor reads one
          // subpath at a time and reports such a type as forgotten; extract() gives these
          // messages back, and apiReport() holds every such type to another subpath's exports.
          'ae-forgotten-export': { logLevel: ExtractorLogLevel.Warning, addToApiReportFile: false },
          // The declarations of an external package, such as ESLint's, may reach a file API
          // Extractor does not analyse; an external package is not part of the report.
          'ae-wrong-input-file-type': { logLevel: ExtractorLogLevel.None },
        },
        // The comments are TypeScript's and JSDoc's, such as the {type} of a JavaScript subpath's
        // @param, not TSDoc's; the report records the declarations.
        tsdocMessageReporting: { default: { logLevel: ExtractorLogLevel.None } },
      },
    },
  });
  /** @type {string[]} */
  const problems = [];
  /** @type {string[]} */
  const borrowed = [];
  Extractor.invoke(config, {
    localBuild: true,
    showVerboseMessages: false,
    messageCallback: (message) => {
      const forgotten = /The symbol "([^"]+)" needs to be exported/.exec(message.text);

      if (message.messageId === 'ae-forgotten-export' && forgotten?.[1] !== undefined) {
        borrowed.push(forgotten[1]);
      } else if (
        (message.logLevel === ExtractorLogLevel.Error ||
          message.logLevel === ExtractorLogLevel.Warning) &&
        !message.messageId.startsWith('console-')
      ) {
        problems.push(`${subpath.name}: ${message.formatMessageWithLocation(root)}`);
      }

      message.handled = true;
    },
  });
  const made = join(out, `${name}.api.md`);

  return {
    report: problems.length === 0 && existsSync(made) ? readFileSync(made, 'utf8') : null,
    problems,
    borrowed: [...new Set(borrowed)].sort(),
  };
}

/**
 * @typedef {object} ApiReportOptions
 * @property {boolean} [update] whether to write the report instead of comparing with it
 * @property {string} [report] the absolute path of the committed report; the SDK's by default
 * @property {string} [work] the absolute work directory; .cache/panel-sdk-api by default
 */

/**
 * Makes the report and compares it with the committed one, or, with update, writes it.
 *
 * @param {string} root
 * @param {ApiReportOptions} [options]
 * @returns {ApiReport}
 */
export function apiReport(root, options = {}) {
  const reportPath = options.report ?? join(root, REPORT);
  const work = options.work ?? join(root, WORK);

  rmSync(work, { recursive: true, force: true });
  const stability = stabilities(root);
  const problems = [...writeDeclarations(root, work), ...stability.problems];

  if (problems.length > 0) {
    return { changed: false, problems };
  }

  /** @type {string[]} */
  const sections = [
    `## API Report File for "${PACKAGE}"`,
    '',
    "> Do not edit this file. It is a report generated by [API Extractor](https://api-extractor.com/), one section per subpath, with each export's stability tag in place of @public: `npm run api:report` writes it anew.",
  ];

  for (const subpath of SUBPATHS) {
    const made = extract(root, work, subpath);

    if (made.report === null) {
      problems.push(
        ...made.problems,
        ...(made.problems.length === 0 ? [`${subpath.name}: API Extractor wrote no report`] : []),
      );
      continue;
    }

    const lenders = SUBPATHS.filter((other) => other !== subpath && other.level === '@stable').map(
      (other) => other.name,
    );

    for (const type of made.borrowed) {
      const lender = lenders.find((other) => stability.tags.get(other)?.has(type) === true);

      if (lender === undefined) {
        problems.push(
          `${subpath.name}: an export refers to ${type}, which no stable subpath of ${PACKAGE} exports; export it, so an addon can name it`,
        );
      }
    }

    sections.push(
      '',
      `### ${PACKAGE}${subpath.name}`,
      '',
      '```ts',
      stamp(body(made.report), stability.tags.get(subpath.name) ?? new Map()),
      '```',
    );
  }

  if (problems.length > 0) {
    return { changed: false, problems };
  }

  const report = `${sections.join('\n')}\n`;
  const committed = existsSync(reportPath) ? readFileSync(reportPath, 'utf8') : null;

  if (options.update === true) {
    mkdirSync(dirname(reportPath), { recursive: true });
    writeFileSync(reportPath, report);
  }

  return { changed: options.update !== true && report !== committed, problems: [] };
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
      `${REPORT} is not what the SDK exports now. If the change of the API is intended, record its version decision, write the report anew with npm run api:report and review the diff.\n`,
    );
    process.exit(1);
  }

  process.stdout.write(update ? `wrote ${REPORT}\n` : `${REPORT} is up to date\n`);
}
