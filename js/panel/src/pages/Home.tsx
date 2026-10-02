import { Button, Form, TaskScreen } from '@cboxdk/cms-ui-kit';
import { Head, router } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import { useTranslation } from '../i18n/translations';

export interface HomeProps {
  /** The address the logout posts to. */
  readonly logout: string;
}

/**
 * The start page of a person who signed in (PRD 13.4): it says so, and signs out. Signing out
 * posts to the server, which ends the session and answers with the login page.
 */
export default function Home({ logout }: HomeProps) {
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
