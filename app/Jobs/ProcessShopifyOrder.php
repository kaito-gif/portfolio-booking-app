<?php

namespace App\Jobs;

use App\Actions\ImportOrderReservations;
use App\Enums\WebhookStatus;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * 詳細設計8.2。webhook_events を注文取り込みまで進める。
 */
class ProcessShopifyOrder implements ShouldQueue
{
    use Queueable;

    private const BACKOFF_SECONDS = [60, 300, 900, 1800, 3600];

    public int $tries = 5;

    public int $timeout = 60;

    public function __construct(
        public readonly int $webhookEventId,
    ) {}

    public function backoff(): array
    {
        return self::BACKOFF_SECONDS;
    }

    public function handle(ImportOrderReservations $importOrderReservations): void
    {
        $event = WebhookEvent::find($this->webhookEventId);

        if ($event === null) {
            // demo:reset 等で行が消えた後の再実行（8.1の共通の作法）
            return;
        }

        if (in_array($event->status, [WebhookStatus::Processed, WebhookStatus::Skipped], true)) {
            return;
        }

        $event->status = WebhookStatus::Processing;
        $event->attempts++;
        $event->next_attempt_at = isset(self::BACKOFF_SECONDS[$event->attempts - 1])
            ? CarbonImmutable::now()->addSeconds(self::BACKOFF_SECONDS[$event->attempts - 1])
            : null;
        $event->save();

        $payload = json_decode((string) $event->payload, true);

        if (! is_array($payload)) {
            $event->status = WebhookStatus::Failed;
            $event->failure_reason = 'payloadのJSONデコードに失敗しました（仕様違反のため再試行しない）';
            $event->save();

            return;
        }

        $result = $importOrderReservations->execute($payload);

        $event->status = $result->status;
        $event->failure_reason = $result->reason;
        $event->processed_at = CarbonImmutable::now();
        $event->save();

        if ($result->status === WebhookStatus::Failed) {
            throw new RuntimeException($result->reason ?? "WebhookEvent#{$event->id} の処理に失敗しました");
        }
    }
}
