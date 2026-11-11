// Verity browser verifier: framework-free ES modules, no dependencies (WebCrypto and BigInt only).
// Works in every current browser and in Node 20+.
//
//   import { verifyRecord, verifyCarried } from './index.js';
//   const record = (await (await fetch('/verity/competitions/VTY-ABC123/verification')).json()).data;
//   const { verdict, checks } = record.competition.status === 'carried' ? await verifyCarried(record) : await verifyRecord(record);
//
// verdict: 'ok' (every check passed), 'bad' (any failed), 'unverifiable' (local demo randomness).
// The drop-in page component is verify-page/verity-verify.js.
export * from './verity.js';
export { verifyRound, roundTime, QUICKNET } from './drand.js';
export { verifyRecord, verifyCarried, auditHash, canonicalJson } from './verify-record.js';
