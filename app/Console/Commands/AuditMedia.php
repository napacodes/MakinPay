<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\PublicMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Report stored media references that have no file behind them.
 *
 * A missing image is invisible in code review and in tests: the row still holds
 * a path, the page still renders, and only the browser's network tab shows the
 * 404. This walks every column that carries a media reference and checks each
 * one against public/ and the public disk.
 *
 * Read-only. It never rewrites a reference, because a path that resolves
 * nowhere may be an asset that simply has not been copied onto this machine
 * yet — deleting the reference would destroy the only record of it.
 */
class AuditMedia extends Command
{
    protected $signature = 'makinpay:media:audit
                            {--json : Machine-readable output.}';

    protected $description = 'Report stored image references whose file is missing from public/ and the public disk';

    /**
     * Table => columns that hold stored media references. JSON columns are
     * walked recursively; everything else is read as a single value.
     *
     * @var array<string, list<string>>
     */
    private const SOURCES = [
        'page_components'                   => ['content_data'],
        'page_component_repeated_contents'  => ['content_data'],
        'pages'                             => ['breadcrumb'],
        'blogs'                             => ['thumbnail'],
        'payment_gateways'                  => ['logo'],
        'wallet_earn_plans'                 => ['icon'],
        'settings'                          => ['val'],
    ];

    public function handle(): int
    {
        PublicMedia::flush();

        $checked = [];
        $missing = [];

        foreach (self::SOURCES as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                DB::table($table)
                    ->select([$column])
                    ->orderBy($column)
                    ->chunk(200, function ($rows) use ($table, $column, &$checked, &$missing): void {
                        foreach ($rows as $row) {
                            foreach ($this->referencesIn($row->{$column}) as $path) {
                                $checked[$path] = true;

                                if (! PublicMedia::exists($path)) {
                                    $missing[$path][] = $table.'.'.$column;
                                }
                            }
                        }
                    });
            }
        }

        ksort($missing);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'checked' => count($checked),
                'missing' => array_map(
                    fn (string $path, array $sources): array => ['path' => $path, 'referenced_by' => array_values(array_unique($sources))],
                    array_keys($missing),
                    $missing
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $missing === [] ? self::SUCCESS : self::FAILURE;
        }

        $this->info(sprintf('Checked %d distinct media reference(s).', count($checked)));

        if ($missing === []) {
            $this->info('Every referenced image resolves to a file.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn(sprintf('%d reference(s) have no file:', count($missing)));

        $this->table(
            ['Stored path', 'Referenced by'],
            array_map(
                fn (string $path, array $sources): array => [$path, implode(', ', array_unique($sources))],
                array_keys($missing),
                $missing
            )
        );

        $this->newLine();
        $this->line('These rows were left untouched. Restore the file to public/ or the public disk,');
        $this->line('or correct the reference deliberately once the original asset is located.');

        return self::FAILURE;
    }

    /**
     * Extract media-looking references from a stored value, which may be a
     * plain path or a JSON document with paths nested anywhere inside it.
     *
     * @return list<string>
     */
    private function referencesIn(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            return $this->referencesInStructure($decoded);
        }

        return $this->looksLikeMedia($value) ? [ltrim(trim($value), '/')] : [];
    }

    /**
     * @param  array<mixed> $structure
     * @return list<string>
     */
    private function referencesInStructure(array $structure): array
    {
        $paths = [];

        array_walk_recursive($structure, function ($item) use (&$paths): void {
            if (is_string($item) && $this->looksLikeMedia($item)) {
                $paths[] = ltrim(trim($item), '/');
            }
        });

        return $paths;
    }

    private function looksLikeMedia(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $extension = strtolower(pathinfo(parse_url($value, PHP_URL_PATH) ?: $value, PATHINFO_EXTENSION));

        return in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'bmp', 'avif'], true);
    }
}
