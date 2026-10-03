import './brand.css';

/**
 * An image of a brand in a light and a dark version, and the text that stands for it.
 *
 * @experimental
 */
export interface BrandLogo {
  /** The address of the version shown in the light mode. */
  readonly light: string;
  /** The address of the version shown in the dark mode. */
  readonly dark: string;
  /** The alternative text a screen reader announces for the image. */
  readonly alt: string;
}

/**
 * The props of Brand.
 *
 * @experimental
 */
export interface BrandProps {
  /** The product name, shown as text beside the logo. */
  readonly name: string;
  /** The logo, or null for the name alone. */
  readonly logo?: BrandLogo | null;
}

/**
 * The installation's brand: its logo, in the version of the colour mode the page is shown in, and
 * its product name. The installation sets both, never an addon; without them the caller passes the
 * panel's own name. Only the logo of the current mode is shown, so a screen reader announces one
 * alternative text.
 *
 * @experimental
 */
export function Brand({ name, logo = null }: BrandProps) {
  return (
    <span className="cms-brand">
      {logo === null ? null : (
        <>
          <img className="cms-brand__logo cms-brand__logo--light" src={logo.light} alt={logo.alt} />
          <img className="cms-brand__logo cms-brand__logo--dark" src={logo.dark} alt={logo.alt} />
        </>
      )}
      <span className="cms-brand__name">{name}</span>
    </span>
  );
}
