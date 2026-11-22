/** The per-person maximum in words, by the ticket selector and in the basket. */
export const maxPerPersonLine = (max: number): string =>
  `Max ${max.toLocaleString('en-GB')} ${max === 1 ? 'ticket' : 'tickets'} per person (online and postal combined)`;

/** For someone who already holds some: what they can still add. */
export const canAddLine = (max: number, held: number): string => {
  const left = Math.max(0, max - held);
  return left ? `You can add up to ${left.toLocaleString('en-GB')} more` : `You already have the maximum of ${max.toLocaleString('en-GB')} ${max === 1 ? 'ticket' : 'tickets'}`;
};
