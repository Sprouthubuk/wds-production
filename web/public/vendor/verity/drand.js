/**
 * drand "quicknet" beacon check — a port of backend/app/Verity/Drand.php.
 *
 * A quicknet round is a BLS signature (scheme bls-unchained-g1-rfc9380) on
 * SHA-256(round as 8-byte big-endian), made by the League of Entropy network.
 * The round's randomness is SHA-256(signature). Checking the signature against
 * the network's public key means nobody has to trust the relay that served it.
 *
 * Signatures are on G1, the public key on G2. Valid iff e(sig, g2) = e(H(m), pk),
 * checked as e(sig, -g2) · e(H(m), pk) = 1 with one final exponentiation.
 * Plain BigInt arithmetic, no dependencies; speed is not a goal (about a second).
 */

export const QUICKNET = {
  chainHash: '52db9ba70e0cc0f6eaf7803dd07447a1f5477735fd3f661792ba94600c84e971',
  publicKey: '83cf0f2896adee7eb8b5f01fcad3912212c437e0073e911fb90022d3e760183c8c4b450b6a0a6c3ac6a5776a2d1064510d1fec758c921cc22b0e17e63aaf4bcb5ed66304de9cf809bd274ca73bab4af5a6e9c76a4bc09e76eae8991ef5ece45a',
  genesisTime: 1692803367,
  period: 3,
  dst: 'BLS_SIG_BLS12381G1_XMD:SHA-256_SSWU_RO_NUL_',
};

const P = 0x1a0111ea397fe69a4b1ba7b6434bacd764774b84f38512bf6730d2a0f6b0f6241eabfffeb153ffffb9feffffffffaaabn;
const R = 0x73eda753299d7d483339d80809a1d80553bda402fffe5bfeffffffff00000001n;
const X_ABS = 0xd201000000010000n;      // |x|, the BLS12-381 parameter (x is negative)
const H_EFF = 0xd201000000010001n;      // G1 cofactor clearing (RFC 9380 §8.8.1)
const HALF = (P - 1n) / 2n;

// ---- Fp
const md = (a) => { const r = a % P; return r < 0n ? r + P : r; };
function powm(b, e, m = P) { let r = 1n; b %= m; if (b < 0n) b += m; while (e > 0n) { if (e & 1n) r = r * b % m; b = b * b % m; e >>= 1n; } return r; }
function finv(a) { // extended Euclid
  let [r0, r1] = [md(a), P], [s0, s1] = [1n, 0n];
  if (r0 === 0n) throw new Error('Inverse of zero');
  while (r1 !== 0n) { const q = r0 / r1; [r0, r1] = [r1, r0 - q * r1]; [s0, s1] = [s1, s0 - q * s1]; }
  return md(s0);
}
const isSquare = (a) => { a = md(a); return a === 0n || powm(a, HALF) === 1n; };
const fsqrt = (a) => { const y = powm(a, (P + 1n) / 4n); return y * y % P === md(a) ? y : null; };

// ---- Fp2 = Fp[u]/(u² + 1), as [c0, c1]
const F2 = {
  zero: [0n, 0n], one: [1n, 0n],
  add: (a, b) => [md(a[0] + b[0]), md(a[1] + b[1])],
  sub: (a, b) => [md(a[0] - b[0]), md(a[1] - b[1])],
  neg: (a) => [md(-a[0]), md(-a[1])],
  mul: (a, b) => [md(a[0] * b[0] - a[1] * b[1]), md(a[0] * b[1] + a[1] * b[0])],
  muls: (a, s) => [md(a[0] * s), md(a[1] * s)],
  sqr: (a) => F2.mul(a, a),
  inv: (a) => { const t = finv(a[0] * a[0] + a[1] * a[1]); return [md(a[0] * t), md(-a[1] * t)]; },
  mulXi: (a) => [md(a[0] - a[1]), md(a[0] + a[1])], // × (1 + u)
  eq: (a, b) => a[0] === b[0] && a[1] === b[1],
  isZero: (a) => a[0] === 0n && a[1] === 0n,
  sqrt(a) {
    let x;
    if (a[1] === 0n) {
      const s = fsqrt(a[0]);
      x = s !== null ? [s, 0n] : (() => { const t = fsqrt(md(-a[0])); return t === null ? null : [0n, t]; })();
    } else {
      const alpha = fsqrt(a[0] * a[0] + a[1] * a[1]);
      if (alpha === null) return null;
      const half = finv(2n);
      let delta = md((a[0] + alpha) * half);
      if (!isSquare(delta)) delta = md((a[0] - alpha) * half);
      const x0 = fsqrt(delta);
      if (x0 === null || x0 === 0n) return null;
      x = [x0, md(a[1] * finv(2n * x0))];
    }
    return x && F2.eq(F2.sqr(x), a) ? x : null;
  },
};

// ---- Fp6 = Fp2[v]/(v³ − ξ), ξ = 1 + u, as [c0, c1, c2]
const F6 = {
  zero: [F2.zero, F2.zero, F2.zero], one: [F2.one, F2.zero, F2.zero],
  add: (a, b) => [F2.add(a[0], b[0]), F2.add(a[1], b[1]), F2.add(a[2], b[2])],
  sub: (a, b) => [F2.sub(a[0], b[0]), F2.sub(a[1], b[1]), F2.sub(a[2], b[2])],
  neg: (a) => [F2.neg(a[0]), F2.neg(a[1]), F2.neg(a[2])],
  mul(a, b) {
    const m = F2.mul, s = F2.add;
    return [
      s(m(a[0], b[0]), F2.mulXi(s(m(a[1], b[2]), m(a[2], b[1])))),
      s(s(m(a[0], b[1]), m(a[1], b[0])), F2.mulXi(m(a[2], b[2]))),
      s(s(m(a[0], b[2]), m(a[1], b[1])), m(a[2], b[0])),
    ];
  },
  mulF2: (a, s) => [F2.mul(a[0], s), F2.mul(a[1], s), F2.mul(a[2], s)],
  mulV: (a) => [F2.mulXi(a[2]), a[0], a[1]], // × v
  inv(a) {
    const [a0, a1, a2] = a, m = F2.mul;
    const c0 = F2.sub(F2.sqr(a0), F2.mulXi(m(a1, a2)));
    const c1 = F2.sub(F2.mulXi(F2.sqr(a2)), m(a0, a1));
    const c2 = F2.sub(F2.sqr(a1), m(a0, a2));
    const t = F2.inv(F2.add(m(a0, c0), F2.mulXi(F2.add(m(a2, c1), m(a1, c2)))));
    return [m(c0, t), m(c1, t), m(c2, t)];
  },
};

// ---- Fp12 = Fp6[w]/(w² − v), as [c0, c1]
const F12 = {
  one: [F6.one, F6.zero],
  mul: (a, b) => [
    F6.add(F6.mul(a[0], b[0]), F6.mulV(F6.mul(a[1], b[1]))),
    F6.add(F6.mul(a[0], b[1]), F6.mul(a[1], b[0])),
  ],
  inv(a) {
    const t = F6.inv(F6.sub(F6.mul(a[0], a[0]), F6.mulV(F6.mul(a[1], a[1]))));
    return [F6.mul(a[0], t), F6.neg(F6.mul(a[1], t))];
  },
  conj: (a) => [a[0], F6.neg(a[1])],
  add: (a, b) => [F6.add(a[0], b[0]), F6.add(a[1], b[1])],
  mulF2: (a, s) => [F6.mulF2(a[0], s), F6.mulF2(a[1], s)],
  pow(a, e) { let r = F12.one; while (e > 0n) { if (e & 1n) r = F12.mul(r, a); a = F12.mul(a, a); e >>= 1n; } return r; },
  isOne: (a) => JSON.stringify(a, (_, v) => (typeof v === 'bigint' ? v.toString() : v)) === JSON.stringify(F12.one, (_, v) => (typeof v === 'bigint' ? v.toString() : v)),
};

// w⁻¹ and w⁻³: the untwist E'(Fp2) → E(Fp12) is (x, y) ↦ (x·w⁻², y·w⁻³).
const W = [F6.zero, F6.one];
const W1 = F12.inv(W);
const W3 = F12.mul(F12.mul(W1, W1), W1);

// ---- Curves. G1: y² = x³ + 4 over Fp. G2 (twist): y² = x³ + 4(1 + u) over Fp2. Affine points, null = infinity.
const G1 = {
  add(p, q) {
    if (!p) return q; if (!q) return p;
    let l;
    if (p[0] === q[0]) { if (md(p[1] + q[1]) === 0n) return null; l = md(3n * p[0] * p[0] * finv(2n * p[1])); }
    else l = md((q[1] - p[1]) * finv(q[0] - p[0]));
    const x = md(l * l - p[0] - q[0]);
    return [x, md(l * (p[0] - x) - p[1])];
  },
  mul(p, k) { let r = null; while (k > 0n) { if (k & 1n) r = G1.add(r, p); p = G1.add(p, p); k >>= 1n; } return r; },
};

export const G2_GEN = [
  [0x024aa2b2f08f0a91260805272dc51051c6e47ad4fa403b02b4510b647ae3d1770bac0326a805bbefd48056c8c121bdb8n, 0x13e02b6052719f607dacd3a088274f65596bd0d09920b61ab5da61bbdc7f5049334cf11213945d57e5ac7d055d042b7en],
  [0x0ce5d527727d6e118cc9cdc6da2e351aadfd9baa8cbdd3a76d429a695160d12c923ac9cc3baca289e193548608b82801n, 0x0606c4a02ea734cc32acd2b02bc28b99cb3e287e85a763af267492ab572e99ab3f370d275cec1da1aaa9075ff05f79ben],
];

const G2 = {
  add(p, q) {
    if (!p) return q; if (!q) return p;
    let l;
    if (F2.eq(p[0], q[0])) { if (F2.isZero(F2.add(p[1], q[1]))) return null; l = F2.mul(F2.muls(F2.sqr(p[0]), 3n), F2.inv(F2.muls(p[1], 2n))); }
    else l = F2.mul(F2.sub(q[1], p[1]), F2.inv(F2.sub(q[0], p[0])));
    const x = F2.sub(F2.sub(F2.sqr(l), p[0]), q[0]);
    return [x, F2.sub(F2.mul(l, F2.sub(p[0], x)), p[1])];
  },
  mul(p, k) { let r = null; while (k > 0n) { if (k & 1n) r = G2.add(r, p); p = G2.add(p, p); k >>= 1n; } return r; },
};

// ---- Point decoding (ZCash compressed format)
const bytesToInt = (b) => b.reduce((a, x) => (a << 8n) | BigInt(x), 0n);
export const hexToBytes = (h) => Uint8Array.from(h.match(/../g) ?? [], (x) => parseInt(x, 16));

export function decodeG1(hex) {
  if (!/^[0-9a-f]{96}$/.test(hex)) return null;
  const b = hexToBytes(hex);
  if (!(b[0] & 0x80) || (b[0] & 0x40)) return null; // must be compressed, not infinity
  const sign = (b[0] & 0x20) !== 0;
  b[0] &= 0x1f;
  const x = bytesToInt(b);
  if (x >= P) return null;
  let y = fsqrt(x * x * x + 4n);
  if (y === null) return null;
  if ((y > HALF) !== sign) y = P - y;
  return [x, y];
}

export function decodeG2(hex) {
  if (!/^[0-9a-f]{192}$/.test(hex)) return null;
  const b = hexToBytes(hex);
  if (!(b[0] & 0x80) || (b[0] & 0x40)) return null;
  const sign = (b[0] & 0x20) !== 0;
  b[0] &= 0x1f;
  const x1 = bytesToInt(b.slice(0, 48)), x0 = bytesToInt(b.slice(48));
  if (x0 >= P || x1 >= P) return null;
  const x = [x0, x1];
  let y = F2.sqrt(F2.add(F2.mul(F2.sqr(x), x), [4n, 4n]));
  if (y === null) return null;
  const big = y[1] !== 0n ? y[1] > HALF : y[0] > HALF;
  if (big !== sign) y = F2.neg(y);
  return [x, y];
}

// ---- Hash to G1 (RFC 9380, BLS12381G1_XMD:SHA-256_SSWU_RO_)
const enc = new TextEncoder();
const sha = async (bytes) => new Uint8Array(await crypto.subtle.digest('SHA-256', bytes));
const cat = (...a) => { const o = new Uint8Array(a.reduce((n, x) => n + x.length, 0)); let i = 0; for (const x of a) { o.set(x, i); i += x.length; } return o; };

export async function expandMessageXmd(msg, dst, len) {
  const ell = Math.ceil(len / 32);
  const dstPrime = cat(dst, [dst.length]);
  const b0 = await sha(cat(new Uint8Array(64), msg, [len >> 8, len & 255], [0], dstPrime));
  const out = [await sha(cat(b0, [1], dstPrime))];
  for (let i = 2; i <= ell; i++) out.push(await sha(cat(out[i - 2].map((x, j) => x ^ b0[j]), [i], dstPrime)));
  return cat(...out).slice(0, len);
}

const SSWU_A = 0x144698a3b8e9433d693a02c96d4982b0ea985383ee66a8d8e8981aefd881ac98936f8da0e0f97f5cf428082d584c1dn;
const SSWU_B = 0x12e2908d11688030018b12e8753eee3b2016c1f0f24f4070a0b9c14fcef35ef55a23215a316ceaa5d1cc48e98e172be0n;
const SSWU_Z = 11n;

function sswu(u) {
  const A = SSWU_A, B = SSWU_B, Z = SSWU_Z;
  const u2 = md(u * u);
  const den = md(Z * Z * u2 * u2 + Z * u2);
  const tv1 = den === 0n ? 0n : finv(den);
  const x1 = tv1 === 0n ? md(B * finv(Z * A)) : md(-B * finv(A) * (1n + tv1));
  const g = (x) => md(x * x * x + A * x + B);
  const gx1 = g(x1);
  let x, y;
  if (isSquare(gx1)) { x = x1; y = fsqrt(gx1); } else { x = md(Z * u2 * x1); y = fsqrt(g(x)); }
  if ((u & 1n) !== (y & 1n)) y = md(-y);
  return [x, y];
}

// 11-isogeny E' → E (RFC 9380 Appendix E.2), coefficients lowest degree first.
const ISO = [
  // x numerator
  ['11a05f2b1e833340b809101dd99815856b303e88a2d7005ff2627b56cdb4e2c85610c2d5f2e62d6eaeac1662734649b7', '17294ed3e943ab2f0588bab22147a81c7c17e75b2f6a8417f565e33c70d1e86b4838f2a6f318c356e834eef1b3cb83bb', 'd54005db97678ec1d1048c5d10a9a1bce032473295983e56878e501ec68e25c958c3e3d2a09729fe0179f9dac9edcb0', '1778e7166fcc6db74e0609d307e55412d7f5e4656a8dbf25f1b33289f1b330835336e25ce3107193c5b388641d9b6861', 'e99726a3199f4436642b4b3e4118e5499db995a1257fb3f086eeb65982fac18985a286f301e77c451154ce9ac8895d9', '1630c3250d7313ff01d1201bf7a74ab5db3cb17dd952799b9ed3ab9097e68f90a0870d2dcae73d19cd13c1c66f652983', 'd6ed6553fe44d296a3726c38ae652bfb11586264f0f8ce19008e218f9c86b2a8da25128c1052ecaddd7f225a139ed84', '17b81e7701abdbe2e8743884d1117e53356de5ab275b4db1a682c62ef0f2753339b7c8f8c8f475af9ccb5618e3f0c88e', '80d3cf1f9a78fc47b90b33563be990dc43b756ce79f5574a2c596c928c5d1de4fa295f296b74e956d71986a8497e317', '169b1f8e1bcfa7c42e0c37515d138f22dd2ecb803a0c5c99676314baf4bb1b7fa3190b2edc0327797f241067be390c9e', '10321da079ce07e272d8ec09d2565b0dfa7dccdde6787f96d50af36003b14866f69b771f8c285decca67df3f1605fb7b', '6e08c248e260e70bd1e962381edee3d31d79d7e22c837bc23c0bf1bc24c6b68c24b1b80b64d391fa9c8ba2e8ba2d229'],
  // x denominator
  ['8ca8d548cff19ae18b2e62f4bd3fa6f01d5ef4ba35b48ba9c9588617fc8ac62b558d681be343df8993cf9fa40d21b1c', '12561a5deb559c4348b4711298e536367041e8ca0cf0800c0126c2588c48bf5713daa8846cb026e9e5c8276ec82b3bff', 'b2962fe57a3225e8137e629bff2991f6f89416f5a718cd1fca64e00b11aceacd6a3d0967c94fedcfcc239ba5cb83e19', '3425581a58ae2fec83aafef7c40eb545b08243f16b1655154cca8abc28d6fd04976d5243eecf5c4130de8938dc62cd8', '13a8e162022914a80a6f1d5f43e7a07dffdfc759a12062bb8d6b44e833b306da9bd29ba81f35781d539d395b3532a21e', 'e7355f8e4e667b955390f7f0506c6e9395735e9ce9cad4d0a43bcef24b8982f7400d24bc4228f11c02df9a29f6304a5', '772caacf16936190f3e0c63e0596721570f5799af53a1894e2e073062aede9cea73b3538f0de06cec2574496ee84a3a', '14a7ac2a9d64a8b230b3f5b074cf01996e7f63c21bca68a81996e1cdf9822c580fa5b9489d11e2d311f7d99bbdcc5a5e', 'a10ecf6ada54f825e920b3dafc7a3cce07f8d1d7161366b74100da67f39883503826692abba43704776ec3a79a1d641', '95fc13ab9e92ad4476d6e3eb3a56680f682b4ee96f7d03776df533978f31c1593174e4b4b7865002d6384d168ecdd0a', '1'],
  // y numerator
  ['90d97c81ba24ee0259d1f094980dcfa11ad138e48a869522b52af6c956543d3cd0c7aee9b3ba3c2be9845719707bb33', '134996a104ee5811d51036d776fb46831223e96c254f383d0f906343eb67ad34d6c56711962fa8bfe097e75a2e41c696', 'cc786baa966e66f4a384c86a3b49942552e2d658a31ce2c344be4b91400da7d26d521628b00523b8dfe240c72de1f6', '1f86376e8981c217898751ad8746757d42aa7b90eeb791c09e4a3ec03251cf9de405aba9ec61deca6355c77b0e5f4cb', '8cc03fdefe0ff135caf4fe2a21529c4195536fbe3ce50b879833fd221351adc2ee7f8dc099040a841b6daecf2e8fedb', '16603fca40634b6a2211e11db8f0a6a074a7d0d4afadb7bd76505c3d3ad5544e203f6326c95a807299b23ab13633a5f0', '4ab0b9bcfac1bbcb2c977d027796b3ce75bb8ca2be184cb5231413c4d634f3747a87ac2460f415ec961f8855fe9d6f2', '987c8d5333ab86fde9926bd2ca6c674170a05bfe3bdd81ffd038da6c26c842642f64550fedfe935a15e4ca31870fb29', '9fc4018bd96684be88c9e221e4da1bb8f3abd16679dc26c1e8b6e6a1f20cabe69d65201c78607a360370e577bdba587', 'e1bba7a1186bdb5223abde7ada14a23c42a0ca7915af6fe06985e7ed1e4d43b9b3f7055dd4eba6f2bafaaebca731c30', '19713e47937cd1be0dfd0b8f1d43fb93cd2fcbcb6caf493fd1183e416389e61031bf3a5cce3fbafce813711ad011c132', '18b46a908f36f6deb918c143fed2edcc523559b8aaf0c2462e6bfe7f911f643249d9cdf41b44d606ce07c8a4d0074d8e', 'b182cac101b9399d155096004f53f447aa7b12a3426b08ec02710e807b4633f06c851c1919211f20d4c04f00b971ef8', '245a394ad1eca9b72fc00ae7be315dc757b3b080d4c158013e6632d3c40659cc6cf90ad1c232a6442d9d3f5db980133', '5c129645e44cf1102a159f748c4a3fc5e673d81d7e86568d9ab0f5d396a7ce46ba1049b6579afb7866b1e715475224b', '15e6be4e990f03ce4ea50b3b42df2eb5cb181d8f84965a3957add4fa95af01b2b665027efec01c7704b456be69c8b604'],
  // y denominator
  ['16112c4c3a9c98b252181140fad0eae9601a6de578980be6eec3232b5be72e7a07f3688ef60c206d01479253b03663c1', '1962d75c2381201e1a0cbd6c43c348b885c84ff731c4d59ca4a10356f453e01f78a4260763529e3532f6102c2e49a03d', '58df3306640da276faaae7d6e8eb15778c4855551ae7f310c35a5dd279cd2eca6757cd636f96f891e2538b53dbf67f2', '16b7d288798e5395f20d23bf89edb4d1d115c5dbddbcd30e123da489e726af41727364f2c28297ada8d26d98445f5416', 'be0e079545f43e4b00cc912f8228ddcc6d19c9f0f69bbb0542eda0fc9dec916a20b15dc0fd2ededda39142311a5001d', '8d9e5297186db2d9fb266eaac783182b70152c65550d881c5ecd87b6f0f5a6449f38db9dfa9cce202c6477faaf9b7ac', '166007c08a99db2fc3ba8734ace9824b5eecfdfa8d0cf8ef5dd365bc400a0051d5fa9c01a58b1fb93d1a1399126a775c', '16a3ef08be3ea7ea03bcddfabba6ff6ee5a4375efa1f4fd7feb34fd206357132b920f5b00801dee460ee415a15812ed9', '1866c8ed336c61231a1be54fd1d74cc4f9fb0ce4c6af5920abc5750c4bf39b4852cfe2f7bb9248836b233d9d55535d4a', '167a55cda70a6e1cea820597d94a84903216f763e13d87bb5308592e7ea7d4fbc7385ea3d529b35e346ef48bb8913f55', '4d2f259eea405bd48f010a01ad2911d9c6dd039bb61a6290e591b36e636a5c871a5c29f4f83060400f8b49cba8f6aa8', 'accbb67481d033ff5852c1e48c50c477f94ff8aefce42d28c0f9a88cea7913516f968986f7ebbea9684b529e2561092', 'ad6b9514c767fe3c3613144b45f1496543346d98adf02267d5ceef9a00d9b8693000763e3b90ac11e99b138573345cc', '2660400eb2e4f3b628bdd0d53cd76f2bf565b94e72927c1cb748df27942480e420517bd8714cc80d1fadc1326ed06f7', 'e0fa1d816ddc03e6b24255e0d7819c171c40f65e273b853324efcd6356caa205ca2f570f13497804415473a1d634b8f', '1'],
].map((k) => k.map((h) => BigInt('0x' + h)));

function isoMap([x, y]) {
  const [xn, xd, yn, yd] = ISO.map((k) => k.reduceRight((acc, c) => md(acc * x + c), 0n));
  if (xd === 0n || yd === 0n) return null;
  return [md(xn * finv(xd)), md(y * yn * finv(yd))];
}

export async function hashToG1(msg, dst = QUICKNET.dst) {
  const bytes = await expandMessageXmd(msg, enc.encode(dst), 128);
  const u0 = bytesToInt(bytes.slice(0, 64)) % P, u1 = bytesToInt(bytes.slice(64)) % P;
  return G1.mul(G1.add(isoMap(sswu(u0)), isoMap(sswu(u1))), H_EFF);
}

// ---- Pairing check
/** Product of Miller loops f_{|x|,Q}(P) over the pairs, untwisting Q on the fly. */
function millerLoop(pairs) {
  let f = F12.one;
  const T = pairs.map(([, q]) => q);
  // Line through T (slope l on the twist) evaluated at P, mapped into Fp12:
  //   yP − l·xP·w⁻¹ + (l·xT − yT)·w⁻³
  const line = (l, t, p) => {
    let v = F12.add(F12.mulF2(W1, F2.muls(l, md(-p[0]))), F12.mulF2(W3, F2.sub(F2.mul(l, t[0]), t[1])));
    v = [[F2.add(v[0][0], [p[1], 0n]), v[0][1], v[0][2]], v[1]];
    return v;
  };
  for (let i = 62; i >= 0; i--) {
    f = F12.mul(f, f);
    pairs.forEach(([p], j) => {
      const t = T[j];
      const l = F2.mul(F2.muls(F2.sqr(t[0]), 3n), F2.inv(F2.muls(t[1], 2n)));
      f = F12.mul(f, line(l, t, p));
      T[j] = G2.add(t, t);
    });
    if ((X_ABS >> BigInt(i)) & 1n) {
      pairs.forEach(([p, q], j) => {
        const t = T[j];
        const l = F2.mul(F2.sub(q[1], t[1]), F2.inv(F2.sub(q[0], t[0])));
        f = F12.mul(f, line(l, t, p));
        T[j] = G2.add(t, q);
      });
    }
  }
  return f; // x < 0 would conjugate f; irrelevant when testing a product against 1
}

const FINAL_EXP_HARD = (P ** 6n + 1n) / R;

/** e(P1, Q1) · e(P2, Q2) · … = 1 ? */
export function pairingProductIsOne(pairs) {
  const f = millerLoop(pairs);
  const easy = F12.mul(F12.conj(f), F12.inv(f)); // f^(p⁶ − 1)
  return F12.isOne(F12.pow(easy, FINAL_EXP_HARD));
}

const G2_NEG = [G2_GEN[0], F2.neg(G2_GEN[1])];

/** Is `signature` a valid BLS signature on G1 for this message under the G2 public key? */
export async function verifyG1Signature(signatureHex, message, publicKeyHex) {
  const sig = decodeG1(signatureHex), pk = decodeG2(publicKeyHex);
  if (!sig || !pk) return false;
  if (G1.mul(sig, R) !== null) return false; // not in the prime-order subgroup
  const h = await hashToG1(message);
  return pairingProductIsOne([[sig, G2_NEG], [h, pk]]);
}

const toHex = (b) => [...b].map((x) => x.toString(16).padStart(2, '0')).join('');

/** The message a quicknet round signs: SHA-256 of the round number as 8 bytes, big-endian. */
export async function roundMessage(round) {
  const b = new Uint8Array(8);
  new DataView(b.buffer).setBigUint64(0, BigInt(round));
  return sha(b);
}

/**
 * Full check of one quicknet round as served by any relay:
 * signature valid for that round under the quicknet key, and randomness = SHA-256(signature).
 * @returns {Promise<{valid: boolean, reason: string|null}>}
 */
export async function verifyRound({ round, randomness, signature }, publicKey = QUICKNET.publicKey) {
  if (!Number.isInteger(Number(round)) || Number(round) < 1) return { valid: false, reason: 'Bad round number.' };
  if (!/^[0-9a-f]{96}$/.test(signature ?? '')) return { valid: false, reason: 'Bad signature encoding.' };
  if (toHex(await sha(hexToBytes(signature))) !== randomness) return { valid: false, reason: 'Randomness is not the hash of the signature.' };
  const ok = await verifyG1Signature(signature, await roundMessage(round), publicKey);
  return ok ? { valid: true, reason: null } : { valid: false, reason: 'Signature does not verify against the drand quicknet key.' };
}

/** Unix time at which a quicknet round is published. */
export const roundTime = (round) => QUICKNET.genesisTime + (Number(round) - 1) * QUICKNET.period;

// Exposed for tests.
export const _internal = { P, R, G1, G2, F12, decodeG1, decodeG2, sswu, isoMap };
