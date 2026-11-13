<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Support\AdminShell;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\View\Component;
use Throwable;

/**
 * The one header every admin page starts with.
 *
 * Breadcrumb (with the return link at its end), then the identity row (icon
 * tile or avatar, eyebrow, title, ID chip, one status pill, subtitle, meta),
 * then the controls (state, search, secondary, primary, the ⋯ menu), then an
 * optional stats strip. Section tabs, filters and toolbars are page content
 * and render below it; the component has no prop for them on purpose.
 *
 * Every prop is optional except the title, and the layout does not break
 * when a slot is empty — that is the whole reason it replaced the per-page
 * heroes, each of which broke differently when a value was missing.
 */
final class PageHeader extends Component
{
    /** @var list<string> */
    public const TONES = ['success', 'warning', 'danger', 'neutral', 'brand'];

    /** @var list<string> */
    public const DOT_TONES = ['success', 'warning', 'danger', 'neutral', 'primary', 'brand'];

    public string $variant;

    /**
     * brand = filled indigo tile, soft = tinted tile with a ring.
     *
     * v2 makes brand the default: a module page that wants the quieter one
     * asks for it, which is the way round that keeps list and detail pages
     * consistent without every caller saying so.
     *
     * v2.2 adds three filled hues for a module whose pages sit side by side
     * in one operator's day. They are the same tile in a different colour,
     * never a different anatomy, and anything unrecognised still falls back
     * to brand rather than rendering an unstyled square.
     */
    public string $tile;

    /** @var list<string> */
    public const TILES = ['brand', 'soft', 'teal', 'violet', 'sky'];

    /** One of icon | fa | avatar | initials, or null when there is no tile. */
    public ?string $tileKind = null;

    public ?string $tileIcon = null;

    public ?string $tileFontAwesome = null;

    public ?string $avatarUrl = null;

    public ?string $initials = null;

    /** @var array{tone: string, label: string, dot: bool}|null */
    public ?array $status;

    /** @var list<array{label: string, url: string|null}> */
    public array $breadcrumbs;

    /** @var list<array{label: string, url: string|null}|Htmlable> */
    public array $meta;

    /** @var list<array{label: string, value: string, delta: string|null, tone: string, note: string|null, dot: string|null, id: string|null, spark: list<int>|null, icon: string|null, iconKind: string|null, url: string|null}> */
    public array $stats;

    /** @var array{label: string, value: string, total: string, percent: int, note: string|null, attrs: array{ratio: array<string, scalar>, fill: array<string, scalar>, note: array<string, scalar>}}|null */
    public ?array $progress;

    /**
     * @param mixed                                                                                                                                                                          $avatar      A URL, a path under public/, or a model exposing `avatar_url` / `avatar` / `image`.
     * @param array{tone?: string, label: string, dot?: bool}|string|null                                                                                                                    $status
     * @param list<string|array{label: string, url?: string|null}|Htmlable>                                                                                                                  $meta
     * @param list<array{label: string, url?: string|null}>|null                                                                                                                             $breadcrumbs Defaults to the menu-derived trail.
     * @param list<array{label: string, value: mixed, delta?: string|null, tone?: string, note?: string|null, dot?: string|bool, icon?: string, spark?: list<int|float>, url?: string|null}> $stats
     * @param array{label: string, value: int|float, total: int|float, note?: string}|null                                                                                                   $progress
     */
    public function __construct(
        public string $title,
        public ?string $subtitle = null,
        ?string $icon = null,
        mixed $avatar = null,
        ?string $initials = null,
        public ?string $eyebrow = null,
        public ?string $back = null,
        public ?string $backLabel = null,
        string $variant = 'card',
        string $tile = 'brand',
        array|string|null $status = null,
        public ?string $badge = null,
        array $meta = [],
        ?array $breadcrumbs = null,
        array $stats = [],
        ?array $progress = null,
    ) {
        $this->variant     = $variant === 'compact' ? 'compact' : 'card';
        $this->tile        = in_array($tile, self::TILES, true) ? $tile : 'brand';
        $this->status      = self::normaliseStatus($status);
        $this->meta        = self::normaliseMeta($meta);
        $this->breadcrumbs = self::normaliseBreadcrumbs($breadcrumbs ?? AdminShell::breadcrumb());
        $this->stats       = array_values(array_map(self::normaliseStat(...), array_values($stats)));
        $this->progress    = self::normaliseProgress($progress);
        $this->backLabel   = $backLabel ?? self::defaultBackLabel($this->breadcrumbs);

        $this->resolveTile($icon, $avatar, $initials);
    }

    public function render(): View
    {
        return view('components.page-header');
    }

    public function hasStrip(): bool
    {
        return $this->stats !== [] || $this->progress !== null;
    }

    /**
     * How many cells the strip lays out, progress cell included.
     */
    public function stripColumns(): int
    {
        return count($this->stats) + ($this->progress === null ? 0 : 1);
    }

    /**
     * The strip's width budget, in stat-cell widths.
     *
     * v2.1 lays the strip out as a wrapping flex row rather than a grid, so
     * a cell sizes itself from a share of the strip instead of from a track
     * the grid reserved — that is what lets four cells drop to two-up and
     * then one-up without a media query per column count. The progress cell
     * is worth 1.5 stat cells, so the denominator is fractional and the
     * view hands it to the sheet as a custom property.
     */
    public function stripUnits(): string
    {
        $units = count($this->stats) + ($this->progress === null ? 0 : 1.5);

        return rtrim(rtrim(number_format(max(1, $units), 1, '.', ''), '0'), '.');
    }

    /**
     * Digits in the reader's locale, so an Arabic panel counts in Arabic.
     *
     * Strings pass through untouched: pages that already formatted a value
     * (a currency amount, a percentage) keep what they wrote.
     */
    public static function formatNumber(mixed $value): string
    {
        if (! is_int($value) && ! is_float($value)) {
            return (string) $value;
        }

        $decimals = is_float($value) && floor($value) !== $value ? 2 : 0;

        if (extension_loaded('intl')) {
            try {
                $formatted = Number::format($value, $decimals, null, app()->getLocale());

                if (is_string($formatted)) {
                    return $formatted;
                }
            } catch (Throwable) {
                /* An unknown locale is not worth a 500 on every admin page. */
            }
        }

        return number_format($value, $decimals);
    }

    private function resolveTile(?string $icon, mixed $avatar, ?string $initials): void
    {
        $url = self::avatarUrl($avatar);

        if ($url !== null) {
            $this->tileKind  = 'avatar';
            $this->avatarUrl = $url;

            return;
        }

        $initials = trim((string) $initials);

        if ($initials === '' && $avatar !== null) {
            $name     = data_get($avatar, 'name');
            $initials = self::initialsFor(is_string($name) && $name !== '' ? $name : $this->title);
        }

        if ($initials !== '') {
            $this->tileKind = 'initials';
            $this->initials = mb_strtoupper(mb_substr($initials, 0, 2));

            return;
        }

        $icon = trim((string) $icon);

        if ($icon === '') {
            return;
        }

        /* A FontAwesome class from a page that has not moved to <x-icon> yet. */
        if (str_starts_with($icon, 'fa-')) {
            $this->tileKind        = 'fa';
            $this->tileFontAwesome = $icon;

            return;
        }

        $this->tileKind = 'icon';
        $this->tileIcon = $icon;
    }

    private static function avatarUrl(mixed $avatar): ?string
    {
        if (is_string($avatar)) {
            $avatar = trim($avatar);

            if ($avatar === '') {
                return null;
            }

            if (preg_match('#^(https?:)?//#i', $avatar) === 1 || str_starts_with($avatar, '/') || str_starts_with($avatar, 'data:')) {
                return $avatar;
            }

            return asset($avatar);
        }

        if (is_object($avatar) || is_array($avatar)) {
            foreach (['avatar_url', 'avatar', 'image'] as $attribute) {
                $value = data_get($avatar, $attribute);

                if (is_string($value) && trim($value) !== '') {
                    return self::avatarUrl($value);
                }
            }
        }

        return null;
    }

    private static function initialsFor(string $name): string
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: []));

        return implode('', array_map(
            static fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($words, 0, 2)
        ));
    }

    /**
     * @param  array{tone?: string, label: string, dot?: bool}|string|null $status
     * @return array{tone: string, label: string, dot: bool}|null
     */
    private static function normaliseStatus(array|string|null $status): ?array
    {
        if ($status === null) {
            return null;
        }

        if (is_string($status)) {
            $status = ['label' => $status];
        }

        $label = trim((string) ($status['label'] ?? ''));

        if ($label === '') {
            return null;
        }

        $tone = in_array($status['tone'] ?? null, self::TONES, true) ? $status['tone'] : 'neutral';

        /*
         * Every pill carries its dot, neutral counts included.
         *
         * This read "a count is a neutral pill and carries no dot" until the
         * boards were put side by side in a browser: 2a shows
         * "● 1,544 accounts" and 2h "● 7 roles", both neutral and both
         * dotted, and the reference component draws the dot unconditionally.
         * `['dot' => false]` is still the opt-out.
         */
        return [
            'tone'  => $tone,
            'label' => $label,
            'dot'   => (bool) ($status['dot'] ?? true),
        ];
    }

    /**
     * @param  list<string|array{label: string, url?: string|null}|Htmlable> $meta
     * @return list<array{label: string, url: string|null}|Htmlable>
     */
    private static function normaliseMeta(array $meta): array
    {
        $items = [];

        foreach ($meta as $item) {
            if ($item instanceof Htmlable) {
                $items[] = $item;

                continue;
            }

            if (is_array($item)) {
                $label = trim((string) ($item['label'] ?? ''));

                if ($label !== '') {
                    $items[] = ['label' => $label, 'url' => isset($item['url']) ? (string) $item['url'] : null];
                }

                continue;
            }

            $label = trim((string) $item);

            if ($label !== '') {
                $items[] = ['label' => $label, 'url' => null];
            }
        }

        return $items;
    }

    /**
     * @param  list<array{label?: string, url?: string|null}> $breadcrumbs
     * @return list<array{label: string, url: string|null}>
     */
    private static function normaliseBreadcrumbs(array $breadcrumbs): array
    {
        $trail = [];

        foreach ($breadcrumbs as $crumb) {
            if (! is_array($crumb)) {
                continue;
            }

            $label = trim((string) ($crumb['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            $trail[] = ['label' => $label, 'url' => isset($crumb['url']) ? (string) $crumb['url'] : null];
        }

        return $trail;
    }

    /**
     * @param  array{label?: string, value?: mixed, delta?: string|null, tone?: string, note?: string|null, dot?: string|bool, url?: string|null} $stat
     * @return array{label: string, value: string, delta: string|null, tone: string, note: string|null, dot: string|null, url: string|null}
     */
    private static function normaliseStat(array $stat): array
    {
        $tone = in_array($stat['tone'] ?? null, self::DOT_TONES, true) ? $stat['tone'] : 'neutral';
        $dot  = $stat['dot'] ?? null;

        if ($dot === true) {
            $dot = $tone;
        } elseif (! is_string($dot) || ! in_array($dot, self::DOT_TONES, true)) {
            $dot = null;
        }

        $delta = trim((string) ($stat['delta'] ?? ''));
        $note  = trim((string) ($stat['note'] ?? ''));
        $id    = trim((string) ($stat['id'] ?? ''));
        $url   = trim((string) ($stat['url'] ?? ''));
        $spark = self::normaliseSpark($stat['spark'] ?? null);

        /*
         * A cell carries a sparkline or a tonal icon tile, never both: the
         * sparkline says "this moves", the tile says "this is a count". A
         * page that passes the two gets the sparkline, because drawing a
         * trend line next to a static figure is the failure the handoff
         * names — "never decorate a static count with a fake trend".
         */
        $icon = $spark === null ? trim((string) ($stat['icon'] ?? '')) : '';

        /*
         * `id` lands on the value, for a page whose script rewrites a figure
         * live. `url` makes the whole cell a link — a fact an operator can act
         * on should name the tab that acts on it, which is the only reason
         * the background-tasks health figures were kept out of a strip.
         */
        return [
            'label'    => (string) ($stat['label'] ?? ''),
            'value'    => self::formatNumber($stat['value'] ?? ''),
            'delta'    => $delta === '' ? null : $delta,
            'tone'     => $tone,
            'note'     => $note === '' ? null : $note,
            'dot'      => $dot,
            'id'       => $id === '' ? null : $id,
            'spark'    => $spark,
            'icon'     => $icon === '' ? null : $icon,
            'iconKind' => $icon === '' ? null : (str_starts_with($icon, 'fa-') ? 'fa' : 'icon'),
            'url'      => $url  === '' ? null : $url,
        ];
    }

    /**
     * "Back to Roles & permissions", from the crumb above the current page.
     *
     * The link replaced a 40px arrow button that said only "Back": at the
     * end of a breadcrumb row the destination is already on screen, so
     * naming it costs nothing and an unlabelled arrow beside a labelled
     * trail read as a second, competing control.
     *
     * @param list<array{label: string, url: string|null}> $breadcrumbs
     */
    private static function defaultBackLabel(array $breadcrumbs): string
    {
        $parent = $breadcrumbs[count($breadcrumbs) - 2]['label'] ?? null;

        return (string) ($parent === null ? __('Back') : __('Back to :page', ['page' => $parent]));
    }

    /**
     * A stat's sparkline, as bar heights in percent.
     *
     * The shape is drawn from the numbers the page already has, so it is
     * computed here rather than in the view: a bar is its share of the run's
     * largest value, floored at 12% so a zero still reads as a bar rather
     * than a gap. The last bar is the current period and is drawn in the
     * brand colour by the stylesheet.
     *
     * @return list<int>|null
     */
    private static function normaliseSpark(mixed $spark): ?array
    {
        if (! is_array($spark) || $spark === []) {
            return null;
        }

        $values = array_values(array_filter($spark, static fn ($v): bool => is_int($v) || is_float($v)));

        if ($values === []) {
            return null;
        }

        $max = max(array_map('abs', $values));

        if ($max <= 0) {
            return null;
        }

        return array_map(
            static fn ($value): int => max(12, (int) round(abs($value) / $max * 100)),
            $values
        );
    }

    /**
     * The note under the bar is the page's sentence ("82% of the catalog is
     * live"), not a generated one: the component owns no strings of its own
     * beyond the three shell labels, so a page reads the same in every locale
     * it was written for.
     *
     * @param  array{label?: string, value?: int|float, total?: int|float, note?: string|null, attrs?: array<string, array<string, scalar>>}|null                                                             $progress
     * @return array{label: string, value: string, total: string, percent: int, note: string|null, attrs: array{ratio: array<string, scalar>, fill: array<string, scalar>, note: array<string, scalar>}}|null
     */
    private static function normaliseProgress(?array $progress): ?array
    {
        if ($progress === null) {
            return null;
        }

        $value   = (float) ($progress['value'] ?? 0);
        $total   = (float) ($progress['total'] ?? 0);
        $percent = $total > 0 ? (int) round(min(100, max(0, $value / $total * 100))) : 0;
        $note    = trim((string) ($progress['note'] ?? ''));

        /*
         * `attrs` hangs DOM hooks on the three parts a script may rewrite as
         * the page is used: the ratio, the bar's fill and the note. The cell
         * is otherwise server-rendered, and the role editor's meter has to
         * keep counting as permissions are switched — without this the page
         * would need its own meter below the header, which is the duplicate
         * this prop exists to remove.
         *
         * @var array<string, array<string, scalar>> $attrs
         */
        $attrs = is_array($progress['attrs'] ?? null) ? $progress['attrs'] : [];

        return [
            'label'   => (string) ($progress['label'] ?? ''),
            'value'   => self::formatNumber($progress['value'] ?? 0),
            'total'   => self::formatNumber($progress['total'] ?? 0),
            'percent' => $percent,
            'note'    => $note === '' ? null : $note,
            'attrs'   => [
                'ratio' => self::normaliseAttrs($attrs['ratio'] ?? null),
                'fill'  => self::normaliseAttrs($attrs['fill'] ?? null),
                'note'  => self::normaliseAttrs($attrs['note'] ?? null),
            ],
        ];
    }

    /**
     * @return array<string, scalar>
     */
    private static function normaliseAttrs(mixed $attrs): array
    {
        if (! is_array($attrs)) {
            return [];
        }

        $clean = [];

        foreach ($attrs as $name => $value) {
            $name = trim((string) $name);

            /* Hooks and text direction only — never a style or an event. */
            if ($name === '' || ! preg_match('/^(data-[a-z0-9-]+|dir|id|aria-[a-z-]+)$/', $name)) {
                continue;
            }

            if (is_scalar($value)) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }
}
