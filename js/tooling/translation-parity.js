// The translation key parity check (GUARDRAILS 8: every text in Danish and English from day one).
// A directory of catalogues holds one JSON file per locale, named by its locale, such as da.json
// and en.json. Each is a flat object from key to a non-empty string, and every catalogue has the
// same keys. Run it as `node js/tooling/translation-parity.js <directory> [locale...]`; the
// locales default to da and en, every one of them must have its file, and no other file may lie
// beside them. It prints each problem as `<file>: <problem>` and exits 1 when there is one.

import { readdirSync, readFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

/** The locales of the panel (GUARDRAILS 8). */
export const DEFAULT_LOCALES = ['da', 'en'];

/** A key: dot-separated segments of lower-case letters, digits and underscores. */
const KEY = /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/;

/**
 * @typedef {object} Catalogue
 * @property {string} file
 * @property {Set<string>} keys
 */

/**
 * Reads one catalogue and adds what is wrong with it to the problems.
 *
 * @param {string} path
 * @param {string[]} problems
 * @returns {Catalogue}
 */
function readCatalogue(path, problems) {
  const file = basename(path);
  /** @type {Set<string>} */
  const keys = new Set();
  /** @type {unknown} */
  let decoded;

  try {
    decoded = JSON.parse(readFileSync(path, 'utf8'));
  } catch (error) {
    problems.push(
      `${file}: not valid JSON (${error instanceof Error ? error.message : 'unknown'})`,
    );

    return { file, keys };
  }

  if (typeof decoded !== 'object' || decoded === null || Array.isArray(decoded)) {
    problems.push(`${file}: not a JSON object`);

    return { file, keys };
  }

  for (const [key, value] of Object.entries(decoded)) {
    if (!KEY.test(key)) {
      problems.push(`${file}: the key "${key}" is not dot-separated lower-case words`);
    }

    if (typeof value !== 'string' || value.trim() === '') {
      problems.push(`${file}: the key "${key}" has no text`);
    }

    keys.add(key);
  }

  return { file, keys };
}

/**
 * Checks the catalogues in a directory and returns every problem, sorted; none means they pass.
 *
 * @param {string} directory
 * @param {string[]} [locales]
 * @returns {string[]}
 */
export function translationParityProblems(directory, locales = DEFAULT_LOCALES) {
  /** @type {string[]} */
  const problems = [];
  const expected = new Set(locales.map((locale) => `${locale}.json`));
  const present = readdirSync(directory).filter((entry) => entry.endsWith('.json'));

  for (const file of present) {
    if (!expected.has(file)) {
      problems.push(`${file}: not a catalogue of the locales ${locales.join(', ')}`);
    }
  }

  /** @type {Catalogue[]} */
  const catalogues = [];

  for (const file of [...expected].sort()) {
    if (!present.includes(file)) {
      problems.push(`${file}: missing`);
    } else {
      catalogues.push(readCatalogue(join(directory, file), problems));
    }
  }

  const union = new Set(catalogues.flatMap((catalogue) => [...catalogue.keys]));

  for (const catalogue of catalogues) {
    for (const key of union) {
      if (!catalogue.keys.has(key)) {
        const holders = catalogues
          .filter((other) => other.keys.has(key))
          .map((other) => other.file)
          .join(', ');
        problems.push(`${catalogue.file}: missing the key "${key}" that ${holders} has`);
      }
    }
  }

  return problems.sort();
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const [directory, ...locales] = process.argv.slice(2);

  if (directory === undefined) {
    process.stderr.write('Usage: node translation-parity.js <directory> [locale...]\n');
    process.exit(64);
  }

  const problems = translationParityProblems(
    directory,
    locales.length > 0 ? locales : DEFAULT_LOCALES,
  );

  for (const problem of problems) {
    process.stderr.write(`${directory}/${problem}\n`);
  }

  process.exit(problems.length > 0 ? 1 : 0);
}
