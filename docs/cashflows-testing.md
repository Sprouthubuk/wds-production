# Cashflows: setup, testing and go-live

WDS takes card payments through **Cashflows Hosted Checkout Pages (HCP)**. The customer pays on Cashflows' page, never
on ours. The code is `app/Services/Payments/CashflowsGateway.php`, and the automated tests are
`tests/Feature/CashflowsGatewayTest.php` (with a fake Cashflows) and `web/tests/cashflows-pages.test.mjs`.

Source: developer.cashflows.com (the Gateway guide, the HCP integration guide, and Testing and troubleshooting).

## How it works

1. **Checkout.** We create a *payment job* (`POST /api/gateway/payment-jobs`). It carries:
   - the amount (as a 2dp string), GBP, `en_GB`;
   - `order.orderNumber` = our order reference (`WDS-XXXXXX`);
   - the customer's name and email;
   - three return URLs back to the order page;
   - `expirationDateTimeUtc` = the end of the ticket reservation (`RAFFLE_RESERVATION_MINUTES`, 15 by default).

   The customer is sent to `links.action.url`. The order stores both the payment job reference and the payment
   reference.
2. **Return.** Cashflows sends the customer back with `?paymentjobref=&paymentref=&ordernumber=`. The return proves
   nothing. The order page shows **"Confirming your payment…"** and reloads every 4 seconds. Each load asks our API,
   which asks Cashflows (`GET …/payment-jobs/{job}/payments/{payment}`), at most every 3 seconds per order.
3. **Notification.** Cashflows posts a `PaymentStatusChange` notification to our Notification URL. Notifications aren't
   signed, so:
   - the URL carries a secret token, compared in constant time;
   - the status is **always** fetched from Cashflows before we act;
   - repeat notifications change nothing;
   - the reply is `200 {"paymentJobReference","paymentReference"}`. If Cashflows can't be queried, the reply is 503,
     so it retries (retries continue for up to a month).
4. **Outcome.**
   - **Paid:** tickets are allocated, subject to the existing rules: the credit card cap, sold-out or closed refunds,
     and so on.
   - **Failed:** the order is marked failed and the reservation is released.
   - **Cancelled or Expired:** the order is cancelled and the reservation is released.

   If a payment arrives after the reservation has ended, it is still honoured if the tickets are still available and
   the competition is open. Otherwise it is **refunded automatically**.
5. **Refunds.** `POST …/payments/{payment}/refunds` with `amountToRefund` and `refundNumber` (`WDS-XXXXXX-R1`, `-R2`, …).
   They are used:
   - automatically: credit card cap, late payment, sold out or closed;
   - from Admin → Orders → open an order → **Refund**, in full or in part.
6. **Request authentication.** Every request carries `ConfigurationId` and `Hash`. `Hash` is the lowercase hex SHA-512
   of the API key followed by the exact JSON string sent. For a GET, it's the SHA-512 of the API key alone.

Every call and notification is kept on the order, with card numbers cut to the first 6 and last 4 digits and no keys.
You can see them in Admin → order → **Payment** → History.

### Card type and the £250 credit card cap

Cashflows' docs don't name a credit/debit field. The code looks for any funding or card-type field in the payment,
including inside `cashFlowsAcquiringDetails` (a JSON string). If it doesn't find one, it looks up the BIN: the first
6 digits of `TruncatedCardNumber`, through `BinLookup`. For now that lookup always returns "don't know" (`NullBinLookup`).
If neither gives an answer, the card type is **unknown**.

Until detection is confirmed, `CASHFLOWS_UNKNOWN_CARD_AS_CREDIT=true` (the default) counts unknown cards towards the cap.
If a refund is triggered this way, the customer is told that we couldn't confirm the card was a debit card.

The full redacted response of the first 20 paid payments is written to the Laravel log as
`Cashflows: paid payment structure (redacted)`. After the first test payments:

1. Read those log lines. The card type and its source also show in Admin → order → Payment.
2. If a real funding field exists and isn't picked up, add its name to `CashflowsGateway::cardDetails()`.
3. Once debit cards show as `debit` and credit cards as `credit`, set `CASHFLOWS_UNKNOWN_CARD_AS_CREDIT=false`.

## Configuration (.env, never in git)

| Setting | Integration (test) | Production |
|---|---|---|
| `PAYMENT_DRIVER` | `cashflows` | `cashflows` |
| `CASHFLOWS_BASE_URL` | `https://gateway-int.cashflows.com` (the docs also use `https://gateway-inta.cashflows.com`; use whichever Cashflows confirms) | `https://gateway.cashflows.com` |
| `CASHFLOWS_CONFIGURATION_ID` | from the integration account | from portal.cashflows.com |
| `CASHFLOWS_API_KEY` | from the integration account | from portal.cashflows.com |
| `CASHFLOWS_WEBHOOK_TOKEN` | a new random value: `php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"` | a different random value |
| `CASHFLOWS_WEBHOOK_IPS` (optional) | `54.75.5.171,54.73.83.234` | `54.74.58.255,52.215.48.101` |
| `CASHFLOWS_UNKNOWN_CARD_AS_CREDIT` | `true` | `true` until card types are confirmed |

Leave `CASHFLOWS_WEBHOOK_IPS` empty unless you want the allowlist. Turn it on only after a notification has arrived and
the log shows the IP it came from, so a proxy doesn't block notifications. Run `php8.5 artisan config:cache` after
any change.

## Portal URLs (staging)

These assume the staging domains suggested in `forge/README.md` section 6 (`staging.wdscompetitions.co.uk` for the
website and `staging-api.wdscompetitions.co.uk` for the API). Use the real domains if they differ.

| Portal field | URL |
|---|---|
| Success URL | `https://staging.wdscompetitions.co.uk/order/return?result=success` |
| Failed URL | `https://staging.wdscompetitions.co.uk/order/return?result=failed` |
| Cancelled URL | `https://staging.wdscompetitions.co.uk/order/return?result=cancelled` |
| Notification URL | `https://staging-api.wdscompetitions.co.uk/api/payments/cashflows/notify?token=<CASHFLOWS_WEBHOOK_TOKEN>` |

- Each payment job also sends its own return URLs, straight to `/order/<reference>?key=…&result=…`. The Portal URLs
  above are the fallback; `/order/return` forwards to the right order page.
- **Notification types to enable:** Cancelled, Failed and Expired (already done). Paid is useful too; if it's off, the
  order page's own status checks still confirm Paid.
- **The staging API must not be behind HTTP basic auth**, or Cashflows can't deliver notifications. Basic auth on the
  staging *website* is fine: only testers' browsers go there.
- The token is in the URL, so it appears in the nginx access log. Treat it like a password and rotate it if it leaks:
  change `CASHFLOWS_WEBHOOK_TOKEN` and the Portal URL together.
- **Production:** the same URLs on `wdscompetitions.co.uk` and `api.wdscompetitions.co.uk`, with production's own token.

## Test matrix (integration account)

Use any expiry date in the future (within 10 years) and CVC `123`. The cardholder name sets the outcome. Use a test
competition at £1–£10 a ticket with no per-person limit.

> **Card numbers:** the Cashflows Testing page lists **8400682600000008** as Visa *debit* and **8400182600000009** as
> Visa *credit*. The brief listed 8400682600000009 as Visa debit, which isn't on that page, so use 8400682600000008.
> Other debit cards on the page: 8400682600011005 (Visa) and 5400100400099001 (Mastercard).

| # | Card / name | Do | Expected |
|---|---|---|---|
| 1 | Visa debit 8400682600000008, "Luke Skywalker" | Buy 3 tickets | Order page: "Confirming your payment…", then "You're in" with 3 numbers, plus a tickets email. Admin → order → Payment: card type **Debit, from the payment (Cashflows)**. If it shows *Unknown*, see "Card type" above. |
| 2 | Visa credit 4000000000000002, "Luke Skywalker" | Buy tickets totalling ≤ £250 | Paid, card type **Credit**, and the amount counts towards the £250 a month. |
| 3 | Credit card, same customer as #2 | Buy enough to take the month over £250 | Order page: "Payment refunded", with the £250 message. No tickets. Admin: a refund `…-R1` shows as **completed**, marked *Automatic*. The money shows as refunded in the Cashflows Portal. |
| 4 | Any card, "Han Solo" | Complete the 3DS challenge | The order page waits ("Confirming your payment…"), then shows paid. |
| 5 | Any card, "Ben Kenobi" | Pay | Declined. The order page says "Your payment didn't go through… No money was taken". The order is failed and the reservation released (the tickets are back on sale at once). |
| 6 | Any card, "Darth Vader" | Pay | Technical failure, handled the same as #5. The History shows the Failed status. |
| 7 | Any card | Press Cancel on the payment page | Back on the order page: "You cancelled the payment, so your tickets were released". The order is cancelled. |
| 8a | Debit card | Start a payment, wait 15+ minutes (the reservation ends), then try to pay | Cashflows should refuse the payment, because the page has expired. If it is taken, it's honoured while the tickets are still there. |
| 8b | Debit card | Start a payment, close the competition from Admin (or let it close), then pay | Refunded automatically. The order page says the payment arrived too late. No tickets. |
| 9 | (any paid order) | Wait for Cashflows to resend a notification, or replay one: `curl -X POST '<Notification URL>' -H 'Content-Type: application/json' -d '{"notifyType":"PaymentStatusChange","paymentJobReference":"<job>","paymentReference":"<payment>"}'` | Replies 200 with both references. The order and tickets are unchanged and no second email is sent. History gains a *notification* and a *status* row. |
| 10 | (a paid order) | Admin → order → Refund £1 (partial), then Refund with the amount empty (full, optionally "Take the tickets out of the draw") | Partial: the order stays paid and its tickets stay in. Full: the order is refunded, plus the tickets are withdrawn if ticked while open. Both refunds show as **completed** in the panel and in the Cashflows Portal. A third attempt says nothing is left to refund. |

Also check:
- A refund larger than what's left is refused before reaching Cashflows.
- Cashflows' own refusals appear as plain messages: `RefundAmountExceedsOriginalAmount`, `PaymentAlreadyFullyRefunded`
  and `InvalidPaymentForRefund`. These are covered by automated tests; on the integration account, try refunding an
  order that was already fully refunded in the Portal.
- Nothing in Admin → History shows a full card number or the API key.

## Live £1 smoke test (if the integration account is slow)

`forge/README.md` says staging never uses a live payment account. **Run this only with the owner's explicit go-ahead,
and for one session.**

1. On staging, put the **live** credentials in `.env`: `CASHFLOWS_BASE_URL=https://gateway.cashflows.com`, plus the
   live `CASHFLOWS_CONFIGURATION_ID`, `CASHFLOWS_API_KEY` and a new `CASHFLOWS_WEBHOOK_TOKEN`. Then run `config:cache`.
2. Set the live Portal's URLs to the staging URLs above (note what was there before).
3. Create a test competition at **£1 a ticket** (draft → publish on staging).
4. Buy 1 ticket with a **real debit card**.
   - Expect: paid, tickets issued.
   - Note the card type and its source in Admin → order → Payment, and keep the `paid payment structure` log line.
5. Buy 1 ticket with a **real credit card**.
   - Expect: paid, card type credit.
   - If it shows Unknown, the BIN/field work isn't done yet: note it.
6. Admin → each order → **Refund** (full). Expect completed refunds, and both cards credited within a few days.
7. Optional: one partial refund of £0.50 on a £1 order bought with 2 tickets.
8. Put staging back:
   - `PAYMENT_DRIVER=fake`, or the integration credentials once they arrive;
   - live credentials out of staging's `.env`;
   - the live Portal URLs back to production's (or blank until go-live);
   - rotate the live API key in the Portal if it was ever shared outside the password manager.

## Go-live checklist (production)

- [ ] The integration test matrix passes (or the live smoke test, with its refunds confirmed in the Portal).
- [ ] Card type detection is confirmed from the `paid payment structure` log lines (or a BIN service is plugged into
      `BinLookup`). Only then set `CASHFLOWS_UNKNOWN_CARD_AS_CREDIT=false`.
- [ ] Production `.env`:
  - `PAYMENT_DRIVER=cashflows`
  - `CASHFLOWS_BASE_URL=https://gateway.cashflows.com`
  - the production `CASHFLOWS_CONFIGURATION_ID` and `CASHFLOWS_API_KEY`
  - a new `CASHFLOWS_WEBHOOK_TOKEN`

  Then run `config:cache`. The values go in the password manager, never in git, chat or tickets.
- [ ] **Rotate the API key** in portal.cashflows.com if the live key was used for staging or seen by anyone outside the
      password manager. Update production's `.env` at the same moment.
- [ ] Production Portal URLs:
  - Success `https://wdscompetitions.co.uk/order/return?result=success`
  - Failed `…?result=failed`
  - Cancelled `…?result=cancelled`
  - Notification `https://api.wdscompetitions.co.uk/api/payments/cashflows/notify?token=<production token>`

  Enable notifications for status changes (Paid, Failed, Cancelled, Expired).
- [ ] Portal: the payment page expiry default is about 15 minutes. Our jobs set their own expiry to the reservation end.
- [ ] Optional: `CASHFLOWS_WEBHOOK_IPS=54.74.58.255,52.215.48.101`, once a notification has been seen arriving from
      those IPs.
- [ ] Send the completed **accreditation spreadsheet** to **implementations@cashflows.com**, and wait for Cashflows to
      sign off the integration before taking real payments.
- [ ] First real payments: watch Admin → Orders for "refund needed" and check the History on the first few orders.
