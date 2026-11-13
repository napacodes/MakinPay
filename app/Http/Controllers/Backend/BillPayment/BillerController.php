<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\BillPayment;

use App\Enums\BillPayment\BillerAmountType;
use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\BillPayment\StoreBillerRequest;
use App\Http\Requests\Backend\BillPayment\UpdateBillerRequest;
use App\Models\BillCategory;
use App\Models\Biller;
use App\Models\BillerItem;
use App\Models\BillPaymentProvider;
use App\Models\Currency;
use App\Services\BillPayment\BillPaymentPricer;
use App\Support\HomeCountry;
use App\Support\Money;
use App\Traits\FileManageTrait;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The billers customers pay: who they are, which number identifies an
 * account, what they charge, and which provider pays them.
 *
 * Reading needs bill-payment-list; every write needs bill-payment-manage.
 * A biller that has payments is never deleted, because those payments point
 * at it. The way to retire one is to switch it off.
 */
class BillerController extends BaseController
{
    use FileManageTrait;

    /**
     * Customer-number patterns an admin can start from, keyed by the value
     * the preset select posts. "custom" leaves the pattern as typed.
     *
     * @var array<string, array{label: string, pattern: string}>
     */
    public const PATTERN_PRESETS = [
        'digits'       => ['label' => 'Digits, 6 to 20', 'pattern' => '[0-9]{6,20}'],
        'eleven'       => ['label' => 'Exactly 11 digits', 'pattern' => '[0-9]{11}'],
        'alphanumeric' => ['label' => 'Letters and digits, 4 to 30', 'pattern' => '[A-Za-z0-9]{4,30}'],
    ];

    public static function permissions(): array
    {
        return [
            'index'                                         => 'bill-payment-list',
            'create|store|edit|update|destroy|toggleStatus' => 'bill-payment-manage',
        ];
    }

    public function index(Request $request, BillPaymentPricer $pricer): View
    {
        $filters = [
            'search'   => trim((string) $request->query('search', '')),
            'category' => (string) $request->query('category', ''),
            'country'  => strtoupper(trim((string) $request->query('country', ''))),
            'provider' => (string) $request->query('provider', ''),
            'status'   => (string) $request->query('status', ''),
        ];

        $billers = Biller::query()
            ->with(['category', 'provider'])
            ->withCount(['items as active_items_count' => fn (Builder $items): Builder => $items->where('status', true)])
            ->when($filters['search'] !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $match): Builder => $match
                    ->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('external_code', 'like', '%'.$filters['search'].'%'),
            ))
            ->when(ctype_digit($filters['category']), fn (Builder $query): Builder => $query->where('bill_category_id', (int) $filters['category']))
            ->when(preg_match('/^[A-Z]{2}$/', $filters['country']) === 1, fn (Builder $query): Builder => $query->where('country', $filters['country']))
            ->when(ctype_digit($filters['provider']), fn (Builder $query): Builder => $query->where('bill_payment_provider_id', (int) $filters['provider']))
            ->when($filters['status'] === 'active', fn (Builder $query): Builder => $query->where('status', true))
            ->when($filters['status'] === 'disabled', fn (Builder $query): Builder => $query->where('status', false))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        $fees = $billers->getCollection()->mapWithKeys(
            fn (Biller $biller): array => [$biller->id => $this->effectiveFee($pricer, $biller)],
        );

        return view('backend.bill_payment.billers.index', [
            'billers'    => $billers,
            'fees'       => $fees,
            'filters'    => $filters,
            'categories' => BillCategory::query()->orderBy('sort_order')->orderBy('name')->get(),
            'providers'  => BillPaymentProvider::query()->orderBy('sort_order')->orderBy('name')->get(),
            'countries'  => Biller::query()->distinct()->orderBy('country')->pluck('country'),
            'stats'      => [
                'active'     => Biller::query()->where('status', true)->count(),
                'disabled'   => Biller::query()->where('status', false)->count(),
                'countries'  => Biller::query()->distinct()->count('country'),
                'categories' => BillCategory::query()->active()->count(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('backend.bill_payment.billers.create', $this->formData(new Biller([
            'amount_type'           => BillerAmountType::Open->value,
            'customer_number_label' => 'Account number',
            'status'                => false,
        ])));
    }

    public function store(StoreBillerRequest $request): RedirectResponse
    {
        $data       = $request->validated();
        $attributes = $this->attributesFrom($request, $data);

        DB::transaction(function () use ($attributes, $data): void {
            $biller = Biller::query()->create($attributes);

            $this->syncItems($biller, $data);
        });

        notifyEvs('success', __('Biller added.'));

        return redirect()->route('admin.bill-payment.billers.index');
    }

    public function edit(Biller $biller): View
    {
        $biller->load('items');

        return view('backend.bill_payment.billers.edit', $this->formData($biller));
    }

    public function update(UpdateBillerRequest $request, Biller $biller): RedirectResponse
    {
        $data       = $request->validated();
        $attributes = $this->attributesFrom($request, $data, $biller);

        DB::transaction(function () use ($attributes, $biller, $data): void {
            $biller->update($attributes);

            $this->syncItems($biller, $data);
        });

        notifyEvs('success', __('Biller updated.'));

        return redirect()->route('admin.bill-payment.billers.index');
    }

    public function destroy(Biller $biller): RedirectResponse
    {
        if ($biller->payments()->exists()) {
            notifyEvs('error', __('This biller has payments and cannot be deleted. Disable it instead.'));

            return redirect()->back();
        }

        // Its packages go with it (biller_items cascades).
        $biller->delete();

        notifyEvs('success', __('Biller deleted.'));

        return redirect()->route('admin.bill-payment.billers.index');
    }

    public function toggleStatus(Biller $biller): RedirectResponse
    {
        if (! $biller->status
            && trim((string) $biller->external_code) === ''
            && $biller->provider?->billersComeFromSync()) {
            notifyEvs('error', __('This biller has no provider code, so :provider would refuse every payment to it. Switch on a biller imported by the catalogue sync instead.', [
                'provider' => $biller->provider->name,
            ]));

            return redirect()->back();
        }

        $biller->forceFill(['status' => ! $biller->status])->save();
        $biller->load(['category', 'provider']);

        $hiddenByParent = $biller->status
            && (! $biller->category?->status || ! $biller->provider?->status);

        notifyEvs('success', match (true) {
            $hiddenByParent => __('Biller enabled. Customers still cannot see it while its category or provider is off.'),
            $biller->status => __('Biller enabled.'),
            default         => __('Biller disabled.'),
        });

        return redirect()->back();
    }

    /**
     * The fee a quote would charge on this biller, described the way the
     * customer reads it, e.g. "100 XOF + 1.5%".
     */
    private function effectiveFee(BillPaymentPricer $pricer, Biller $biller): string
    {
        $currency          = strtoupper(trim((string) $biller->currency));
        [$fixed, $percent] = $pricer->feeRule($biller, $currency);

        return Money::describeFee($fixed, $percent, $currency);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Biller $biller): array
    {
        $selectable = array_flip(HomeCountry::selectableCodes());
        $countries  = [];

        foreach (getCountries() as $country) {
            $code = strtoupper((string) ($country['code'] ?? ''));

            if (isset($selectable[$code])) {
                $countries[$code] = trim(title((string) ($country['name'] ?? $code)).' ('.$code.')');
            }
        }

        asort($countries);

        return [
            'biller'         => $biller,
            'categories'     => BillCategory::query()->orderBy('sort_order')->orderBy('name')->get(),
            'providers'      => BillPaymentProvider::query()->orderBy('sort_order')->orderBy('name')->get(),
            'currencies'     => Currency::query()->orderBy('code')->get(['code', 'name', 'decimal_places']),
            'countries'      => $countries,
            'patternPresets' => self::PATTERN_PRESETS,
        ];
    }

    /**
     * The biller row from validated input. A fixed biller keeps no open
     * range and an open biller no packages, so the row only ever holds what
     * its amount type uses.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function attributesFrom(StoreBillerRequest $request, array $data, ?Biller $biller = null): array
    {
        $isFixed = $data['amount_type'] === BillerAmountType::Fixed->value;

        $attributes = [
            'bill_category_id'         => (int) $data['bill_category_id'],
            'bill_payment_provider_id' => (int) $data['bill_payment_provider_id'],
            'name'                     => $data['name'],
            'country'                  => $data['country'],
            'currency'                 => $data['currency'],
            'description'              => $data['description'] ?? null,
            'customer_number_label'    => $data['customer_number_label'],
            'customer_number_pattern'  => $data['customer_number_pattern'] ?? null,
            'customer_number_example'  => $data['customer_number_example'] ?? null,
            'customer_number_help'     => $data['customer_number_help']    ?? null,
            'amount_type'              => $data['amount_type'],
            'min_amount'               => $isFixed ? null : ($data['min_amount'] ?? null),
            'max_amount'               => $isFixed ? null : ($data['max_amount'] ?? null),
            'fee_percent'              => $data['fee_percent']     ?? null,
            'fee_fixed'                => $data['fee_fixed']       ?? null,
            'token_label'              => $data['token_label']     ?? null,
            'processing_note'          => $data['processing_note'] ?? null,
            'instructions'             => $data['instructions']    ?? null,
            'status'                   => (bool) ($data['status'] ?? false),
            'sort_order'               => (int) ($data['sort_order'] ?? $biller?->sort_order ?? 0),
        ];

        if ($request->hasFile('logo')) {
            $attributes['logo'] = $this->uploadImage($request->file('logo'), $biller?->logo);
        }

        return $attributes;
    }

    /**
     * Make the biller's packages match the submitted list: update the ones it
     * names, create the new ones, delete the rest.
     *
     * Removed rows go first and kept rows drop their codes before any row is
     * written, so two packages swapping codes, or a new package reusing a
     * removed one's code, never trips biller_items_biller_external_unique
     * half way through. Payments keep their own snapshot of the package, and
     * their biller_item_id is null-on-delete.
     *
     * @param array<string, mixed> $data
     */
    private function syncItems(Biller $biller, array $data): void
    {
        if ($biller->isSyncManaged()) {
            $this->updateSyncedItems($biller, $data);

            return;
        }

        $submitted = $data['amount_type'] === BillerAmountType::Fixed->value
            ? array_values((array) ($data['items'] ?? []))
            : [];

        $keptIds = array_values(array_filter(array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $submitted,
        )));

        BillerItem::query()
            ->where('biller_id', $biller->id)
            ->whereNotIn('id', $keptIds)
            ->delete();

        BillerItem::query()
            ->where('biller_id', $biller->id)
            ->whereIn('id', $keptIds)
            ->update(['external_code' => null]);

        foreach ($submitted as $position => $item) {
            $row = [
                'name'          => $item['name'],
                'amount'        => (string) $item['amount'],
                'external_code' => $item['external_code'] ?? null,
                'status'        => (bool) ($item['status'] ?? true),
                'sort_order'    => $position,
            ];

            $id = (int) ($item['id'] ?? 0);

            if ($id > 0) {
                BillerItem::query()->where('biller_id', $biller->id)->whereKey($id)->update($row);

                continue;
            }

            BillerItem::query()->create(array_merge($row, ['biller_id' => $biller->id]));
        }
    }

    /**
     * A synced biller's packages belong to the catalogue sync: an admin may
     * switch an existing package on or off and reorder them, nothing more.
     * No package is deleted, recoded, renamed, repriced or added here, and a
     * package the form did not post is left as it is.
     *
     * @param array<string, mixed> $data
     */
    private function updateSyncedItems(Biller $biller, array $data): void
    {
        if ($data['amount_type'] !== BillerAmountType::Fixed->value) {
            return;
        }

        foreach (array_values((array) ($data['items'] ?? [])) as $position => $item) {
            $id = (int) ($item['id'] ?? 0);

            if ($id <= 0) {
                continue;
            }

            BillerItem::query()
                ->where('biller_id', $biller->id)
                ->whereKey($id)
                ->update([
                    'status'     => (bool) ($item['status'] ?? true),
                    'sort_order' => $position,
                ]);
        }
    }
}
