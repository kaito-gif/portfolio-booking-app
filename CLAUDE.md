# chanoka ワークショップ予約管理(副業ポートフォリオ第2弾)

## この案件の前提

- **副業案件を獲得するためのポートフォリオ用予約管理システム**。クライアント案件ではない
- 第1弾(Shopifyデモストア `chanoka-demo`)に続く第2弾。決済・在庫は Shopify に任せ、
  Shopify が持たない予約業務(受付・電話予約・キャンセル・名簿出力)をこちらが担う
- リポジトリは**公開予定**。屋号・実ドメイン・実在の氏名などをコード・コメント・
  コミットメッセージ・ドキュメントのどこにも書かない
- 本番の場所: 未定(Xserver 共用レンタルサーバー、サブドメインを想定)。詳細は `docs/context.md`

## 背景

経緯・意思決定の理由・進捗は `docs/context.md` を参照(このファイルには**ルール**だけを書き、
**事実**は `docs/context.md` 側に置く)。

仕様書は4段構成。**実装で迷ったら要件 → 非機能要件 → 基本設計 → 詳細設計の順に参照し、
上位文書と食い違ったら上位文書を正として下位文書を直す。**

- `docs/requirements.md` — 何を作るか・対象外
- `docs/non-functional-requirements.md` — 測れる形にした非機能要件
- `docs/design.md` — どう作るか(方針)
- `docs/detailed-design.md` — そのまま実装できる粒度(型・シグネチャ・カラム定義)

## 構成

- スタック: PHP 8.3 / Laravel 13 / Filament(最新安定版、管理画面) / MySQL 8.0
- キュー・キャッシュ・セッション: すべて `database` ドライバ(Redis 不可。Xserver 共用サーバーの制約)
- 主要ディレクトリ(実装が進み次第この節を更新する):
  - `app/Actions` — 業務ロジックを集約。予約の作成・キャンセルは**どの経路からもここだけを呼ぶ**
    (Filament のフォーム・ジョブ・コントローラに業務ロジックを書かない)
  - `app/Filament` — 管理画面(Resources / Pages / Widgets)
  - `app/Services/Shopify` — Shopify GraphQL 連携
  - `app/Enums` — 状態は enum + モデルのメソッド経由のみ。`status` の直接代入を禁止
- ローカル起動: `docker compose up -d`

## 検証

- 起動: `docker compose up -d`(停止は `docker compose down`)
- 検証URL: http://localhost:8080/(管理画面は `/admin`、段階1以降)
- テスト: `docker compose exec app php artisan test --filter=<対象>`(全体は `php artisan test`)。
  **MySQL で実行する**(SQLite ではなく。照合順序・unique 制約の NULL 挙動に依存するため。
  `docs/detailed-design.md` 16.2)
- Shopify API を叩くテストは書かない。`Http::fake()` で必ず差し替える(`docs/design.md` 10章)
- 静的チェック: `docker compose exec app ./vendor/bin/pint --test`
- ログ: `storage/logs/laravel.log`(`docker compose exec app tail -f storage/logs/laravel.log`)
- 画面変更を伴う場合は実ブラウザで操作し、コンソールエラーとログの両方を確認する

## 触ってはいけないもの

- 本番/ステージング環境への直接適用、マイグレーションの本番適用、`.env` の本番値
- Shopify の実 API を叩くテスト(必ず `Http::fake()` で差し替える)
- 屋号・実ドメイン・実在の氏名などの、公開リポジトリに書けない情報
- シードデータに実在の個人情報を思わせる値を入れない(`docs/requirements.md` 7.2)
- 業務ロジックを `Actions` 以外(Filament のフォーム・ジョブ・コントローラ)に書くこと

## Claudeがやりがちなミス

(まだ空。訂正を受けたら `/fixmd` の実行を提案する)
