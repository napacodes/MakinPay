<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Api\Insights\TransactionCategorizer;

/**
 * The spending categories as a person reads them - the same eight the
 * app shows, so a notification names what the screen names.
 */
class SpendCategoryLabel
{
    public static function of(?string $category): string
    {
        return match ($category) {
            TransactionCategorizer::TRANSFERS     => __('Transfers'),
            TransactionCategorizer::SHOPPING      => __('Shopping'),
            TransactionCategorizer::BILLS         => __('Bills & subscriptions'),
            TransactionCategorizer::FOOD          => __('Food & dining'),
            TransactionCategorizer::TRANSPORT     => __('Transport'),
            TransactionCategorizer::ENTERTAINMENT => __('Entertainment'),
            TransactionCategorizer::CASH          => __('Cash'),
            default                               => __('Everything else'),
        };
    }
}
