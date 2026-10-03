import { StatusScreen, TextLink } from '@cboxdk/cms-ui-kit';
import { Head } from '@inertiajs/react';

import type { NotFoundPageV1 } from '../../generated/pages/NotFoundPageV1';
import { useTranslation } from '../../i18n/translations';

/**
 * The page for an address below the panel that it has no page for. The server answers it with
 * 404; the page says so and links back to the start of the panel. The props are NotFoundPageV1,
 * generated from the page's JSON Schema.
 */
export default function NotFound({ home }: NotFoundPageV1) {
  const { t } = useTranslation();

  return (
    <>
      <Head title={t('panel.not_found.title')} />
      <StatusScreen
        code="404"
        title={t('panel.not_found.title')}
        description={t('panel.not_found.body')}
      >
        <TextLink href={home}>{t('panel.not_found.home')}</TextLink>
      </StatusScreen>
    </>
  );
}
