<?php

namespace App\Http\Controllers\Backend;

use App\Enums\KycStatus;
use App\Exceptions\NotifyErrorException;
use App\Models\KycTemplate;
use App\Services\Kyc\KycAutomationSettings;
use App\Services\Media\MediaSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class KycTemplateController extends BaseController
{
    public static function permissions(): array
    {
        return [
            'index'                            => 'kyc-template-list',
            'store|edit|update|destroy|toggle' => 'kyc-template-manage',
        ];
    }

    /**
     * Display a listing of the KYC templates.
     */
    public function index()
    {
        /*
         * The counts come from the query, not from a per-row lookup in the
         * view: the list shows how much traffic each template actually
         * carries, and that is the whole reason to keep a paused one around.
         */
        $templates = KycTemplate::query()
            ->withCount([
                'submissions',
                'submissions as approved_count' => fn ($query) => $query->where('status', KycStatus::APPROVED),
                'submissions as rejected_count' => fn ($query) => $query->where('status', KycStatus::REJECTED),
                'submissions as pending_count'  => fn ($query) => $query->where('status', KycStatus::PENDING),
            ])
            ->with('updatedBy:id,name')
            ->latest()
            ->get();

        return view('backend.kyc.template.index', array_merge(
            compact('templates'),
            self::previewContext()
        ));
    }

    /**
     * Store a newly created KYC template in storage.
     *
     * @throws NotifyErrorException|Throwable
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'         => ['required', 'string', 'max:255', 'unique:kyc_templates,title'],
            'description'   => 'nullable|string|max:1000',
            'fields'        => 'required|array',
            'applicable_to' => 'required|array',
            'routing'       => ['nullable', Rule::in(['auto', 'manual'])],
            'status'        => 'required|boolean',
        ]);

        $validated['routing']    = $validated['routing'] ?? 'auto';
        $validated['updated_by'] = auth('admin')->id();

        DB::beginTransaction();
        try {
            KycTemplate::create($validated);
            DB::commit();
            notifyEvs('success', __('KYC Template created successfully!'));

            return redirect()->route('admin.kyc.template.index');
        } catch (Throwable $th) {
            DB::rollBack();
            throw new NotifyErrorException(__('Failed to process KYC Template: ').$th->getMessage());
        }
    }

    /**
     * Show the form for editing the specified KYC template.
     */
    public function edit(KycTemplate $template)
    {
        // The builder footer states how many submissions already use this.
        $template->loadCount('submissions');

        return view('backend.kyc.template.edit', array_merge(
            compact('template'),
            self::previewContext()
        ));
    }

    /**
     * Update the specified KYC template in storage.
     *
     * @throws NotifyErrorException|Throwable
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('kyc_templates', 'title')->ignore($id),
            ],
            'description'   => 'nullable|string|max:1000',
            'fields'        => 'required|array',
            'applicable_to' => 'required|array',
            'routing'       => ['nullable', Rule::in(['auto', 'manual'])],
            'status'        => 'required|boolean',
        ]);

        $validated['routing']    = $validated['routing'] ?? 'auto';
        $validated['updated_by'] = auth('admin')->id();

        DB::beginTransaction();
        try {
            $kycTemplate = KycTemplate::findOrFail($id);
            // Ensure you’re updating the existing instance
            $kycTemplate->update($validated);
            DB::commit();
            notifyEvs('success', __('KYC Template updated successfully!'));

            return redirect()->route('admin.kyc.template.index');
        } catch (Throwable $th) {
            DB::rollBack();
            throw new NotifyErrorException(__('Failed to process KYC Template: ').$th->getMessage());
        }
    }

    /**
     * Remove the specified KYC template from storage.
     *
     * @throws NotifyErrorException|Throwable
     */
    public function destroy(KycTemplate $template)
    {
        /*
         * A template with history is not deletable, it is pausable.
         *
         * kyc_template_id has carried onDelete('cascade') since the table was
         * created, and this method asked nothing before deleting — so removing
         * a form an admin believed was unused destroyed every verification
         * taken on it: approved identities, their review logs, the consent
         * records and the document digests, all of it, with a success toast on
         * the way out.
         *
         * A verification record has to outlive the form it was collected on,
         * for the same reason the review log is append-only. So the template is
         * what gives way. The database refuses this now too, but it refuses
         * with an SQL error; this refuses with the sentence that says what to
         * do instead.
         */
        $taken = $template->submissions()->count();

        if ($taken > 0) {
            throw new NotifyErrorException(__(
                'This template has :count verification(s) on record, so it cannot be deleted — they would go with it. Pause it instead: a paused template leaves the applicant form and keeps its history.',
                ['count' => $taken]
            ));
        }

        DB::beginTransaction();
        try {
            $template->delete();
            DB::commit();

            notifyEvs('success', __('KYC Template deleted successfully!'));

            return redirect()->route('admin.kyc.template.index');
        } catch (Throwable $th) {
            DB::rollBack();
            throw new NotifyErrorException(__('Failed to delete KYC Template: ').$th->getMessage());
        }
    }

    /**
     * Turn a template on or off from the list.
     *
     * Availability to applicants is the one thing about a template an admin
     * changes without wanting to open it, and a paused template keeps every
     * submission already made against it.
     */
    public function toggle(KycTemplate $template): RedirectResponse
    {
        $template->update([
            'status'     => ! $template->status,
            'updated_by' => auth('admin')->id(),
        ]);

        notifyEvs('success', $template->status
            ? __('":title" is live and offered to applicants.', ['title' => $template->title])
            : __('":title" is paused. Applicants can no longer choose it.', ['title' => $template->title]));

        return redirect()->back();
    }

    /**
     * What the builder's applicant preview needs to tell the truth.
     *
     * The consent line and the upload hint are both administrator settings,
     * so the preview showed neither and an admin composing a form could not
     * see the footer their applicants would. The values come from the same
     * places the real form and its validator read.
     *
     * @return array{requiresConsent: bool, consentText: string, uploadTypes: string, uploadMaxMb: int|float}
     */
    private static function previewContext(): array
    {
        return [
            'requiresConsent' => KycAutomationSettings::requiresConsent(),
            'consentText'     => KycAutomationSettings::consentText(),
            'uploadTypes'     => strtoupper(implode(', ', MediaSettings::identityDocumentExtensions())),
            'uploadMaxMb'     => MediaSettings::effectiveMaxUploadSizeMb(),
        ];
    }
}
