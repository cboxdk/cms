// The shared Prettier configuration for Cbox CMS (GUARDRAILS 1 and 10, gate 1). A repository
// points its .prettierrc at "@cboxdk/cms-tooling/prettier".

/** @type {import('prettier').Config} */
const config = {
  printWidth: 100,
  tabWidth: 2,
  useTabs: false,
  semi: true,
  singleQuote: true,
  trailingComma: 'all',
  bracketSpacing: true,
  arrowParens: 'always',
  endOfLine: 'lf',
};

export default config;
