@extends('layouts.public')

@section('title', '予約詳細 - chanoka ワークショップ予約管理')

@section('content')
    <div class="mx-auto max-w-md px-4 py-10">
        <h1 id="page-heading" tabindex="-1" class="text-2xl font-bold text-gray-900 focus:outline-none">
            予約詳細
        </h1>

        @if (session('status'))
            <div role="alert" class="mt-4 rounded-md border border-green-300 bg-green-50 p-4 text-sm text-green-800">
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                @foreach ($errors->all() as $message)
                    <p>{{ $message }}</p>
                @endforeach
            </div>
        @endif

        <dl class="mt-6 space-y-3 text-sm text-gray-900">
            <div>
                <dt class="font-medium text-gray-700">講座</dt>
                <dd>{{ $reservation->slot->workshop->name }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700">開催日時</dt>
                <dd>{{ $reservation->slot->starts_at->format('Y年n月j日 H:i') }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700">予約番号</dt>
                <dd>{{ $reservation->code }}</dd>
            </div>
            <div>
                <dt class="font-medium text-gray-700">状態</dt>
                <dd>{{ $reservation->status->label() }}</dd>
            </div>
        </dl>

        <div class="mt-8">
            @if ($reservation->status === \App\Enums\ReservationStatus::Cancelled)
                <p class="text-sm text-gray-700">このご予約はキャンセル済みです。</p>
            @elseif ($cancelUrl !== null)
                <details class="rounded-md border border-gray-300 p-4">
                    <summary class="cursor-pointer text-sm font-semibold text-red-700">
                        予約をキャンセルする
                    </summary>
                    <p class="mt-3 text-sm text-gray-700">
                        この操作は取り消せません。キャンセルに伴う返金は、別途こちらからご案内する方法で対応します。
                    </p>
                    <form method="POST" action="{{ $cancelUrl }}" class="mt-3">
                        @csrf
                        <button
                            type="submit"
                            class="rounded-md bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-800 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-700"
                        >
                            はい、キャンセルします
                        </button>
                    </form>
                </details>
            @else
                <p class="text-sm text-gray-700">
                    開催前日23:59を過ぎたため、お電話でご連絡ください。
                </p>
            @endif
        </div>
    </div>

    <script>
        document.getElementById('page-heading')?.focus();
    </script>
@endsection
