// The shared ESLint configuration for Cbox CMS (GUARDRAILS 1 and 10, gate 4): typescript-eslint
// strictTypeChecked with type information from the TypeScript project service, no explicit any,
// the no-unsafe-* rules and the React Hooks rules. A repository imports it from its own
// eslint.config.js and passes its root, so the project service finds its tsconfig.json.

import js from '@eslint/js';
import { defineConfig, globalIgnores } from 'eslint/config';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

/**
 * @typedef {object} CmsEslintOptions
 * @property {string} tsconfigRootDir The directory that holds the repository's tsconfig.json.
 * @property {string[]} [ignores] Extra paths to ignore, on top of vendor/ and build output.
 */

/**
 * The rules that make an unchecked value visible. strictTypeChecked turns them on; they are
 * listed again so a change of preset upstream cannot switch one off unnoticed.
 *
 * @type {import('eslint').Linter.RulesRecord}
 */
export const typeSafetyRules = {
  '@typescript-eslint/no-explicit-any': ['error', { fixToUnknown: false, ignoreRestArgs: false }],
  '@typescript-eslint/no-unsafe-argument': 'error',
  '@typescript-eslint/no-unsafe-assignment': 'error',
  '@typescript-eslint/no-unsafe-call': 'error',
  '@typescript-eslint/no-unsafe-declaration-merging': 'error',
  '@typescript-eslint/no-unsafe-enum-comparison': 'error',
  '@typescript-eslint/no-unsafe-function-type': 'error',
  '@typescript-eslint/no-unsafe-member-access': 'error',
  '@typescript-eslint/no-unsafe-return': 'error',
  '@typescript-eslint/no-unsafe-unary-minus': 'error',
};

/**
 * The React Hooks rules. The plugin's recommended preset leaves some as warnings; here every
 * one of them is an error, so a repository that forgets --max-warnings=0 still fails.
 *
 * @type {import('eslint').Linter.RulesRecord}
 */
export const reactHooksRules = Object.fromEntries(
  Object.keys(reactHooks.configs.flat.recommended.rules).map((rule) => [rule, 'error']),
);

/**
 * @param {CmsEslintOptions} options
 * @returns {import('eslint').Linter.Config[]}
 */
export default function cmsEslintConfig(options) {
  return defineConfig([
    globalIgnores([
      '**/vendor/',
      '**/node_modules/',
      '**/dist/',
      '**/build/',
      ...(options.ignores ?? []),
    ]),
    {
      files: ['**/*.{js,mjs,cjs,ts,mts,cts,tsx}'],
      extends: [js.configs.recommended, tseslint.configs.strictTypeChecked],
      languageOptions: {
        parserOptions: {
          projectService: true,
          tsconfigRootDir: options.tsconfigRootDir,
        },
      },
      linterOptions: {
        reportUnusedDisableDirectives: 'error',
        reportUnusedInlineConfigs: 'error',
      },
      rules: typeSafetyRules,
    },
    {
      files: ['**/*.{ts,mts,cts,tsx}'],
      extends: [reactHooks.configs.flat.recommended],
      rules: reactHooksRules,
    },
  ]);
}
