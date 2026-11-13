<?php

declare(strict_types=1);

namespace App\Services\P2P;

use App\Enums\P2P\OfferStatus;
use App\Enums\P2P\OrderStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\P2P\Offer;
use App\Models\P2P\Order;
use App\Models\P2P\PaymentAccount;
use App\Models\P2P\PaymentMethod;
use App\Models\User;
use App\Services\Media\MediaSettings;
use App\Support\P2PPaymentFileDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A customer's P2P payment accounts - where a buyer pays them - for the
 * website and the app alike.
 *
 * An account is validated against its payment method's own fields, so each
 * method asks for what it needs. One account per method per customer. An
 * account cannot be removed while an open trade uses it, nor while an active
 * sell ad offers its method.
 *
 * A file field is stored on the private disk (P2PPaymentFileDisk) and handed
 * out only to the owner and to the parties of an order that carries it.
 */
class P2PPaymentAccountService
{
    /**
     * @throws ValidationException
     */
    public function create(User $user, Request $request): PaymentAccount
    {
        [$method, $accountData] = $this->payload($user, $request);

        $account = new PaymentAccount;
        $account->fill($accountData);
        $account->user_id = (int) $user->id;
        $account->setRelation('paymentMethod', $method);
        $account->syncDerivedAttributes($method);
        $account->save();

        return $account;
    }

    /**
     * @throws ValidationException
     */
    public function update(User $user, PaymentAccount $paymentAccount, Request $request): PaymentAccount
    {
        [$method, $accountData] = $this->payload($user, $request, $paymentAccount);

        $paymentAccount->fill($accountData);
        $paymentAccount->setRelation('paymentMethod', $method);
        $paymentAccount->syncDerivedAttributes($method);
        $paymentAccount->save();

        return $paymentAccount;
    }

    /**
     * @throws NotifyErrorException
     */
    public function delete(User $user, PaymentAccount $paymentAccount): void
    {
        $hasOpenOrders = Order::query()
            ->where(function ($query) use ($paymentAccount) {
                $query->where('payer_payment_account_id', $paymentAccount->id)
                    ->orWhere('receiver_payment_account_id', $paymentAccount->id);
            })
            ->whereIn('status', [
                OrderStatus::PENDING->value,
                OrderStatus::PAID->value,
                OrderStatus::DISPUTED->value,
            ])
            ->exists();

        if ($hasOpenOrders) {
            throw new NotifyErrorException(__('This payment account is being used in active trades and cannot be removed right now.'));
        }

        $usedBySellAds = Offer::query()
            ->where('user_id', $user->id)
            ->where('side', 'SELL')
            ->whereIn('status', [OfferStatus::ACTIVE->value, OfferStatus::PAUSED->value])
            ->whereHas('paymentMethods', function ($query) use ($paymentAccount) {
                $query->where('p2p_payment_methods.id', $paymentAccount->payment_method_id);
            })
            ->exists();

        if ($usedBySellAds) {
            throw new NotifyErrorException(__('This payment account is linked to one or more active sell ads. Remove that method from those ads first.'));
        }

        $paymentAccount->delete();
    }

    /**
     * The validated account, built against its method's own fields.
     *
     * @return array{0: PaymentMethod, 1: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function payload(User $user, Request $request, ?PaymentAccount $paymentAccount = null): array
    {
        $baseValidated = Validator::make($request->all(), [
            'payment_method_id' => [
                'required',
                'integer',
                Rule::exists('p2p_payment_methods', 'id'),
                Rule::unique('p2p_payment_accounts', 'payment_method_id')
                    ->ignore($paymentAccount?->id)
                    ->where(fn ($query) => $query->where('user_id', $user->id)->whereNull('deleted_at')),
            ],
            'label' => ['nullable', 'string', 'max:191'],
        ])->validate();

        $method = PaymentMethod::query()->findOrFail((int) $baseValidated['payment_method_id']);

        if (! (bool) $method->status) {
            throw ValidationException::withMessages([
                'payment_method_id' => __('The selected payment method is not available right now.'),
            ]);
        }

        $rules = [];
        foreach ($method->normalizedFields() as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $fieldRules   = [];
            $fieldRules[] = ! empty($field['required']) ? 'required' : 'nullable';

            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'number') {
                $fieldRules[] = 'numeric';
            } elseif ($type === 'file') {
                $fieldRules[] = 'file';
                $fieldRules[] = 'mimes:'.implode(',', MediaSettings::identityDocumentExtensions());
                $fieldRules[] = 'max:'.MediaSettings::effectiveMaxUploadSizeKb();
            } else {
                $fieldRules[] = 'string';
                $fieldRules[] = 'max:500';
            }

            if ($type === 'select') {
                $options = collect((array) ($field['options'] ?? []))
                    ->map(fn ($option) => trim((string) $option))
                    ->filter(fn (string $option) => $option !== '')
                    ->values()
                    ->all();

                if ($options !== []) {
                    $fieldRules[] = Rule::in($options);
                }
            }

            $rules['field_values.'.$key] = $fieldRules;
        }

        $dynamicValidated = $rules !== []
            ? Validator::make($request->all(), $rules)->validate()
            : [];

        $fieldValues = [];
        foreach ($method->normalizedFields() as $field) {
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'file') {
                if ($request->hasFile('field_values.'.$key)) {
                    $uploadedFile = $request->file('field_values.'.$key);
                    if ($uploadedFile) {
                        $fieldValues[$key] = P2PPaymentFileDisk::store($uploadedFile);
                    }
                } elseif (P2PPaymentFileDisk::isStoredFile($paymentAccount?->field_values[$key] ?? null)) {
                    // An edit that uploads nothing keeps the file already on the account.
                    $fieldValues[$key] = (string) $paymentAccount->field_values[$key];
                }

                continue;
            }

            $value = data_get($dynamicValidated, 'field_values.'.$key, $request->input('field_values.'.$key));
            if ($value === null) {
                continue;
            }

            $value = is_scalar($value) ? trim((string) $value) : '';
            if ($value === '') {
                continue;
            }

            $fieldValues[$key] = $value;
        }

        $account                    = $paymentAccount ?? new PaymentAccount;
        $account->payment_method_id = (int) $method->id;
        $account->label             = trim((string) ($baseValidated['label'] ?? '')) ?: null;
        $account->field_values      = $fieldValues !== [] ? $fieldValues : null;

        // A fresh proof file puts the account in front of an administrator;
        // an edit that keeps the same file keeps its verdict.
        $uploadedProof = collect($method->normalizedFields())
            ->filter(fn (array $field): bool => (string) ($field['type'] ?? '') === 'file')
            ->contains(fn (array $field): bool => $request->hasFile('field_values.'.(string) ($field['key'] ?? '')));
        if ($uploadedProof) {
            $account->verification_status = PaymentAccount::VERIFICATION_PENDING;
            $account->verified_at         = null;
        }
        $account->setRelation('paymentMethod', $method);
        $account->syncDerivedAttributes($method);

        return [$method, [
            'payment_method_id'   => (int) $method->id,
            'label'               => $account->label,
            'field_values'        => $account->field_values,
            'display_name'        => $account->display_name,
            'display_value'       => $account->display_value,
            'account_name'        => $account->account_name,
            'account_number'      => $account->account_number,
            'instructions'        => $account->instructions,
            'verification_status' => $account->verification_status ?? PaymentAccount::VERIFICATION_NONE,
            'verified_at'         => $account->verified_at,
        ]];
    }
}
