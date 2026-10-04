import { Button, Form } from '@cboxdk/cms-ui-kit';
import { Head, router } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import type { AddonPageV1 } from '../generated/pages/AddonPageV1';
import { PointHost } from '../host';
import { useTranslation } from '../i18n/translations';
import { PanelShell } from '../shell/PanelShell';

/**
 * A page of an addon (PRD 13.4, section 3.4 of the panel extension architecture), at
 * /x/<namespace>/<path>: the shell, the addon's page component of the PageContribution the server
 * named, rendered by the host with its data from the deferred prop of its addon, the result of the
 * page's data query run as the viewer, and the sign-out. The props are AddonPageV1, generated from
 * the page's JSON Schema; the page's own props are its data alone.
 */
export default function Addon({ addon, logout, page }: AddonPageV1) {
  const { t } = useTranslation();

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(logout);
  }

  return (
    <>
      <Head title={t('panel.addon_page.title', { addon })} />
      <PanelShell page={page}>
        <PointHost point="shell.page@1" page={page} />
        <Form method="post" action={logout} onSubmit={signOut}>
          <Button type="submit">{t('panel.home.sign_out')}</Button>
        </Form>
      </PanelShell>
    </>
  );
}
