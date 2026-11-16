import type { FooterConfig, SiteSettings } from './types';

/** Used when the API doesn't send footer config (an older API, or sample data). Matches config/wds.php. */
export const DEFAULT_FOOTER: FooterConfig = {
  instagram: { handle: 'wds_originals', url: 'https://instagram.com/wds_originals' },
  youtube: { name: 'WDS Originals', url: 'https://youtube.com/@wdsoriginals' },
  icoNumber: null,
  poweredBy: true,
};

export const footerConfig = (s: SiteSettings): FooterConfig => ({ ...DEFAULT_FOOTER, ...(s.footer ?? {}) });

/** Independent support, linked from the footer's responsible play block (new tab). */
export const SUPPORT_LINKS = [
  { label: 'GamCare', url: 'https://www.gamcare.org.uk' },
  { label: 'GambleAware', url: 'https://www.gambleaware.org' },
] as const;

/** Accepted cards and wallets (Cashflows; debit only). Monochrome marks in /images/payments. */
export const PAYMENT_MARKS = [
  { key: 'visa', alt: 'Visa' },
  { key: 'mastercard', alt: 'Mastercard' },
  { key: 'apple-pay', alt: 'Apple Pay' },
  { key: 'google-pay', alt: 'Google Pay' },
] as const;

/** The legal line, parts joined with " · ". The ICO registration appears only when config('wds.ico_number') is set. */
export function legalParts(s: SiteSettings, year = new Date().getFullYear()): string[] {
  const ico = footerConfig(s).icoNumber;
  return [
    `© ${year} ${s.legalName}, trading as ${s.tradingName}`,
    `Registered in England & Wales, company no. ${s.companyNumber}`,
    `Registered office: ${s.registeredOffice}`,
    ...(ico ? [`ICO registration: ${ico}`] : []),
    '18+ UK residents only.',
  ];
}
