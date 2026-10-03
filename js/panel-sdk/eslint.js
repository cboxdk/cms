// @cboxdk/cms-panel/eslint: the lint of an addon's panel code (section 7 of the panel extension
// architecture), on the panel's own rules: typescript-eslint strictTypeChecked with type
// information, the no-unsafe-* rules, the React Hooks rules and, in JSX, the rule against literal
// UI text, so every text comes from the addon's catalogue. On top of them an addon may not import
// what the panel keeps to itself, Inertia, React Aria and the component kit's own package, nor use
// a deprecated API, eval, new Function or a string as a timer.
//
//     // eslint.config.js of the addon
//     import cmsPanelAddonEslint from '@cboxdk/cms-panel/eslint';
//     export default cmsPanelAddonEslint({ tsconfigRootDir: import.meta.dirname });

import cmsEslintConfig, { KIT_PRIMITIVES } from '@cboxdk/cms-tooling/eslint';

/**
 * @typedef {object} PanelAddonEslintOptions
 * @property {string} tsconfigRootDir The directory that holds the addon's tsconfig.json.
 * @property {string[]} [ignores] Extra paths to ignore, on top of vendor/ and build output.
 * @stable
 */

/**
 * The imports an addon may not make, with why; the build plugin refuses the same.
 *
 * @stable
 */
export const REFUSED_IMPORTS = Object.freeze([
  {
    group: ['@inertiajs/*'],
    message:
      "Inertia is the panel's own: a contribution reaches the panel only through usePanelHost() from @cboxdk/cms-panel/extend.",
  },
  {
    group: KIT_PRIMITIVES,
    message:
      "React Aria is internal to the panel's component kit (decision D2): use the kit's components from @cboxdk/cms-panel/ui or /experimental.",
  },
  {
    group: ['@cboxdk/cms-ui-kit', '@cboxdk/cms-ui-kit/*'],
    message:
      'The component kit is private to the panel (decision D1): import its components from @cboxdk/cms-panel/ui or /experimental.',
  },
]);

/**
 * The configuration of an addon's repository.
 *
 * @param {PanelAddonEslintOptions} options
 * @returns {import('eslint').Linter.Config[]}
 * @stable
 */
export default function cmsPanelAddonEslint(options) {
  return [
    ...cmsEslintConfig({
      tsconfigRootDir: options.tsconfigRootDir,
      ignores: ['**/resources/panel/generated/', ...(options.ignores ?? [])],
    }),
    {
      files: ['**/*.{js,mjs,cjs,ts,mts,cts,tsx}'],
      rules: {
        'no-restricted-imports': ['error', { patterns: [...REFUSED_IMPORTS] }],
        '@typescript-eslint/no-deprecated': 'error',
        'no-eval': 'error',
        'no-new-func': 'error',
        '@typescript-eslint/no-implied-eval': 'error',
      },
    },
  ];
}
