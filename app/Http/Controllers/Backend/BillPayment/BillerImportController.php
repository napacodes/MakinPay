<?php

declare(strict_types=1);

namespace App\Http\Controllers\Backend\BillPayment;

use App\Http\Controllers\Backend\BaseController;
use App\Http\Requests\Backend\BillPayment\ImportBillersRequest;
use App\Models\BillCategory;
use App\Models\BillPaymentProvider;
use App\Services\BillPayment\BillerCsvImporter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BP5: billers imported from a spreadsheet, in three steps the admin sees
 * one at a time - the file and the provider, every row's verdict, then the
 * import of a file with no refused row.
 *
 * Every step needs bill-payment-manage. The reviewed file waits in the cache
 * under a token only its uploader can open, and is reviewed again before
 * anything is written. What a row may hold is decided by BillerCsvImporter,
 * with the biller form's own rules.
 */
class BillerImportController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'create|template|preview|show|store' => 'bill-payment-manage',
        ];
    }

    public function __construct(private readonly BillerCsvImporter $importer) {}

    public function create(): View
    {
        return view('backend.bill_payment.billers.import', [
            'providers'  => $this->importableProviders(),
            'categories' => BillCategory::query()->orderBy('sort_order')->orderBy('name')->get(['code', 'name', 'status']),
            'columns'    => BillerCsvImporter::TEMPLATE_HEADER,
            'maxRows'    => BillerCsvImporter::MAX_ROWS,
        ]);
    }

    public function template(): StreamedResponse
    {
        return $this->importer->template();
    }

    /**
     * Read the file and keep it for review. A file that cannot be read at
     * all (no header, too many rows) is refused on the upload form.
     */
    public function preview(ImportBillersRequest $request): RedirectResponse
    {
        $provider = $request->provider();
        $file     = $request->file('file');
        $rows     = $this->importer->parse($file);

        $token = $this->importer->remember($rows, $provider, (int) $request->user('admin')->id, (string) $file->getClientOriginalName());

        return redirect()->route('admin.bill-payment.billers.import.show', $token);
    }

    public function show(Request $request, string $token): View
    {
        [$kept, $provider] = $this->kept($request, $token);

        try {
            $review = $this->importer->review($kept['rows'], $provider);
        } catch (ValidationException $exception) {
            throw $exception->redirectTo(route('admin.bill-payment.billers.import'));
        }

        return view('backend.bill_payment.billers.import_preview', [
            'token'    => $token,
            'provider' => $provider,
            'filename' => $kept['filename'],
            'review'   => $review,
            'refused'  => count(array_filter($review, static fn (array $verdict): bool => $verdict['errors'] !== [])),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        [$kept, $provider] = $this->kept($request, $token);

        try {
            $imported = $this->importer->import($kept['rows'], $provider);
        } catch (ValidationException $exception) {
            // Reviewed again just now: a category may have gone since the preview.
            throw $exception->redirectTo(route('admin.bill-payment.billers.import.show', $token));
        }

        $this->importer->forget((int) $request->user('admin')->id, $token);

        notifyEvs('success', trans_choice(
            '{1} :count biller imported under :provider. It is switched off until you enable it.|[2,*] :count billers imported under :provider. They are switched off until you enable them.',
            $imported,
            ['count' => $imported, 'provider' => $provider->name],
        ));

        return redirect()->route('admin.bill-payment.billers.index', ['provider' => $provider->id, 'status' => 'disabled']);
    }

    /**
     * The reviewed file behind a token, for the admin who uploaded it; a
     * token that is not theirs, or has expired, is not found.
     *
     * @return array{0: array{rows: array<int, array<string, string>>, provider_id: int, filename: string}, 1: BillPaymentProvider}
     */
    private function kept(Request $request, string $token): array
    {
        $kept = $this->importer->recall((int) $request->user('admin')->id, $token);

        abort_if($kept === null, 404);

        $provider = BillPaymentProvider::query()->find($kept['provider_id']);

        abort_if($provider === null, 404);

        return [$kept, $provider];
    }

    /**
     * Providers that pay by hand, whose billers an admin writes: the ones a
     * file may import under.
     *
     * @return Collection<int, BillPaymentProvider>
     */
    private function importableProviders()
    {
        return BillPaymentProvider::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->reject(static fn (BillPaymentProvider $provider): bool => $provider->billersComeFromSync())
            ->values();
    }
}
