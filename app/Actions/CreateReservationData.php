<?php

namespace App\Actions;

use App\Enums\ReservationSource;
use App\Models\Slot;

final class CreateReservationData
{
    public function __construct(
        public readonly Slot $slot,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ReservationSource $source,
        public readonly bool $reserveInventory,
        public readonly ?string $shopifyOrderId = null,
        public readonly ?string $shopifyLineItemId = null,
        public readonly ?int $seatIndex = null,
        public readonly bool $sendMail = true,
    ) {}
}
