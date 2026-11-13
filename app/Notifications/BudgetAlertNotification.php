<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Budget;
use App\Notifications\Channels\FcmChannel;
use App\Notifications\Messages\FcmMessage;
use App\Services\Notifications\Fcm\FcmClient;
use App\Support\ApiMoney;
use App\Support\DecimalMoney;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * A budget crossing the line the customer drew, or going over. Sent once
 * per period per level; the row carries enough for the inline "Raise to"
 * action.
 *
 * @param array<string, mixed> $item what BudgetService::describe() said
 */
class BudgetAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Budget $budget, private readonly array $item, private readonly string $categoryLabel) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database'];
        if (app(FcmClient::class)->isConfigured() && method_exists($notifiable, 'routeNotificationForFcm') && $notifiable->routeNotificationForFcm() !== []) {
            $channels[] = FcmChannel::class;
        }

        return $channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title'       => $this->title(),
            'message'     => $this->body(),
            'icon'        => 'target',
            'action_type' => 'budget',
            // The website's page for it (none - this lives in the app only), and the app's screen.
            'action_link'      => null,
            'app_link'         => '/home/budgets',
            'identifier'       => $this->identifier(),
            'category'         => 'rates',
            'budget_id'        => $this->budget->id,
            'budget_category'  => $this->budget->category,
            'suggested_amount' => $this->suggested(),
            'currency'         => $this->item['currency'],
        ];
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage($this->title(), $this->body()))->data([
            'identifier'  => $this->identifier(),
            'action_link' => '/home/budgets',
            'reference'   => (string) $this->budget->id,
        ]);
    }

    private function identifier(): string
    {
        return $this->item['ratio'] >= 100 ? 'budget_exceeded' : 'budget_warning';
    }

    private function title(): string
    {
        if ($this->item['ratio'] >= 100) {
            return __(':category is over budget', ['category' => $this->categoryLabel]);
        }

        return $this->item['days_left'] > 0
            ? __(':category is at :ratio% with :days days left', ['category' => $this->categoryLabel, 'ratio' => $this->item['ratio'], 'days' => $this->item['days_left']])
            : __(':category is at :ratio%', ['category' => $this->categoryLabel, 'ratio' => $this->item['ratio']]);
    }

    private function body(): string
    {
        $currency = $this->item['currency'];
        if ($this->item['over'] !== null) {
            return __(':over :currency over · :spent of :limit', ['over' => $this->item['over'], 'currency' => $currency, 'spent' => $this->item['spent'], 'limit' => $this->item['limit']]);
        }

        return __(':left :currency left of :limit', ['left' => $this->item['left'], 'currency' => $currency, 'limit' => $this->item['limit']]);
    }

    /**
     * The next round figure above what was spent, with a little room: what
     * "Raise to" offers.
     */
    private function suggested(): string
    {
        $base   = DecimalMoney::compare($this->item['spent'], $this->item['limit']) > 0 ? $this->item['spent'] : $this->item['limit'];
        $step   = DecimalMoney::compare($base, '1000') >= 0 ? '100' : (DecimalMoney::compare($base, '100') >= 0 ? '50' : '10');
        $raised = DecimalMoney::multiply($base, '1.15');
        $units  = (int) explode('.', DecimalMoney::divide($raised, $step))[0] + 1;

        return ApiMoney::amount(DecimalMoney::multiply((string) $units, $step), $this->item['currency']);
    }
}
