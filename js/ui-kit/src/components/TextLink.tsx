import type { AnchorHTMLAttributes, ReactNode } from 'react';

import './text-link.css';

export interface TextLinkProps extends Omit<
  AnchorHTMLAttributes<HTMLAnchorElement>,
  'children' | 'className' | 'href' | 'style'
> {
  /** Where the link goes. */
  readonly href: string;
  /** The link's text, from the caller's translations. */
  readonly children: ReactNode;
}

/**
 * A link of the kit. It is a plain anchor, so it opens, copies and focuses as the platform does;
 * it is underlined, so it is told from text by more than its colour, and its target is at least
 * 24 pixels high (WCAG 2.2, 2.5.8).
 */
export function TextLink({ href, children, ...rest }: TextLinkProps) {
  return (
    <a {...rest} href={href} className="cms-text-link">
      {children}
    </a>
  );
}
