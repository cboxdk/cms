import { Brand, Button, Callout, Form, TaskScreen, TextInput, TextLink } from '@cboxdk/cms-ui-kit';
import { Head, useForm } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import type {
  ResetPasswordPageV1,
  ResetPasswordRefusal,
} from '../../generated/pages/ResetPasswordPageV1';
import { useBrand } from '../../brand';
import { useTranslation, type TranslationKey } from '../../i18n/translations';

/** The text of each catalog code the server puts under the password field. */
const PASSWORD_REFUSALS: Readonly<Record<ResetPasswordRefusal, TranslationKey>> = {
  validation_required: 'panel.reset.required',
  password_too_short: 'panel.reset.too_short',
  password_too_long: 'panel.reset.too_long',
  password_breached: 'panel.reset.breached',
};

/**
 * The page a password reset link opens (PRD 5.16): the person chooses a new password, which the
 * server holds to the password policy, and is signed in with it. A link that is unknown, used or
 * expired is one refusal, password_reset_token_invalid, and the page then offers a new link
 * instead of the form. The props are ResetPasswordPageV1, generated from the page's JSON Schema.
 */
export default function ResetPassword({
  action,
  token,
  forgot,
  login,
  refusals,
}: ResetPasswordPageV1) {
  const { t } = useTranslation();
  const brand = useBrand();
  const form = useForm({ token: token ?? '', password: '' });

  const invalid = token === null || refusals.form === 'password_reset_token_invalid';
  const unchecked = refusals.form === 'breached_passwords_unavailable';
  const fieldError = refusals.password === null ? undefined : PASSWORD_REFUSALS[refusals.password];

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
        brand={<Brand name={brand.name} logo={brand.login} />}
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
          <Callout tone="danger">{t('panel.reset.invalid')}</Callout>
        ) : (
          <>
            {unchecked ? (
              <Callout tone="danger">{t('panel.reset.check_unavailable')}</Callout>
            ) : null}
            <Form method="post" action={action} onSubmit={submit}>
              <input type="hidden" name="token" value={form.data.token} />
              <TextInput
                label={t('panel.reset.password')}
                description={t('panel.reset.hint')}
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
