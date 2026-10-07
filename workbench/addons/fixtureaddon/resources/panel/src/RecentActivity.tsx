// The fixture addon's section of the who-am-I page that shows the last command the viewer ran, as
// the addon's observer recorded it in the session storage (activity.ts), or that none was run yet.

import { usePanelHost } from '@cboxdk/cms-panel/extend';
import { Badge, DescriptionList, EmptyState, Section } from '@cboxdk/cms-panel/experimental';

import { readActivity } from './activity';

/** The section's component: it reads none of the point's props, the viewer's actor id. */
export default function RecentActivity() {
  const { t } = usePanelHost();
  const record = readActivity();

  return (
    <Section title={t('fixtureaddon.recent_activity.title')}>
      {record === null ? (
        <EmptyState
          title={t('fixtureaddon.recent_activity.none_title')}
          description={t('fixtureaddon.recent_activity.none_body')}
          headingLevel={3}
        />
      ) : (
        <DescriptionList
          items={[
            {
              id: 'command',
              term: t('fixtureaddon.recent_activity.command'),
              description: <code>{`${record.command}@${String(record.version)}`}</code>,
            },
            {
              id: 'outcome',
              term: t('fixtureaddon.recent_activity.outcome'),
              description: (
                <Badge tone={record.outcome === 'rejected' ? 'warning' : 'success'}>
                  {t(`fixtureaddon.recent_activity.outcome.${record.outcome}`)}
                </Badge>
              ),
            },
            {
              id: 'changeset',
              term: t('fixtureaddon.recent_activity.changeset'),
              description:
                record.changeset === null ? (
                  t('fixtureaddon.recent_activity.no_changeset')
                ) : (
                  <code>{record.changeset}</code>
                ),
            },
          ]}
        />
      )}
    </Section>
  );
}
