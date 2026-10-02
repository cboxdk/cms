import { Alert, Button, Form, TaskScreen, TextField, TextLink } from '@cboxdk/cms-ui-kit';
import { Head, useForm, usePage } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import { useTranslation, type TranslationKey } from '../../i18n/translations';

export interface ResetPasswordProps {
  /** The address the form posts to. */
  readonly action: string;
  /** The token of the link that opened the page, or null when its address holds none. */
  readonly token: string | null;
  /** The address of the page that asks for a new link. */
  readonly forgot: string;
  /** The address of the login page. */
  readonly login: string;
}

/** The text of a catalog code the server put under the password field. */
function passwordError(code: string | undefined): TranslationKey | undefined {
  switch (code) {
    case undefined:
      return undefined;
    case 'validation_required':
      return 'panel.reset.required';
    case 'password_too_short':
      return 'panel.reset.too_short';
    case 'password_too_long':
      return 'panel.reset.too_long';
    default:
      return 'panel.reset.breached';
  }
}

/**
 * The page a password reset link opens (PRD 5.16): the person chooses a new password, which the
 * server holds to the password policy, and is signed in with it. A link that is unknown, used or
 * expired is one refusal, password_reset_token_invalid, and the page then offers a new link
 * instead of the form.
 */
export default function ResetPassword({ action, token, forgot, login }: ResetPasswordProps) {
  const { t } = useTranslation();
  const { errors } = usePage().props;
  const form = useForm({ token: token ?? '', password: '' });

  const invalid = token === null || errors['form'] === 'password_reset_token_invalid';
  const unchecked = errors['form'] === 'breached_passwords_unavailable';
  const fieldError = passwordError(errors['password']);

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
      <Head title={t('panel.reset.title')} />
      <TaskScreen
        title={t('panel.reset.title')}
        description={invalid ? undefined : t('panel.reset.description')}
        footer={
          <>
            {invalid ? <TextLink href={forgot}>{t('panel.reset.request_new')}</TextLink> : null}
            <TextLink href={login}>{t('panel.reset.back')}</TextLink>
          </>
        }
      >
        {invalid ? (
          <Alert tone="danger">{t('panel.reset.invalid')}</Alert>
        ) : (
          <>
            {unchecked ? <Alert tone="danger">{t('panel.reset.check_unavailable')}</Alert> : null}
            <Form method="post" action={action} onSubmit={submit}>
              <input type="hidden" name="token" value={form.data.token} />
              <TextField
                label={t('panel.reset.password')}
                hint={t('panel.reset.hint')}
                type="password"
                name="password"
                autoComplete="new-password"
                required
                value={form.data.password}
                onChange={(event) => {
                  form.setData('password', event.target.value);
                }}
                error={fieldError === undefined ? undefined : t(fieldError)}
              />
              <Button type="submit" variant="primary" disabled={form.processing}>
                {t('panel.reset.submit')}
              </Button>
            </Form>
          </>
        )}
      </TaskScreen>
    </>
  );
}
