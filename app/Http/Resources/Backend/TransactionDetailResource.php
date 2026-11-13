<?php

namespace App\Http\Resources\Backend;

use App\Enums\MethodType;
use App\Enums\TrxStatus;
use App\Enums\TrxType;
use App\Models\MerchantWebhookDelivery;
use App\Models\Transaction;
use App\Services\Tax\TaxPresenter;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The single description of one transaction, shared by the admin detail modal,
 * the deep-link page and the receipt PDF.
 *
 * Every money value leaves here as a quantized string. The clients render what
 * they are given and never do arithmetic — a float crossing into JavaScript is
 * how a sub-cent crypto amount reaches the screen as `1.0000000000000001e-8`.
 *
 * @mixin Transaction
 */
class TransactionDetailResource extends JsonResource
{
    /**
     * Gateway payloads are provider-shaped and unpredictable: one of them will
     * eventually carry a bearer token or a signing secret, and this endpoint
     * renders the payload verbatim in a `<pre>`. Mask on the way out rather
     * than trust a per-provider allowlist to stay complete.
     */
    private const SENSITIVE_KEY = '/token|secret|password|key/i';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Transaction $trx */
        $trx = $this->resource;

        $refunded   = $trx->refundedAmount();
        $refundable = $trx->refundableAmount();

        return [
            'id'           => strtoupper((string) $trx->trx_id),
            'type'         => $trx->trx_type->value,
            'type_label'   => $trx->trx_type->label(),
            'status'       => $trx->status->value,
            'status_label' => $trx->status->label(),
            'status_color' => $trx->status->color(),

            'description' => $trx->description,
            'amount'      => $this->money($trx->amount),
            'fee'         => $this->money($trx->fee),
            'net_amount'  => $this->money($trx->net_amount),
            'currency'    => $trx->currency,

            'payable_amount'   => $this->money($trx->payable_amount),
            'payable_currency' => $trx->payable_currency ?: $trx->currency,
            'amount_flow'      => $trx->amount_flow?->value,
            'sign'             => $trx->amount_flow?->sign($trx->status) ?? '',

            // GF6: in the payable currency, inside payable_amount and apart
            // from the fee; a line per rule. No lines when none was charged.
            'tax' => TaxPresenter::transaction($trx),

            'fx'     => $this->fx(),
            'wallet' => [
                'reference' => $trx->wallet_reference,
                'amount'    => $this->money($trx->wallet_amount),
                'fee'       => $this->money($trx->wallet_fee),
            ],

            'provider'         => $trx->provider,
            'gateway_code'     => $trx->gateway_code,
            'processing_type'  => $trx->processing_type?->value,
            'processing_label' => $trx->processing_type?->label(),
            'trx_reference'    => $trx->trx_reference,
            'trx_data'         => $this->maskedPayload($trx->trx_data ?? []),
            'remarks'          => $trx->remarks,
            'expires_at'       => $trx->expires_at?->toIso8601String(),

            'created_at'    => $trx->created_at?->toIso8601String(),
            'created_human' => $trx->created_at?->format('d M Y, H:i'),
            'created_rel'   => $trx->created_at?->diffForHumans(),

            'customer' => $this->customer(),
            'refunds'  => [
                'refunded'   => $this->money($refunded),
                'refundable' => $this->money($refundable),
                'items'      => $trx->refunds
                    ->map(fn (Transaction $refund): array => [
                        'id'       => strtoupper((string) $refund->trx_id),
                        'amount'   => $this->money($refund->amount),
                        'reason'   => $refund->remarks ?: $refund->description,
                        'at'       => $refund->created_at?->toIso8601String(),
                        'at_human' => $refund->created_at?->format('d M Y, H:i'),
                    ])
                    ->values()
                    ->all(),
            ],

            'webhooks' => $this->webhooks(),

            'can'      => $this->abilities($refundable),
            'timeline' => $this->timeline(),
            'urls'     => [
                /*
                 * One document, two deliveries. `receipt` downloads the PDF;
                 * `receipt_print` renders the same template as a page that
                 * raises the print dialog on arrival, because an operator who
                 * wants paper should not have to download a file, find it in
                 * a downloads folder and open it first.
                 */
                'receipt'       => route('admin.transaction.receipt', $trx->trx_id),
                'receipt_print' => route('admin.transaction.receipt', [$trx->trx_id, 'print' => 1]),
                'customer'      => $trx->user ? route('admin.user.manage', $trx->user->username) : null,
                'action'        => route('admin.transaction.action', $trx->trx_id),
                'refund'        => route('admin.transaction.refund', $trx->trx_id),
                'self'          => route('admin.transaction.show', $trx->trx_id),
            ],
        ];
    }

    /**
     * The webhooks owed to the merchant for this payment, newest first. Only
     * a merchant payment has any; the query is skipped for everything else.
     *
     * @return list<array<string, mixed>>
     */
    private function webhooks(): array
    {
        /** @var Transaction $trx */
        $trx = $this->resource;

        if ($trx->trx_type !== TrxType::RECEIVE_PAYMENT) {
            return [];
        }

        return $trx->webhookDeliveries()
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(function (MerchantWebhookDelivery $delivery): array {
                $at = $delivery->delivered_at ?? $delivery->updated_at;

                return [
                    'id'                 => $delivery->id,
                    'event'              => $delivery->event,
                    'status'             => $delivery->status,
                    'attempts'           => $delivery->attempts,
                    'status_code'        => $delivery->last_status_code,
                    'error'              => $delivery->last_error,
                    'url'                => $delivery->url,
                    'at'                 => $at?->toIso8601String(),
                    'at_human'           => $at?->format('d M Y, H:i'),
                    'next_attempt_human' => $delivery->isPending() ? $delivery->next_attempt_at?->diffForHumans() : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * What this admin may actually do, answered here rather than in the view so
     * the modal, the deep-link page and the POST endpoints cannot disagree.
     * Frontend visibility is not authorization: the controller asks the same
     * three questions again before anything moves.
     *
     * @return array{approve: bool, reject: bool, refund: bool}
     */
    public function abilities(?float $refundable = null): array
    {
        /** @var Transaction $trx */
        $trx = $this->resource;

        $permission = self::actionPermission($trx->trx_type);

        $actionable = $trx->status   === TrxStatus::PENDING
            && $trx->processing_type === MethodType::MANUAL
            && $permission !== null
            && auth('admin')->user()?->can($permission) === true;

        $refundable ??= $trx->refundableAmount();

        return [
            'approve' => $actionable,
            'reject'  => $actionable,
            'refund'  => $trx->trx_type === TrxType::RECEIVE_PAYMENT
                && $trx->status         === TrxStatus::COMPLETED
                && $refundable > 0
                && auth('admin')->user()?->can('transaction-refund') === true,
        ];
    }

    /**
     * Approving a manual deposit and approving a manual withdrawal move money
     * in opposite directions, so each stays behind the permission its own
     * screen already uses. Every other type has no manual approval path at
     * all and must answer null rather than fall through to something
     * permissive.
     */
    public static function actionPermission(TrxType $type): ?string
    {
        return match ($type) {
            TrxType::DEPOSIT  => 'deposit-action',
            TrxType::WITHDRAW => 'withdraw-action',
            default           => null,
        };
    }

    /**
     * @return array<string, string|null>|null
     */
    private function fx(): ?array
    {
        /** @var Transaction $trx */
        $trx = $this->resource;

        if (blank($trx->exchange_rate) || (float) $trx->exchange_rate <= 0) {
            return null;
        }

        return [
            'entered_amount'   => Money::quantize($trx->entered_amount, $trx->entered_currency ?: $trx->currency),
            'entered_currency' => $trx->entered_currency ?: $trx->currency,
            'base_amount'      => Money::quantize($trx->base_amount, $trx->base_currency),
            'base_fee'         => Money::quantize($trx->base_fee, $trx->base_currency),
            'base_currency'    => $trx->base_currency,
            'exchange_rate'    => Money::formatRate($trx->exchange_rate),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customer(): ?array
    {
        /** @var Transaction $trx */
        $trx = $this->resource;

        if (! $trx->user) {
            return null;
        }

        return [
            'name'         => $trx->user->name,
            'username'     => $trx->user->username,
            'email'        => $trx->user->email,
            'kyc_verified' => $trx->user->isKycVerified(),
            'avatar'       => $trx->user->avatar,
            'url'          => route('admin.user.manage', $trx->user->username),
        ];
    }

    /**
     * The lifecycle, derived from the columns the row already carries. There is
     * no event table behind this and there does not need to be: `created_at`,
     * the status alongside `updated_at`, the refund rows and `expires_at`
     * between them describe everything that has happened to a transaction.
     *
     * @return list<array{title: string, at: string|null, subtitle: string, state: string}>
     */
    private function timeline(): array
    {
        /** @var Transaction $trx */
        $trx = $this->resource;

        $entries = [[
            'title'    => __('Initiated'),
            'at'       => $trx->created_at?->toIso8601String(),
            'subtitle' => trim(($trx->provider ?: __('System')).' · '.($trx->processing_type?->label() ?? '')),
            'state'    => 'done',
        ]];

        if ($trx->status === TrxStatus::PENDING) {
            $entries[] = [
                'title' => $trx->processing_type === MethodType::MANUAL
                    ? __('Awaiting approval')
                    : __('Gateway callback'),
                'at'       => null,
                'subtitle' => $trx->expires_at
                    ? __('Expires :date', ['date' => $trx->expires_at->format('d M Y, H:i')])
                    : __('No expiry set'),
                'state' => 'current',
            ];
        } else {
            $entries[] = [
                'title'    => $trx->status->label(),
                'at'       => $trx->updated_at?->toIso8601String(),
                'subtitle' => $trx->remarks ?: __('No remarks recorded.'),
                'state'    => 'done',
            ];
        }

        foreach ($trx->refunds as $refund) {
            $entries[] = [
                'title'    => $trx->isFullyRefunded() ? __('Refund issued') : __('Partial refund'),
                'at'       => $refund->created_at?->toIso8601String(),
                'subtitle' => Money::format($refund->amount, $refund->currency).' · '.($refund->remarks ?: $refund->trx_id),
                'state'    => 'done',
            ];
        }

        return $entries;
    }

    /**
     * @param  array<array-key, mixed> $payload
     * @return array<array-key, mixed>
     */
    private function maskedPayload(array $payload): array
    {
        $masked = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1) {
                $masked[$key] = '••••••••';

                continue;
            }

            $masked[$key] = is_array($value) ? $this->maskedPayload($value) : $value;
        }

        return $masked;
    }

    private function money(string|int|float|null $amount): string
    {
        /** @var Transaction $trx */
        $trx = $this->resource;

        return Money::quantize($amount, $trx->currency ?: null);
    }
}
