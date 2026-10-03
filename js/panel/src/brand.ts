// The installation's brand (PRD 13.4): its product name and logos, which every page shares as the
// prop brand, written by the server's PanelBrandCodecV1 from cbox-cms.panel.branding, or the
// panel's own name without branding. No theme or addon sets any of it.

import { usePage } from '@inertiajs/react';

import type { PanelBrandV1 } from './generated/pages/PanelBrandV1';

/** The props every page of the panel shares, besides its own. */
interface SharedProps {
  readonly brand: PanelBrandV1;
  readonly [prop: string]: unknown;
}

/** The installation's brand, from the prop every page shares. */
export function useBrand(): PanelBrandV1 {
  return usePage<SharedProps>().props.brand;
}

/**
 * The product name the server wrote into the page's application-name meta element when the
 * installation sets one, or undefined, for the document's title, which is set outside any page.
 */
export function applicationName(): string | undefined {
  const name = document.querySelector<HTMLMetaElement>('meta[name="application-name"]')?.content;

  return name === undefined || name === '' ? undefined : name;
}
