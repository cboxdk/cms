// The stories of the panel's points (PRD 13.4, section 2.7 of the panel extension architecture):
// what js/panel/stories/generated, which cms:panel:stories writes from panel.php, renders. The
// overview lists every point with its kind and stability; a point's story shows its facts, the order
// its contributions render in, its props schema, and the point as a page renders it: its host, with
// the point's sample props and the contributions cms:build compiled for it, the core's own
// registered by the panel's build and an addon's from its bundle.

import { Button, Card, DescriptionList, EmptyState, Stack } from '@cboxdk/cms-ui-kit';
import { useEffect, useState, type ReactNode } from 'react';

import type { PointFillsPropV1 } from '../src/generated/pages/ContributionsV1';
import { PointHost, usePointHost, type Tightened } from '../src/host';
import { AddonSource, CORE_NAMESPACE } from '../src/host/addons';
import type { Contributions } from '../src/host/model';
import { importAddon } from '../src/host/PanelRuntime';
import type { HostServices } from '../src/host/panel-host';
import { registrationDigest } from '../src/host/registration';
import { HostRuntimeProvider } from '../src/host/runtime';
import { CORE_CONTRIBUTIONS } from '../src/host/core';
import { localeOf, lookup, TranslationProvider, useTranslation } from '../src/i18n/translations';

/** A point as cms:panel:stories writes it. */
export interface PanelPointStoryData {
  readonly id: string;
  readonly page: string;
  /** The translation key of the point's label. */
  readonly label: string;
  readonly since: string;
  readonly stability: string;
  /** The point's props class. */
  readonly class: string;
  /** The point's props schema as JSON text, or null for a point without props. */
  readonly schema: string | null;
  /** The point as cms.contributions sends it, each contribution handed the sample props. */
  readonly point: PointFillsPropV1;
}

/** What Storybook hands a story's render function: the toolbar's globals. */
export interface StoryContext {
  readonly globals: Readonly<Record<string, unknown>>;
}

/** One story of the section. */
export interface Story {
  readonly name?: string;
  readonly render: (args: Readonly<Record<string, never>>, context: StoryContext) => ReactNode;
}

/** The props of PanelPointStory. */
export interface PanelPointStoryProps {
  readonly points: readonly PanelPointStoryData[];
  /** The point the story shows, or undefined for the overview. */
  readonly only?: string | undefined;
}

/** The story of the overview of every point. */
export function overviewStory(points: readonly PanelPointStoryData[]): Story {
  return {
    name: 'Overview',
    render: (_args, { globals }) => (
      <InLocale globals={globals}>
        <PanelPointStory points={points} />
      </InLocale>
    ),
  };
}

/** The story of one point. */
export function pointStory(points: readonly PanelPointStoryData[], id: string): Story {
  return {
    name: id,
    render: (_args, { globals }) => (
      <InLocale globals={globals}>
        <PanelPointStory points={points} only={id} />
      </InLocale>
    ),
  };
}

function InLocale({
  globals,
  children,
}: {
  readonly globals: StoryContext['globals'];
  readonly children: ReactNode;
}) {
  const locale = typeof globals.locale === 'string' ? globals.locale : 'en';

  return <TranslationProvider locale={localeOf(locale)}>{children}</TranslationProvider>;
}

/** The overview of the points, or one point's story. */
export function PanelPointStory({ points, only }: PanelPointStoryProps) {
  const { t } = useTranslation();
  const shown = only === undefined ? points : points.filter((point) => point.id === only);

  if (shown.length === 0) {
    return (
      <EmptyState title={t('panel.points.none_title')} description={t('panel.points.none_body')} />
    );
  }

  return (
    <Stack gap="lg">
      {shown.map((point) => (
        <PointCard key={point.id} point={point} points={points} withHost={only !== undefined} />
      ))}
    </Stack>
  );
}

function PointCard({
  point,
  points,
  withHost,
}: {
  readonly point: PanelPointStoryData;
  readonly points: readonly PanelPointStoryData[];
  readonly withHost: boolean;
}) {
  const { t, locale } = useTranslation();
  const shows =
    point.point.multiplicity === 'max'
      ? t('panel.points.max', { max: point.point.max ?? 0 })
      : t(
          point.point.multiplicity === 'exclusive' ? 'panel.points.exclusive' : 'panel.points.many',
        );
  const label = lookup(locale, point.label) ?? point.label;

  return (
    <Card title={`${point.id} · ${label}`} headingLevel={2}>
      <Stack gap="md">
        <DescriptionList
          items={[
            { id: 'kind', term: t('panel.points.kind'), description: point.point.kind },
            { id: 'page', term: t('panel.points.page'), description: point.page },
            { id: 'region', term: t('panel.points.region'), description: point.point.region ?? '' },
            { id: 'shows', term: t('panel.points.multiplicity'), description: shows },
            { id: 'stability', term: t('panel.points.stability'), description: point.stability },
            { id: 'since', term: t('panel.points.since'), description: point.since },
            {
              id: 'order',
              term: t('panel.points.order'),
              description: t('panel.points.order_rule'),
            },
            {
              id: 'contributions',
              term: t('panel.points.contributions'),
              description:
                point.point.fills.length === 0
                  ? t('panel.points.contributions_none')
                  : point.point.fills
                      .map((fill) => `${fill.id} (${String(fill.priority)})`)
                      .join(', '),
            },
          ]}
        />
        {withHost ? (
          <>
            <Card title={t('panel.points.schema')} headingLevel={3}>
              {point.schema === null ? (
                <p>{t('panel.points.schema_none')}</p>
              ) : (
                <pre className="cms-point-schema">{point.schema}</pre>
              )}
            </Card>
            <Card title={t('panel.points.host')} headingLevel={3}>
              <PointRuntime point={point} points={points} />
            </Card>
          </>
        ) : null}
      </Stack>
    </Card>
  );
}

/** Services for a story: nothing leaves the page, and every report is dispatched as the panel does. */
function storyServices(locale: string): HostServices {
  return {
    locale,
    text: (addon, key) => (addon === CORE_NAMESPACE ? lookup(localeOf(locale), key) : undefined),
    notify: () => undefined,
    visit: () => undefined,
    runCommand: () => Promise.reject(new Error('A story runs no command.')),
    confirm: () => Promise.resolve(false),
    report: () => undefined,
  };
}

/** The page's contributions for the point, each addon's entry with the digest of what it compiled. */
async function storyContributions(
  point: PanelPointStoryData,
  points: readonly PanelPointStoryData[],
): Promise<Contributions> {
  const addons = [...new Set(point.point.fills.map((fill) => fill.addon))].sort();
  const entries = await Promise.all(
    addons.map(async (addon) => ({
      addon,
      any_command: addon === CORE_NAMESPACE,
      issues: [],
      registration: await registrationDigest(
        points.flatMap((candidate) =>
          candidate.point.fills
            .filter((fill) => fill.addon === addon && RUNS_CODE.includes(fill.kind))
            .map((fill) => fill.id),
        ),
      ),
    })),
  );

  return {
    addons: entries,
    commands: '',
    details: true,
    pages: [],
    points: [point.point],
    viewer: null,
  };
}

/** The kinds of contribution an addon's code registers, as runsCode() says in PHP. */
const RUNS_CODE: readonly string[] = [
  'slot',
  'page',
  'decorator',
  'replacement',
  'form_check',
  'flow_step',
  'observer',
  'provider',
];

function PointRuntime({
  point,
  points,
}: {
  readonly point: PanelPointStoryData;
  readonly points: readonly PanelPointStoryData[];
}) {
  const { locale } = useTranslation();
  const [contributions, setContributions] = useState<Contributions | undefined>(undefined);
  const [source] = useState(
    () => new AddonSource(importAddon, { [CORE_NAMESPACE]: CORE_CONTRIBUTIONS }, () => undefined),
  );
  const [services] = useState(() => storyServices(locale));

  useEffect(() => {
    let live = true;

    void storyContributions(point, points).then((built) => {
      if (live) {
        setContributions(built);
      }
    });

    return () => {
      live = false;
    };
  }, [point, points]);

  if (contributions === undefined) {
    return null;
  }

  return (
    <HostRuntimeProvider
      contributions={contributions}
      ext={undefined}
      source={source}
      services={services}
    >
      <PointByKind point={point} />
    </HostRuntimeProvider>
  );
}

/** The point rendered as a page renders a point of its kind. */
function PointByKind({ point }: { readonly point: PanelPointStoryData }) {
  const { t } = useTranslation();
  const { kind, region } = point.point;
  const sample = point.point.fills[0]?.props ?? {};

  if (kind === 'slot' && region === 'tabs') {
    return (
      <PointHost
        point={point.id}
        label={t('panel.points.host')}
        tabs={[
          {
            id: 'own',
            label: t('panel.points.own_tab'),
            content: <p>{t('panel.points.default')}</p>,
          },
        ]}
      />
    );
  }

  if (kind === 'slot' && region === 'columns') {
    return <Columns point={point.id} row={sample} />;
  }

  if (kind === 'slot') {
    return <PointHost point={point.id} />;
  }

  if (kind === 'action') {
    return <PointHost point={point.id} label={t('panel.points.host')} onAction={() => undefined} />;
  }

  if (kind === 'decorator') {
    return (
      <PointHost
        point={point.id}
        render={(tightened: Tightened) => (
          <Button disabled={tightened.disabled}>{t('panel.points.default')}</Button>
        )}
      />
    );
  }

  if (kind === 'replacement') {
    const targets = [
      ...new Set(
        point.point.fills.flatMap((fill) =>
          fill.replacement === null ? [] : [fill.replacement.key],
        ),
      ),
    ];

    return (
      <>
        {targets.map((target) => (
          <PointHost
            key={target}
            point={point.id}
            target={target}
            props={sample}
            fallback={<p>{t('panel.points.default')}</p>}
          />
        ))}
        {targets.length === 0 ? <p>{t('panel.points.default')}</p> : null}
      </>
    );
  }

  return <p>{t('panel.points.asked')}</p>;
}

function Columns({
  point,
  row,
}: {
  readonly point: string;
  readonly row: Readonly<Record<string, never>> | object;
}) {
  const handle = usePointHost(point);
  const { t } = useTranslation();

  return (
    <table>
      <thead>
        <tr>
          <th>{t('panel.points.default')}</th>
          {handle.columns.map((column) => (
            <th key={column.id}>{column.header}</th>
          ))}
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>{t('panel.points.default')}</td>
          {handle.columns.map((column) => (
            <td key={column.id}>{column.cell(row as Parameters<typeof column.cell>[0])}</td>
          ))}
        </tr>
      </tbody>
    </table>
  );
}
