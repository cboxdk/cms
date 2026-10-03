import { Button, Form, TaskScreen } from '@cboxdk/cms-ui-kit';
import { Head, router } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import type { HomePageV1 } from '../generated/pages/HomePageV1';
import { useTranslation } from '../i18n/translations';

/**
 * The start page of a person who signed in (PRD 13.4): it says so, and signs out. Signing out
 * posts to the server, which ends the session and answers with the login page. The props are
 * HomePageV1, generated from the page's JSON Schema.
 */
export default function Home({ logout }: HomePageV1) {
  const { t } = useTranslation();

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(logout);
  }

  return (
    <>
      <Head title={t('panel.home.title')} />
      <TaskScreen title={t('panel.home.title')} description={t('panel.home.body')}>
        <Form method="post" action={logout} onSubmit={signOut}>
          <Button type="submit">{t('panel.home.sign_out')}</Button>
        </Form>
      </TaskScreen>
    </>
  );
}
