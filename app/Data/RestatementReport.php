<?php

declare(strict_types=1);

namespace App\Data;

use App\Services\Reporting\LedgerRestatement;

/**
 * Ledger totals translated into one presentation currency.
 *
 * `exact` and `estimated` are kept apart rather than summed into a single
 * headline, because they are not the same kind of number and a report that
 * hides the difference invites someone to file it as if it were audited.
 *
 * @see LedgerRestatement
 */
readonly class RestatementReport
{
    /**
     * @param string $presentationCurrency What the figures are expressed in.
     * @param string $exact                Translated at each entry's own recorded rate.
     * @param string $estimated            Translated using a current rate for the leg
     *                                     no historical rate exists for.
     * @param int    $entries              Entries that could be translated.
     * @param int    $untranslatable       Entries with no recorded rate, excluded
     *                                     from both totals.
     */
    public function __construct(
        public string $presentationCurrency,
        public string $exact,
        public string $estimated,
        public int $entries,
        public int $untranslatable,
    ) {}

    /**
     * Everything that could be translated. Read `estimated` alongside this: a
     * non-zero estimate means part of the figure rests on today's rates.
     */
    public function total(): string
    {
        return bcadd($this->exact, $this->estimated, 8);
    }

    public function isFullyExact(): bool
    {
        return bccomp($this->estimated, '0', 8) === 0 && $this->untranslatable === 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'presentation_currency' => $this->presentationCurrency,
            'exact'                 => $this->exact,
            'estimated'             => $this->estimated,
            'total'                 => $this->total(),
            'entries'               => $this->entries,
            'untranslatable'        => $this->untranslatable,
            'fully_exact'           => $this->isFullyExact(),
        ];
    }
}
