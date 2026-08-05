<?php

namespace App\Actions;

use App\Contracts\InventoryServiceContract;
use App\Enums\ReservationSource;
use App\Enums\ReservationStatus;
use App\Exceptions\InventoryUnavailableException;
use App\Exceptions\SlotNotBookableException;
use App\Jobs\AdjustShopifyInventory;
use App\Jobs\SendReservationMail;
use App\Models\AuditLog;
use App\Models\Reservation;
use App\Support\ReservationCodeGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class CreateReservation
{
    private const MAX_CODE_ATTEMPTS = 5;

    public function __construct(
        private readonly InventoryServiceContract $inventoryService,
    ) {}

    /**
     * @throws SlotNotBookableException 枠が受付中でない
     * @throws InventoryUnavailableException 在庫確保に失敗（$reserveInventory=true 時のみ）
     */
    public function execute(CreateReservationData $data): Reservation
    {
        if (! $data->slot->isBookable()) {
            throw new SlotNotBookableException("Slot#{$data->slot->id} は受付中ではありません");
        }

        $reservation = DB::transaction(fn () => $this->createPendingReservation($data));

        if (! $data->reserveInventory) {
            // 一意制約違反で既存行が返ってきた場合（Webhookの重複配信）は、
            // 既に confirmed 済みのことがある。ここで confirm() を無条件に呼ぶと
            // InvalidStateTransition で落ち、設計5.1が求める「静かな収束」が崩れる。
            if ($reservation->status === ReservationStatus::InventoryPending) {
                $reservation->confirm();

                AuditLog::record(
                    action: 'reservation.created',
                    actorLabel: $this->actorLabel($data),
                    auditableType: 'Reservation',
                    auditableId: $reservation->id,
                    changes: $this->reservationSummary($reservation),
                );

                if ($data->sendMail) {
                    SendReservationMail::dispatch('confirmed', [$reservation->id], $reservation->email);
                }
            }

            return $reservation;
        }

        return $this->confirmWithInventory($data, $reservation);
    }

    private function confirmWithInventory(CreateReservationData $data, Reservation $reservation): Reservation
    {
        $before = max(0, $data->slot->capacity - $data->slot->confirmedCount());

        try {
            $after = $this->inventoryService->adjust($data->slot, -1, 'reservation');
        } catch (Throwable $e) {
            $reservation->delete();

            throw new InventoryUnavailableException(
                'Shopify の在庫を確保できませんでした。予約は登録されていません',
                previous: $e,
            );
        }

        try {
            $reservation->confirm();
        } catch (Throwable $e) {
            AdjustShopifyInventory::dispatch($data->slot->id, 1, 'compensation', $reservation->id);

            AuditLog::record(
                action: 'inventory.compensated',
                actorLabel: $this->actorLabel($data),
                auditableType: 'Reservation',
                auditableId: $reservation->id,
                changes: ['slot_id' => $data->slot->id],
            );

            throw $e;
        }

        AuditLog::record(
            action: 'reservation.created',
            actorLabel: $this->actorLabel($data),
            auditableType: 'Reservation',
            auditableId: $reservation->id,
            changes: $this->reservationSummary($reservation),
        );

        AuditLog::record(
            action: 'inventory.decremented',
            actorLabel: $this->actorLabel($data),
            auditableType: 'Slot',
            auditableId: $data->slot->id,
            changes: ['before' => $before, 'after' => $after, 'slot_id' => $data->slot->id],
        );

        if ($data->sendMail) {
            SendReservationMail::dispatch('confirmed', [$reservation->id], $reservation->email);
        }

        return $reservation;
    }

    private function createPendingReservation(CreateReservationData $data): Reservation
    {
        for ($attempt = 1; $attempt <= self::MAX_CODE_ATTEMPTS; $attempt++) {
            $code = ReservationCodeGenerator::generate();

            try {
                return Reservation::create([
                    'slot_id' => $data->slot->id,
                    'code' => $code,
                    'name' => $data->name,
                    'email' => $data->email,
                    'phone' => $data->phone,
                    'status' => ReservationStatus::InventoryPending,
                    'source' => $data->source,
                    'shopify_order_id' => $data->shopifyOrderId,
                    'shopify_line_item_id' => $data->shopifyLineItemId,
                    'seat_index' => $data->seatIndex,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }

                if (str_contains($e->getMessage(), 'reservations_order_line_seat_unique')) {
                    return Reservation::query()
                        ->where('shopify_order_id', $data->shopifyOrderId)
                        ->where('shopify_line_item_id', $data->shopifyLineItemId)
                        ->where('seat_index', $data->seatIndex)
                        ->firstOrFail();
                }

                if (str_contains($e->getMessage(), 'reservations_code_unique')) {
                    continue;
                }

                throw $e;
            }
        }

        throw new RuntimeException('予約番号の採番に5回失敗しました');
    }

    private function actorLabel(CreateReservationData $data): string
    {
        if ($data->source !== ReservationSource::Manual) {
            return $data->source === ReservationSource::Shopify ? 'system:webhook' : 'system:seed';
        }

        $user = auth()->user();

        if ($user === null) {
            return 'system:manual';
        }

        return "{$user->name}（{$user->role->label()}）";
    }

    /** @return array<string, mixed> */
    private function reservationSummary(Reservation $reservation): array
    {
        return [
            'slot_id' => $reservation->slot_id,
            'status' => $reservation->status->value,
            'source' => $reservation->source->value,
        ];
    }
}
