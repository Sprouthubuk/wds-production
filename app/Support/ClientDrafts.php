<?php

namespace App\Support;

use App\Models\Competition;

/**
 * The competitions the client has confirmed, as data, for `php artisan wds:client-drafts` (and the local
 * seeder). Missing drafts are created; the confirmed details (confirmed()) are applied to each one only
 * while it is still an unpublished draft. Anything else edited in Admin (title, copy, photos) is kept.
 */
final class ClientDrafts
{
    public const MAIN_EVENT = 'bmw-e46-330ci-clubsport';

    /** Until real photos arrive (served by the website). */
    public const PLACEHOLDER_IMAGE = '/images/prize-placeholder.svg';

    public const COILOVERS = 'bc-racing-br-series-coilovers';

    public const ENGINE_TABLE = 'bespoke-built-bedside-engine-table';

    /**
     * Christmas Eve 2026. Times are UTC (UK winter time is GMT). All three close Thu 24 Dec at 19:00; the
     * live draws run from 19:30 in the order engine table, coilovers, BMW. Postal entries for the paid ones
     * must be received by 19:00 on Tue 22 Dec (the 48-hour default, so postal_closes_at stays empty).
     */
    private const CLOSES_AT = '2026-12-24 19:00:00';

    private const DRAW_AT = '2026-12-24 19:30:00';

    /**
     * The confirmed details, by slug: applied while that competition is an unpublished draft.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function confirmed(): array
    {
        return [
            self::MAIN_EVENT => self::mainEvent() + ['draw_method' => Competition::DRAW_PHYSICAL],
            self::COILOVERS => ['draw_method' => Competition::DRAW_PHYSICAL, 'max_per_person' => null, 'closes_at' => self::CLOSES_AT, 'postal_closes_at' => null, 'draw_at' => self::DRAW_AT],
            // Free: 500 entries, one per verified account, online only (no postal route).
            self::ENGINE_TABLE => ['draw_method' => Competition::DRAW_PHYSICAL, 'total_tickets' => 500, 'max_per_person' => 1, 'postal_entry_enabled' => false,
                'closes_at' => self::CLOSES_AT, 'postal_closes_at' => null, 'draw_at' => self::DRAW_AT],
        ];
    }

    /** @return array<string, mixed> */
    public static function mainEvent(): array
    {
        return [
            'ticket_price_pence' => 1495,
            'bundles' => [['qty' => 3, 'price_pence' => 4000], ['qty' => 10, 'price_pence' => 12500]],
            'total_tickets' => 750,
            'max_per_person' => null, // no cap
            'cash_alternative_pence' => 500000,
            'closes_at' => self::CLOSES_AT,
            'postal_closes_at' => null,
            'draw_at' => self::DRAW_AT,
        ];
    }

    /**
     * New drafts, by slug, as first created (then confirmed() is applied on top).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function newDrafts(): array
    {
        return [
            self::COILOVERS => [
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
                'max_per_person' => null,
                'cash_alternative_pence' => 50000,
                'opens_at' => null,
                'closes_at' => null,
                'postal_closes_at' => null,
                'draw_at' => null,
                'postal_entry_enabled' => true,
                'event_sales_enabled' => true,
                'sort_order' => 2,
            ],
            self::ENGINE_TABLE => [
                'title' => 'Bespoke Built Bedside Engine Table',
                'strapline' => 'A one-off bedside table built around an engine. Free to enter.',
                'category' => 'merch',
                'status' => 'draft',
                'images' => [['src' => self::PLACEHOLDER_IMAGE, 'alt' => 'Bespoke built bedside engine table: photos coming soon']],
                'description' => [
                    'A bespoke bedside table built around an engine, made by WDS Originals.',
                    'Free to enter online: one entry per verified account.',
                ],
                'specs' => [['label' => 'Entry', 'value' => 'Free']],
                'ticket_price_pence' => 0,
                'bundles' => [],
                'total_tickets' => 500,
                'max_per_person' => 1,
                'cash_alternative_pence' => null,
                'opens_at' => null,
                'closes_at' => null,
                'postal_closes_at' => null,
                'draw_at' => null,
                'postal_entry_enabled' => false,
                'event_sales_enabled' => false,
                'sort_order' => 3,
            ],
        ];
    }
}
