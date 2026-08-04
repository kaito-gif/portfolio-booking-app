<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'actor_label', 'action', 'auditable_type', 'auditable_id', 'changes', 'ip_address'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
        ];
    }

    public static function record(
        string $action,
        string $actorLabel,
        ?string $auditableType = null,
        ?int $auditableId = null,
        ?array $changes = null,
        ?int $userId = null,
    ): self {
        return self::create([
            'user_id' => $userId,
            'actor_label' => $actorLabel,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'changes' => $changes,
            'ip_address' => request()?->ip(),
        ]);
    }
}
