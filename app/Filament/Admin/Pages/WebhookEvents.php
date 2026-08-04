<?php

namespace App\Filament\Admin\Pages;

use App\Enums\WebhookStatus;
use App\Jobs\ProcessShopifyOrder;
use App\Models\AuditLog;
use App\Models\WebhookEvent;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * 詳細設計11.5。Webhook受信履歴の閲覧と、failedのみの手動再実行。
 */
class WebhookEvents extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static ?string $navigationLabel = 'Webhookイベント';

    protected static ?string $title = 'Webhookイベント';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(WebhookEvent::query())
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')
                    ->label('受信日時')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('topic')
                    ->label('topic'),
                TextColumn::make('shopify_order_id')
                    ->label('注文ID'),
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (WebhookStatus $state) => $state->label()),
                TextColumn::make('attempts')
                    ->label('試行回数'),
                TextColumn::make('failure_reason')
                    ->label('失敗理由')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状態')
                    ->options(collect(WebhookStatus::cases())->mapWithKeys(
                        fn (WebhookStatus $status) => [$status->value => $status->label()]
                    )),
            ])
            ->recordActions([
                Action::make('viewPayload')
                    ->label('payload表示')
                    ->modalHeading('受信 payload')
                    ->modalContent(fn (WebhookEvent $record) => new HtmlString(
                        '<pre style="white-space: pre-wrap; word-break: break-all;">'.
                        e(json_encode(json_decode((string) $record->payload, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)).
                        '</pre>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
                Action::make('retry')
                    ->label('手動再実行')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (WebhookEvent $record) => $record->status === WebhookStatus::Failed)
                    ->requiresConfirmation()
                    ->action(function (WebhookEvent $record) {
                        AuditLog::record(
                            action: 'webhook.retried_manually',
                            actorLabel: $this->actorLabel(),
                            auditableType: 'WebhookEvent',
                            auditableId: $record->id,
                            userId: Auth::id(),
                        );

                        ProcessShopifyOrder::dispatch($record->id);
                    }),
            ]);
    }

    private function actorLabel(): string
    {
        $user = Auth::user();

        return $user === null ? 'system' : "{$user->name}（{$user->role->label()}）";
    }
}
