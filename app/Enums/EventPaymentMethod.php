<?php

namespace App\Enums;

/**
 * How a customer pays at an event stand. The value is stored on `orders.payment_method`.
 *
 * EXTENSION POINT: card payment through the site (not built yet).
 * Today every method is "taken at the stand": staff take the money (cash in hand, or the
 * standalone card reader) and the sale is recorded as paid straight away, so the tickets are
 * issued at once (CheckoutService::start with channel 'event').
 *
 * To add card payment through the site later:
 *   1. Add a case, e.g. `case SiteCard = 'site_card';` with label "Card (on this tablet)".
 *   2. Return false from settlesAtStand() for it.
 *   3. In EventSaleController::store, when settlesAtStand() is false, create the order as
 *      'pending' (the online checkout path with staff_user_id and channel 'event') and return
 *      the payment provider's checkout/session details instead of tickets. The stand page then
 *      shows the card form and waits for the order to turn 'paid' (the existing payment webhook,
 *      CheckoutService::handlePaymentResult, issues the tickets and emails them).
 *   4. The stand page already draws its payment buttons from this enum (`paymentMethods` in
 *      GET /api/admin/event-sales/competitions), so the new button appears on its own.
 * Reports group by the stored value, so the new method gets its own row with no changes.
 */
enum EventPaymentMethod: string
{
    case CardReader = 'card_reader';
    case Cash = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::CardReader => 'Card reader',
            self::Cash => 'Cash',
        };
    }

    /** True when staff take the payment themselves and the sale is paid as soon as it's recorded. */
    public function settlesAtStand(): bool
    {
        return true;
    }

    /** @return list<array{value: string, label: string}> for the stand's payment buttons. */
    public static function forStand(): array
    {
        return array_map(fn (self $m) => ['value' => $m->value, 'label' => $m->label()], self::cases());
    }
}
