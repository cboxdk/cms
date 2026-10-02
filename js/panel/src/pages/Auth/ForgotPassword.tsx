import { Alert, Button, Form, TaskScreen, TextField, TextLink } from '@cboxdk/cms-ui-kit';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import { useTranslation } from '../../i18n/translations';

export interface ForgotPasswordProps {
  /** The address the form posts to. */
  readonly action: string;
  /** The address of the login page. */
  readonly login: string;
  /** Whether a request for a link was taken just now. */
  readonly requested: boolean;
  /** How many minutes a link works. */
  readonly minutes: number;
}

/**
 * The page that asks for a password reset link (PRD 5.16). The server answers every request the
 * same, whether an account has the email or not, so the page says only that a link is on its way
 * if one does. An email left empty comes back with validation_required under the field.
 */
export default function ForgotPassword({ action, login, requested, minutes }: ForgotPasswordProps) {
  const { t } = useTranslation();
  const { errors } = usePage().props;
  const form = useForm({ email: '' });

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post(action, { preserveState: true });
  }

  return (
    <>
      <Head title={t('panel.forgot.title')} />
      <TaskScreen
        title={t('panel.forgot.title')}
        description={t('panel.forgot.description')}
        footer={<TextLink href={login}>{t('panel.forgot.back')}</TextLink>}
      >
        {requested ? <Alert>{t('panel.forgot.requested', { minutes })}</Alert> : null}
        <Form method="post" action={action} onSubmit={submit}>
          <TextField
            label={t('panel.forgot.email')}
            type="email"
            name="email"
            autoComplete="username"
            required
            value={form.data.email}
            onChange={(event) => {
              form.setData('email', event.target.value);
            }}
            error={errors['email'] === undefined ? undefined : t('panel.forgot.required')}
          />
          <Button type="submit" variant="primary" disabled={form.processing}>
            {t('panel.forgot.submit')}
          </Button>
        </Form>
      </TaskScreen>
    </>
  );
}
