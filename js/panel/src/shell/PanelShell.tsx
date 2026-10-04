// The shell every page behind the login renders around its content (PRD 13.4, section 8 of the
// panel extension architecture): the header with the installation's brand and the actions of the
// viewer's menu, which addons contribute to shell.user-menu@1, and the navigation of the addons'
// pages, the nav entries of shell.nav@1 that the server left for the viewer, each opening a page
// below /x/<namespace>/. The entries are the pages of the command palette too. Each page keeps its
// sign-out in its own content.

import { Brand, ShellHeader, SideNav } from '@cboxdk/cms-ui-kit';
import type { ReactNode } from 'react';

import { useBrand } from '../brand';
import { PointHost, usePointHost } from '../host';
import { useTranslation } from '../i18n/translations';

/** The props of PanelShell. */
export interface PanelShellProps {
  /** The id of the page shown, which its nav entry is marked as; undefined for the panel's own pages. */
  readonly page?: string | undefined;
  readonly children: ReactNode;
}

/** The shell around a page behind the login: the brand, the viewer's menu and the navigation. */
export function PanelShell({ page, children }: PanelShellProps) {
  const { t } = useTranslation();
  const brand = useBrand();
  const { nav } = usePointHost('shell.nav@1');

  return (
    <>
      <ShellHeader brand={<Brand name={brand.name} logo={brand.logo} />}>
        <PointHost point="shell.user-menu@1" label={t('panel.shell.user_menu')} />
      </ShellHeader>
      {nav.length === 0 ? null : (
        <SideNav
          label={t('panel.shell.nav')}
          groups={[
            {
              id: 'addons',
              items: nav.map((entry) => ({
                id: entry.id,
                label: entry.label,
                href: entry.url,
                current: entry.page === page,
              })),
            },
          ]}
        />
      )}
      {children}
    </>
  );
}
