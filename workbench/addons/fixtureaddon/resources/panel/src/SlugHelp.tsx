// The fixture addon's aside of entry.create's form: what the addon's slug is and where it comes
// from, beside the form the viewer fills in. The point's props are the command the form runs.

import { usePanelHost, type SlotProps } from '@cboxdk/cms-panel/extend';
import { Callout, type CommandFormContextV1 } from '@cboxdk/cms-panel/experimental';

export default function SlugHelp({ props }: SlotProps<CommandFormContextV1>) {
  const { t } = usePanelHost();

  return (
    <Callout tone="info" title={t('fixtureaddon.slug_help.title')}>
      {t('fixtureaddon.slug_help.body', { command: props.command })}
    </Callout>
  );
}
