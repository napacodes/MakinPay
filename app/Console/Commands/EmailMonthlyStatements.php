<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\MonthlyStatementMail;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Api\StatementService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * On the 1st: last month's statements to everyone who asked for them in
 * the app. A wallet with no movement that month sends nothing.
 */
class EmailMonthlyStatements extends Command
{
    protected $signature = 'statements:email {--period= : YYYY-MM, default last month}';

    protected $description = 'Mail last month\'s wallet statements to customers who opted in';

    public function handle(StatementService $statements): int
    {
        $period = (string) ($this->option('period') ?: CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'));

        if (! StatementService::isPeriod($period)) {
            $this->error('Period must be YYYY-MM.');

            return self::INVALID;
        }

        $sent   = 0;
        $failed = 0;

        User::query()->where('monthly_statement_email', true)->whereNotNull('email')->chunkById(100, function ($users) use ($statements, $period, &$sent, &$failed): void {
            foreach ($users as $user) {
                try {
                    $sent += $this->mailOne($user, $statements, $period) ? 1 : 0;
                } catch (Throwable $e) {
                    // One customer's broken statement must not cost everyone
                    // after them theirs.
                    report($e);
                    $failed++;
                }
            }
        });

        $this->info("Statements for {$period} mailed to {$sent} customers.");

        if ($failed > 0) {
            $this->warn("{$failed} customers could not be mailed; see the log.");
        }

        return self::SUCCESS;
    }

    /**
     * Build and send one customer's statements. False when no wallet moved.
     */
    private function mailOne(User $user, StatementService $statements, string $period): bool
    {
        $pdfs = [];

        foreach ($user->wallets()->with('currency')->get() as $wallet) {
            $rows = $statements->rows($wallet, $period);

            if ($rows->isEmpty()) {
                continue;
            }

            $pdfs[$this->fileName($wallet, $period)] = $statements->pdf($rows, [
                'title'    => __('Statement :period', ['period' => $period]),
                'name'     => $user->name,
                'wallet'   => $wallet->uuid,
                'currency' => $wallet->currency?->code,
                'closing'  => collect($statements->months($wallet))->firstWhere('period', $period)['closing'] ?? null,
            ]);
        }

        if ($pdfs === []) {
            return false;
        }

        Mail::to($user->email)->send(new MonthlyStatementMail($period, $pdfs));

        return true;
    }

    private function fileName(Wallet $wallet, string $period): string
    {
        return 'statement-'.$wallet->currency?->code.'-'.$period.'.pdf';
    }
}
