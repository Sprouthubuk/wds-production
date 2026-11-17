# DNS cutover: wdscompetitions.co.uk → production

**Target date: [TBC]**, before the competitions open (placeholder: 1 Dec 2026, 19:00 UK). Leave at least 48 hours:
DNS, SSL, the Cashflows live Portal URLs and a smoke test all happen on the day, and a mistake needs time to fix.

Nothing in this document has been changed yet. It's the plan, plus what the domain looks like today.

## What's there today (public DNS, read on 17 Nov 2026)

DNS is hosted on **Google Cloud DNS** (`ns-cloud-b1…b4.googledomains.com`). Make the changes there, wherever that
account lives.

| Name | Type | Value today | What it means |
|---|---|---|---|
| `wdscompetitions.co.uk` | A | `23.227.38.36` | **Shopify** |
| `wdscompetitions.co.uk` | AAAA | `2620:127:f00f:4::` | **Shopify** (IPv6) |
| `www.wdscompetitions.co.uk` | CNAME | `shops.myshopify.com` | **Shopify** |
| `api.wdscompetitions.co.uk` | — | no record | new |
| `wdscompetitions.co.uk` | MX | **none** | the domain receives no email |
| `wdscompetitions.co.uk` | TXT | **none** (no SPF, no verification records) | |
| `_dmarc.wdscompetitions.co.uk` | TXT | `v=DMARC1; p=none` | a DMARC record **already exists** (monitor only, no reports) |
| `resend._domainkey.wdscompetitions.co.uk` | TXT | `p=MIGfMA0…AQAB` (Resend DKIM key) | Resend sending: **keep exactly** |
| `send.wdscompetitions.co.uk` | CNAME | `send.forge.rmta.net` (MX `feedback.forge.rmta.net`, SPF `v=spf1 ip4:52.3.252.119 ip4:44.222.39.36 ip4:199.249.231.0/24 ~all`) | Resend's bounce/return-path, via Forge: **keep exactly** |
| `wdscompetitions.co.uk` | CAA | none | any CA may issue, so Let's Encrypt works |

### Flags

- **The apex and www point at a Shopify store today.** Ask Jason whether that store is still in use. Moving the
  records takes it off this domain. If it's used, it needs another domain (or its `myshopify.com` address) first.
  Also remove the domain from Shopify's admin (Settings → Domains) after cutover, so Shopify doesn't keep trying to
  renew a certificate for it.
- **Jason's email is not affected.** There's no MX on wdscompetitions.co.uk, so no mailbox lives here; his mail is
  on `wdsoriginals.com`, which this cutover doesn't touch. Nothing here suggests otherwise. (`sales@wdsoriginals.com`
  stays the contact address.) Consequence: nobody can reply to `noreply@wdscompetitions.co.uk`, which is intended;
  every email sets Reply-To to sales@wdsoriginals.com.
- **The only email records are Resend's** (`resend._domainkey`, `send`). Don't edit or delete them, and don't add an
  apex MX or SPF "to be safe": it isn't needed for Resend, which aligns via `send.` and DKIM.

## The changes on the day

Lower the TTL of the apex A/AAAA and `www` records to 300 seconds **a day before**, so the switch and any rollback
are quick. (Note the current values above before changing anything; they're the rollback.)

| Name | Type | New value | Notes |
|---|---|---|---|
| `wdscompetitions.co.uk` | A | production server's public IP (Forge → server `wds-production`) | replaces Shopify's |
| `wdscompetitions.co.uk` | AAAA | **delete** | unless the Forge server has IPv6 configured; otherwise IPv6 visitors would still reach Shopify |
| `www.wdscompetitions.co.uk` | CNAME → A | production server's IP (or CNAME `wdscompetitions.co.uk`) | replaces `shops.myshopify.com` |
| `api.wdscompetitions.co.uk` | A | production server's IP | new |
| `_dmarc.wdscompetitions.co.uk` | TXT | `v=DMARC1; p=none; rua=mailto:<a mailbox you read>; adkim=r; aspf=r` | **update** the existing record (keep only one `_dmarc` record) |
| `resend._domainkey`, `send` | | **unchanged** | |

DMARC: start at `p=none` with `rua` reports for two to four weeks, check the reports show Resend passing, then move
to `p=quarantine`. The `rua` mailbox must be one someone reads. If it's on another domain (for example
`dmarc@wdsoriginals.com`), that domain must authorise it with a TXT record
`wdscompetitions.co.uk._report._dmarc.wdsoriginals.com` = `v=DMARC1`, or reports are silently dropped. (That's one new
TXT record on wdsoriginals.com; it doesn't affect its mail.)

## Forge, in order

1. **Domains on the sites.** Forge → production website site → Domains: add `wdscompetitions.co.uk` and
   `www.wdscompetitions.co.uk` (pick one as primary; the other redirects). Production API site: add
   `api.wdscompetitions.co.uk`. Keep the `*.on-forge.com` names until everything works.
2. **DNS** as above. Wait until `nslookup wdscompetitions.co.uk 1.1.1.1`, `www.` and `api.` all return the server IP.
3. **SSL.** Forge → each site → SSL → Let's Encrypt, for exactly the domains on that site. It only succeeds once DNS
   points at the server (step 2). Then check `https://` works on all three with no warning, and `http://`
   redirects to `https://`.
4. **Environment** (Forge → site → Environment; you edit these):

   | Site | Setting | New value |
   |---|---|---|
   | API | `APP_URL` | `https://api.wdscompetitions.co.uk` |
   | API | `FRONTEND_URL` | `https://wdscompetitions.co.uk` (or `www.` if that's the primary) |
   | API | `VERITY_VERIFY_URL` | `https://wdscompetitions.co.uk/verify` |
   | API | `SANCTUM_STATEFUL_DOMAINS` / `SESSION_DOMAIN`, if set | the new website domain |
   | Website | `API_URL` | `https://api.wdscompetitions.co.uk` |

   Then on the API: `php8.5 artisan config:cache` and `php8.5 artisan queue:restart`. Redeploy the website.
5. **Cashflows live Portal URLs** (`docs/cashflows-testing.md`, go-live steps 11–16): the new domains.
6. **Check:**
   - `https://api.wdscompetitions.co.uk/up` → 200; `https://wdscompetitions.co.uk` loads the WDS site, not Shopify.
   - Email links (password reset to yourself, `php8.5 artisan wds:mail-test <you>`) open `wdscompetitions.co.uk`.
   - A launch alert test send (Admin → Launch alerts → Send test) has working Unsubscribe links on the new domain.
   - `/verify` loads, and a draw certificate's verify link uses the new domain.
   - Old links: `wds-competitions.on-forge.com` still works for now. Remove the on-forge names later.

## Rollback

Put the A/AAAA/CNAME values from "What's there today" back. With a 300-second TTL, most visitors are back within
minutes. Revert `APP_URL`/`FRONTEND_URL`/`VERITY_VERIFY_URL` and `config:cache`. Leave Resend's records alone in
either direction.
