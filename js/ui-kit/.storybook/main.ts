// The Storybook of the component kit (GUARDRAILS 8: every component has a story and a visual
// regression test). It shows the stories in js/ui-kit/stories, written against the kit's public
// entry @cboxdk/cms-ui-kit as a page or an addon uses it. Gate 7 builds it
// (`npm run storybook:build`), checks that every component the kit exports has a story
// (`npm run storybook:exports`) and runs every story as a test in Chromium through Vitest
// (`npm run storybook:stories`): its play function, axe through the accessibility addon, and a
// screenshot compared with the baseline committed in js/ui-kit/visual-baselines.
//
// Telemetry and the notifications of new versions are off, so neither a build nor a test run
// reaches the network. The configuration is a plain object: Storybook's own declarations do not
// compile under the repository's tsconfig (exactOptionalPropertyTypes without skipLibCheck), so
// no file of the repository imports them; stories/csf.ts describes the part the stories use.

const config = {
  framework: '@storybook/react-vite',
  stories: ['../stories/**/*.stories.tsx'],
  addons: ['@storybook/addon-a11y', '@storybook/addon-vitest'],
  core: { disableTelemetry: true, disableWhatsNewNotifications: true },
};

export default config;
