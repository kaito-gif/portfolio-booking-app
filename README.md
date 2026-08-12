# chanoka ワークショップ予約管理

副業ポートフォリオ用に構築した、ワークショップ予約管理システムです。
架空の日本茶ブランド「chanoka」が Shopify ストアで販売するワークショップ参加権を起点に、
Shopify が持たない予約業務（受付・電話予約・キャンセル・当日名簿）を担います。

このリポジトリはポートフォリオとして公開しているものであり、クライアント案件の成果物ではありません。
掲載しているブランド・商品・人物名はすべて架空のものです。

## デモ

デモ環境の URL・デモアカウント・システム構成・実装のポイントは、以下の公開ページに掲載しています。

**→ [chanoka ワークショップ予約管理 - 実装ポートフォリオ](https://impartial-astronaut-89c.notion.site/Shopify-3b032860c0bd81369c52f1fc7e587f78)**

デモ環境はデータと Shopify 在庫を毎日深夜に初期状態へ自動リセットするため、操作しても翌日には元に戻ります。

## システム構成

講座（ワークショップの種類）・開催枠（日時ごとの回）・予約（1件＝1席）の3層構造です。
予約経路は2つあります。

- **Shopify 経由**：参加権購入 → Webhook 受信 → 署名検証・重複排除 → 予約を自動登録 → 確定メール送信
- **電話・対面**：管理画面から手動登録。登録と同時に Shopify 側の在庫を1減らす

キャンセル時は Shopify の在庫を1戻します。定員超過の判定は持たず、Shopify の在庫を
売り切れ制御の唯一の正としているため、二重管理が発生しません。

## 技術スタック

- PHP 8.3 / Laravel 13 / Filament v5（管理画面）
- 本番: MariaDB 10.5（Xserver 共用レンタルサーバー）/ ローカル・CI: MySQL 8.0
- キュー・キャッシュ・セッション: すべて `database` ドライバ（Redis 不可の制約下）
- Shopify Admin GraphQL API（Webhook 受信・在庫調整）
- GitHub Actions（CI: テスト・Pint / CD: 承認を経た自動デプロイ）

## ディレクトリ構成

- `app/Actions` — 業務ロジックを集約。予約の作成・キャンセルはどの経路からもここだけを呼ぶ
- `app/Filament/Admin` — 管理画面（Resources / Pages / Widgets）
- `app/Services/Shopify` — Shopify GraphQL 連携（Webhook 受信・在庫調整）
- `app/Jobs` — Webhook からの注文取り込み・在庫調整・メール送信の非同期処理
- `app/Enums` — 状態は enum + モデルのメソッド経由でのみ遷移する
- `app/Policies` — 管理画面の操作権限、デモユーザー保護
- `docs/` — 要件定義書・非機能要件定義書・基本設計書・詳細設計書
- `.github/workflows` — CI/CD
- `scripts/deploy` — 本番サーバー上でのリリース処理

仕様の詳細は `docs/` 配下の4文書（要件 → 非機能要件 → 基本設計 → 詳細設計）を参照してください。

## ローカル環境での起動・検証

```bash
docker compose up -d
docker compose exec app php artisan migrate
```

- 動作確認: http://localhost:8080/ （管理画面は `/admin`）
- テスト: `docker compose exec app php artisan test`（MySQL 上で実行）
- 静的解析: `docker compose exec app ./vendor/bin/pint --test`

Shopify と連携する機能を試すには `.env` に `SHOPIFY_*` の実クレデンシャルが必要です
（未設定でもアプリ自体は起動し、Shopify 連携部分のみ動作しません）。
