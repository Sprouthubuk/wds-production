<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\PaymentEvent;
use App\Services\CreditCardCap;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Cashflows Gateway API, Hosted Checkout Pages (developer.cashflows.com: Gateway guide, HCP integration guide).
 *
 * - Every request carries ConfigurationId and Hash: lowercase hex SHA-512 of (API key + the exact JSON body sent);
 *   a GET has no body, so its Hash is of the API key alone. The body is encoded once and that same string is both
 *   hashed and sent (no pretty-printing).
 * - Create a payment job (POST /api/gateway/payment-jobs), send the customer to links.action.url.
 * - The customer's return to our site proves nothing: the order only moves when GET
 *   /api/gateway/payment-jobs/{job}/payments/{payment} says so (polled by the order page, and re-queried for every
 *   notification, see PaymentController::cashflows).
 * - Refunds: POST …/payments/{payment}/refunds with amountToRefund + refundNumber, full or partial.
 *
 * Integration or production is purely config: CASHFLOWS_BASE_URL, CASHFLOWS_CONFIGURATION_ID, CASHFLOWS_API_KEY.
 * Every request and response is kept on the order, redacted (PaymentLog).
 */
class CashflowsGateway implements PaymentGateway
{
    /** The refund errors Cashflows documents, in words for admins. */
    public const REFUND_ERRORS = [
        'RefundAmountExceedsOriginalAmount' => 'Cashflows refused: that is more than is left to refund on this payment. Check the refunds already made below.',
        'PaymentAlreadyFullyRefunded' => 'Cashflows says this payment has already been refunded in full (perhaps in the Cashflows Portal). Nothing more was refunded. If so, use "Mark refunded" to record it here.',
        'InvalidPaymentForRefund' => "Cashflows can't refund this payment: it wasn't taken, or it isn't a payment that can be refunded yet. Check it in the Cashflows Portal.",
        'InvalidRefundStatus' => "Cashflows can't refund this payment in its current state. Try again later or refund it in the Cashflows Portal.",
        'GenericRejectionOfRefund' => 'Cashflows rejected the refund. Try again later or refund it in the Cashflows Portal.',
    ];

    public function __construct(private array $config, private BinLookup $bins = new NullBinLookup()) {}

    public function name(): string { return 'cashflows'; }

    /** Lowercase hex SHA-512 of the API key followed by the exact body string sent ('' for a GET). */
    public static function hash(string $apiKey, string $body = ''): string
    {
        return hash('sha512', $apiKey.$body);
    }

    /** The JSON string that is both hashed and sent: compact, slashes and unicode unescaped. */
    public static function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function amount(int $pence): string
    {
        return number_format($pence / 100, 2, '.', '');
    }

    public static function pence(mixed $amount): ?int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : null;
    }

    public function createHostedPayment(Order $order, string $returnUrl): HostedPayment
    {
        [$first, $last] = array_pad(preg_split('/\s+/', trim((string) $order->name), 2) ?: [], 2, '');
        $billing = array_filter(['firstName' => $first, 'lastName' => $last], fn ($v) => $v !== '');
        $join = str_contains($returnUrl, '?') ? '&' : '?';
        $body = [
            'amountToCollect' => self::amount((int) $order->total_pence),
            'currency' => 'GBP',
            'locale' => 'en_GB',
            'order' => [
                'orderNumber' => $order->reference,
                'billingAddress' => $billing ?: null,
                'billingIdentity' => ['emailAddress' => $order->email],
            ],
            'parameters' => [
                'returnUrlSuccess' => "{$returnUrl}{$join}result=success",
                'returnUrlFailed' => "{$returnUrl}{$join}result=failed",
                'returnUrlCancelled' => "{$returnUrl}{$join}result=cancelled",
            ],
        ];
        $body['order'] = array_filter($body['order'], fn ($v) => $v !== null);
        // The payment page stops taking payment when our ticket reservation ends (RAFFLE_RESERVATION_MINUTES).
        if (($this->config['send_expiry'] ?? true) && $order->reserved_until) {
            $body['expirationDateTimeUtc'] = $order->reserved_until->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }

        $res = $this->send('POST', '/api/gateway/payment-jobs', $body);
        $json = $res->json() ?? [];
        $job = data_get($json, 'data.reference');
        $payment = data_get($json, 'data.order.payments.0.reference');
        $url = data_get($json, 'links.action.url');
        PaymentLog::record($order, 'created', $res->successful() ? 'Pending' : 'Error',
            $res->successful() ? 'Payment page created' : "Create payment job failed (HTTP {$res->status()})",
            ['request' => $body, 'httpStatus' => $res->status(), 'response' => $json ?: $res->body()]);

        if (! $res->successful() || ! is_string($job) || ! is_string($url)) {
            Log::error('Cashflows: create payment job failed', ['order' => $order->reference, 'status' => $res->status(), 'errors' => PaymentRedactor::redact(data_get($json, 'errors') ?? data_get($json, 'messages'))]);
            throw new RuntimeException("Cashflows didn't return a payment page (HTTP {$res->status()}).");
        }

        return new HostedPayment($url, $job, is_string($payment) ? $payment : null);
    }

    /** Cashflows notifications go to PaymentController::cashflows (token in the URL, then a status query). */
    public function parseWebhook(Request $request): PaymentResult
    {
        throw new NotFoundHttpException('Cashflows notifications use /api/payments/cashflows/notify.');
    }

    public function fetchStatus(Order $order): ?PaymentResult
    {
        if (! $order->payment_job_reference || ! $order->payment_reference) {
            return null;
        }

        return $this->fetchPayment($order, $order->payment_job_reference, $order->payment_reference);
    }

    /** GET the payment and turn it into a PaymentResult. Throws if Cashflows can't be reached (a notification is then retried). */
    public function fetchPayment(Order $order, string $jobReference, string $paymentReference, string $why = 'Status check'): ?PaymentResult
    {
        $res = $this->send('GET', '/api/gateway/payment-jobs/'.rawurlencode($jobReference).'/payments/'.rawurlencode($paymentReference));
        $json = $res->json() ?? [];
        if (! $res->successful()) {
            PaymentLog::record($order, 'error', null, "{$why}: HTTP {$res->status()}", ['httpStatus' => $res->status(), 'response' => $json ?: $res->body()]);
            if ($res->status() === 404) {
                return null;
            }
            throw new RuntimeException("Cashflows status query failed (HTTP {$res->status()}).");
        }

        $payment = is_array($json['data'] ?? null) ? $json['data'] : $json;
        $result = $this->resultFromPayment($order->reference, $payment, $paymentReference);
        $card = $result->cardType ? " · card {$result->cardType}".($result->cardTypeSource ? " ({$result->cardTypeSource})" : '') : '';
        PaymentLog::record($order, 'status', $result->gatewayStatus, "{$why}: ".($result->gatewayStatus ?? 'no status').$card, $json);
        $this->logFirstPayments($order, $result, $json);

        return $result;
    }

    public function resultFromPayment(string $orderReference, array $payment, ?string $paymentReference = null): PaymentResult
    {
        $raw = (string) ($payment['status'] ?? $payment['paymentStatus'] ?? data_get($payment, 'order.paymentStatus') ?? '');
        $status = match (strtolower($raw)) {
            'paid', 'completed', 'captured', 'authorised', 'authorized' => PaymentResult::PAID,
            'failed', 'rejected', 'declined', 'error' => PaymentResult::FAILED,
            'cancelled', 'canceled', 'expired', 'voided', 'abandoned' => PaymentResult::CANCELLED,
            default => PaymentResult::PENDING,
        };
        $card = $status === PaymentResult::PAID ? $this->cardDetails($payment) : ['type' => null, 'source' => null, 'ref' => null];

        return new PaymentResult(
            $orderReference,
            $status,
            null,
            self::pence($payment['amountToCollect'] ?? $payment['amount'] ?? null),
            'card',
            $card['type'],
            $card['ref'],
            $card['source'],
            $raw !== '' ? $raw : null,
            $paymentReference ?? (is_string($payment['reference'] ?? null) ? $payment['reference'] : null),
        );
    }

    /**
     * Credit / debit / prepaid for the £250 credit card cap. First from the payment itself (any funding/card-type
     * field, including inside cashFlowsAcquiringDetails, a JSON string); then from the BIN (first 6 digits of
     * TruncatedCardNumber) through BinLookup; otherwise 'unknown' (CASHFLOWS_UNKNOWN_CARD_AS_CREDIT decides how that counts).
     *
     * @return array{type: string, source: ?string, ref: ?string, bin: ?string}
     */
    public function cardDetails(array $payment): array
    {
        $docs = [$payment];
        foreach (self::findAll($payment, fn ($k) => strcasecmp($k, 'cashFlowsAcquiringDetails') === 0) as $details) {
            $decoded = is_string($details) ? json_decode($details, true) : $details;
            if (is_array($decoded)) {
                $docs[] = $decoded;
            }
        }

        $type = null;
        foreach ($docs as $doc) {
            foreach (self::findAll($doc, fn ($k) => (bool) preg_match('/^(funding(_?type|_?source)?|card_?type|card_?category|card_?funding(_?type)?|card_?product_?type|account_?type|card_?kind)$/i', $k)) as $v) {
                if (is_string($v) && ($type = self::fundingWord($v))) {
                    break 2;
                }
            }
        }

        $truncated = null;
        foreach ($docs as $doc) {
            foreach (self::findAll($doc, fn ($k) => (bool) preg_match('/^(truncated_?card_?number|masked_?(card_?number|pan)|card_?number_?masked)$/i', $k)) as $v) {
                if (is_string($v) && preg_match('/^\d{6}[*xX\d]{2,9}\d{4}$/', str_replace(' ', '', $v))) {
                    $truncated = str_replace(' ', '', $v);
                    break 2;
                }
            }
        }
        $bin = $truncated ? substr($truncated, 0, 6) : null;
        $source = $type ? 'gateway' : null;
        if (! $type && $bin && ($fromBin = CreditCardCap::normalise($this->bins->lookup($bin))) && $fromBin !== 'unknown') {
            [$type, $source] = [$fromBin, 'bin'];
        }

        return [
            'type' => $type ?? 'unknown',
            'source' => $source,
            // Harm monitoring: a fingerprint of the masked number (never the number itself).
            'ref' => $truncated ? 'cf:'.substr(hash('sha256', $truncated), 0, 24) : null,
            'bin' => $bin,
        ];
    }

    public function refund(Order $order, int $amountPence, string $refundNumber, string $reason): RefundResult
    {
        if (! $order->payment_job_reference || ! $order->payment_reference) {
            return RefundResult::failed(null, "This order has no Cashflows payment to refund. Refund it in the Cashflows Portal, then mark it refunded.");
        }
        $body = ['amountToRefund' => self::amount($amountPence), 'refundNumber' => $refundNumber];
        try {
            $res = $this->send('POST', '/api/gateway/payment-jobs/'.rawurlencode($order->payment_job_reference).'/payments/'.rawurlencode($order->payment_reference).'/refunds', $body);
        } catch (ConnectionException $e) {
            PaymentLog::record($order, 'refund', 'Error', "Refund {$refundNumber} of £".self::amount($amountPence).': Cashflows could not be reached', ['request' => $body]);

            return RefundResult::failed(null, "Cashflows couldn't be reached, so nothing was refunded. Try again in a few minutes.");
        }
        $json = $res->json() ?? [];
        $status = data_get($json, 'data.status');
        if ($res->successful() && ! in_array(strtolower((string) $status), ['failed', 'rejected', 'declined'], true)) {
            PaymentLog::record($order, 'refund', is_string($status) ? $status : 'Completed', "Refund {$refundNumber} of £".self::amount($amountPence).' accepted', ['request' => $body, 'httpStatus' => $res->status(), 'response' => $json]);

            return new RefundResult(true, is_string(data_get($json, 'data.reference')) ? data_get($json, 'data.reference') : null);
        }

        $code = self::errorCode($json ?: $res->body());
        PaymentLog::record($order, 'refund', 'Refused', "Refund {$refundNumber} of £".self::amount($amountPence).' refused: '.($code ?? "HTTP {$res->status()}"), ['request' => $body, 'httpStatus' => $res->status(), 'response' => $json ?: $res->body()]);

        return RefundResult::failed($code, self::REFUND_ERRORS[$code] ?? 'Cashflows refused the refund'.($code ? " ({$code})" : " (HTTP {$res->status()})").'. Nothing was refunded. Check the payment in the Cashflows Portal.');
    }

    /** The first documented error code anywhere in a response, else the first "code"-like value. */
    public static function errorCode(mixed $response): ?string
    {
        $text = is_string($response) ? $response : (string) json_encode($response);
        foreach (array_keys(self::REFUND_ERRORS) as $code) {
            if (str_contains($text, $code)) {
                return $code;
            }
        }
        if (is_array($response)) {
            foreach (self::findAll($response, fn ($k) => (bool) preg_match('/^(code|errorCode|error_code|messageCode)$/i', $k)) as $v) {
                if (is_string($v) && preg_match('/^[A-Za-z][A-Za-z0-9_]{2,63}$/', $v)) {
                    return $v;
                }
            }
        }

        return null;
    }

    /** Log the full (redacted) structure of the first paid payments, so the real field names can be checked. */
    private function logFirstPayments(Order $order, PaymentResult $result, array $json): void
    {
        $limit = (int) ($this->config['log_first_payments'] ?? 20);
        if ($result->status !== PaymentResult::PAID || $limit < 1) {
            return;
        }
        if (PaymentEvent::where('kind', 'status')->where('status', $result->gatewayStatus)->count() <= $limit) {
            Log::info('Cashflows: paid payment structure (redacted)', ['order' => $order->reference, 'cardType' => $result->cardType, 'cardTypeSource' => $result->cardTypeSource, 'response' => PaymentRedactor::redact($json)]);
        }
    }

    private function send(string $method, string $path, ?array $body = null): Response
    {
        $configId = (string) ($this->config['configuration_id'] ?? '');
        $key = (string) ($this->config['api_key'] ?? '');
        if ($configId === '' || $key === '') {
            throw new RuntimeException('Cashflows is not configured: set CASHFLOWS_CONFIGURATION_ID and CASHFLOWS_API_KEY.');
        }
        $json = $body === null ? '' : self::encode($body);
        $request = Http::baseUrl(rtrim((string) ($this->config['base_url'] ?: 'https://gateway-int.cashflows.com'), '/'))
            ->timeout((int) ($this->config['timeout'] ?? 20))
            ->acceptJson()
            ->withHeaders(['ConfigurationId' => $configId, 'Hash' => self::hash($key, $json)]);

        return $body === null
            ? $request->get($path)
            : $request->withBody($json, 'application/json')->send($method, $path);
    }

    /** Every value in a nested array whose key matches. */
    private static function findAll(array $data, callable $keyMatches): array
    {
        $found = [];
        $walk = function (array $node) use (&$walk, &$found, $keyMatches) {
            foreach ($node as $k => $v) {
                if (is_string($k) && $keyMatches($k)) {
                    $found[] = $v;
                }
                if (is_array($v)) {
                    $walk($v);
                }
            }
        };
        $walk($data);

        return $found;
    }

    private static function fundingWord(string $v): ?string
    {
        $v = strtolower($v);

        return match (true) {
            str_contains($v, 'prepaid') || str_contains($v, 'pre-paid') => 'prepaid',
            str_contains($v, 'debit') => 'debit',
            str_contains($v, 'credit') || str_contains($v, 'charge') => 'credit',
            default => null,
        };
    }
}
