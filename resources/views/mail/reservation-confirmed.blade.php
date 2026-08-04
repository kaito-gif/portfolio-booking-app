@component('mail::message')
# ワークショップのご予約を承りました

以下の内容でご予約が確定しました。予約番号は当日の受付でお伝えください。

@foreach ($reservations as $reservation)
@component('mail::panel')
**{{ $reservation->slot->workshop->name }}**
開催日時: {{ $reservation->slot->starts_at->format('Y年n月j日 H:i') }}
予約番号: {{ $reservation->code }}
キャンセル期限: {{ $reservation->slot->cancelDeadline()->format('Y年n月j日 H:i') }}
@endcomponent
@endforeach

## 会場について
会場の詳細は、お申し込みいただいたショップの案内ページをご確認ください。

@component('mail::button', ['url' => $reservations->first()->lookupUrl()])
予約内容を確認する
@endcomponent

キャンセルをご希望の場合は、上記の照会ページから開催前日23:59までにお手続きください。
**キャンセルに伴う返金は、別途こちらからご案内する方法で対応いたします。**

@component('mail::subcopy')
本メールは chanoka ワークショップ予約管理システムのデモ環境から送信されています。実際の決済・返金は発生しません。
@endcomponent
@endcomponent
