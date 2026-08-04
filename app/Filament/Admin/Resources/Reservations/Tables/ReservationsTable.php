<?php

namespace App\Filament\Admin\Resources\Reservations\Tables;

use App\Actions\CancelReservation;
use App\Enums\CancelledBy;
use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Workshop;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

class ReservationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['slot.workshop']))
            ->defaultPaginationPageOption(50)
            ->paginated([50])
            ->columns([
                TextColumn::make('code')
                    ->label('予約番号')
                    ->searchable(),
                TextColumn::make('slot.workshop.name')
                    ->label('講座'),
                TextColumn::make('slot.starts_at')
                    ->label('開催日時')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('氏名')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('メール')
                    ->searchable(),
                TextColumn::make('phone')
                    ->label('電話'),
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (ReservationStatus $state) => $state->label()),
                TextColumn::make('source')
                    ->label('経路')
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('checked_in_at')
                    ->label('チェックイン')
                    ->dateTime(),
            ])
            ->filters([
                Filter::make('slot_starts_at')
                    ->label('開催日')
                    ->schema([
                        DatePicker::make('from')->label('から'),
                        DatePicker::make('until')->label('まで'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereHas(
                            'slot',
                            fn (Builder $q) => $q->whereDate('starts_at', '>=', $date)
                        ))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereHas(
                            'slot',
                            fn (Builder $q) => $q->whereDate('starts_at', '<=', $date)
                        ))),
                SelectFilter::make('workshop')
                    ->label('講座')
                    ->options(fn () => Workshop::query()->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $workshopId) => $q->whereHas(
                            'slot',
                            fn (Builder $q) => $q->where('workshop_id', $workshopId)
                        ))),
                SelectFilter::make('status')
                    ->label('予約状態')
                    ->multiple()
                    ->options(collect(ReservationStatus::cases())->mapWithKeys(
                        fn (ReservationStatus $status) => [$status->value => $status->label()]
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('cancel')
                    ->label('キャンセル')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(function (Reservation $record) {
                        if ($record->status !== ReservationStatus::Confirmed) {
                            return false;
                        }

                        // 期限切れ予約のキャンセルは admin のみ（要件4.3・詳細設計11.1）。
                        return Auth::user()->isAdmin() || $record->isCancellableByCustomer();
                    })
                    ->requiresConfirmation()
                    ->action(function (Reservation $record) {
                        $actor = Auth::user();

                        app(CancelReservation::class)->execute(
                            reservation: $record,
                            by: $actor->isAdmin() ? CancelledBy::Admin : CancelledBy::Staff,
                            actor: $actor,
                        );
                    }),
            ])
            ->headerActions([
                Action::make('exportCsv')
                    ->label('CSV出力')
                    ->action(fn (Table $table) => static::exportCsv($table))
                    ->color('gray'),
            ]);
    }

    private static function exportCsv(Table $table)
    {
        $reservations = $table->getQuery()->with(['slot.workshop'])->get();

        $filename = 'reservations_'.now()->format('YmdHis').'.csv';

        return Response::streamDownload(function () use ($reservations) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [
                '予約番号', '講座名', '開催日時', '氏名', 'メールアドレス', '電話番号', '状態', '経路', '予約日時', 'チェックイン日時',
            ], "\r\n");

            foreach ($reservations as $reservation) {
                fputcsv($handle, [
                    static::escapeForCsv($reservation->code),
                    static::escapeForCsv($reservation->slot->workshop->name),
                    $reservation->slot->starts_at->format('Y-m-d H:i'),
                    static::escapeForCsv($reservation->name),
                    static::escapeForCsv($reservation->email),
                    static::escapeForCsv($reservation->phone ?? ''),
                    $reservation->status->label(),
                    $reservation->source->label(),
                    $reservation->created_at->format('Y-m-d H:i'),
                    $reservation->checked_in_at?->format('Y-m-d H:i') ?? '',
                ], "\r\n");
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private static function escapeForCsv(string $value): string
    {
        return preg_match('/\A[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
