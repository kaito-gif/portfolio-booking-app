@component('mail::message')
# 明日のワークショップのご案内

ご予約いただいているワークショップの開催が近づいてまいりました。

@component('mail::panel')
**{{ $reservation->slot->workshop->name }}**
開催日時: {{ $reservation->slot->starts_at->format('Y年n月j日 H:i') }}
予約番号: {{ $reservation->code }}
@endcomponent

## 持ち物
筆記用具など、講座ページに記載の持ち物をご確認のうえお越しください。

@component('mail::button', ['url' => $reservation->lookupUrl()])
予約内容を確認する
@endcomponent

@component('mail::subcopy')
本メールは chanoka ワークショップ予約管理システムのデモ環境から送信されています。実際の決済・返金は発生しません。
@endcomponent
@endcomponent
