// @cboxdk/cms-panel/stylelint: the stylelint configuration of an addon's panel styles (sections 2.3
// and 2.4 of the panel extension architecture), with stylelint's own rules only. An addon's styles
// read the design tokens as var(--cms-*), so they set no colour of their own, never use !important,
// which would break the cascade layers' order, select no element by id, load nothing from a URL and
// import no other stylesheet, because the panel serves only the addon's own bundle. The build
// plugin also wraps them in the layer cms.addon, and cms:build refuses a stylesheet outside it.
//
//     // stylelint.config.js of the addon
//     export { default } from '@cboxdk/cms-panel/stylelint';

/**
 * A stylelint configuration of stylelint's own rules.
 *
 * @typedef {object} StylelintConfig
 * @property {Readonly<Record<string, unknown>>} rules
 * @stable
 */

/**
 * The configuration of an addon's styles.
 *
 * @type {StylelintConfig}
 * @stable
 */
const config = Object.freeze({
  rules: Object.freeze({
    'declaration-no-important': true,
    'color-no-hex': true,
    'color-named': 'never',
    'function-disallowed-list': [
      'rgb',
      'rgba',
      'hsl',
      'hsla',
      'hwb',
      'lab',
      'lch',
      'oklab',
      'oklch',
      'url',
    ],
    'selector-max-id': 0,
    'at-rule-disallowed-list': ['import', 'font-face'],
    'custom-property-pattern': '^cms-addon-[a-z0-9]+(?:-[a-z0-9]+)*$',
  }),
});

export default config;
