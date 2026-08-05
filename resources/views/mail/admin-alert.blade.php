@component('mail::message')
{{ $bodyText }}

@component('mail::button', ['url' => $adminUrl])
管理画面で確認する
@endcomponent

@component('mail::subcopy')
本メールは chanoka ワークショップ予約管理システムのデモ環境から送信されています。
@endcomponent
@endcomponent
