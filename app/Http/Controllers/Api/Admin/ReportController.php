<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Money;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin → Reports: a summary for the page and CSV exports, all for one date range (?from=&to=,
 * whole UK days; default the last 30 days).
 */
class ReportController extends Controller
{
    public const EXPORTS = ['sales', 'event-takings', 'postal', 'customers', 'discounts'];

    public function __construct(private ReportService $reports) {}

    public function summary(Request $request)
    {
        [$from, $to] = $this->range($request);
        $sales = $this->reports->salesByCompetition($from, $to);
        $postal = $this->reports->postalEntries($from, $to);

        return response()->json(['data' => [
            'from' => $from->copy()->timezone('Europe/London')->toDateString(),
            'to' => $to->copy()->timezone('Europe/London')->toDateString(),
            'sales' => $sales->map(fn ($r) => array_merge($r, [
                'onlineRevenue' => Money::pounds($r['onlineRevenue']), 'eventRevenue' => Money::pounds($r['eventRevenue']), 'totalRevenue' => Money::pounds($r['totalRevenue']),
            ])),
            'totals' => [
                'tickets' => $sales->sum('totalTickets'),
                'revenue' => Money::pounds($sales->sum('totalRevenue')),
                'postalAccepted' => $postal->where('status', 'accepted')->count(),
                'postalRejected' => $postal->where('status', 'rejected')->count(),
            ],
            'eventTakings' => $this->reports->eventTakings($from, $to)->map(fn ($r) => array_merge($r, ['total' => Money::pounds($r['total'])])),
            'postalReasons' => $postal->where('status', 'rejected')->countBy('reason')->map(fn ($n, $reason) => ['reason' => $reason, 'count' => $n])->values(),
            'marketingCustomers' => $this->reports->marketingCustomers($from, $to)->count(),
            'discounts' => $this->reports->discountUsage($from, $to)->map(fn ($r) => array_merge($r, ['discount' => Money::pounds($r['discount']), 'revenue' => Money::pounds($r['revenue'])])),
        ]]);
    }

    public function export(Request $request, string $type)
    {
        abort_unless(in_array($type, self::EXPORTS, true), 404);
        [$from, $to] = $this->range($request);
        $uk = fn (Carbon $t) => $t->copy()->timezone('Europe/London')->format('Y-m-d H:i');
        $gbp = fn (int $pence) => number_format($pence / 100, 2, '.', '');

        [$head, $rows] = match ($type) {
            'sales' => [
                ['Competition', 'Status', 'Online tickets', 'Online revenue (GBP)', 'Event tickets', 'Event revenue (GBP)', 'Postal entries', 'Total tickets', 'Total revenue (GBP)'],
                $this->reports->salesByCompetition($from, $to)->map(fn ($r) => [
                    $r['competition'], $r['status'], $r['onlineTickets'], $gbp($r['onlineRevenue']), $r['eventTickets'], $gbp($r['eventRevenue']),
                    $r['postalEntries'], $r['totalTickets'], $gbp($r['totalRevenue']),
                ]),
            ],
            'event-takings' => [
                ['Staff', 'Payment method', 'Orders', 'Tickets', 'Total (GBP)'],
                $this->reports->eventTakings($from, $to)->map(fn ($r) => [$r['staff'], $r['paymentMethod'], $r['orders'], $r['tickets'], $gbp($r['total'])]),
            ],
            'postal' => [
                ['Received (UK)', 'Competition', 'Name', 'Email', 'Status', 'Ticket', 'Rejection reason'],
                $this->reports->postalEntries($from, $to)->map(fn ($r) => [$uk($r['receivedAt']), $r['competition'], $r['name'], $r['email'], $r['status'], $r['ticket'], $r['reason']]),
            ],
            'customers' => [
                ['Email', 'First name', 'Last name', 'Consent source', 'Since (UK)'],
                $this->reports->marketingCustomers($from, $to)->map(fn ($r) => [$r['email'], $r['firstName'], $r['lastName'], $r['source'], $uk(Carbon::parse($r['since']))]),
            ],
            'discounts' => [
                ['Code', 'Discount', 'Orders', 'Discount given (GBP)', 'Revenue after discount (GBP)', 'Uses all time', 'Active'],
                $this->reports->discountUsage($from, $to)->map(fn ($r) => [$r['code'], $r['type'], $r['orders'], $gbp($r['discount']), $gbp($r['revenue']), $r['usedAllTime'], $r['active'] ? 'yes' : 'no']),
            ],
        };

        $name = sprintf('wds-%s-%s-to-%s.csv', $type, $from->copy()->timezone('Europe/London')->toDateString(), $to->copy()->timezone('Europe/London')->toDateString());

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows £ and accents
            fputcsv($out, $head, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** Spreadsheet-safe cell: text starting with = + - @ (or a tab/CR) can't run as a formula. */
    public static function cell(mixed $v): string
    {
        $s = (string) ($v ?? '');

        return $s !== '' && ! is_int($v) && ! is_float($v) && ! preg_match('/^-?\d+(\.\d+)?$/', $s) && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$s
            : $s;
    }

    private function range(Request $request): array
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        return ReportService::range($request->query('from'), $request->query('to'));
    }
}
