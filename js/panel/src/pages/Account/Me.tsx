import {
  Badge,
  Button,
  DataTable,
  DescriptionList,
  EmptyState,
  ErrorState,
  Form,
  Page,
  PageHeader,
  ProblemDetails,
  Section,
} from '@cboxdk/cms-ui-kit';
import { Head, router } from '@inertiajs/react';
import type { SubmitEvent } from 'react';

import type { AccountMePageV1 } from '../../generated/pages/AccountMePageV1';
import {
  validateActorMeV1,
  type ActorClass,
  type ActorMeV1,
  type ActorState,
  type GrantEffect,
  type OwnGrantV1,
} from '../../generated/protocol/ActorMeV1';
import { validateProblemV1 } from '../../generated/protocol/ProblemV1';
import { PointHost } from '../../host';
import { useTranslation, type TranslationKey } from '../../i18n/translations';
import { PanelShell } from '../../shell/PanelShell';

/** The text of each actor class, in the panel's catalogue. */
const CLASSES: Readonly<Record<ActorClass, TranslationKey>> = {
  staff: 'panel.account_me.class.staff',
  end_user: 'panel.account_me.class.end_user',
  service: 'panel.account_me.class.service',
};

/** The text of each actor state, in the panel's catalogue. */
const STATES: Readonly<Record<ActorState, TranslationKey>> = {
  pending: 'panel.account_me.state.pending',
  active: 'panel.account_me.state.active',
  deactivated: 'panel.account_me.state.deactivated',
  deprovisioned: 'panel.account_me.state.deprovisioned',
};

/** The text of each grant effect, in the panel's catalogue. */
const EFFECTS: Readonly<Record<GrantEffect, TranslationKey>> = {
  allow: 'panel.account_me.effect.allow',
  deny: 'panel.account_me.effect.deny',
};

/**
 * The who-am-I page (PRD 5.16, 13.4): the person's own actor, profile and grants, read with
 * actor.me as the person. The props are AccountMePageV1, generated from the page's JSON Schema:
 * the read's result, a document of actor.me.result.v1.json the page checks with the generated
 * validator before it shows it, or the rejection, the problem details of a rejected read, which the
 * page shows as every refusal is shown. Below its own sections the page hosts account.me.sections@1, where
 * addons add sections about the viewer, and it keeps its sign-out in its own content.
 */
export default function Me({ logout, rejection, result }: AccountMePageV1) {
  const { t } = useTranslation();
  const checked = result === null ? null : validateActorMeV1(result);
  const refusal = rejection === null ? null : validateProblemV1(rejection);

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(logout);
  }

  return (
    <>
      <Head title={t('panel.account_me.title')} />
      <PanelShell page="account.me">
        <Page
          header={
            <PageHeader
              title={t('panel.account_me.title')}
              description={t('panel.account_me.description')}
            />
          }
        >
          {checked !== null && checked.valid ? (
            <Self me={checked.value} />
          ) : refusal !== null && refusal.valid ? (
            <ProblemDetails problem={refusal.value} explanation={t('panel.account_me.refused')} />
          ) : (
            <ErrorState
              title={t('panel.account_me.unavailable_title')}
              description={t('panel.account_me.unavailable_body')}
            />
          )}
          <PointHost point="account.me.sections@1" />
          <Form method="post" action={logout} onSubmit={signOut}>
            <Button type="submit">{t('panel.home.sign_out')}</Button>
          </Form>
        </Page>
      </PanelShell>
    </>
  );
}

/** The person's own actor and profile, and the grants they hold. */
function Self({ me }: { readonly me: ActorMeV1 }) {
  const { t } = useTranslation();

  return (
    <>
      <Section title={t('panel.account_me.profile')}>
        <DescriptionList
          items={[
            {
              id: 'name',
              term: t('panel.account_me.name'),
              description: me.profile?.display_name ?? t('panel.account_me.no_profile'),
            },
            {
              id: 'email',
              term: t('panel.account_me.email'),
              description: me.profile?.email ?? t('panel.account_me.no_profile'),
            },
            {
              id: 'actor',
              term: t('panel.account_me.actor'),
              description: <code>{me.actor}</code>,
            },
            { id: 'class', term: t('panel.account_me.class'), description: t(CLASSES[me.class]) },
            {
              id: 'state',
              term: t('panel.account_me.state'),
              description: (
                <Badge tone={me.state === 'active' ? 'success' : 'neutral'}>
                  {t(STATES[me.state])}
                </Badge>
              ),
            },
          ]}
        />
      </Section>
      <Section
        title={t('panel.account_me.grants')}
        description={t('panel.account_me.grants_description')}
      >
        <DataTable<OwnGrantV1>
          label={t('panel.account_me.grants')}
          columns={[
            {
              id: 'role',
              title: t('panel.account_me.grant.role'),
              rowHeader: true,
              render: (grant) => grant.role_handle,
            },
            {
              id: 'node',
              title: t('panel.account_me.grant.node'),
              render: (grant) => <code>{grant.node}</code>,
            },
            {
              id: 'effect',
              title: t('panel.account_me.grant.effect'),
              render: (grant) => (
                <Badge tone={grant.effect === 'allow' ? 'success' : 'warning'}>
                  {t(EFFECTS[grant.effect])}
                </Badge>
              ),
            },
            {
              id: 'locales',
              title: t('panel.account_me.grant.locales'),
              render: (grant) =>
                grant.locales === null
                  ? t('panel.account_me.grant.every_locale')
                  : grant.locales.join(', '),
            },
          ]}
          rows={me.grants}
          rowKey={(grant) => grant.id}
          empty={
            <EmptyState
              title={t('panel.account_me.no_grants_title')}
              description={t('panel.account_me.no_grants_body')}
              headingLevel={3}
            />
          }
        />
      </Section>
    </>
  );
}
