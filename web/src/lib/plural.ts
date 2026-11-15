/** "1 entry", "2 entries", "1,234 entries": the count (en-GB) and the right form of the word. */
export function count(n: number, one: string, many = `${one}s`): string {
  return `${n.toLocaleString('en-GB')} ${n === 1 ? one : many}`;
}
