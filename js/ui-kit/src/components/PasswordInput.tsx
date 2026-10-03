import { useEffect, useRef, useState, type InputHTMLAttributes, type Ref } from 'react';
import { mergeRefs } from 'react-aria';

import { useKitTranslation } from '../i18n/translations';
import { Field } from './Field';
import { Icon } from './Icon';

/**
 * The props of PasswordInput: an input element's attributes, without className, style and type, and the kit's own.
 *
 * @experimental
 */
export interface PasswordInputProps extends Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'children' | 'className' | 'style' | 'type'
> {
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the password must be, such as its least length, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the input. */
  readonly error?: string | undefined;
  /** A ref to the input element. */
  readonly ref?: Ref<HTMLInputElement>;
}

/**
 * A password field: a TextInput of type password with a button inside its edge that shows and
 * hides what is typed. The button is a toggle (aria-pressed) named in the kit's own text, reached
 * by Tab after the input; the password is hidden again when the form is submitted, so a browser
 * never offers to save it as plain text.
 *
 * @experimental
 */
export function PasswordInput({ label, description, error, ref, ...rest }: PasswordInputProps) {
  const t = useKitTranslation();
  const [shown, setShown] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  useEffect(() => {
    const form = input.current?.form;

    if (form === null || form === undefined) {
      return undefined;
    }

    const hide = () => {
      setShown(false);
    };
    form.addEventListener('submit', hide);

    return () => {
      form.removeEventListener('submit', hide);
    };
  }, []);

  return (
    <Field
      id={rest.id}
      label={label}
      description={description}
      error={error}
      required={rest.required}
    >
      {(control) => (
        <div className="cms-input-group" data-invalid={error !== undefined}>
          <input
            {...rest}
            {...control}
            ref={ref === undefined ? input : mergeRefs(input, ref)}
            type={shown ? 'text' : 'password'}
            className="cms-input"
            spellCheck={false}
            autoCapitalize="none"
          />
          <button
            type="button"
            className="cms-input-group__button"
            aria-pressed={shown}
            aria-label={t('kit.password.show')}
            onClick={() => {
              setShown((current) => !current);
            }}
          >
            <Icon name={shown ? 'eye-off' : 'eye'} />
          </button>
        </div>
      )}
    </Field>
  );
}
