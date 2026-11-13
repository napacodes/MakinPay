<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Currency;
use App\Models\KycTemplate;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BackgroundHealthService;

/**
 * What a brand-new installation still has to do before the dashboard has
 * anything to show.
 *
 * Every step's done-state is computed from the database, never stored: an
 * administrator who disables their only gateway again sees that step reopen,
 * which is the honest answer.
 */
class SetupChecklistService
{
    public function __construct(
        private readonly DashboardSla $sla,
        private readonly BackgroundHealthService $health,
    ) {}

    /**
     * A site with no users and no transactions has never been used.
     *
     * Deliberately not "and no active gateway": migrations insert gateways
     * already enabled and carrying placeholder credentials, so that clause is
     * false on a database that has only ever been migrated, and the first-run
     * state could never be reached at all.
     */
    public function isFreshInstall(): bool
    {
        return User::query()->count()        === 0
            && Transaction::query()->count() === 0;
    }

    /**
     * @return array{steps: list<array<string, mixed>>, done: int, total: int, percent: int}
     */
    public function steps(): array
    {
        $background = $this->health->summary();

        $steps = [
            [
                'key'    => 'gateway',
                'title'  => __('Add a payment gateway'),
                'why'    => __('Without one, users cannot deposit — the platform earns nothing.'),
                'action' => __('Add gateway'),
                'link'   => route('admin.payment.gateway.index'),
                'done'   => PaymentGateway::query()->active()->get()->contains(
                    fn (PaymentGateway $gateway): bool => $gateway->isConfigured()
                ),
            ],
            [
                'key'    => 'currencies',
                'title'  => __('Enable currencies'),
                'why'    => __('Wallets are created per enabled currency; pick your base currency first.'),
                'action' => __('Review'),
                'link'   => route('admin.currency.index'),
                'done'   => Currency::query()->where('status', true)->exists(),
            ],
            [
                'key'    => 'kyc_template',
                'title'  => __('Create a KYC template'),
                'why'    => __('Required before users can pass identity verification and lift limits.'),
                'action' => __('Create'),
                'link'   => route('admin.kyc.template.index'),
                'done'   => KycTemplate::query()->exists(),
            ],
            [
                'key'    => 'background',
                'title'  => __('Configure cron & queue worker'),
                'why'    => __('Scheduled payouts, emails and rate refresh all run through the queue.'),
                'action' => __('Instructions'),
                'link'   => route('admin.background-tasks.index'),
                'done'   => ($background['scheduler']['status'] ?? null) === 'ok'
                    && ($background['queue']['status'] ?? null)          === 'ok',
            ],
            [
                'key'    => 'sla',
                'title'  => __('Set SLA targets'),
                'why'    => __('Drives the SLA alerts and the "oldest waiting" colours on this page.'),
                'action' => __('Set targets'),
                'link'   => route('admin.settings.site.index', ['section' => 'dashboard_settings']),
                'done'   => $this->sla->isConfigured(),
            ],
        ];

        $done  = count(array_filter($steps, static fn (array $step): bool => $step['done']));
        $total = count($steps);

        return [
            'steps'   => $steps,
            'done'    => $done,
            'total'   => $total,
            'percent' => $total === 0 ? 0 : (int) round($done / $total * 100),
        ];
    }
}
