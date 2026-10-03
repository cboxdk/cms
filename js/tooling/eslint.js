// The shared ESLint configuration for Cbox CMS (GUARDRAILS 1, 8 and 10, gate 4): typescript-eslint
// strictTypeChecked with type information from the TypeScript project service, no explicit any,
// the no-unsafe-* rules, the React Hooks rules and, in JSX, the rule against literal UI text. A
// repository imports it from its own eslint.config.js and passes its root, so the project service
// finds its tsconfig.json. A repository with the component kit names where it lives: the kit's
// components then may not accept className or style props, the kit imports React Aria by
// component and never from the package's root, and the code that uses the kit may not import
// React Aria at all, because its primitives are internal to the kit.

import js from '@eslint/js';
import { defineConfig, globalIgnores } from 'eslint/config';
import reactHooks from 'eslint-plugin-react-hooks';
import tseslint from 'typescript-eslint';

import { noLiteralUiText } from './no-literal-ui-text.js';
import { noStyleProps } from './no-style-props.js';

/**
 * @typedef {object} CmsEslintOptions
 * @property {string} tsconfigRootDir The directory that holds the repository's tsconfig.json.
 * @property {string[]} [ignores] Extra paths to ignore, on top of vendor/ and build output.
 * @property {CmsKitOptions} [kit] Where the component kit lives, when the repository has it.
 */

/**
 * @typedef {object} CmsKitOptions
 * @property {string} directory The kit's source directory, relative to the root, such as "js/ui-kit/src".
 * @property {string[]} consumers The directories of the code that uses the kit, relative to the
 *   root, such as "js/panel", which may not import the kit's primitives.
 */

/**
 * The packages of React Aria, the headless primitives the kit builds on (decision D2), which no
 * code outside the kit may import.
 */
export const KIT_PRIMITIVES = [
  'react-aria-components',
  'react-aria-components/*',
  'react-aria',
  'react-aria/*',
  'react-stately',
  'react-stately/*',
  '@react-aria/*',
  '@react-stately/*',
  '@react-types/*',
];

/**
 * The rules that hold the kit and its consumers, for a repository that has the kit.
 *
 * @param {CmsKitOptions} kit
 * @returns {import('eslint').Linter.Config[]}
 */
function kitConfigs(kit) {
  return [
    {
      files: [`${kit.directory}/**/*.tsx`],
      plugins: { 'cms-kit': { rules: { 'no-style-props': eslintRule(noStyleProps) } } },
      rules: { 'cms-kit/no-style-props': 'error' },
    },
    {
      files: [`${kit.directory}/**/*.{ts,tsx}`],
      rules: {
        'no-restricted-imports': [
          'error',
          {
            paths: [
              {
                name: 'react-aria-components',
                message:
                  'Import each primitive from its own module, such as react-aria-components/Dialog: the declarations of the package root do not compile under exactOptionalPropertyTypes (PROGRESS.md, B1-X2). Where a module of its own does not compile either, use the hook from react-aria.',
              },
            ],
          },
        ],
      },
    },
    {
      files: kit.consumers.map((glob) => `${glob.replace(/\/$/, '')}/**/*.{ts,tsx}`),
      rules: {
        'no-restricted-imports': [
          'error',
          {
            patterns: [
              {
                group: KIT_PRIMITIVES,
                message:
                  "React Aria is internal to the component kit (decision D2): use the kit's components, which have props of their own.",
              },
            ],
          },
        ],
      },
    },
  ];
}

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
 * A rule written with typescript-eslint's RuleCreator, as ESLint's own types name a rule. The two
 * describe the same runtime object, but typescript-eslint types the rule context with its own,
 * wider interface that ESLint's declarations lack, so the rule is cast through unknown here, once.
 *
 * @param {import('@typescript-eslint/utils').TSESLint.RuleModule<string, unknown[]>} rule
 * @returns {import('eslint').Rule.RuleModule}
 */
function eslintRule(rule) {
  return /** @type {import('eslint').Rule.RuleModule} */ (/** @type {unknown} */ (rule));
}

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
    {
      files: ['**/*.tsx'],
      plugins: {
        cms: { rules: { 'no-literal-ui-text': eslintRule(noLiteralUiText) } },
      },
      rules: {
        'cms/no-literal-ui-text': 'error',
      },
    },
    ...(options.kit === undefined ? [] : kitConfigs(options.kit)),
  ]);
}
