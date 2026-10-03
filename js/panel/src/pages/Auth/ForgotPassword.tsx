import { Brand, Button, Callout, Form, TaskScreen, TextInput, TextLink } from '@cboxdk/cms-ui-kit';
import { Head, useForm } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import type {
  ForgotPasswordPageV1,
  ForgotPasswordRefusal,
} from '../../generated/pages/ForgotPasswordPageV1';
import { useBrand } from '../../brand';
import { showcase } from '../../showcase';
import { useTranslation, type TranslationKey } from '../../i18n/translations';

/** The text of each catalog code a request for a link is refused with. */
const REFUSALS: Readonly<Record<ForgotPasswordRefusal, TranslationKey>> = {
  validation_required: 'panel.forgot.required',
};

/**
 * The page that asks for a password reset link (PRD 5.16). The server answers every request the
 * same, whether an account has the email or not, so the page says only that a link is on its way
 * if one does. An email left empty comes back with validation_required under the field. The props
 * are ForgotPasswordPageV1, generated from the page's JSON Schema.
 */
export default function ForgotPassword({
  action,
  login,
  requested,
  minutes,
  refusals,
}: ForgotPasswordPageV1) {
  const { t } = useTranslation();
  const brand = useBrand();
  const form = useForm({ email: '' });

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    form.post(action, { preserveState: true });
  }

  return (
    <>
      <Head title={t('panel.forgot.title')} />
      <TaskScreen
        brand={<Brand name={brand.name} logo={brand.login} />}
        showcase={showcase(t)}
        title={t('panel.forgot.title')}
        description={t('panel.forgot.description')}
        footer={<TextLink href={login}>{t('panel.forgot.back')}</TextLink>}
      >
        {requested ? <Callout>{t('panel.forgot.requested', { minutes })}</Callout> : null}
        <Form method="post" action={action} onSubmit={submit}>
          <TextInput
            label={t('panel.forgot.email')}
            type="email"
            name="email"
            autoComplete="username"
            required
            value={form.data.email}
            onChange={(event) => {
              form.setData('email', event.target.value);
            }}
            error={refusals.email === null ? undefined : t(REFUSALS[refusals.email])}
          />
          <Button type="submit" variant="primary" disabled={form.processing}>
            {t('panel.forgot.submit')}
          </Button>
        </Form>
      </TaskScreen>
    </>
  );
}
