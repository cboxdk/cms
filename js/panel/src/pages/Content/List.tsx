import {
  Badge,
  Button,
  DataTable,
  EmptyState,
  ErrorState,
  FilterChip,
  Form,
  Inline,
  Page,
  PageHeader,
  Pagination,
  ProblemDetails,
  RowTitle,
  SegmentedControl,
  Stack,
  Timestamp,
  type TableDensity,
} from '@cboxdk/cms-ui-kit';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState, type SubmitEvent } from 'react';

import { afterOf, back, forward, visitPage, type Cursors } from '../../access/list-pages';
import { explanationOf } from '../../access/problems';
import { readOf } from '../../access/reads';
import {
  createAction,
  densityOf,
  filteredUrl,
  keepDensity,
  STATE_STATUS,
  STATES,
  statusOf,
  titleOf,
} from '../../content/list';
import { typesById, typesOf } from '../../content/types';
import type { ContentListPageV1 } from '../../generated/pages/ContentListPageV1';
import { validateEntryListV1, type ListedEntryV1 } from '../../generated/protocol/EntryListV1';
import type { TypeEditorV1 } from '../../generated/protocol/TypeEditorV1';
import { useHostRuntime } from '../../host/runtime';
import { useTranslation } from '../../i18n/translations';
import { paletteOf } from '../../shell/palette';
import { commandFormUrl, PanelShell } from '../../shell/PanelShell';

/**
 * The content list (PRD 5.4, 13.4): the entries the person reaches, newest first, across every type
 * or of the type the address names, read with entry.list as the person a keyset page at a time.
 * Each row shows the entry's title, the title field its type's editor descriptor names, below it the
 * type's label, a pill of its state and its last change. The filter chips choose the type, which
 * opens that type's list, and the release state of the shared variant, both kept in the address,
 * and the density, comfortable or compact, is the reader's own setting, kept in this browser. With
 * no entries the page offers the first action, the form of entry.create when the person may run it;
 * while another page loads, the table says so; a rejected read shows the problem details with what
 * the code means, and an answer the panel cannot read says what to do. The types come from the
 * shared prop `types`, the read of type.list, and the props are ContentListPageV1, generated from
 * the page's JSON Schema; the page keeps its sign-out in its own content.
 */
export default function List({ logout, rejection, result, state, type }: ContentListPageV1) {
  const { t, locale } = useTranslation();
  const page = usePage();
  const { contributions } = useHostRuntime();
  const shared = page.props;
  const palette = useMemo(() => paletteOf(shared.palette), [shared.palette]);
  const types = useMemo(() => typesOf(shared['types']), [shared]);
  const read = useMemo(() => readOf(result, rejection, validateEntryListV1), [result, rejection]);
  const [loading, setLoading] = useState(false);
  const [cursors, setCursors] = useState<Cursors>([]);
  const [density, setDensity] = useState<TableDensity>(() => densityOf());
  const after = afterOf(page.url);
  const editors = types.status === 'ready' ? types.types : [];
  const byId = typesById(editors);
  const shown = editors.find((editor) => editor.name === type);
  const content = types.content ?? page.url.split('?')[0] ?? '';
  const create = createAction(palette);
  const title = shown === undefined ? t('panel.content.title') : shown.label;
  const visit = {
    onStart: () => {
      setLoading(true);
    },
    onFinish: () => {
      setLoading(false);
    },
  };

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(logout);
  }

  function open(url: string) {
    setCursors([]);
    router.visit(url, { onStart: visit.onStart, onFinish: visit.onFinish });
  }

  const createButton =
    create === null ? undefined : (
      <Button
        variant="primary"
        icon="plus"
        onClick={() => {
          router.visit(commandFormUrl(contributions.commands, create));
        }}
      >
        {t('panel.content.create')}
      </Button>
    );

  function typeOf(entry: ListedEntryV1): TypeEditorV1 | undefined {
    return byId.get(entry.type);
  }

  return (
    <>
      <Head title={title} />
      <PanelShell page="content.list" contentType={type}>
        <Page
          header={
            <PageHeader
              title={title}
              description={
                shown === undefined
                  ? t('panel.content.description')
                  : (shown.description ?? t('panel.content.description_type'))
              }
              actions={createButton}
            />
          }
        >
          <Stack gap="md">
            <Inline gap="sm" justify="between" wrap>
              <Inline gap="sm" wrap>
                <FilterChip
                  label={t('panel.content.filter.type')}
                  options={editors.map((editor) => ({ id: editor.name, label: editor.label }))}
                  value={type}
                  disabled={loading}
                  onChange={(next) => {
                    open(filteredUrl(content, next, state));
                  }}
                />
                <FilterChip
                  label={t('panel.content.filter.state')}
                  options={STATES.map((id) => ({ id, label: t(STATE_STATUS[id].key) }))}
                  value={state}
                  disabled={loading}
                  onChange={(next) => {
                    open(
                      filteredUrl(
                        content,
                        type,
                        STATES.find((candidate) => candidate === next) ?? null,
                      ),
                    );
                  }}
                />
              </Inline>
              <SegmentedControl
                label={t('panel.content.density')}
                options={[
                  { id: 'comfortable', label: t('panel.content.density.comfortable') },
                  { id: 'compact', label: t('panel.content.density.compact') },
                ]}
                value={density}
                onChange={(next) => {
                  const chosen: TableDensity = next === 'compact' ? 'compact' : 'comfortable';
                  setDensity(chosen);
                  keepDensity(chosen);
                }}
              />
            </Inline>
            {read.status === 'ready' ? (
              <DataTable<ListedEntryV1>
                key={density}
                label={title}
                density={density}
                columns={[
                  {
                    id: 'title',
                    title: t('panel.content.column.title'),
                    rowHeader: true,
                    render: (entry) => {
                      const entryType = typeOf(entry);
                      const text = titleOf(entry, entryType);

                      return (
                        <RowTitle
                          title={text ?? t('panel.content.untitled')}
                          fallback={text === null}
                          detail={
                            density === 'comfortable'
                              ? (entryType?.label ?? t('panel.content.unknown_type'))
                              : undefined
                          }
                        />
                      );
                    },
                  },
                  ...(density === 'compact'
                    ? [
                        {
                          id: 'type',
                          title: t('panel.content.column.type'),
                          secondary: true,
                          render: (entry: ListedEntryV1) =>
                            typeOf(entry)?.label ?? t('panel.content.unknown_type'),
                        },
                      ]
                    : []),
                  {
                    id: 'state',
                    title: t('panel.content.column.state'),
                    render: (entry) => {
                      const status = statusOf(entry);

                      return <Badge tone={status.tone}>{t(status.key)}</Badge>;
                    },
                  },
                  {
                    id: 'changed',
                    title: t('panel.content.column.changed'),
                    align: 'end',
                    secondary: true,
                    render: (entry) => <Timestamp value={entry.changed_at} />,
                  },
                ]}
                rows={read.value.entries}
                rowKey={(entry) => entry.id}
                loading={loading ? t('panel.content.loading') : undefined}
                empty={
                  <EmptyState
                    title={t(
                      state === null ? 'panel.content.empty_title' : 'panel.content.empty_filtered',
                    )}
                    description={t(
                      create === null ? 'panel.content.empty_readonly' : 'panel.content.empty_body',
                    )}
                    action={createButton}
                    headingLevel={2}
                  />
                }
                pagination={
                  after === null && read.value.next === null ? undefined : (
                    <Pagination
                      label={t('panel.content.pagination')}
                      status={t('panel.access.page_status', {
                        count: read.value.entries.length,
                      })}
                      onPrevious={
                        after === null
                          ? undefined
                          : () => {
                              const previous = back(cursors);
                              setCursors(previous.cursors);
                              visitPage(page.url, previous.after, visit);
                            }
                      }
                      onNext={
                        read.value.next === null
                          ? undefined
                          : () => {
                              setCursors(forward(cursors, after));
                              visitPage(page.url, read.value.next, visit);
                            }
                      }
                    />
                  )
                }
              />
            ) : read.status === 'rejected' ? (
              <ProblemDetails
                problem={read.problem}
                explanation={explanationOf(locale, t, read.problem, 'panel.content.refused')}
              />
            ) : (
              <ErrorState
                title={t('panel.access.unavailable_title')}
                description={t('panel.access.unavailable_body')}
              />
            )}
          </Stack>
          <Form method="post" action={logout} onSubmit={signOut}>
            <Button type="submit">{t('panel.home.sign_out')}</Button>
          </Form>
        </Page>
      </PanelShell>
    </>
  );
}
