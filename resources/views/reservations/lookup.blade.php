@extends('layouts.public')

@section('title', '予約照会 - chanoka ワークショップ予約管理')

@section('content')
    <div class="mx-auto max-w-md px-4 py-10">
        <h1 id="page-heading" tabindex="-1" class="text-2xl font-bold text-gray-900 focus:outline-none">
            予約照会
        </h1>
        <p class="mt-2 text-sm text-gray-700">
            確認メールに記載の予約番号と、ご予約時のメールアドレスを入力してください。
        </p>

        @if ($errors->any())
            <div role="alert" class="mt-4 rounded-md border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-semibold">入力内容をご確認ください</p>
                <ul class="mt-1 list-disc pl-5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('lookup.submit') }}" class="mt-6 space-y-4" novalidate>
            @csrf

            <div>
                <label for="code" class="block text-sm font-medium text-gray-900">予約番号</label>
                <input
                    type="text"
                    id="code"
                    name="code"
                    value="{{ old('code', $code) }}"
                    required
                    placeholder="CHK-XXXXX-XXXXX"
                    class="mt-1 block w-full rounded-md border border-gray-400 px-3 py-2 text-gray-900 focus:border-blue-700 focus:outline focus:outline-2 focus:outline-blue-700"
                    @error('code') aria-invalid="true" aria-describedby="code-error" @enderror
                >
                @error('code')
                    <p id="code-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-900">メールアドレス</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    required
                    class="mt-1 block w-full rounded-md border border-gray-400 px-3 py-2 text-gray-900 focus:border-blue-700 focus:outline focus:outline-2 focus:outline-blue-700"
                    @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                >
                @error('email')
                    <p id="email-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full rounded-md bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-gray-700 focus:outline focus:outline-2 focus:outline-offset-2 focus:outline-blue-700"
            >
                照会する
            </button>
        </form>
    </div>

    <script>
        document.getElementById('page-heading')?.focus();
    </script>
@endsection
