<x-filament-panels::page>
    <div class="daily-roster-toolbar no-print">
        <div>
            <label for="daily-roster-date" class="daily-roster-label">対象日</label>
            <input type="date" id="daily-roster-date" wire:model.live="date" class="daily-roster-date-input" />
        </div>

        <button type="button" onclick="window.print()" class="daily-roster-print-btn">印刷</button>
    </div>

    @php $dailySlots = $this->getWorkshopSlots(); @endphp

    @forelse ($dailySlots as $slot)
        <div class="daily-roster-slot">
            <h2 class="daily-roster-slot-heading">
                {{ $slot->workshop->name }}　{{ $slot->starts_at->format('H:i') }}〜
            </h2>

            <table class="daily-roster-table">
                <thead>
                    <tr>
                        <th>氏名</th>
                        <th>電話</th>
                        <th>予約番号</th>
                        <th class="no-print">チェックイン</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($slot->reservations as $reservation)
                        <tr>
                            <td>{{ $reservation->name }}</td>
                            <td>{{ $reservation->phone }}</td>
                            <td>{{ $reservation->code }}</td>
                            <td class="no-print">
                                @if ($reservation->checked_in_at !== null)
                                    <span class="daily-roster-checked-in">
                                        済 {{ $reservation->checked_in_at->format('H:i') }}
                                    </span>
                                    @if ($this->isCurrentUserAdmin())
                                        <button
                                            type="button"
                                            wire:click="revertCheckIn({{ $reservation->id }})"
                                            wire:loading.attr="disabled"
                                            wire:confirm="チェックインを取り消しますか？"
                                            class="daily-roster-revert-btn"
                                        >
                                            取り消し
                                        </button>
                                    @endif
                                @else
                                    <button
                                        type="button"
                                        wire:click="checkIn({{ $reservation->id }})"
                                        wire:loading.attr="disabled"
                                        class="daily-roster-checkin-btn"
                                    >
                                        チェックイン
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="daily-roster-empty-cell">予約なし</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @empty
        <p class="daily-roster-empty-cell">この日の開催枠はありません。</p>
    @endforelse

    <style>
        .daily-roster-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .daily-roster-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: rgb(55 65 81);
            margin-bottom: 0.25rem;
        }

        .daily-roster-date-input {
            border-radius: 0.5rem;
            border: 1px solid rgb(209 213 219);
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
        }

        .daily-roster-print-btn,
        .daily-roster-checkin-btn {
            border-radius: 0.5rem;
            background-color: rgb(217 119 6);
            color: #fff;
            font-size: 0.875rem;
            font-weight: 500;
            padding: 0.5rem 1rem;
            border: none;
            cursor: pointer;
        }

        .daily-roster-checkin-btn {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
        }

        .daily-roster-print-btn:hover,
        .daily-roster-checkin-btn:hover {
            background-color: rgb(180 83 9);
        }

        .daily-roster-revert-btn {
            margin-left: 0.5rem;
            border: none;
            background: none;
            color: rgb(220 38 38);
            font-size: 0.75rem;
            text-decoration: underline;
            cursor: pointer;
        }

        @media (prefers-color-scheme: dark) {
            .daily-roster-revert-btn {
                color: rgb(248 113 113);
            }
        }

        .daily-roster-slot {
            margin-bottom: 2rem;
            border-radius: 0.75rem;
            border: 1px solid rgb(229 231 235);
            padding: 1rem;
        }

        .daily-roster-slot-heading {
            font-size: 1.125rem;
            font-weight: 700;
            color: rgb(17 24 39);
        }

        .daily-roster-table {
            width: 100%;
            margin-top: 0.75rem;
            font-size: 0.875rem;
            border-collapse: collapse;
        }

        .daily-roster-table th,
        .daily-roster-table td {
            text-align: left;
            padding: 0.375rem 1rem 0.375rem 0;
        }

        .daily-roster-table thead tr {
            border-bottom: 1px solid rgb(229 231 235);
        }

        .daily-roster-table tbody tr {
            border-bottom: 1px solid rgb(243 244 246);
        }

        .daily-roster-checked-in {
            color: rgb(21 128 61);
        }

        .daily-roster-empty-cell {
            padding: 0.5rem 0;
            color: rgb(156 163 175);
        }

        @media (prefers-color-scheme: dark) {
            .daily-roster-label {
                color: rgb(229 231 235);
            }

            .daily-roster-date-input {
                border-color: rgb(75 85 99);
                background-color: rgb(31 41 55);
                color: #fff;
            }

            .daily-roster-slot {
                border-color: rgb(55 65 81);
            }

            .daily-roster-slot-heading {
                color: #fff;
            }

            .daily-roster-table thead tr {
                border-color: rgb(55 65 81);
            }

            .daily-roster-table tbody tr {
                border-color: rgb(31 41 55);
            }

            .daily-roster-checked-in {
                color: rgb(74 222 128);
            }
        }

        @media print {
            .no-print,
            .fi-topbar,
            .fi-sidebar {
                display: none !important;
            }

            .daily-roster-slot {
                break-inside: avoid;
                page-break-after: always;
            }
        }
    </style>
</x-filament-panels::page>
