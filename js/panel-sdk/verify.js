// `cms-panel-addon verify` (section 4.5 of the panel extension architecture, decision D7): an
// addon's prebuilt bundle, dist/panel, is committed in its release commit, so its CI holds the
// committed files to what the build writes. verify reads panel-manifest.json, checks every file it
// lists against its SHA-384, so a file edited by hand after the build fails, builds the addon
// again into a directory of its own with the addon's own Vite configuration, and compares the
// two manifests file by file, so a bundle that is not what the sources give fails too.

import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { isAbsolute, join, resolve } from 'node:path';

import { integrity, MANIFEST } from './vite.js';

/** Where an addon's bundle lives below its package, as the manifest's `bundle` names it. */
export const DIST = 'dist/panel';

/**
 * @typedef {object} BundleFile
 * @property {string} integrity
 * @property {string} kind
 * @property {string} path
 */

/**
 * @typedef {object} BundleManifest
 * @property {string[]} contributions
 * @property {string} entry
 * @property {string[]} externals
 * @property {BundleFile[]} files
 */

/**
 * @typedef {object} VerifyOptions
 * @property {string} root the addon's package directory, which holds its Vite configuration
 * @property {string} [dist] the committed bundle, `dist/panel` below the root unless given
 * @property {(outDir: string) => Promise<void>} [build] builds the addon into the directory; Vite with the addon's own configuration unless given
 */

/**
 * @typedef {object} VerifyReport
 * @property {string[]} problems what keeps the bundle from verifying, none when it verifies
 * @property {number} files how many files the committed manifest lists
 */

/**
 * The manifest at the path, or null with the reason it could not be read.
 *
 * @param {string} path
 * @returns {{ manifest: BundleManifest } | { problem: string }}
 */
export function readManifest(path) {
  let text;

  try {
    text = readFileSync(path, 'utf8');
  } catch {
    return { problem: `${path} is missing: build the addon first.` };
  }

  /** @type {unknown} */
  let parsed;

  try {
    parsed = JSON.parse(text);
  } catch {
    return { problem: `${path} is not JSON.` };
  }

  if (
    typeof parsed !== 'object' ||
    parsed === null ||
    !Array.isArray(/** @type {{ files?: unknown }} */ (parsed).files) ||
    typeof (/** @type {{ entry?: unknown }} */ (parsed).entry) !== 'string'
  ) {
    return { problem: `${path} is not a bundle manifest: it has no entry and files.` };
  }

  return { manifest: /** @type {BundleManifest} */ (parsed) };
}

/**
 * The files of a manifest whose bytes on disk are not what it names, each with why.
 *
 * @param {string} dist
 * @param {BundleManifest} manifest
 * @returns {string[]}
 */
export function editedFiles(dist, manifest) {
  const problems = [];

  for (const file of manifest.files) {
    let bytes;

    try {
      bytes = readFileSync(join(dist, file.path));
    } catch {
      problems.push(`${file.path} is listed in ${MANIFEST} and missing from ${dist}.`);

      continue;
    }

    if (integrity(bytes) !== file.integrity) {
      problems.push(
        `${file.path} was changed after the build: its bytes are not the ${file.integrity} the manifest names. Build the addon again and commit what it writes.`,
      );
    }
  }

  return problems;
}

/**
 * What differs between the committed manifest and the one a fresh build wrote.
 *
 * @param {BundleManifest} committed
 * @param {BundleManifest} fresh
 * @returns {string[]}
 */
export function manifestDifferences(committed, fresh) {
  const problems = [];

  if (committed.entry !== fresh.entry) {
    problems.push(`the entry is ${committed.entry}, and a fresh build writes ${fresh.entry}.`);
  }

  for (const [name, left, right] of [
    ['contributions', committed.contributions, fresh.contributions],
    ['externals', committed.externals, fresh.externals],
  ]) {
    if (JSON.stringify(left) !== JSON.stringify(right)) {
      problems.push(
        `the ${String(name)} are ${JSON.stringify(left)}, and a fresh build writes ${JSON.stringify(right)}.`,
      );
    }
  }

  const byPath = new Map(fresh.files.map((file) => [file.path, file]));

  for (const file of committed.files) {
    const built = byPath.get(file.path);

    if (built === undefined) {
      problems.push(`${file.path} is committed, and a fresh build does not write it.`);
    } else if (built.integrity !== file.integrity) {
      problems.push(
        `${file.path} is not what a fresh build writes: the sources changed after the build, or the build is not reproducible.`,
      );
    }

    byPath.delete(file.path);
  }

  for (const path of byPath.keys()) {
    problems.push(`${path} is written by a fresh build and not committed.`);
  }

  return problems;
}

/**
 * Builds the addon with Vite and its own configuration from the root, into the directory.
 *
 * @param {string} root
 * @param {string} outDir
 * @returns {Promise<void>}
 */
async function buildWithVite(root, outDir) {
  const { build } = await import('vite');

  await build({
    root,
    logLevel: 'silent',
    build: { outDir, emptyOutDir: true },
  });
}

/**
 * Verifies the committed bundle: every listed file has the bytes its manifest names, and a fresh
 * build writes the same manifest.
 *
 * @param {VerifyOptions} options
 * @returns {Promise<VerifyReport>}
 */
export async function verifyBundle(options) {
  const root = resolve(options.root);
  const dist = options.dist === undefined ? join(root, DIST) : resolve(root, options.dist);
  const read = readManifest(join(dist, MANIFEST));

  if ('problem' in read) {
    return { problems: [read.problem], files: 0 };
  }

  const problems = editedFiles(dist, read.manifest);

  if (problems.length > 0) {
    return { problems, files: read.manifest.files.length };
  }

  const scratch = mkdtempSync(join(tmpdir(), 'cms-panel-addon-verify-'));

  try {
    try {
      await (options.build ?? ((outDir) => buildWithVite(root, outDir)))(scratch);
    } catch (error) {
      return {
        problems: [
          `the addon does not build: ${error instanceof Error ? error.message : String(error)}`,
        ],
        files: read.manifest.files.length,
      };
    }

    const fresh = readManifest(join(scratch, MANIFEST));

    if ('problem' in fresh) {
      return {
        problems: [`a fresh build wrote no manifest: ${fresh.problem}`],
        files: read.manifest.files.length,
      };
    }

    return {
      problems: manifestDifferences(read.manifest, fresh.manifest),
      files: read.manifest.files.length,
    };
  } finally {
    rmSync(scratch, { recursive: true, force: true });
  }
}

/**
 * @typedef {object} VerifyCommand
 * @property {'verify'} command
 * @property {string} root
 * @property {string | undefined} dist
 */

/**
 * The command the arguments ask for, or the usage when they ask for none the bin has.
 *
 * @param {readonly string[]} argv the arguments after the bin's name
 * @param {string} cwd
 * @returns {VerifyCommand | { usage: string }}
 */
export function parseArguments(argv, cwd) {
  const [command, ...rest] = argv;
  const usage =
    'Usage: cms-panel-addon verify [--root=<dir>] [--dist=<dir>]\n\nVerifies the committed bundle of the addon in the root, the current directory unless given: every file of dist/panel/panel-manifest.json has the bytes the manifest names, and a fresh build writes the same manifest.';

  if (command !== 'verify') {
    return { usage };
  }

  let root = cwd;
  /** @type {string | undefined} */
  let dist;

  for (const argument of rest) {
    if (argument.startsWith('--root=')) {
      const value = argument.slice('--root='.length);
      root = isAbsolute(value) ? value : resolve(cwd, value);
    } else if (argument.startsWith('--dist=')) {
      dist = argument.slice('--dist='.length);
    } else {
      return { usage };
    }
  }

  return { command: 'verify', root, dist };
}

/**
 * Runs the bin: prints each problem and gives the exit code, 0 when the bundle verifies, 1 when
 * it does not, and 64 for arguments the bin does not take.
 *
 * @param {readonly string[]} argv
 * @param {string} cwd
 * @param {{ out: (line: string) => void, error: (line: string) => void }} output
 * @returns {Promise<number>}
 */
export async function main(argv, cwd, output) {
  const parsed = parseArguments(argv, cwd);

  if ('usage' in parsed) {
    output.error(parsed.usage);

    return 64;
  }

  const report = await verifyBundle({
    root: parsed.root,
    ...(parsed.dist === undefined ? {} : { dist: parsed.dist }),
  });

  for (const problem of report.problems) {
    output.error(`The bundle does not verify: ${problem}`);
  }

  if (report.problems.length > 0) {
    return 1;
  }

  output.out(
    `The bundle verifies: ${String(report.files)} file${report.files === 1 ? '' : 's'} as the build wrote them, and a fresh build writes the same.`,
  );

  return 0;
}
