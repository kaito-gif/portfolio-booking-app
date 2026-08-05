<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\MailLog;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 詳細設計13章。90日超過分の個人情報・本文を消す。
 * mail_logs.body/to・webhook_events.payload はNULL化（行は残す）、
 * audit_logsは行そのものを削除する。
 */
class LogsPrune extends Command
{
    private const RETENTION_DAYS = 90;

    protected $signature = 'logs:prune {--dry-run : 実際には変更せず件数だけ表示する}';

    protected $description = '90日超過分のメール本文・Webhook payload・監査ログを削除する';

    public function handle(): int
    {
        $threshold = CarbonImmutable::now()->subDays(self::RETENTION_DAYS);
        $dryRun = (bool) $this->option('dry-run');

        $mailLogsQuery = MailLog::query()
            ->where('created_at', '<', $threshold)
            ->where(fn ($query) => $query->whereNotNull('body')->orWhereNotNull('to'));
        $webhookEventsQuery = WebhookEvent::query()
            ->where('created_at', '<', $threshold)
            ->whereNotNull('payload');
        $auditLogsQuery = AuditLog::query()->where('created_at', '<', $threshold);

        $mailLogsCount = $mailLogsQuery->count();
        $webhookEventsCount = $webhookEventsQuery->count();
        $auditLogsCount = $auditLogsQuery->count();

        if (! $dryRun) {
            $mailLogsQuery->update(['body' => null, 'to' => null]);
            $webhookEventsQuery->update(['payload' => null]);
            $auditLogsQuery->delete();
        }

        $summary = "logs:prune mail_logs={$mailLogsCount} webhook_events={$webhookEventsCount} audit_logs={$auditLogsCount} dry_run=".($dryRun ? '1' : '0');
        $this->info($summary);
        Log::info('logs:prune', [
            'mail_logs' => $mailLogsCount,
            'webhook_events' => $webhookEventsCount,
            'audit_logs' => $auditLogsCount,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
