import { useKitTranslation, type KitTranslationKey } from '../i18n/translations';
import { Badge } from './Badge';
import type { Tone } from './tone';

/**
 * The classification of a field or a reader's access (PRD 12.2), from the lowest to the highest.
 *
 * @experimental
 */
export type Classification = 'public' | 'internal' | 'confidential' | 'personal' | 'sensitive';

/**
 * The props of ClassificationBadge.
 *
 * @experimental
 */
export interface ClassificationBadgeProps {
  /** The level to show. */
  readonly classification: Classification;
}

const LEVELS: Readonly<Record<Classification, { key: KitTranslationKey; tone: Tone }>> = {
  public: { key: 'kit.classification.public', tone: 'neutral' },
  internal: { key: 'kit.classification.internal', tone: 'info' },
  confidential: { key: 'kit.classification.confidential', tone: 'warning' },
  personal: { key: 'kit.classification.personal', tone: 'danger' },
  sensitive: { key: 'kit.classification.sensitive', tone: 'danger' },
};

/**
 * The classification of a field or of what a reader may read, as a badge whose text names the
 * level in the kit's own text and whose tone rises with it.
 *
 * @experimental
 */
export function ClassificationBadge({ classification }: ClassificationBadgeProps) {
  const t = useKitTranslation();
  const level = LEVELS[classification];

  return <Badge tone={level.tone}>{t(level.key)}</Badge>;
}
