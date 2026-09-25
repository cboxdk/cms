// The JS gate for the monorepo (GUARDRAILS 10, gate 4). The rules live in js/tooling; this file
// only tells them where the tsconfig.json is and leaves out the agent harness.

import cmsEslintConfig from '@cboxdk/cms-tooling/eslint';

export default cmsEslintConfig({
  tsconfigRootDir: import.meta.dirname,
  ignores: ['.claude/', '.harness/'],
});
