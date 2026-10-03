// The parts every field of the kit is made of, so each one looks and reads the same: the label,
// with the kit's mark of a required field after it; the description below the label; and the
// error below the control. Internal to the kit: Field gives callers the same parts for a control of
// their own, and the controls built on React Aria's hooks spread the hooks' props on these parts.

import type { HTMLAttributes, LabelHTMLAttributes, ReactNode } from 'react';

import { useKitTranslation } from '../../i18n/translations';
import { Icon } from '../Icon';

import '../field.css';

export function FieldLabel({
  children,
  required,
  ...props
}: Omit<LabelHTMLAttributes<HTMLLabelElement>, 'className' | 'style'> & {
  readonly children: ReactNode;
  readonly required?: boolean | undefined;
}) {
  const t = useKitTranslation();

  return (
    <label {...props} className="cms-field__label">
      {children}
      {required === true ? (
        <span className="cms-field__required" aria-hidden="true">
          {t('kit.field.required')}
        </span>
      ) : null}
    </label>
  );
}

export function FieldDescription({
  children,
  ...props
}: Omit<HTMLAttributes<HTMLElement>, 'className' | 'style'> & { readonly children: ReactNode }) {
  return (
    <p {...props} className="cms-field__description">
      {children}
    </p>
  );
}

export function FieldErrorText({
  children,
  ...props
}: Omit<HTMLAttributes<HTMLElement>, 'className' | 'style'> & { readonly children: ReactNode }) {
  return (
    <p {...props} className="cms-field__error">
      <Icon name="error" size="sm" />
      <span>{children}</span>
    </p>
  );
}

/** The ids of a field's description and error that are shown, for aria-describedby. */
export function describedBy(
  ...ids: readonly (string | false | null | undefined)[]
): string | undefined {
  const joined = ids.filter((id) => typeof id === 'string' && id !== '').join(' ');

  return joined === '' ? undefined : joined;
}
