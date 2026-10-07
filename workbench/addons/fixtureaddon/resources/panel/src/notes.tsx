// The fixture addon's notes on the access pages: on the grants page, the four-eyes rule its hook
// DenySelfGrant enforces on grant.assign; on the roles page, the permission fixtureaddon.articles
// a role gives to open the addon's page. The points have no props, so the components take none.

import { usePanelHost } from '@cboxdk/cms-panel/extend';
import { Callout } from '@cboxdk/cms-panel/experimental';

export function FourEyesNote() {
  const { t } = usePanelHost();

  return (
    <Callout tone="info" title={t('fixtureaddon.four_eyes_note.title')}>
      {t('fixtureaddon.four_eyes_note.body')}
    </Callout>
  );
}

export function ArticlesPermissionNote() {
  const { t } = usePanelHost();

  return (
    <Callout tone="info" title={t('fixtureaddon.articles_permission.title')}>
      {t('fixtureaddon.articles_permission.body')}
    </Callout>
  );
}
