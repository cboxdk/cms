// The JS gate for the monorepo (GUARDRAILS 10, gate 4). The rules live in js/tooling; this file
// only tells them where the tsconfig.json and the component kit are, which code uses the kit, and
// leaves out the agent harness and the tool caches in .cache/.

import cmsEslintConfig from '@cboxdk/cms-tooling/eslint';

export default cmsEslintConfig({
  tsconfigRootDir: import.meta.dirname,
  ignores: ['.cache/', '.claude/', '.harness/', 'workbench/addons/*/dist/'],
  kit: {
    directory: 'js/ui-kit/src',
    consumers: ['js/panel', 'js/panel-sdk', 'workbench/resources/js'],
  },
});
