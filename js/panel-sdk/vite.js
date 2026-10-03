// @cboxdk/cms-panel/vite: the Vite plugin an addon builds its panel bundle with (section 4.5 of
// the panel extension architecture).
//
//     import cmsPanelAddon from '@cboxdk/cms-panel/vite';
//     export default defineConfig({ plugins: [cmsPanelAddon()] });
//
// It leaves the panel's shared modules to the panel's import map: React, its JSX runtime, React
// DOM and the SDK with its subpaths are external, so the bundle runs on the panel's own React and
// kit and never on a second copy. It fails the build on an import an addon may not make: Inertia,
// which the panel's import map refuses an addon at run time too, React Aria and the component kit's
// own package, whose components an addon reaches only through @cboxdk/cms-panel, and a module
// loaded from a URL, which the panel's content security policy would refuse.

/**
 * The bare specifiers of the panel's shared React modules, as js/panel/shared-modules.json lists
 * them and the panel's import map maps them; a test holds the two equal.
 *
 * @stable
 */
export const SHARED_MODULES = Object.freeze([
  'react',
  'react/jsx-runtime',
  'react-dom',
  'react-dom/client',
]);

/**
 * The SDK's package, which the import map maps with every subpath to the panel's own copy.
 *
 * @stable
 */
export const SDK = '@cboxdk/cms-panel';

/**
 * Each import an addon may not make, with why.
 *
 * @type {readonly { matches: (specifier: string) => boolean, reason: string }[]}
 */
const REFUSED = Object.freeze([
  {
    matches: (specifier) => specifier.startsWith('@inertiajs/'),
    reason:
      "Inertia is the panel's own: a contribution reaches the panel only through usePanelHost(), and the panel's import map refuses Inertia to an addon at run time too.",
  },
  {
    matches: (specifier) =>
      /^(?:react-aria-components|react-aria|react-stately)(?:\/|$)/.test(specifier) ||
      /^@(?:react-aria|react-stately|react-types)\//.test(specifier),
    reason:
      "React Aria is internal to the panel's component kit (decision D2): use the kit's components from @cboxdk/cms-panel/ui or /experimental.",
  },
  {
    matches: (specifier) =>
      specifier === '@cboxdk/cms-ui-kit' || specifier.startsWith('@cboxdk/cms-ui-kit/'),
    reason:
      'The component kit is private to the panel (decision D1): import its components from @cboxdk/cms-panel/ui or /experimental.',
  },
  {
    matches: (specifier) =>
      /^(?:[a-z][a-z0-9+.-]*:)?\/\//i.test(specifier) || /^(?:https?|data|blob):/i.test(specifier),
    reason:
      "A module is loaded only from the addon's own bundle: the panel's content security policy refuses every other origin.",
  },
]);

/**
 * Whether the import is one of the panel's shared modules, which the bundle leaves to the import
 * map.
 *
 * @param {string} specifier
 * @returns {boolean}
 * @stable
 */
export function isShared(specifier) {
  return SHARED_MODULES.includes(specifier) || specifier === SDK || specifier.startsWith(`${SDK}/`);
}

/**
 * Why an addon may not import the module, or null when it may.
 *
 * @param {string} specifier
 * @returns {string | null}
 * @stable
 */
export function refusal(specifier) {
  return REFUSED.find((refused) => refused.matches(specifier))?.reason ?? null;
}

/**
 * The plugin.
 *
 * @returns {import('vite').Plugin}
 * @stable
 */
export default function cmsPanelAddon() {
  return {
    name: 'cboxdk-cms-panel-addon',
    enforce: 'pre',
    resolveId(source, importer) {
      const reason = refusal(source);

      if (reason !== null) {
        this.error(
          `${importer === undefined ? 'The bundle' : importer} imports ${source}, which an addon of the Cbox CMS panel may not import. ${reason}`,
        );
      }

      return isShared(source) ? { id: source, external: true } : null;
    },
  };
}
