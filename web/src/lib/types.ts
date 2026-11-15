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
  /** A draft seen through a preview link: shown as live, entries disabled. */
  preview?: boolean;
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
  maxPerPerson: number | null; // null = no per-person cap
  /** Postponed (emergency only): the old closing time and the public reason. */
  postponement?: { at: string; from: string | null; reason: string | null } | null;
  bundles: Bundle[];
  opensAt: string | null;
  closesAt: string | null;
  /** Postal entries must be received by then (default: 48 hours before closesAt). */
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
  /** "Redraw pending" / "Prize unawarded" when the winner was forfeited and there's no current winner. */
  note?: string | null;
  redraws?: number;
  /** The draw reference (e.g. WDS-7KQ2MX) and method (A: verity, B: external) of a published draw. */
  reference?: string | null;
  method?: 'verity' | 'external';
  externalKind?: 'rng' | 'physical' | null;
}

/** A competition that isn't live yet: only what the public may see (never prices or ticket counts). */
export interface CompetitionTeaser {
  slug: string;
  title: string;
  image: { src: string; alt: string } | null;
  /** When entries open (optional); the pre-launch countdown targets it while it's still ahead. */
  opensAt?: string | null;
  drawAt: string | null;
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
  /** Optional YouTube channel for live draws (Instagram stays primary). */
  drawsYoutubeUrl: string | null;
  siteSpendCapEnabled?: boolean;
  siteSpendCapMonthly?: number | null;
}

export interface BasketLine {
  competitionId: number;
  slug: string;
  title: string;
  image: string;
  qty: number;
  ticketPrice: number;
  bundles: Bundle[];
  maxPerPerson: number | null; // null = no per-person cap
  drawAt: string;
}
