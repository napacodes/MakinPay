<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Homepage media renders real files
|--------------------------------------------------------------------------
|
| The homepage 404'd 40 images because content rows hold disk-relative paths
| and the section views handed them straight to asset(). These tests assert
| the rendered page points at /storage/..., that the database still holds the
| original references, and that the audit command can see a missing file.
|
| @group serial
|
*/

use App\Support\PublicMedia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    PublicMedia::flush();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $this->artisan('makinpay:install', [
        '--force'          => true,
        '--no-interaction' => true,
        '--seed-baseline'  => true,
        '--allow-existing' => true,
    ])->assertSuccessful();

    // The test bootstrap redirects storage_path() to a temp tree, so the
    // seeded references have no files behind them until we put them there.
    materialiseSeededMedia();
})->group('serial');

/**
 * Put a file at every media reference the page builder stores, mirroring a
 * real install where the uploads are present on the public disk.
 */
function materialiseSeededMedia(): void
{
    foreach (storedMediaReferences() as $path) {
        Storage::disk('public')->put($path, 'binary');
    }

    PublicMedia::flush();
}

/**
 * @return list<string>
 */
function storedMediaReferences(): array
{
    $paths = [];

    $rows = DB::table('page_components')->pluck('content_data')
        ->merge(DB::table('page_component_repeated_contents')->pluck('content_data'));

    foreach ($rows as $row) {
        $decoded = json_decode((string) $row, true);

        if (! is_array($decoded)) {
            continue;
        }

        array_walk_recursive($decoded, function ($item) use (&$paths): void {
            if (is_string($item) && preg_match('#\.(png|jpe?g|gif|webp|svg)$#i', $item)) {
                $paths[ltrim($item, '/')] = true;
            }
        });
    }

    return array_keys($paths);
}

/**
 * Every path component of the absolute URLs the page emits.
 *
 * @return list<string>
 */
function imagePathsIn(string $html): array
{
    preg_match_all('#https?://[^"\'\s]+#', $html, $matches);

    $paths = [];

    foreach ($matches[0] as $url) {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($path !== '' && preg_match('#\.(png|jpe?g|gif|webp|svg)$#i', $path)) {
            $paths[] = $path;
        }
    }

    return array_values(array_unique($paths));
}

it('renders content images from the public disk instead of the web root', function (): void {
    $html = $this->get('/')->assertOk()->getContent();

    $paths = imagePathsIn($html);

    expect($paths)->not->toBeEmpty();

    $broken = array_values(array_filter($paths, fn (string $path): bool => str_starts_with($path, '/images/')));

    expect($broken)->toBe([]);

    $served = array_values(array_filter($paths, fn (string $path): bool => str_starts_with($path, '/storage/images/')));

    expect($served)->not->toBeEmpty();
});

it('never writes the resolved path back to the database', function (): void {
    $before = DB::table('page_components')->orderBy('id')->pluck('content_data', 'id')->all();
    $beforeRepeated = DB::table('page_component_repeated_contents')->orderBy('id')->pluck('content_data', 'id')->all();

    $this->get('/')->assertOk();

    expect(DB::table('page_components')->orderBy('id')->pluck('content_data', 'id')->all())->toBe($before)
        ->and(DB::table('page_component_repeated_contents')->orderBy('id')->pluck('content_data', 'id')->all())->toBe($beforeRepeated);

    foreach ($before as $content) {
        expect((string) $content)->not->toContain('"storage/');
    }
});

it('leaves the component model clean after preparing it for display', function (): void {
    $component = \App\Models\PageComponent::where('component_key', 'banner')->firstOrFail();

    $component->prepareForDisplay();

    expect($component->isDirty())->toBeFalse()
        ->and($component->content_data['hero_main_image'])->toStartWith('storage/images/');
});

it('audits stored references and reports the ones with no file', function (): void {
    $this->artisan('makinpay:media:audit')->assertSuccessful();

    $component = \App\Models\PageComponent::where('component_key', 'banner')->firstOrFail();
    $original  = $component->content_data;

    $component->content_data = array_merge($original, ['hero_main_image' => 'images/2099/01/01/never_uploaded.png']);
    $component->save();
    PublicMedia::flush();

    $this->artisan('makinpay:media:audit')
        ->expectsOutputToContain('never_uploaded.png')
        ->assertExitCode(1);

    // Put the row back so the fixture stays honest for any later test.
    $component->content_data = $original;
    $component->save();
    PublicMedia::flush();

    $this->artisan('makinpay:media:audit')->assertSuccessful();
});
