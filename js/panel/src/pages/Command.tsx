import { Button, Form, Page, PageHeader } from '@cboxdk/cms-ui-kit';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, type SubmitEvent } from 'react';

import { CommandBadge, CommandForm, readForm } from '../forms/CommandForm';
import type { CommandFormPageV1 } from '../generated/pages/CommandFormPageV1';
import { PointHost } from '../host';
import { lookup, useTranslation } from '../i18n/translations';
import { PanelShell } from '../shell/PanelShell';

/**
 * The generic command form (PRD 6.1, 13.4): the page of any command the Inertia profile exposes,
 * at the address the profile runs it at, which the command palette opens. The props are
 * CommandFormPageV1, generated from the page's JSON Schema: the command's name and version and its
 * JSON Schema, from which the kit's SchemaForm is rendered, with the labels of the panel's
 * catalogue when it has them for the command and its fields, and the schema's own texts otherwise.
 * Beside the form the page hosts command.form.aside@1, where addons add help and context about the
 * command, and it keeps its sign-out in its own content.
 */
export default function Command({ logout, command, version, schema }: CommandFormPageV1) {
  const { t, locale } = useTranslation();
  const errors = usePage().props.errors;
  const read = useMemo(() => readForm(schema), [schema]);
  const title =
    lookup(locale, `panel.action.${command}.title`) ??
    read.title ??
    t('panel.command_form.title', { command, version });
  const description = lookup(locale, `panel.action.${command}.description`) ?? read.description;

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(logout);
  }

  return (
    <>
      <Head title={title} />
      <PanelShell page="command.form">
        <Page
          width="narrow"
          header={
            <PageHeader
              title={title}
              description={description}
              meta={<CommandBadge command={command} version={version} />}
            />
          }
        >
          <CommandForm command={command} version={version} read={read} errors={errors} />
          <PointHost point="command.form.aside@1" />
          <Form method="post" action={logout} onSubmit={signOut}>
            <Button type="submit">{t('panel.home.sign_out')}</Button>
          </Form>
        </Page>
      </PanelShell>
    </>
  );
}
