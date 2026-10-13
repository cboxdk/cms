import {
  Callout,
  ErrorState,
  ProblemDetails,
  SegmentedControl,
  Section,
  Select,
  Stack,
} from '@cboxdk/cms-ui-kit';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import { explanationOf } from '../../access/problems';
import type { ReadState } from '../../access/reads';
import { entryUrl } from '../../content/editor';
import {
  PREVIEW_WIDTHS,
  previewDocument,
  previewLocales,
  previewRevisions,
  type PreviewItem,
  type PreviewValue,
  type PreviewWidth,
} from '../../content/preview';
import type { EntryHeadV1 } from '../../generated/protocol/EntryDetailV1';
import type { EntryPreviewV1 } from '../../generated/protocol/EntryPreviewV1';
import type { TypeEditorV1 } from '../../generated/protocol/TypeEditorV1';
import type { JsonObject } from '../../generated/validation';
import { useTranslation } from '../../i18n/translations';

/**
 * The editor's Preview (PRD 5.4, 8.9, 13.4): the record entry.preview answered with, the draft or
 * the revision chosen, written by the type's record codec at the public classification access
 * exactly as the delivery API would send it, shown as a document at reading width. The person
 * chooses the revision, which reads entry.preview again through the page's address, the language
 * the fields are labelled and their values written in, and the width it is read at; the record
 * itself is below as JSON. A preview reads and never writes, and touches no cache.
 */
export function PreviewPanel({
  descriptor,
  entry,
  head,
  list,
  preview,
  revision,
}: {
  readonly descriptor: TypeEditorV1;
  readonly entry: string;
  readonly head: EntryHeadV1;
  readonly list: string;
  readonly preview: ReadState<EntryPreviewV1>;
  readonly revision: number | null;
}) {
  const { t, locale: panelLocale } = useTranslation();
  const locales = useMemo(() => previewLocales(descriptor, panelLocale), [descriptor, panelLocale]);
  const [locale, setLocale] = useState<string>(panelLocale);
  const [width, setWidth] = useState<PreviewWidth>('reading');
  const [loading, setLoading] = useState(false);
  const revisions = previewRevisions(descriptor, head.latest, head.revision);
  const shown = preview.status === 'ready' ? preview.value.revision : (revision ?? head.revision);

  function revisionLabel(number: number): string {
    if (number === head.revision) {
      return t('panel.preview.revision_draft', { revision: number });
    }

    return number === head.released
      ? t('panel.preview.revision_released', { revision: number })
      : t('panel.preview.revision_other', { revision: number });
  }

  function choose(value: string): void {
    const number = Number(value);
    const url = entryUrl(list, entry);

    setLoading(true);
    router.visit(number === head.revision ? url : `${url}?revision=${String(number)}`, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onFinish: () => {
        setLoading(false);
      },
    });
  }

  return (
    <Section
      title={t('panel.preview.title')}
      description={t('panel.preview.description')}
      headingLevel={2}
    >
      <Stack gap="lg">
        <div className="cms-panel-preview__controls">
          <Select
            id="cms-panel-preview-revision"
            label={t('panel.preview.revision')}
            options={revisions.map((number) => ({
              id: String(number),
              label: revisionLabel(number),
            }))}
            value={String(shown)}
            onChange={choose}
            disabled={loading}
          />
          <SegmentedControl
            label={t('panel.preview.locale')}
            options={locales.map((candidate) => ({ id: candidate, label: candidate }))}
            value={locale}
            onChange={setLocale}
          />
          <SegmentedControl
            label={t('panel.preview.width')}
            options={PREVIEW_WIDTHS.map((candidate) => ({
              id: candidate,
              label: t(`panel.preview.width.${candidate}`),
            }))}
            value={width}
            onChange={(value) => {
              setWidth(PREVIEW_WIDTHS.find((candidate) => candidate === value) ?? 'reading');
            }}
          />
          <span className="cms-panel-preview__status" role="status" aria-live="polite">
            {loading ? t('panel.preview.loading') : ''}
          </span>
        </div>
        <PreviewBody descriptor={descriptor} preview={preview} locale={locale} width={width} />
      </Stack>
    </Section>
  );
}

/** What the read gave: the document, or why there is none. */
function PreviewBody({
  descriptor,
  preview,
  locale,
  width,
}: {
  readonly descriptor: TypeEditorV1;
  readonly preview: ReadState<EntryPreviewV1>;
  readonly locale: string;
  readonly width: PreviewWidth;
}) {
  const { t, locale: panelLocale } = useTranslation();

  if (preview.status === 'rejected') {
    return (
      <ProblemDetails
        problem={preview.problem}
        explanation={explanationOf(panelLocale, t, preview.problem, 'panel.preview.refused')}
      />
    );
  }

  if (preview.status === 'unreadable') {
    return (
      <ErrorState
        title={t('panel.preview.unavailable_title')}
        description={t('panel.preview.unavailable_body')}
        headingLevel={3}
      />
    );
  }

  const value = preview.value;

  return (
    <Stack gap="md">
      <Callout tone="info">{showing(t, value)}</Callout>
      <div className="cms-panel-preview__frame" data-width={width}>
        <Document descriptor={descriptor} record={value.data} locale={locale} />
      </div>
      <details className="cms-panel-preview__json">
        <summary>{t('panel.preview.record')}</summary>
        <pre>
          <code>{JSON.stringify(value.data, null, 2)}</code>
        </pre>
      </details>
    </Stack>
  );
}

/** What the preview shows, in a sentence: the draft, the released revision or another. */
function showing(t: ReturnType<typeof useTranslation>['t'], value: EntryPreviewV1): string {
  if (value.revision === value.released && value.state === 'released') {
    return t('panel.preview.showing_released');
  }

  if (value.revision === value.draft) {
    return value.released === null || value.state !== 'released'
      ? t('panel.preview.showing_unreleased')
      : t('panel.preview.showing_draft', { released: value.released });
  }

  return t('panel.preview.showing_other', { revision: value.revision });
}

/** The record as a document a person reads, in the language chosen. */
function Document({
  descriptor,
  record,
  locale,
}: {
  readonly descriptor: TypeEditorV1;
  readonly record: JsonObject;
  readonly locale: string;
}) {
  const { t } = useTranslation();
  const document = useMemo(
    () => previewDocument(descriptor, record, locale),
    [descriptor, record, locale],
  );

  return (
    <article className="cms-panel-preview__document" lang={locale}>
      {document.title === null ? null : (
        <h3 className="cms-panel-preview__title">{document.title}</h3>
      )}
      {document.items.length === 0 ? (
        <p>{t('panel.preview.empty')}</p>
      ) : (
        <Items items={document.items} />
      )}
    </article>
  );
}

/** Fields as a list of their labels and values. */
function Items({ items }: { readonly items: readonly PreviewItem[] }) {
  return (
    <dl className="cms-panel-preview__items">
      {items.map((item) => (
        <div key={item.id} className="cms-panel-preview__item">
          <dt>{item.label}</dt>
          <dd>
            <Value value={item.value} />
          </dd>
        </div>
      ))}
    </dl>
  );
}

/** One value of a field. */
function Value({ value }: { readonly value: PreviewValue }) {
  const { t } = useTranslation();

  switch (value.kind) {
    case 'text':
      return <>{value.text}</>;
    case 'yes_no':
      return <>{t(value.value ? 'panel.preview.yes' : 'panel.preview.no')}</>;
    case 'paragraphs':
      return (
        <Stack gap="sm">
          {value.paragraphs.map((paragraph, index) => (
            <p key={index}>{paragraph}</p>
          ))}
        </Stack>
      );
    case 'list':
      return (
        <ul className="cms-panel-preview__list">
          {value.items.map((item, index) => (
            <li key={index}>
              <Value value={item} />
            </li>
          ))}
        </ul>
      );
    case 'group':
      return <Items items={value.items} />;
  }
}
