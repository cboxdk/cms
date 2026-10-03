// @cboxdk/cms-panel/storybook: the Storybook preset of an addon (section 2.7 of the panel extension
// architecture), so an addon's stories render as the panel renders its contributions: the panel's
// cascade layers and design tokens, the locale and the theme from the toolbar through the kit's
// KitI18nProvider and data-theme on the document, and axe through the accessibility addon, whose
// violations fail a story's test.
//
//     // .storybook/main.js of the addon
//     export default { framework: '@storybook/react-vite', addons: ['@cboxdk/cms-panel/storybook'] };

import { fileURLToPath } from 'node:url';

/**
 * The preview the preset adds to every story.
 *
 * @stable
 */
export const PREVIEW = fileURLToPath(new URL('./preview.tsx', import.meta.url));

/**
 * The addons the preset brings.
 *
 * @stable
 */
export const addons = ['@storybook/addon-a11y'];

/**
 * The preview annotations: Storybook's own and the addon's, then the SDK's preview.
 *
 * @param {string[]} [entries]
 * @returns {string[]}
 * @stable
 */
export function previewAnnotations(entries = []) {
  return [...entries, PREVIEW];
}
