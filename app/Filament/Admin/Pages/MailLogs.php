<?php

namespace App\Filament\Admin\Pages;

use App\Jobs\SendReservationMail;
use App\Models\AuditLog;
use App\Models\MailLog;
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
 * 詳細設計11.5。メール送信履歴の閲覧と、failedのみの手動再送。
 */
class MailLogs extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'メール送信履歴';

    protected static ?string $title = 'メール送信履歴';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(MailLog::query())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('送信日時')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('種別'),
                TextColumn::make('to')
                    ->label('宛先')
                    ->searchable(),
                TextColumn::make('subject')
                    ->label('件名')
                    ->limit(40),
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_error')
                    ->label('エラー')
                    ->limit(50)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状態')
                    ->options([
                        'queued' => 'queued',
                        'sent' => 'sent',
                        'failed' => 'failed',
                    ]),
                SelectFilter::make('type')
                    ->label('種別')
                    ->options([
                        'confirmed' => 'confirmed',
                        'reminder' => 'reminder',
                        'cancelled' => 'cancelled',
                    ]),
            ])
            ->recordActions([
                Action::make('previewBody')
                    ->label('本文プレビュー')
                    ->modalHeading('メール本文')
                    ->modalContent(fn (MailLog $record) => new HtmlString(
                        $record->body ?? '<p>本文はありません。</p>'
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('閉じる'),
                Action::make('resend')
                    ->label('再送')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (MailLog $record) => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->action(function (MailLog $record) {
                        AuditLog::record(
                            action: 'mail.resent_manually',
                            actorLabel: $this->actorLabel(),
                            auditableType: 'MailLog',
                            auditableId: $record->id,
                            userId: Auth::id(),
                        );

                        SendReservationMail::dispatch(
                            $record->type,
                            $record->related_reservation_ids ?? [$record->reservation_id],
                            $record->to,
                            $record->id,
                        );
                    }),
            ]);
    }

    private function actorLabel(): string
    {
        $user = Auth::user();

        return $user === null ? 'system' : "{$user->name}（{$user->role->label()}）";
    }
}
