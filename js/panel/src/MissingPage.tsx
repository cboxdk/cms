import { useTranslation } from './i18n/translations';

/**
 * Shown when the server names an Inertia page this build of the panel does not have, which
 * happens when the panel's assets and the server are of different versions.
 */
export function MissingPage({ page }: { readonly page: string }) {
  const { t } = useTranslation();

  return (
    <main>
      <h1>{t('panel.page_missing.title')}</h1>
      <p>{t('panel.page_missing.body', { page })}</p>
    </main>
  );
}
