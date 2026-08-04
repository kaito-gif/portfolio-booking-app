<?php

namespace App\Enums;

enum WebhookStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => '受信済み・未処理',
            self::Processing => '処理中',
            self::Processed => '処理済み',
            self::Skipped => '対象外',
            self::Failed => '失敗',
        };
    }
}
