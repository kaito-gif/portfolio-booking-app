<?php

namespace App\Models;

use App\Enums\WebhookStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['webhook_id', 'topic', 'shopify_order_id', 'payload', 'received_at'])]
class WebhookEvent extends Model
{
    protected $attributes = [
        'status' => 'received',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }
}
