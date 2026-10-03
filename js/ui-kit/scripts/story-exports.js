// The story-per-export check of gate 7 (GUARDRAILS 8: every component has a story; the panel
// extension architecture of 2 October 2026, section 2.7: gate 7 fails when a kit export has no
// story). It reads the kit's public entry, js/ui-kit/src/index.ts, and every story file below
// js/ui-kit/stories with the TypeScript parser, and fails when a component the entry exports is the
// component of no story file's meta.
//
// A component is an exported value named in PascalCase: a capital first letter and a lower-case
// letter after it, so KitI18nProvider is one and KIT_LOCALES and isKitLocale are not. Types are
// left out. A story file covers the component its default export names as `component`, which must
// be imported from the kit's package, @cboxdk/cms-ui-kit, as a page or an addon imports it. The
// entry may not hide its names behind `export *`.
//
// Run it as `npm run storybook:exports`, or `node js/ui-kit/scripts/story-exports.js
// [--root=<dir>]` to check another tree. It prints each problem as `<file>:<line>: <message>` and
// exits 1 when there is one, 0 otherwise.

import { readdirSync, readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

import ts from 'typescript';

/** The kit's public entry, relative to the root. */
export const ENTRY = 'js/ui-kit/src/index.ts';

/** The directory of the kit's stories, relative to the root. */
export const STORIES = 'js/ui-kit/stories';

/** The suffix of a story file, as .storybook/main.ts finds them. */
export const STORY_SUFFIX = '.stories.tsx';

/** The package the stories import the kit from. */
export const PACKAGE = '@cboxdk/cms-ui-kit';

/**
 * @typedef {object} SourceText
 * @property {string} path relative to the root
 * @property {string} text
 */

/**
 * @typedef {object} KitExport
 * @property {string} name
 * @property {number} line
 */

/**
 * Whether an exported name is a component's: PascalCase.
 *
 * @param {string} name
 * @returns {boolean}
 */
export function isComponentName(name) {
  return /^[A-Z][A-Za-z0-9]*$/.test(name) && /[a-z]/.test(name);
}

/**
 * @param {ts.SourceFile} source
 * @param {ts.Node} node
 * @returns {number}
 */
function lineOf(source, node) {
  return source.getLineAndCharacterOfPosition(node.getStart(source)).line + 1;
}

/**
 * @param {ts.Node} node
 * @param {ts.SyntaxKind} kind
 * @returns {boolean}
 */
function hasModifier(node, kind) {
  return (
    ts.canHaveModifiers(node) &&
    (ts.getModifiers(node) ?? []).some((modifier) => modifier.kind === kind)
  );
}

/**
 * @param {ts.Node} node
 * @returns {boolean}
 */
function isExported(node) {
  return hasModifier(node, ts.SyntaxKind.ExportKeyword);
}

/**
 * @param {SourceText} file
 * @returns {ts.SourceFile}
 */
function parse(file) {
  return ts.createSourceFile(
    file.path,
    file.text,
    ts.ScriptTarget.Latest,
    true,
    file.path.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
}

/**
 * The components the entry exports, in the order it exports them, and what keeps the check from
 * reading the entry's names.
 *
 * @param {SourceText} entry
 * @returns {{ components: KitExport[], problems: string[] }}
 */
export function kitComponents(entry) {
  const source = parse(entry);
  /** @type {KitExport[]} */
  const components = [];
  /** @type {string[]} */
  const problems = [];
  /** @param {string} name @param {ts.Node} node */
  const add = (name, node) => {
    if (isComponentName(name)) {
      components.push({ name, line: lineOf(source, node) });
    }
  };

  for (const statement of source.statements) {
    if (ts.isExportDeclaration(statement)) {
      if (statement.isTypeOnly) {
        continue;
      }

      if (statement.exportClause === undefined) {
        problems.push(
          `${entry.path}:${String(lineOf(source, statement))}: export * hides the names the kit exports; name each one`,
        );
      } else if (ts.isNamedExports(statement.exportClause)) {
        for (const element of statement.exportClause.elements) {
          if (!element.isTypeOnly) {
            add(element.name.text, element);
          }
        }
      } else {
        add(statement.exportClause.name.text, statement);
      }
    } else if (
      ts.isExportAssignment(statement) ||
      (isExported(statement) && hasModifier(statement, ts.SyntaxKind.DefaultKeyword))
    ) {
      problems.push(
        `${entry.path}:${String(lineOf(source, statement))}: a default export has no name to hold to a story; export it by name`,
      );
    } else if (
      (ts.isFunctionDeclaration(statement) || ts.isClassDeclaration(statement)) &&
      isExported(statement) &&
      statement.name !== undefined
    ) {
      add(statement.name.text, statement);
    } else if (ts.isVariableStatement(statement) && isExported(statement)) {
      for (const declaration of statement.declarationList.declarations) {
        if (ts.isIdentifier(declaration.name)) {
          add(declaration.name.text, declaration);
        }
      }
    }
  }

  return { components, problems };
}

/**
 * The expression without the parentheses, `satisfies` and `as` around it.
 *
 * @param {ts.Expression} expression
 * @returns {ts.Expression}
 */
function unwrap(expression) {
  let current = expression;

  while (
    ts.isParenthesizedExpression(current) ||
    ts.isSatisfiesExpression(current) ||
    ts.isAsExpression(current)
  ) {
    current = current.expression;
  }

  return current;
}

/**
 * The kit component a story file's meta names, or null when its meta names none, such as a story
 * of a pattern; and what is wrong with the file.
 *
 * @param {SourceText} story
 * @returns {{ component: string | null, problems: string[] }}
 */
export function storyComponent(story) {
  const source = parse(story);
  /** @type {Map<string, string>} the local name of each value imported from the kit's package */
  const imports = new Map();
  /** @type {Map<string, ts.Expression>} */
  const constants = new Map();
  /** @type {ts.ExportAssignment | undefined} */
  let meta;

  for (const statement of source.statements) {
    if (
      ts.isImportDeclaration(statement) &&
      ts.isStringLiteral(statement.moduleSpecifier) &&
      statement.moduleSpecifier.text === PACKAGE &&
      statement.importClause?.namedBindings !== undefined &&
      ts.isNamedImports(statement.importClause.namedBindings) &&
      statement.importClause.phaseModifier !== ts.SyntaxKind.TypeKeyword
    ) {
      for (const element of statement.importClause.namedBindings.elements) {
        if (!element.isTypeOnly) {
          imports.set(element.name.text, (element.propertyName ?? element.name).text);
        }
      }
    } else if (ts.isVariableStatement(statement)) {
      for (const declaration of statement.declarationList.declarations) {
        if (ts.isIdentifier(declaration.name) && declaration.initializer !== undefined) {
          constants.set(declaration.name.text, declaration.initializer);
        }
      }
    } else if (ts.isExportAssignment(statement) && statement.isExportEquals !== true) {
      meta = statement;
    }
  }

  if (meta === undefined) {
    return {
      component: null,
      problems: [`${story.path}:1: has no default export with the meta of its stories`],
    };
  }

  let value = unwrap(meta.expression);

  if (ts.isIdentifier(value)) {
    const initializer = constants.get(value.text);
    value = initializer === undefined ? value : unwrap(initializer);
  }

  if (!ts.isObjectLiteralExpression(value)) {
    return {
      component: null,
      problems: [
        `${story.path}:${String(lineOf(source, meta))}: the default export is not an object literal, so the check cannot read its component`,
      ],
    };
  }

  for (const property of value.properties) {
    const named =
      (ts.isPropertyAssignment(property) || ts.isShorthandPropertyAssignment(property)) &&
      (ts.isIdentifier(property.name) || ts.isStringLiteral(property.name)) &&
      property.name.text === 'component';

    if (!named) {
      continue;
    }

    const component = ts.isPropertyAssignment(property)
      ? unwrap(property.initializer)
      : property.name;
    const imported = ts.isIdentifier(component) ? imports.get(component.text) : undefined;

    if (imported === undefined) {
      return {
        component: null,
        problems: [
          `${story.path}:${String(lineOf(source, property))}: the component of the meta is not a value imported from ${PACKAGE}`,
        ],
      };
    }

    return { component: imported, problems: [] };
  }

  return { component: null, problems: [] };
}

/**
 * Every problem of the kit's stories: what keeps the check from reading a file, and each component
 * the entry exports without a story.
 *
 * @param {SourceText} entry
 * @param {readonly SourceText[]} stories
 * @returns {string[]}
 */
export function storyExportProblems(entry, stories) {
  const kit = kitComponents(entry);
  const problems = [...kit.problems];
  /** @type {Set<string>} */
  const covered = new Set();

  for (const story of stories) {
    const read = storyComponent(story);
    problems.push(...read.problems);

    if (read.component !== null) {
      covered.add(read.component);
    }
  }

  for (const { name, line } of kit.components) {
    if (!covered.has(name)) {
      problems.push(
        `${entry.path}:${String(line)}: the kit exports the component ${name} without a story; add a file ${STORIES}/<Name>${STORY_SUFFIX} whose default export names it as its component`,
      );
    }
  }

  return problems;
}

/**
 * The entry and the story files below a root, sorted by path.
 *
 * @param {string} root
 * @returns {{ entry: SourceText, stories: SourceText[] }}
 */
export function readKit(root) {
  /** @param {string} path */
  const read = (path) => ({ path, text: readFileSync(join(root, path), 'utf8') });
  const stories = readdirSync(join(root, STORIES), { recursive: true, encoding: 'utf8' })
    .filter((file) => file.endsWith(STORY_SUFFIX))
    .map((file) => `${STORIES}/${file.split('\\').join('/')}`)
    .sort()
    .map(read);

  return { entry: read(ENTRY), stories };
}

if (process.argv[1] !== undefined && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const option = process.argv.slice(2).find((argument) => argument.startsWith('--root='));
  const root = resolve(
    option === undefined ? join(import.meta.dirname, '../../..') : option.slice('--root='.length),
  );
  const { entry, stories } = readKit(root);
  const problems = storyExportProblems(entry, stories);

  if (problems.length > 0) {
    process.stderr.write(problems.map((problem) => `${problem}\n`).join(''));
    process.exit(1);
  }

  const names = kitComponents(entry).components.map((component) => component.name);
  process.stdout.write(
    `Every component the kit exports has a story (${String(names.length)}): ${names.join(', ')}.\n`,
  );
}
