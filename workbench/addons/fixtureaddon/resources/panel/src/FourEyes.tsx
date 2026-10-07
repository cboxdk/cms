// The fixture addon's step before the submit of grant.assign's form (section 3.8 of the panel
// extension architecture): the four-eyes rule as a courtesy. It shows who gets the grant and asks
// the viewer to confirm that a second person reviewed it, then goes on to the core's confirmation,
// or stops, which cancels the flow in the addon's name. The rule itself is the addon's authorize
// hook DenySelfGrant, which refuses a grant to oneself whatever the viewer confirms here; a step
// is never enforcement.

import { usePanelHost, type StepProps } from '@cboxdk/cms-panel/extend';
import { Button, Callout, Inline, Stack } from '@cboxdk/cms-panel/experimental';

import type { GrantAssignV1 } from '../generated/contributions';

/** The draft of grant.assign as the form holds it while it is edited: any member may be missing. */
type Draft = { readonly [K in keyof GrantAssignV1]?: GrantAssignV1[K] };

export default function FourEyes({ draft, next, cancel }: StepProps<GrantAssignV1>) {
  const { t } = usePanelHost();
  const actor = (draft as Draft).actor;

  return (
    <Callout tone="warning" title={t('fixtureaddon.four_eyes.title')}>
      <Stack gap="sm">
        <p data-fixtureaddon-grantee={actor ?? ''}>
          {typeof actor === 'string'
            ? t('fixtureaddon.four_eyes.grantee', { actor })
            : t('fixtureaddon.four_eyes.no_grantee')}
        </p>
        <p>{t('fixtureaddon.four_eyes.body')}</p>
        <Inline gap="sm">
          <Button type="button" variant="primary" onClick={next}>
            {t('fixtureaddon.four_eyes.reviewed')}
          </Button>
          <Button
            type="button"
            variant="quiet"
            onClick={() => {
              cancel('fixtureaddon.four_eyes.cancelled');
            }}
          >
            {t('fixtureaddon.four_eyes.stop')}
          </Button>
        </Inline>
      </Stack>
    </Callout>
  );
}
