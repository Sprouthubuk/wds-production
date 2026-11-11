export interface Bundle {
  /** Number of tickets in the bundle (> 1). */
  qty: number;
  /** Fixed price for the bundle, in pounds. */
  price: number;
}

export interface Spec {
  label: string;
  value: string;
}

export type CompetitionStatus = 'draft' | 'scheduled' | 'live' | 'closed' | 'drawn';

export interface Competition {
  id: number;
  slug: string;
  title: string;
  /** Short line under the title, e.g. "Estoril Blue. ZF manual." */
  strapline: string;
  category: 'cars' | 'parts' | 'cash' | 'merch';
  badge: string | null;
  status: CompetitionStatus;
  images: { src: string; alt: string }[];
  description: string[];
  specs: Spec[];
  ticketPrice: number;
  totalTickets: number;
  ticketsSold: number;
  maxPerPerson: number;
  bundles: Bundle[];
  opensAt: string | null;
  closesAt: string | null;
  /** Postal entries must be received by then (default: three days before closesAt). */
  postalClosesAt: string | null;
  /** Only set when an admin chose a postal close; null means the default. */
  postalClosesAtCustom?: string | null;
  /** Set once published on the Verity draw engine (the draw is then locked in). */
  verityRef?: string | null;
  publishedAt?: string | null;
  drawAt: string;
  cashAlternative: number | null;
  buildVideoUrl: string | null;
}

export interface Winner {
  name: string;
  prize: string;
  image: string | null;
  drawnAt: string | null;
  ticketNumber: string | null;
  /** The public verify page for the draw this winner came from (once published). */
  verifyUrl?: string | null;
}

export interface DrawResult {
  id: number | null;
  competition: string;
  drawAt: string;
  winningTicket: string | null;
  winner: string | null;
  certificateUrl: string | null;
  /** The public page that re-checks the draw in the visitor's browser. */
  verifyUrl: string | null;
  drandRound?: number | null;
}

export interface SiteSettings {
  tradingName: string;
  legalName: string;
  companyNumber: string;
  registeredOffice: string;
  supportEmail: string;
  postalEntryAddress: string[];
  drawsInstagram: string;
  socials: { label: string; url: string }[];
  youtubeUrl: string;
}

export interface BasketLine {
  competitionId: number;
  slug: string;
  title: string;
  image: string;
  qty: number;
  ticketPrice: number;
  bundles: Bundle[];
  maxPerPerson: number;
  drawAt: string;
}
