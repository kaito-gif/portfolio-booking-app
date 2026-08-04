<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['reservation_id', 'related_reservation_ids', 'type', 'to', 'subject', 'body', 'status', 'sent_at', 'last_error'])]
class MailLog extends Model
{
    protected function casts(): array
    {
        return [
            'related_reservation_ids' => 'array',
            'sent_at' => 'datetime',
        ];
    }
}
