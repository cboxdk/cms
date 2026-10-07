// The fixture addon's section below what a dry run of entry.create's form would change: a note
// that the slug is derived when the article is created for real, so the dry run shows no slug.

import { usePanelHost, type SlotProps } from '@cboxdk/cms-panel/extend';
import { Callout, type DryRunViewV1 } from '@cboxdk/cms-panel/experimental';

export default function DryRunNote({ props }: SlotProps<DryRunViewV1>) {
  const { t } = usePanelHost();

  return (
    <Callout tone="info" title={t('fixtureaddon.dry_run_note.title')}>
      {t('fixtureaddon.dry_run_note.body', { command: props.command })}
    </Callout>
  );
}
