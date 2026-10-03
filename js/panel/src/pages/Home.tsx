import { Brand, Button, Form, ShellHeader, TaskScreen } from '@cboxdk/cms-ui-kit';
import { Head, router } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import { useBrand } from '../brand';
import type { HomePageV1 } from '../generated/pages/HomePageV1';
import { useTranslation } from '../i18n/translations';

/**
 * The start page of a person who signed in (PRD 13.4): the shell's header with the installation's
 * brand, and a panel that says the person signed in and signs out. Signing out
 * posts to the server, which ends the session and answers with the login page. The props are
 * HomePageV1, generated from the page's JSON Schema.
 */
export default function Home({ logout }: HomePageV1) {
  const { t } = useTranslation();
  const brand = useBrand();

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(logout);
  }

  return (
    <>
      <Head title={t('panel.home.title')} />
      <ShellHeader brand={<Brand name={brand.name} logo={brand.logo} />} />
      <TaskScreen title={t('panel.home.title')} description={t('panel.home.body')}>
        <Form method="post" action={logout} onSubmit={signOut}>
          <Button type="submit">{t('panel.home.sign_out')}</Button>
        </Form>
      </TaskScreen>
    </>
  );
}
