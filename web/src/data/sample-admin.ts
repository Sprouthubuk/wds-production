import { sampleCompetitions } from './sample';

/** Preview data for the admin when API_URL isn't set. Keys are admin API paths. */
const now = Date.now();
const iso = (minsAgo: number) => new Date(now - minsAgo * 60_000).toISOString();
const bmw = sampleCompetitions[0];

const orders = [
  { id: 6, reference: 'WDS-7KQ2ZD', name: 'Dan Hughes', email: 'dan@example.com', competition: bmw.title, tickets: 10, total: 125, status: 'paid', channel: 'online', paymentMethod: 'card', createdAt: iso(4) },
  { id: 5, reference: 'WDS-P3M8RA', name: 'Sophie Clarke', email: 'sophie@example.com', competition: bmw.title, tickets: 3, total: 40, status: 'paid', channel: 'online', paymentMethod: 'card', createdAt: iso(19) },
  { id: 4, reference: 'WDS-B6TN1C', name: 'Ryan Patel', email: 'ryan@example.com', competition: bmw.title, tickets: 1, total: 14.95, status: 'pending', channel: 'online', paymentMethod: null, createdAt: iso(26) },
  { id: 3, reference: 'WDS-Q9HV4E', name: 'Tom Barker', email: 'tom@example.com', competition: bmw.title, tickets: 5, total: 69.95, status: 'paid', channel: 'event', paymentMethod: 'cash', createdAt: iso(140) },
  { id: 2, reference: 'WDS-L2WX8S', name: 'Kate Moss', email: 'kate@example.com', competition: bmw.title, tickets: 2, total: 29.9, status: 'failed', channel: 'online', paymentMethod: null, createdAt: iso(300) },
];

export const sampleAdmin: Record<string, unknown> = {
  '/dashboard': {
    data: {
      revenueToday: 1284.5, ordersToday: 31, revenueTotal: 6951.75, customers: 212, postalThisWeek: 6, idToReview: 1, refundsNeeded: 0,
      daily: Array.from({ length: 14 }, (_, i) => ({ day: new Date(now - (13 - i) * 86_400_000).toISOString().slice(0, 10), revenue: [180, 240, 210, 395, 300, 520, 610, 450, 380, 700, 655, 820, 905, 1284.5][i], orders: [5, 7, 6, 10, 8, 13, 15, 11, 10, 17, 16, 20, 22, 31][i] })),
      live: [{ id: bmw.id, title: bmw.title, sold: bmw.ticketsSold, total: bmw.totalTickets, drawAt: bmw.drawAt, revenue: 6951.75 }],
      recentOrders: orders,
    },
  },
  '/competitions': { data: sampleCompetitions.map((c) => ({ ...c, status: c.status })) },
  '/orders': { data: orders, total: orders.length, current_page: 1, last_page: 1 },
  '/customers': {
    data: [
      { id: 11, name: 'Dan Hughes', email: 'dan@example.com', phone: '07700 900123', role: 'customer', tickets: 22, spent: 270, credit: 0, flags: [], joinedAt: iso(9000) },
      { id: 12, name: 'Sophie Clarke', email: 'sophie@example.com', phone: '07700 900456', role: 'customer', tickets: 6, spent: 80, credit: 5, flags: [], joinedAt: iso(7000) },
      { id: 13, name: 'Lee Grant', email: 'lee@example.com', phone: null, role: 'customer', tickets: 3, spent: 40, credit: 0, flags: ['on break'], joinedAt: iso(5000) },
      { id: 1, name: 'Jason Wood', email: 'jason@example.com', phone: null, role: 'admin', tickets: 0, spent: 0, credit: 0, flags: [], joinedAt: iso(20000) },
    ], total: 4, current_page: 1, last_page: 1,
  },
  '/verifications': [{ id: 1, user_id: 13, status: 'to_review', created_at: iso(60), user: { id: 13, name: 'Lee Grant', email: 'lee@example.com' } }],
  '/postal': {
    data: [
      { id: 3, competition: bmw.title, name: 'Gary Smith', email: 'gary@example.com', receivedOn: iso(1440).slice(0, 10), status: 'allocated', reason: null, ticket: '0462' },
      { id: 2, competition: bmw.title, name: 'Amy Jones', email: 'amy@example.com', receivedOn: iso(2880).slice(0, 10), status: 'rejected', reason: 'Entrant under 18', ticket: null },
    ], total: 2, current_page: 1, last_page: 1,
  },
  '/draws': [],
  '/winners': [{ id: 1, display_name: 'Mark Robinson', prize: 'Alpina D3 Coupé', image: '/images/winner-mark-robinson.jpg', verification: 'approved', handover: 'delivered', show_on_site: true, drawn_at: null, competition: null }],
  '/promotions': [{ id: 1, code: 'XMAS10', type: 'percent', value: 10, competition_id: null, usage_limit: 200, times_used: 37, expires_at: '2026-12-20T23:59:00Z', active: true }],
  '/settings': { data: {
    tradingName: 'WDS Competitions', legalName: 'WDS Originals Limited', companyNumber: '13330886',
    registeredOffice: 'Knoll House, Knoll Road, Camberley, Surrey, GU15 3SY', supportEmail: 'competitions@wdsoriginals.com',
    postalEntryAddress: ['WDS Competitions — Free Entry', '[POSTAL ADDRESS LINE 1]', '[TOWN] [POSTCODE]'], drawsInstagram: 'wds_originals',
    socials: [{ label: 'Instagram @wds_originals', url: 'https://instagram.com/wds_originals' }], youtubeUrl: 'https://youtube.com/@wdsoriginals',
  } },
  '/event-sales/competitions': { data: sampleCompetitions },
  '/event-sales/summary': { data: { orders: 4, cash: 69.95, card: 125 } },
};
