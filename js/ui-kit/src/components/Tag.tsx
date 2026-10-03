import { useKitTranslation } from '../i18n/translations';
import { Icon } from './Icon';
import type { Tone } from './tone';

import './tag.css';

/**
 * The props of Tag.
 *
 * @experimental
 */
export interface TagProps {
  /** The tag's text, from the caller's translations or the data, such as a locale. */
  readonly label: string;
  /** The tone of the tag's border; neutral by default. */
  readonly tone?: Tone;
  /**
   * Called when the tag's remove button is pressed. With it, the tag has a button after its text,
   * named "Remove <label>" in the kit's own text; without it, the tag is text only.
   */
  readonly onRemove?: (() => void) | undefined;
}

/**
 * A short value shown as a chip, such as a locale of a grant, or one of the choices of a
 * MultiSelect, which removes it with the tag's own button. The button is a plain button, reached by
 * Tab and pressed with Enter or Space.
 *
 * @experimental
 */
export function Tag({ label, tone = 'neutral', onRemove }: TagProps) {
  const t = useKitTranslation();

  return (
    <span className="cms-tag" data-tone={tone}>
      <span className="cms-tag__label">{label}</span>
      {onRemove === undefined ? null : (
        <button
          type="button"
          className="cms-tag__remove"
          aria-label={t('kit.tag.remove', { label })}
          onClick={onRemove}
        >
          <Icon name="close" size="sm" />
        </button>
      )}
    </span>
  );
}
