<?php

namespace App\Http\Controllers\Backend;

use App\Enums\MethodType;
use App\Http\Requests\WithdrawMethod\StoreUpdateRequest;
use App\Models\PaymentGateway;
use App\Models\WithdrawMethod;
use App\Services\MassPayment\MassPayoutMethods;
use App\Support\PaymentMethodCodeGenerator;
use App\Support\WithdrawFieldNormalizer;
use App\Traits\FileManageTrait;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class WithdrawMethodController extends BaseController
{
    use FileManageTrait;

    public static function permissions(): array
    {
        return [
            'index'             => 'withdraw-method-list',
            'store|edit|update' => 'withdraw-method-manage',
        ];
    }

    /**
     * Display a listing of withdraw methods.
     */
    public function index(): View
    {
        $type    = MethodType::tryFrom((string) request('type')) ?? MethodType::AUTOMATIC;
        $search  = trim((string) request('search'));
        $gateway = filled(request('gateway'))
            ? PaymentGateway::find((int) request('gateway'))
            : null;

        $paymentGateways = PaymentGateway::active()->withdrawAvailable()->get();

        $paymentMethods = WithdrawMethod::query()
            ->where('type', $type)
            ->when($gateway, fn ($query) => $query->where('payment_gateway_id', $gateway->id))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")
                ->orWhere('currency', 'like', "%{$search}%")))
            ->with('paymentGateway')
            ->paginate(10)
            ->withQueryString();

        $massPayoutOptions = app(MassPayoutMethods::class)->driverOptions($type);

        return view('backend.withdraw.method.index', compact('type', 'paymentGateways', 'paymentMethods', 'gateway', 'massPayoutOptions'));
    }

    /**
     * Stores a new withdraw method.
     *
     * @param  StoreUpdateRequest $request The request containing the withdraw method data.
     * @return RedirectResponse   A redirect response to the withdraw method index page.
     */
    public function store(StoreUpdateRequest $request)
    {

        // Prepare the data for creating a new withdraw method.
        $data = $this->prepareData($request);

        // Create a new withdrawal method using the prepared data.
        WithdrawMethod::create($data);

        // Notify the user of a successful withdrawal method creation.
        notifyEvs('success', __('Withdraw Method Added Successfully'));

        // Redirect the user back to the withdrawal method index page.
        return redirect()->route('admin.withdraw.method.index', ['type' => $request->input('type')]);
    }

    /**
     * Prepares the data for creating or updating a withdrawMethod.
     *
     * @return array
     */
    private function prepareData(StoreUpdateRequest $request, ?WithdrawMethod $paymentMethod = null)
    {
        // Get the validated request data
        $validated = $request->validated();

        // Blank is "not offered". Normalised here as well as by the global
        // empty-string middleware, so the column only ever holds a driver
        // code or NULL.
        if (array_key_exists('mass_payout_driver', $validated)) {
            $validated['mass_payout_driver'] = filled($validated['mass_payout_driver']) ? $validated['mass_payout_driver'] : null;
        }

        $paymentGateway = $request->filled('payment_gateway_id')
            ? PaymentGateway::query()->findOrFail((int) $request->input('payment_gateway_id'))
            : ($paymentMethod?->paymentGateway ?? null);

        $method_code = ($paymentGateway && $request->filled('currency'))
            ? PaymentMethodCodeGenerator::forGatewayCurrency(
                $paymentGateway,
                (string) $request->input('currency'),
                WithdrawMethod::class,
                $paymentMethod?->id,
            )
            : $validated['method_code'];

        // Handle logo upload safely (fallback to null if nothing exists)
        $logo = $request->hasFile('logo')
            ? self::uploadImage($request->file('logo'), $paymentMethod?->logo)
            : ($paymentMethod?->logo ?? null);

        // Prepare withdraw fields safely
        $fields = $request->filled('fields')
            ? WithdrawFieldNormalizer::normalize($request->input('fields'))
            : WithdrawFieldNormalizer::normalize($paymentGateway?->withdraw_field);

        if ($fields === []) {
            $fields = [['name' => 'account', 'type' => 'text', 'validation' => 'required']];
        }

        // The legacy `charge` / `*_charge` / `*_charge_type` columns are NOT
        // written here. The form posts the fixed/percent components, and
        // ResolvesRoleBasedCharges derives the legacy pair from them on save.
        //
        // Injecting defaults here used to silently wipe every configured
        // charge: the form reposts the exact stored decimal, so the component
        // columns are not dirty, while the injected legacy defaults are — and
        // the sync hook then treated the legacy pair as the source of truth.
        $chargeData = [];

        // Return the merged array
        return array_merge($validated, compact('logo', 'method_code', 'fields'), $chargeData, [
            'status' => $request->boolean('status'),
        ]);
    }

    /**
     * Displays the edit form for a withdraw method.
     *
     * @param  int    $id The ID of the withdraw method to edit.
     * @return string The rendered edit form view.
     *
     * @throws Throwable
     */
    public function edit(int $id)
    {
        // Retrieve the withdraw method with its associated payment gateway.
        $paymentMethod = WithdrawMethod::with('paymentGateway')->find($id);

        // Get all available payment gateways.
        $paymentGateways = PaymentGateway::active()->withdrawAvailable()->get();

        // The "Mass payout" select: the drivers this type can carry, each
        // with the reasons this method's shape cannot take it, and why the
        // saved driver is not offered yet, if it is not.
        $massPayoutMethods = app(MassPayoutMethods::class);
        $massPayoutOptions = collect($massPayoutMethods->driverOptions($paymentMethod->type))
            ->map(fn (array $option, string $driver): array => $option + [
                'compatibility' => $massPayoutMethods->compatibilityIssues(
                    $driver,
                    $paymentMethod->type,
                    $paymentMethod->paymentGateway?->code,
                    $paymentMethod->fields,
                ),
            ])
            ->all();
        $massPayoutIssues = filled($paymentMethod->mass_payout_driver)
            ? $massPayoutMethods->issuesFor($paymentMethod)
            : [];

        // Render the edit form view with the retrieved data.
        return view('backend.withdraw.method.partials._edit_payment_method_form_data', compact('paymentMethod', 'paymentGateways', 'massPayoutOptions', 'massPayoutIssues'))->render();
    }

    /**
     * Updates a withdraw method.
     *
     * @param  int              $id
     * @return RedirectResponse
     */
    public function update(StoreUpdateRequest $request, $id)
    {

        // Retrieve the withdraw method by its ID
        $paymentMethod = WithdrawMethod::find($id);

        // Prepare the data for updating the withdraw method
        $data = $this->prepareData($request, $paymentMethod);

        // Update the withdraw method with the prepared data
        $paymentMethod->update($data);

        // Notify the user of a successful update
        notifyEvs('success', __('Withdraw Method Updated Successfully'));

        // Redirect the user back to the withdrawal method index page
        return redirect()->route('admin.withdraw.method.index', ['type' => $request->input('type')]);
    }
}
