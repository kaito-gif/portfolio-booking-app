@component('mail::message')
# ご予約のキャンセルを承りました

以下のご予約のキャンセルを承りました。

@component('mail::panel')
**{{ $reservation->slot->workshop->name }}**
開催日時: {{ $reservation->slot->starts_at->format('Y年n月j日 H:i') }}
予約番号: {{ $reservation->code }}
@endcomponent

**返金については、別途こちらからご案内する方法で対応いたします。**

@component('mail::subcopy')
本メールは chanoka ワークショップ予約管理システムのデモ環境から送信されています。実際の決済・返金は発生しません。
@endcomponent
@endcomponent
