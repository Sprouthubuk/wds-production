/**
 * A ticket number as it's shown everywhere: "#0007". At least 4 digits (wider only past 9,999 tickets),
 * the same as Ticket::format() on the API (emails, CSVs, the chip sheet).
 */
export function ticket(n: string | number | null | undefined): string {
  if (n === null || n === undefined || n === '') return '';
  return `#${String(n).replace(/^#/, '').padStart(4, '0')}`;
}
