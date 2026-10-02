import { Alert, Button, Form, TaskScreen, TextField, TextLink } from '@cboxdk/cms-ui-kit';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import { useTranslation, type TranslationKey } from '../../i18n/translations';

/** Why the panel sent the browser here, as the server names it. */
export type SignInReason =
  'required' | 'expired' | 'ended' | 'revoked' | 'signed_out' | 'password_changed';

export interface LoginProps {
  /** The address the form posts to. */
  readonly action: string;
  /** The address of the page that asks for a password reset link. */
  readonly forgot: string;
  /** Why the panel sent the browser to this page, or null when it did not. */
  readonly reason: SignInReason | null;
}

const REASONS: Readonly<Record<SignInReason, TranslationKey>> = {
  required: 'panel.login.reason.required',
  expired: 'panel.login.reason.expired',
  ended: 'panel.login.reason.ended',
  revoked: 'panel.login.reason.revoked',
  signed_out: 'panel.login.reason.signed_out',
  password_changed: 'panel.login.reason.password_changed',
};

/**
 * The text of a catalog code the server put in the errors prop. Every refused login reads the same,
 * whether the email is unknown or the password wrong, so the page never tells whether an account
 * exists.
 */
function refusal(code: string | undefined): TranslationKey | undefined {
  switch (code) {
    case undefined:
      return undefined;
    case 'validation_required':
      return 'panel.login.required';
    case 'login_rate_limited':
      return 'panel.login.rate_limited';
    default:
      return 'panel.login.failed';
  }
}

/**
 * The login page of the panel (PRD 5.16): a member of staff signs in with the email and password
 * of their local account, or follows the link to ask for a password reset link. The form posts to the server, which answers with the start page, or
 * back here with the catalog code of the refusal in the errors prop: under the field it is about,
 * or under `form` for the login as a whole.
 */
export default function Login({ action, forgot, reason }: LoginProps) {
  const { t } = useTranslation();
  const { errors } = usePage().props;
  const form = useForm({ email: '', password: '' });

  const failed = refusal(errors['form']);
  const emailError = refusal(errors['email']);
  const passwordError = refusal(errors['password']);

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post(action, {
      preserveState: true,
      onFinish: () => {
        form.reset('password');
      },
    });
  }

  return (
    <>
      <Head title={t('panel.login.title')} />
      <TaskScreen
        title={t('panel.login.title')}
        description={t('panel.login.description')}
        footer={<TextLink href={forgot}>{t('panel.login.forgot')}</TextLink>}
      >
        {reason === null || failed !== undefined ? null : <Alert>{t(REASONS[reason])}</Alert>}
        {failed === undefined ? null : <Alert tone="danger">{t(failed)}</Alert>}
        <Form method="post" action={action} onSubmit={submit}>
          <TextField
            label={t('panel.login.email')}
            type="email"
            name="email"
            autoComplete="username"
            required
            value={form.data.email}
            onChange={(event) => {
              form.setData('email', event.target.value);
            }}
            error={emailError === undefined ? undefined : t(emailError)}
          />
          <TextField
            label={t('panel.login.password')}
            type="password"
            name="password"
            autoComplete="current-password"
            required
            value={form.data.password}
            onChange={(event) => {
              form.setData('password', event.target.value);
            }}
            error={passwordError === undefined ? undefined : t(passwordError)}
          />
          <Button type="submit" variant="primary" disabled={form.processing}>
            {t('panel.login.submit')}
          </Button>
        </Form>
      </TaskScreen>
    </>
  );
}
