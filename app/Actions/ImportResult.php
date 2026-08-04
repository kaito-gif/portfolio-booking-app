<?php

namespace App\Actions;

use App\Enums\WebhookStatus;

final class ImportResult
{
    /**
     * @param  array<int, int>  $createdReservationIds
     */
    public function __construct(
        public readonly WebhookStatus $status,
        public readonly array $createdReservationIds,
        public readonly ?string $reason,
    ) {}
}
