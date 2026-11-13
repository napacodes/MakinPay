<?php

namespace App\Http\Controllers\Backend;

use App\Http\Requests\Backend\Media\StoreMediaAssetRequest;
use App\Http\Requests\Backend\Media\UpdateMediaAssetRequest;
use App\Http\Resources\Backend\MediaAssetResource;
use App\Models\MediaAsset;
use App\Models\MediaStorageProvider;
use App\Services\Handlers\MediaAssetHandler;
use App\Services\Media\MediaSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MediaAssetController extends BaseController
{
    /** @var list<string> */
    private const SORTS = ['newest', 'oldest', 'largest', 'name'];

    /** @var list<string> */
    private const AGGREGATE_TYPES = ['image', 'document', 'video', 'audio', 'archive', 'other'];

    public static function permissions(): array
    {
        return [
            'index'   => 'media-list',
            'store'   => 'media-upload',
            'update'  => 'media-edit',
            'destroy' => 'media-delete',
        ];
    }

    public function index(Request $request, MediaAssetHandler $handler): View
    {
        $activeProvider = $handler->activeProvider();

        if (! $request->hasAny(['search', 'type', 'provider', 'folder', 'page', 'tab', 'sort', 'view'])) {
            $handler->syncLocalPublicUploads(auth('admin')->user());
        }

        $providers = MediaStorageProvider::query()
            ->withCount('assets')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        $folder = $request->filled('folder') ? (string) $request->input('folder') : null;
        $type   = in_array($request->input('type'), self::AGGREGATE_TYPES, true) ? (string) $request->input('type') : null;
        $sort   = in_array($request->input('sort'), self::SORTS, true) ? (string) $request->input('sort') : 'newest';

        $assets = $this->applySort($this->filtered($request, $folder, $type), $sort)
            ->with(['storageProvider', 'uploader'])
            /*
             * Twelve, not twenty-four. Four rows of cards sit level with the
             * folder rail beside them; twice that turned the grid into a
             * column that towered over both side panes and made the screen
             * read as three unrelated pieces.
             */
            ->paginate(12)
            ->withQueryString();

        return view('backend.media.index', [
            'assets'         => $assets,
            'activeProvider' => $activeProvider,
            'providers'      => $providers,
            'metrics'        => $this->metrics(),
            'folders'        => $this->folderCounts(),
            'typeCounts'     => $this->typeCounts($request, $folder),
            'selectedFolder' => $folder,
            'selectedType'   => $type,
            'sort'           => $sort,
            'view'           => $this->rememberedView($request),
            'uploadLimitMb'  => MediaSettings::effectiveMaxUploadSizeMb(),
        ]);
    }

    public function store(StoreMediaAssetRequest $request, MediaAssetHandler $handler): RedirectResponse|JsonResponse
    {
        $assets = $handler->uploadMany($request->validated(), auth('admin')->user());

        if ($request->expectsJson()) {
            return response()->json([
                'assets'  => MediaAssetResource::collection(collect($assets))->resolve($request),
                'message' => $this->uploadedMessage(count($assets)),
            ], 201);
        }

        notifyEvs('success', $this->uploadedMessage(count($assets)));

        return redirect()->route('admin.media.index');
    }

    public function update(UpdateMediaAssetRequest $request, MediaAsset $mediaAsset, MediaAssetHandler $handler): RedirectResponse|JsonResponse
    {
        $handler->updateAsset($mediaAsset, $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'asset'   => (new MediaAssetResource($mediaAsset->load(['storageProvider', 'uploader'])))->resolve($request),
                'message' => __('Media details updated successfully.'),
            ]);
        }

        notifyEvs('success', __('Media details updated successfully.'));

        return redirect()->back();
    }

    public function destroy(Request $request, MediaAsset $mediaAsset, MediaAssetHandler $handler): RedirectResponse|JsonResponse
    {
        $handler->deleteAsset($mediaAsset);

        if ($request->expectsJson()) {
            return response()->json(['message' => __('Media file deleted successfully.')]);
        }

        notifyEvs('success', __('Media file deleted successfully.'));

        return redirect()->back();
    }

    /**
     * The filters every count on the screen has to agree with.
     *
     * The type chips carry their own counts, so they are filtered by
     * everything except the type itself — a chip that reports 6 and then
     * shows nothing is worse than no count at all.
     */
    private function filtered(Request $request, ?string $folder, ?string $type): Builder
    {
        return MediaAsset::query()
            ->when($request->filled('search'), function (Builder $query) use ($request): void {
                $search = (string) $request->input('search');

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('original_name', 'like', "%{$search}%")
                        ->orWhere('file_name', 'like', "%{$search}%")
                        ->orWhere('alt_text', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('provider'), fn (Builder $query): Builder => $query->where('media_storage_provider_id', $request->integer('provider')))
            ->when($folder !== null, fn (Builder $query): Builder => $query->where('folder', $folder))
            ->when($type !== null, fn (Builder $query): Builder => $query->where('aggregate_type', $type));
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'oldest'  => $query->oldest(),
            'largest' => $query->orderByDesc('size'),
            'name'    => $query->orderBy('original_name'),
            default   => $query->latest(),
        };
    }

    /**
     * Folder rows and their totals. A folder counts everything it holds
     * regardless of the type chip, because the rail is how a type chip that
     * emptied the grid gets escaped.
     *
     * @return array<string, int>
     */
    private function folderCounts(): array
    {
        return MediaAsset::query()
            ->whereNotNull('folder')
            ->where('folder', '!=', '')
            ->selectRaw('folder, COUNT(*) as assets_count')
            ->groupBy('folder')
            ->orderBy('folder')
            ->pluck('assets_count', 'folder')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function typeCounts(Request $request, ?string $folder): array
    {
        $counts = $this->filtered($request, $folder, null)
            ->selectRaw('aggregate_type, COUNT(*) as assets_count')
            ->groupBy('aggregate_type')
            ->pluck('assets_count', 'aggregate_type')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $counts['all'] = array_sum($counts);

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function metrics(): array
    {
        $byType = MediaAsset::query()
            ->selectRaw('aggregate_type, COUNT(*) as assets_count, COALESCE(SUM(size), 0) as total_size')
            ->groupBy('aggregate_type')
            ->get()
            ->keyBy('aggregate_type');

        return [
            'total'         => (int) $byType->sum('assets_count'),
            'images'        => (int) ($byType['image']->assets_count ?? 0),
            'imageBytes'    => (int) ($byType['image']->total_size ?? 0),
            'documents'     => (int) ($byType['document']->assets_count ?? 0),
            'documentBytes' => (int) ($byType['document']->total_size ?? 0),
            'providers'     => MediaStorageProvider::query()->count(),
            'addedThisWeek' => MediaAsset::query()->where('created_at', '>=', now()->subWeek())->count(),
        ];
    }

    /**
     * Grid or list, remembered per admin.
     *
     * A `?view=` on the URL sets it; without one the session answers, so
     * paging or filtering keeps the layout the admin chose instead of
     * snapping back to grid on the second page.
     */
    private function rememberedView(Request $request): string
    {
        $requested = $request->input('view');

        if (in_array($requested, ['grid', 'list'], true)) {
            session(['media_library_view' => $requested]);

            return $requested;
        }

        $stored = session('media_library_view');

        return in_array($stored, ['grid', 'list'], true) ? $stored : 'grid';
    }

    private function uploadedMessage(int $count): string
    {
        return trans_choice('{1} Media file uploaded successfully.|[2,*] :count media files uploaded successfully.', $count, [
            'count' => $count,
        ]);
    }
}
