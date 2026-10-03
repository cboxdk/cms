import { Alert, Brand, Button, Form, TaskScreen, TextField, TextLink } from '@cboxdk/cms-ui-kit';
import { Head, useForm } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import type { LoginPageV1, LoginRefusal, SignInReason } from '../../generated/pages/LoginPageV1';
import { useBrand } from '../../brand';
import { useTranslation, type TranslationKey } from '../../i18n/translations';

const REASONS: Readonly<Record<SignInReason, TranslationKey>> = {
  required: 'panel.login.reason.required',
  expired: 'panel.login.reason.expired',
  ended: 'panel.login.reason.ended',
  revoked: 'panel.login.reason.revoked',
  signed_out: 'panel.login.reason.signed_out',
  password_changed: 'panel.login.reason.password_changed',
};

/**
 * The text of each catalog code a login is refused with. Every refused login reads the same,
 * whether the email is unknown or the password wrong, so the page never tells whether an account
 * exists.
 */
const REFUSALS: Readonly<Record<LoginRefusal, TranslationKey>> = {
  validation_required: 'panel.login.required',
  login_rejected: 'panel.login.failed',
  login_rate_limited: 'panel.login.rate_limited',
};

function refusal(code: LoginRefusal | null): TranslationKey | undefined {
  return code === null ? undefined : REFUSALS[code];
}

/**
 * The login page of the panel (PRD 5.16): a member of staff signs in with the email and password
 * of their local account, or follows the link to ask for a password reset link. The form posts to
 * the server, which answers with the start page, or back here with the catalog code of the refusal
 * in the refusals prop: under the field it is about, or under `form` for the login as a whole. The
 * props are LoginPageV1, generated from the page's JSON Schema.
 */
export default function Login({ action, forgot, reason, refusals }: LoginPageV1) {
  const { t } = useTranslation();
  const brand = useBrand();
  const form = useForm({ email: '', password: '' });

  const failed = refusal(refusals.form);
  const emailError = refusal(refusals.email);
  const passwordError = refusal(refusals.password);

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
        brand={<Brand name={brand.name} logo={brand.login} />}
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
