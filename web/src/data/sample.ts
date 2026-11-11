import type { Competition, DrawResult, SiteSettings, Winner } from '../lib/types';

/**
 * Sample data used when API_URL is not set, so the site can be previewed
 * without the Laravel API running. Remove once the API is live.
 */
export const sampleCompetitions: Competition[] = [
  {
    id: 1,
    slug: 'bmw-e46-330ci-clubsport',
    title: 'BMW E46 330Ci Clubsport',
    strapline: 'Estoril Blue. ZF manual. One of an estimated 600 ever built, refreshed in the WDS Originals workshop and drawn live on Christmas Eve.',
    category: 'cars',
    badge: 'Main event',
    status: 'live',
    images: [
      { src: '/images/e46-clubsport-34.jpg', alt: 'Estoril Blue BMW E46 330Ci Clubsport in the WDS Originals workshop' },
      { src: '/images/e46-clubsport-front.jpg', alt: 'Clubsport front view' },
      { src: '/images/e46-clubsport-bonnet.jpg', alt: 'Clubsport with the bonnet up' },
    ],
    description: [
      'With an estimated 600 Clubsports ever made, these cars are becoming more and more sought after, especially one presented like this. It\'s a low-owner example that has just been through extensive work on the WDS Originals YouTube channel.',
      'It has recently had a replacement engine with a light refresh, a ZF manual gearbox swap and a long list of upgrades. We\'ve also refurbished the wheels, headlights and weather trims to make it one of the nicest examples in the UK.',
    ],
    specs: [
      { label: 'Built', value: '~600' },
      { label: 'Gearbox', value: 'ZF manual' },
      { label: 'Paint', value: 'Estoril Blue' },
      { label: 'Interior', value: 'Heated black Nappa' },
      { label: 'Audio', value: 'Harman Kardon' },
      { label: 'Engine', value: 'Replacement, refreshed' },
    ],
    ticketPrice: 14.95,
    totalTickets: 750,
    ticketsSold: 465,
    maxPerPerson: 50,
    bundles: [
      { qty: 3, price: 40 },
      { qty: 10, price: 125 },
    ],
    opensAt: null,
    closesAt: null,
    drawAt: '2026-12-24T20:00:00Z',
    cashAlternative: null,
    buildVideoUrl: 'https://youtube.com/@wdsoriginals',
  },
];

export const sampleWinners: Winner[] = [
  { name: 'Mark Robinson', prize: 'Alpina D3 Coupé', image: '/images/winner-mark-robinson.jpg', drawnAt: null, ticketNumber: null },
];

export const sampleDraws: DrawResult[] = [
  { competition: 'BMW E46 330Ci Clubsport', drawAt: '2026-12-24T20:00:00Z', winningTicket: null, winner: null, certificateUrl: null },
  { competition: 'Alpina D3 Coupé', drawAt: '', winningTicket: null, winner: 'Mark Robinson', certificateUrl: null },
];

export const sampleSettings: SiteSettings = {
  tradingName: 'WDS Competitions',
  legalName: 'WDS Originals Limited',
  companyNumber: '13330886',
  registeredOffice: 'Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY',
  supportEmail: 'competitions@wdsoriginals.com',
  postalEntryAddress: ['WDS Competitions — Free Entry', '[POSTAL ADDRESS LINE 1]', '[TOWN] [POSTCODE]'],
  drawsInstagram: 'wds_originals',
  socials: [
    { label: 'Instagram @wds_originals', url: 'https://instagram.com/wds_originals' },
    { label: 'Instagram @wds_competitions', url: 'https://instagram.com/wds_competitions' },
  ],
  youtubeUrl: 'https://youtube.com/@wdsoriginals',
};
