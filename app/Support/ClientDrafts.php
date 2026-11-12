<?php

namespace App\Support;

/**
 * The competitions the client has confirmed, as data, for `php artisan wds:client-drafts` (and the local
 * seeder). New drafts are only ever created, never overwritten; the main event's confirmed details are
 * only applied while it is still an unpublished draft.
 */
final class ClientDrafts
{
    public const MAIN_EVENT = 'bmw-e46-330ci-clubsport';

    /** Until real photos arrive (served by the website). */
    public const PLACEHOLDER_IMAGE = '/images/prize-placeholder.svg';

    /**
     * The main event's confirmed details. Times are UTC (UK winter time is GMT): entries close Thu 24 Dec
     * 2026 at 19:00, live draw 19:30. The postal deadline is the 48-hour default: 19:00 on Tue 22 Dec.
     *
     * @return array<string, mixed>
     */
    public static function mainEvent(): array
    {
        return [
            'ticket_price_pence' => 1495,
            'bundles' => [['qty' => 3, 'price_pence' => 4000], ['qty' => 10, 'price_pence' => 12500]],
            'total_tickets' => 750,
            'cash_alternative_pence' => 500000,
            'closes_at' => '2026-12-24 19:00:00',
            'postal_closes_at' => null,
            'draw_at' => '2026-12-24 19:30:00',
        ];
    }

    /**
     * New drafts, by slug. Dates are left empty until the client confirms them.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function newDrafts(): array
    {
        return [
            'bc-racing-br-series-coilovers' => [
                'title' => 'BC Racing BR Series Coilovers',
                'strapline' => 'A brand-new set of BC Racing BR Series coilovers to suit your car, or £500 cash.',
                'category' => 'parts',
                'status' => 'draft',
                'images' => [['src' => self::PLACEHOLDER_IMAGE, 'alt' => 'BC Racing BR Series coilovers: photos coming soon']],
                'description' => [
                    'A brand-new set of BC Racing BR Series coilovers, chosen from the BC BR range to suit the winner\'s car.',
                    'Fitment depends on BC Racing offering a BR Series kit for the winner\'s car. If there isn\'t one, the winner takes the £500 cash alternative instead.',
                ],
                'specs' => [
                    ['label' => 'Brand', 'value' => 'BC Racing'],
                    ['label' => 'Series', 'value' => 'BR'],
                    ['label' => 'Condition', 'value' => 'New'],
                ],
                'ticket_price_pence' => 500,
                'bundles' => [['qty' => 5, 'price_pence' => 2000]],
                'total_tickets' => 495,
                'max_per_person' => 50,
                'cash_alternative_pence' => 50000,
                'opens_at' => null,
                'closes_at' => null,
                'postal_closes_at' => null,
                'draw_at' => null,
                'postal_entry_enabled' => true,
                'event_sales_enabled' => true,
                'sort_order' => 2,
            ],
        ];
    }
}
