import { StatusScreen, TextLink } from '@cboxdk/cms-ui-kit';
import { Head } from '@inertiajs/react';

import { useTranslation } from '../../i18n/translations';

export interface NotFoundProps {
  /** The address of the panel's start, below the prefix the panel is mounted at. */
  readonly home: string;
}

/**
 * The page for an address below the panel that it has no page for. The server answers it with
 * 404; the page says so and links back to the start of the panel.
 */
export default function NotFound({ home }: NotFoundProps) {
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
