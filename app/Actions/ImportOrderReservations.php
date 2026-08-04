<?php

namespace App\Actions;

use App\Enums\CancelledBy;
use App\Enums\ReservationSource;
use App\Enums\WebhookStatus;
use App\Jobs\SendReservationMail;
use App\Models\Reservation;
use App\Models\Slot;
use Throwable;

/**
 * 詳細設計5.3。注文1件から予約を必要数まとめて作る。
 */
final class ImportOrderReservations
{
    public function __construct(
        private readonly CreateReservation $createReservation,
        private readonly CancelReservation $cancelReservation,
    ) {}

    public function execute(array $orderPayload): ImportResult
    {
        $orderId = (string) ($orderPayload['id'] ?? '');
        $lineItems = $orderPayload['line_items'] ?? [];

        /** @var array<int, Reservation> $createdReservations */
        $createdReservations = [];
        $skipReasons = [];
        $failureReasons = [];

        foreach ($lineItems as $lineItem) {
            $lineItemId = $lineItem['id'] ?? null;

            if ($lineItemId === null) {
                $failureReasons[] = 'line_item.idが欠損しています（仕様違反）';

                continue;
            }

            $lineItemId = (string) $lineItemId;
            $variantId = isset($lineItem['variant_id']) ? (string) $lineItem['variant_id'] : null;
            $quantity = (int) ($lineItem['quantity'] ?? 0);

            $slot = $variantId === null ? null : Slot::query()
                ->where('shopify_variant_id', $this->toVariantGid($variantId))
                ->first();

            if ($slot === null) {
                $skipReasons[] = "line_item#{$lineItemId}: 開催枠に紐づかないバリアント（物販等）";

                continue;
            }

            if (! $slot->isBookable()) {
                $skipReasons[] = "line_item#{$lineItemId}: 開催枠#{$slot->id}は受付中ではありません（{$slot->status->label()}）";

                continue;
            }

            $lineItemReservations = [];

            try {
                for ($seatIndex = 1; $seatIndex <= $quantity; $seatIndex++) {
                    $lineItemReservations[] = $this->createReservation->execute(new CreateReservationData(
                        slot: $slot,
                        name: $this->customerName($orderPayload),
                        email: $this->customerEmail($orderPayload),
                        phone: $this->customerPhone($orderPayload),
                        source: ReservationSource::Shopify,
                        reserveInventory: false,
                        shopifyOrderId: $orderId,
                        shopifyLineItemId: $lineItemId,
                        seatIndex: $seatIndex,
                        sendMail: false,
                    ));
                }

                $createdReservations = [...$createdReservations, ...$lineItemReservations];
            } catch (Throwable $e) {
                foreach ($lineItemReservations as $reservation) {
                    $this->cancelReservation->execute(
                        reservation: $reservation,
                        by: CancelledBy::System,
                        restoreInventory: false,
                        sendCancelledMail: false,
                    );
                }

                $failureReasons[] = "line_item#{$lineItemId}: {$e->getMessage()}";
            }
        }

        if ($failureReasons !== []) {
            return new ImportResult(
                status: WebhookStatus::Failed,
                createdReservationIds: [],
                reason: implode(' / ', $failureReasons),
            );
        }

        if ($createdReservations === []) {
            return new ImportResult(
                status: WebhookStatus::Skipped,
                createdReservationIds: [],
                reason: $skipReasons === [] ? null : implode(' / ', $skipReasons),
            );
        }

        $createdReservationIds = array_map(fn (Reservation $r) => $r->id, $createdReservations);

        SendReservationMail::dispatch('confirmed', $createdReservationIds, $this->customerEmail($orderPayload));

        return new ImportResult(
            status: WebhookStatus::Processed,
            createdReservationIds: $createdReservationIds,
            // 一部 line item が対象外でも、その理由は failure_reason に残す（設計5.2）
            reason: $skipReasons === [] ? null : implode(' / ', $skipReasons),
        );
    }

    /**
     * 注文 Webhook の line_item.variant_id は数値の legacy ID で届く一方、
     * slots.shopify_variant_id は GraphQL の GID 形式（gid://shopify/ProductVariant/...）
     * で保存する運用（resolveInventoryItemId が ID! 引数に GID を要求するため）。
     * 数値のまま突き合わせると実運用の注文が常に対象外(skip)になってしまうため正規化する。
     */
    private function toVariantGid(string $variantId): string
    {
        if (str_starts_with($variantId, 'gid://')) {
            return $variantId;
        }

        return "gid://shopify/ProductVariant/{$variantId}";
    }

    private function customerName(array $orderPayload): string
    {
        $customer = $orderPayload['customer'] ?? [];
        $name = trim(($customer['last_name'] ?? '').' '.($customer['first_name'] ?? ''));

        return $name !== '' ? $name : (string) ($orderPayload['email'] ?? '');
    }

    private function customerEmail(array $orderPayload): string
    {
        return (string) ($orderPayload['email'] ?? $orderPayload['contact_email'] ?? '');
    }

    private function customerPhone(array $orderPayload): ?string
    {
        $phone = $orderPayload['phone'] ?? ($orderPayload['customer']['phone'] ?? null);

        return $phone !== null ? (string) $phone : null;
    }
}
