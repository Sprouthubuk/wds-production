# DCMS Voluntary Code: what the code does

This is an audit of the promises in our Responsible Play Policy (`/responsible-play`), the terms and the FAQs
against the code.

**Key:**
- **done**: it already existed before this audit (any fixes are noted).
- **built**: added in this audit.
- **blocked**: it needs something outside our code.

Last checked: 12 November 2026.

| # | Promise | Status | Where |
|---|---|---|---|
| 1 | Credit card spending is capped at £250 per calendar month per customer, across all competitions (credit card payments only) | **built** (#35), **blocked** for live payments | `CreditCardCap`, `CheckoutService::handlePaymentResult` |
| 2 | No credit cards on instant-win competitions | **built** (#35) | `competitions.instant_win`, `CreditCardCap` |
| 3 | Customer-set spend limits (daily, weekly or monthly), including £0 | **done**, £0 **built** | `ResponsiblePlay`, `MeController::limits`, account page |
| 4 | Limits can be set at registration and in the account | account **done**, registration **built** | `AuthController::register` (`monthlyLimit`), `/sign-in` |
| 5 | Decreases apply instantly; increases take effect after 72 hours | **built** (it was 24 hours) | `config/raffle.php` `limit_increase_delay_hours` |
| 6 | Limits can't be dodged by checking out as a guest | **built** | `CheckoutService::start` |
| 7 | 7-day pause (also 24 hours and 30 days), self-service | **done** | `MeController::takeBreak` |
| 8 | Self-exclusion for 6 months or more, self-service, which can't be shortened | **built** (it could be shortened) | `MeController::selfExclude` |
| 9 | Self-exclusion blocks entry | **done**; the guest-checkout gap is **built** | `ResponsiblePlay::assertCanSpend`, `CheckoutService::start` |
| 10 | Self-exclusion blocks all marketing | **built** | `MeController`, `ReportService::marketingCustomers` |
| 11 | Permanent account closure, self-service | **built** | `MeController::close` (`users.closed_at`) |
| 12 | Harm monitoring: flag for admin review on repeated limit hits, frequent payment-method changes or use of break tools | **built**; card changes **blocked** for live payments | `HarmMonitor`, `responsible_play_events`, `harm_flags` |
| 13 | Admin review queue with notes and the actions contact, lower limit and block | **built** | Admin → Responsible play; `HarmFlagController` |
| 14 | A Responsible Play page listing support services, linked in the footer and at checkout | page and footer **done**; checkout link and extra services **built** | `/responsible-play`, footer, basket |
| 15 | Odds and maximum entries shown before purchase | **done** | Entry panel |
| 16 | Admin reminder if a winner hasn't been contacted within 48 hours | **built** | `wds:winner-contact-reminders` (hourly), dashboard, Winners page |
| 17 | 14-day claim deadline | **done**; shown to admins **built** | `raffle.draws.claim_days` = 14, `Winner::claimBy()` |
| 18 | Optional site-wide monthly spend cap (off by default) | **built** in the next PR | Admin → Settings |

## Details

1. **Credit card cap.**
   - **When it's checked:** when the provider confirms the payment, before any tickets are allocated.
   - **Who it counts:** paid credit card orders this calendar month (UK time), matched by email or account.
   - **Over the cap:** the payment is refunded in full and nothing is allocated. The order page explains why, and paying by debit card is fine.
   - **If the refund can't be automatic:** the order shows in Admin as "Refunds needed".
   - **Card types:** debit, prepaid and unknown don't count towards the cap.
   - **Blocked:** Cashflows must report the card's funding type, and refunds must be wired up. Both are marked `TODO(cashflows)` in `CashflowsGateway`. Until then every live payment is `unknown`, so the cap doesn't bite. **Don't take live credit card payments until this is done.**
2. **Instant wins.** The flag is in the database and checked on payment. No competition uses it yet, and there's no admin switch until instant wins exist.
3. **£0 limit.** No paid entries are allowed. Free competitions stay available because they cost nothing.
4. **Registration.** Registration offers an optional monthly limit.
5. **72-hour delay.** This applies to raising or removing a limit.
6. **Guest checkout.** A guest using an account's email gets that account's limits and blocks (break, self-exclusion, suspension, closure). This also covers event-stand sales. Spend totals include guest orders made with that email.
7. **Breaks.** The options are 24 hours, 7 days and 30 days. A break blocks entry. A break doesn't stop marketing; only self-exclusion and closure do.
8. **Self-exclusion length.** Choosing a shorter period keeps the existing end date.
10. **What "all marketing" covers.** Self-exclusion and closure:
    - switch off the account's opt-in;
    - unsubscribe the newsletter entry;
    - clear earlier guest-order opt-ins;
    - block switching marketing back on.

    The marketing export also excludes self-excluded, suspended and closed accounts.
11. **Closure.**
    - It signs the customer out everywhere, and sign-in is then refused.
    - It can't be reopened online.
    - Tickets the customer already holds stay in their draws.
12. **Harm-monitoring triggers.**
    - **Limit hits:** a spend limit hit 3 times in 30 days.
    - **Card changes:** 3 different cards in 30 days, using `orders.card_ref`, which holds the provider's card token and never a card number. **Blocked:** Cashflows must supply a card token (`TODO(cashflows)`).
    - **Break tools:** any break, self-exclusion or closure.
    - **Duplicates:** there is one open flag per customer per reason.
13. **Queue actions.**
    - **Note:** adds a note.
    - **Contacted them:** records that the customer was contacted.
    - **Lower limit:** applies at once and can only go down.
    - **Block:** suspends the account and signs the customer out.
    - **Reviewed, close:** closes the flag.

    Each action is added to the flag's notes with the time and the staff member's name. Open flags are counted on the dashboard.
14. **Support services listed:**
    - National Gambling Helpline;
    - GamCare;
    - BeGambleAware;
    - Gamblers Anonymous UK;
    - StepChange;
    - Samaritans.

    The checkout link is under the terms checkbox in the basket.
15. **Odds and maximum entries.**
    - The entry panel shows "1 in N" odds, the odds for the chosen quantity, and "Max N per person".
    - There's no maximum line when a competition has no per-person cap (#29).
16. **Winner reminder.**
    - **Who:** winners not marked contacted 48 hours after the draw.
    - **How:** one email to each admin per winner, plus a dashboard tile and a "Contact overdue" label with a "Mark contacted" button on the Winners page.
    - **When it stops:** setting the handover beyond "not started" also counts as contacted.
    - **Exclusions:** winners added by hand, and winners that existed before this change, don't trigger a reminder.

## Open items

- **Cashflows:** card type, card token and automatic refunds (`TODO(cashflows)` in `app/Services/Payments/CashflowsGateway.php`).
