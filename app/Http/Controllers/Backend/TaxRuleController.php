<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend;

use App\Enums\Tax\TaxableType;
use App\Enums\Tax\TaxBase;
use App\Enums\Tax\TaxScope;
use App\Enums\TrxStatus;
use App\Http\Requests\Backend\Tax\TaxRuleRequest;
use App\Models\TaxRule;
use App\Models\Transaction;
use App\Support\DecimalMoney;
use App\Support\HomeCountry;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The tax and levy rules (GF6): the list with what the rules collected, and
 * the one form that creates and edits a rule.
 *
 * Reading needs tax-rule-list; anything that changes a rule needs
 * tax-rule-manage. A rule takes effect from the next transaction priced
 * after it is saved; a transaction already priced keeps the tax it was
 * charged, which is stored on its own row. Deleting a rule therefore never
 * changes a past transaction, and switching one off is the reversible way to
 * stop charging it.
 */
class TaxRuleController extends BaseController
{
    /** How far back the "collected" figure on the list reaches. */
    public const COLLECTED_DAYS = 30;

    public static function permissions(): array
    {
        return [
            'index'                                   => 'tax-rule-list',
            'create|store|edit|update|destroy|toggle' => 'tax-rule-manage',
        ];
    }

    public function index(): View
    {
        $rules = TaxRule::query()->orderBy('transaction_type')->orderBy('sort_order')->orderBy('id')->get();
        $base  = (string) siteCurrency();

        $collected = (string) (Transaction::query()
            ->where('status', TrxStatus::COMPLETED->value)
            ->whereNotNull('base_tax')
            ->where('created_at', '>=', now()->subDays(self::COLLECTED_DAYS))
            ->sum('base_tax') ?: '0');

        return view('backend.tax_rule.index', [
            'rules' => $rules,
            'stats' => [
                'total'     => $rules->count(),
                'active'    => $rules->where('is_active', true)->count(),
                'collected' => DecimalMoney::normalize($collected, Money::decimalsIfKnown($base) ?? 2),
                'currency'  => $base,
            ],
            'homeCountry'  => HomeCountry::code(),
            'needsHome'    => HomeCountry::code() === null && $rules->contains(fn (TaxRule $rule): bool => in_array($rule->scope(), [TaxScope::Local, TaxScope::International], true)),
            'countryNames' => (new TaxRuleRequest)->countryOptions(),
        ]);
    }

    public function create(): View
    {
        return $this->form(new TaxRule([
            'transaction_type' => TaxableType::SendMoney->value,
            'scope'            => TaxScope::All->value,
            'base'             => TaxBase::Amount->value,
            'is_active'        => true,
        ]));
    }

    public function store(TaxRuleRequest $request): RedirectResponse
    {
        TaxRule::query()->create($request->rule());

        notifyEvs('success', __('Tax rule added. It applies from the next transaction.'));

        return redirect()->route('admin.tax-rule.index');
    }

    public function edit(TaxRule $taxRule): View
    {
        return $this->form($taxRule);
    }

    public function update(TaxRuleRequest $request, TaxRule $taxRule): RedirectResponse
    {
        $taxRule->update($request->rule());

        notifyEvs('success', __('Tax rule updated. Transactions already made keep the tax they were charged.'));

        return redirect()->route('admin.tax-rule.index');
    }

    public function destroy(TaxRule $taxRule): RedirectResponse
    {
        $taxRule->delete();

        notifyEvs('success', __('Tax rule deleted. Transactions already made keep the tax they were charged.'));

        return redirect()->route('admin.tax-rule.index');
    }

    public function toggle(TaxRule $taxRule): RedirectResponse
    {
        $taxRule->forceFill(['is_active' => ! $taxRule->is_active])->save();

        notifyEvs('success', $taxRule->is_active
            ? __('Tax rule switched on. It applies from the next transaction.')
            : __('Tax rule switched off. It no longer applies.'));

        return redirect()->back();
    }

    private function form(TaxRule $rule): View
    {
        return view('backend.tax_rule.form', [
            'rule'        => $rule,
            'types'       => TaxableType::cases(),
            'scopes'      => TaxScope::cases(),
            'bases'       => TaxBase::cases(),
            'countries'   => (new TaxRuleRequest)->countryOptions(),
            'homeCountry' => HomeCountry::code(),
            'currency'    => (string) siteCurrency(),
        ]);
    }
}
