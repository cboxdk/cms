// The shell every page behind the login renders around its content (PRD 13.4, section 8 of the
// panel extension architecture): the kit's AppShell, with the installation's brand and the actions
// of the viewer's menu, which addons contribute to shell.user-menu@1, in the top bar, the
// navigation beside the content, the nav entries of shell.nav@1 that the server left for the
// viewer, each opening one of the panel's own pages, such as the who-am-I page the core contributes
// the entry of, or a page of an addon below /x/<namespace>/, and the page in the main landmark the
// skip link leads to. The command palette opens from every page with Ctrl+K or Command+K and from
// its button in the top bar, built from the prop `palette` every page behind the login shares; the
// nav entries are its pages too. Each page keeps its sign-out in its own content.

import { AppShell, Brand, SideNav } from '@cboxdk/cms-ui-kit';
import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { useBrand } from '../brand';
import { PointHost, usePointHost } from '../host';
import { useTranslation } from '../i18n/translations';
import { PanelPalette } from './PanelPalette';

/** The props of PanelShell. */
export interface PanelShellProps {
  /** The id of the page shown, which its nav entry is marked as; undefined for a page without an entry. */
  readonly page?: string | undefined;
  readonly children: ReactNode;
}

/** The shell around a page behind the login: the brand, the viewer's menu, the navigation and the main landmark. */
export function PanelShell({ page, children }: PanelShellProps) {
  const { t } = useTranslation();
  const brand = useBrand();
  const { nav } = usePointHost('shell.nav@1');
  const palette = usePage().props.palette;

  return (
    <AppShell
      brand={<Brand name={brand.name} logo={brand.logo} />}
      actions={
        <>
          <PanelPalette palette={palette} />
          <PointHost point="shell.user-menu@1" label={t('panel.shell.user_menu')} />
        </>
      }
      navigation={
        nav.length === 0 ? null : (
          <SideNav
            label={t('panel.shell.nav')}
            groups={[
              {
                id: 'pages',
                items: nav.map((entry) => ({
                  id: entry.id,
                  label: entry.label,
                  href: entry.url,
                  current: entry.page === page,
                })),
              },
            ]}
          />
        )
      }
    >
      {children}
    </AppShell>
  );
}
