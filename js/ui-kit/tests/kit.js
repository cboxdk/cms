// What the kit's tests share: the paths of the kit, the catalogue of design tokens they check, and
// the stylesheets and components of the kit.

import { readdirSync, readFileSync } from 'node:fs';
import { join, relative } from 'node:path';
import process from 'node:process';

import { readCatalogue } from '../scripts/tokens.js';

/** The root of the repository. */
export const ROOT = join(import.meta.dirname, '../../..');

/** The kit's source directory. */
export const SOURCE = join(ROOT, 'js/ui-kit/src');

/**
 * The catalogue the tests check: js/ui-kit/tokens.json, or the file CMS_KIT_TOKENS names, so a
 * test of the tests can plant a catalogue whose pair fails.
 */
export const TOKENS_FILE = process.env.CMS_KIT_TOKENS ?? join(ROOT, 'js/ui-kit/tokens.json');

/**
 * The directory of the kit's text catalogues the tests check: js/ui-kit/src/i18n/catalogues, or
 * the directory CMS_KIT_CATALOGUES names, so a test of the tests can plant one with a key missing.
 */
export const CATALOGUES_DIRECTORY =
  process.env.CMS_KIT_CATALOGUES ?? join(ROOT, 'js/ui-kit/src/i18n/catalogues');

/** @returns {ReturnType<typeof readCatalogue>} */
export function catalogue() {
  return readCatalogue(readFileSync(TOKENS_FILE, 'utf8'));
}

/**
 * Every file below the kit's source directory with one of the extensions, as a path relative to
 * the root, sorted.
 *
 * @param {string} extension
 * @returns {string[]}
 */
export function kitFiles(extension) {
  return readdirSync(SOURCE, { recursive: true, encoding: 'utf8' })
    .filter((file) => file.endsWith(extension))
    .map((file) => relative(ROOT, join(SOURCE, file)))
    .sort();
}

/**
 * @param {string} path relative to the root
 * @returns {string}
 */
export function read(path) {
  return readFileSync(join(ROOT, path), 'utf8');
}
