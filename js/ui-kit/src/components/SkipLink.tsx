import './skip-link.css';

/**
 * The props of SkipLink.
 *
 * @experimental
 */
export interface SkipLinkProps {
  /** The id of the element the link moves focus to, such as the page's main landmark. */
  readonly target: string;
  /** The link's text, such as "Skip to the content", from the caller's translations. */
  readonly children: string;
}

/**
 * The first link of a page, shown only while it has focus, that moves focus past the navigation to
 * the content (WCAG 2.2, 2.4.1). AppShell renders one; a page outside the shell renders its own.
 * The target must be focusable, such as an element with tabindex -1.
 *
 * @experimental
 */
export function SkipLink({ target, children }: SkipLinkProps) {
  return (
    <a
      href={`#${target}`}
      className="cms-skip-link"
      onClick={(event) => {
        const element = document.getElementById(target);

        if (element !== null) {
          event.preventDefault();
          element.focus();
        }
      }}
    >
      {children}
    </a>
  );
}
