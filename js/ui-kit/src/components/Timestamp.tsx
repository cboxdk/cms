import { useDateFormatter } from 'react-aria';

/**
 * The props of Timestamp.
 *
 * @experimental
 */
export interface TimestampProps {
  /** The time, as an RFC 3339 string such as the kernel writes, or a Date. */
  readonly value: string | Date;
  /** date shows the day; datetime, the default, the day and the time to the minute. */
  readonly format?: 'date' | 'datetime';
  /**
   * The time zone to show the time in, such as Europe/Copenhagen; the reader's own by default. A
   * test or a story names one, so the text does not depend on the machine.
   */
  readonly timeZone?: string | undefined;
}

/**
 * A point in time, written in the page's locale and the reader's time zone, in a time element whose
 * datetime attribute holds the exact instant for a screen reader and a copy.
 *
 * @experimental
 */
export function Timestamp({ value, format = 'datetime', timeZone }: TimestampProps) {
  const formatter = useDateFormatter({
    dateStyle: 'medium',
    ...(format === 'datetime' ? { timeStyle: 'short' } : {}),
    ...(timeZone === undefined ? {} : { timeZone }),
  });
  const date = typeof value === 'string' ? new Date(value) : value;

  if (Number.isNaN(date.getTime())) {
    return <time>{String(value)}</time>;
  }

  return <time dateTime={date.toISOString()}>{formatter.format(date)}</time>;
}
