<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Stored media references vs. the public disk
|--------------------------------------------------------------------------
|
| Content rows hold disk-relative paths ("images/2025/04/10/hero.png") while
| the public disk is served from /storage. Passing the raw value to asset()
| produced /images/..., which 404s. These tests pin the resolver that sits
| between the two, including the cases it must leave alone.
|
*/

use App\Support\PublicMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    PublicMedia::flush();
    Storage::fake('public');
});

it('prefixes storage/ for a file that lives on the public disk', function (): void {
    Storage::disk('public')->put('images/2025/04/10/hero.png', 'binary');

    expect(PublicMedia::normalizePath('images/2025/04/10/hero.png'))
        ->toBe('storage/images/2025/04/10/hero.png');
});

it('leaves a file that is already served from public/ alone', function (): void {
    // Exists in public/general/static, so its URL is correct as-is.
    expect(PublicMedia::normalizePath('general/static/default/placeholder.png'))
        ->toBe('general/static/default/placeholder.png');
});

it('leaves absolute urls alone', function (): void {
    expect(PublicMedia::normalizePath('https://cdn.example.com/a/b/hero.png'))
        ->toBe('https://cdn.example.com/a/b/hero.png');
});

it('leaves an already prefixed storage path alone', function (): void {
    expect(PublicMedia::normalizePath('storage/images/2025/04/10/hero.png'))
        ->toBe('storage/images/2025/04/10/hero.png');
});

it('leaves non-media values alone', function (string $value): void {
    expect(PublicMedia::normalizePath($value))->toBe($value);
})->with([
    'heading'      => 'Manage Your Money More Quickly',
    'route'        => '/user/wallet/list',
    'anchor'       => 'contact',
    'plain word'   => 'subscriber',
    'empty string' => '',
]);

it('walks nested json structures', function (): void {
    Storage::disk('public')->put('images/2025/04/10/hero.png', 'binary');

    $resolved = PublicMedia::normalize([
        'heading'            => ['en' => 'Hello'],
        'button_url'         => '/register',
        'hero_main_image'    => 'images/2025/04/10/hero.png',
        'nested'             => [
            'deeper' => ['image' => 'images/2025/04/10/hero.png'],
            'kept'   => '/static/thing',
        ],
    ]);

    expect($resolved['hero_main_image'])->toBe('storage/images/2025/04/10/hero.png')
        ->and($resolved['nested']['deeper']['image'])->toBe('storage/images/2025/04/10/hero.png')
        ->and($resolved['heading']['en'])->toBe('Hello')
        ->and($resolved['button_url'])->toBe('/register')
        ->and($resolved['nested']['kept'])->toBe('/static/thing');
});

it('degrades an unresolvable reference to the placeholder and warns once', function (): void {
    Log::spy();

    expect(PublicMedia::normalizePath('images/2099/01/01/never_uploaded.png'))
        ->toBe(PublicMedia::PLACEHOLDER);

    // Second call is served from the per-request cache, so still one warning.
    PublicMedia::normalizePath('images/2099/01/01/never_uploaded.png');

    Log::shouldHaveReceived('warning')->once();
});

it('reports whether a reference resolves', function (): void {
    Storage::disk('public')->put('images/a.png', 'binary');

    expect(PublicMedia::exists('images/a.png'))->toBeTrue()
        ->and(PublicMedia::exists('storage/images/a.png'))->toBeTrue()
        ->and(PublicMedia::exists('general/static/default/placeholder.png'))->toBeTrue()
        ->and(PublicMedia::exists('images/absent.png'))->toBeFalse();
});
