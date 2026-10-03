import { useEffect, useId, useRef, useState, type ReactNode } from 'react';

import { useKitTranslation } from '../i18n/translations';
import { Icon } from './Icon';
import { SkipLink } from './SkipLink';

import './app-shell.css';
import './icon-button.css';

/**
 * The props of AppShell.
 *
 * @experimental
 */
export interface AppShellProps {
  /** The installation's name or logo, at the start of the top bar. */
  readonly brand: ReactNode;
  /** The panel's navigation, a SideNav. */
  readonly navigation: ReactNode;
  /** What sits at the end of the top bar, such as the command palette's button and the account. */
  readonly actions?: ReactNode;
  /** The page, a Page. */
  readonly children: ReactNode;
}

/**
 * The frame of every page of the panel: a skip link to the content, the top bar (the page's
 * banner) with the brand and the actions, the navigation beside the content on a wide screen, and
 * the page in the main landmark. Where the shell is narrow, as on a phone, the navigation is hidden behind a button in the
 * top bar, named in the kit's own text, that shows it above the content; Escape hides it again and
 * returns focus to the button, as does choosing an entry.
 *
 * @experimental
 */
export function AppShell({ brand, navigation, actions, children }: AppShellProps) {
  const t = useKitTranslation();
  const id = useId();
  const mainId = `${id}-main`;
  const navigationId = `${id}-navigation`;
  const [open, setOpen] = useState(false);
  const toggle = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    const close = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setOpen(false);
        toggle.current?.focus();
      }
    };
    document.addEventListener('keydown', close);

    return () => {
      document.removeEventListener('keydown', close);
    };
  }, [open]);

  return (
    <div className="cms-app-shell">
      <div className="cms-app-shell__frame" data-navigation-open={open}>
        <SkipLink target={mainId}>{t('kit.shell.skip')}</SkipLink>
        <header className="cms-app-shell__bar">
          <button
            ref={toggle}
            type="button"
            className="cms-icon-button cms-app-shell__toggle"
            aria-label={t('kit.shell.navigation')}
            aria-expanded={open}
            aria-controls={navigationId}
            onClick={() => {
              setOpen((current) => !current);
            }}
          >
            <Icon name={open ? 'close' : 'menu'} />
          </button>
          <div className="cms-app-shell__brand">{brand}</div>
          {actions === undefined ? null : <div className="cms-app-shell__actions">{actions}</div>}
        </header>
        <div
          id={navigationId}
          className="cms-app-shell__navigation"
          onClick={(event) => {
            if (event.target instanceof Element && event.target.closest('a') !== null) {
              setOpen(false);
            }
          }}
        >
          {navigation}
        </div>
        <main id={mainId} className="cms-app-shell__main" tabIndex={-1}>
          {children}
        </main>
      </div>
    </div>
  );
}
