// The version of the panel's API for addon UI (PRD 13.4, section 6 of the panel extension
// architecture): the stable panel points and their props, the host API, the stable tokens, the
// kit's props, the shared modules and the React major. It is the same version as the PHP side's
// Cbox\Cms\Contracts\PanelPoints\PanelApiVersion::current(), which cms:build checks an addon's
// `sdk` against; a test holds the two equal.

/**
 * A version of the panel's API: a minor version only adds, a major version may break.
 *
 * @stable
 */
export interface PanelApiVersion {
  readonly major: number;
  readonly minor: number;
}

/**
 * The version of the panel's API this SDK is, which definePanelAddon records on an addon's
 * registration.
 *
 * @stable
 */
export const PANEL_API_VERSION: PanelApiVersion = Object.freeze({ major: 1, minor: 0 });
