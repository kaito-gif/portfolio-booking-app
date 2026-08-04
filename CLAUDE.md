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

- スタック: PHP 8.3 / Laravel 13 / Filament(最新安定版、管理画面) /
  本番は MariaDB 10.5(Xserver 標準)、ローカル・CI は MySQL 8.0(詳細設計 2.1)
- キュー・キャッシュ・セッション: すべて `database` ドライバ(Redis 不可。Xserver 共用サーバーの制約)
- 主要ディレクトリ(実装が進み次第この節を更新する):
  - `app/Actions` — 業務ロジックを集約。予約の作成・キャンセルは**どの経路からもここだけを呼ぶ**
    (Filament のフォーム・ジョブ・コントローラに業務ロジックを書かない)
  - `app/Filament/Admin/Resources` — 管理画面(Filament v5 はパネルごとに名前空間を切る。
    Resource本体とは別に `Schemas/`(フォーム)・`Tables/`(一覧)・`Pages/` に分割される)
  - `app/Contracts` — `InventoryServiceContract` など、段階をまたいで差し替える境界
  - `app/Services/Shopify` — Shopify GraphQL 連携。段階1時点では `FakeInventoryService` を
    `AppServiceProvider` で仮バインドしている(段階2で実装に差し替え。`docs/context.md` 参照)
  - `app/Enums` — 状態は enum + モデルのメソッド経由のみ。`status` の直接代入を禁止
    (モデル内の遷移メソッドでも `update()` ではなく `$this->status = ...; $this->save();`
    を使うこと。`status` は `#[Fillable]` に含めていないため `update()` は無視される)
  - `app/Exceptions` — ドメイン例外(`InvalidStateTransition` など。詳細設計 5.5)
  - `app/Policies` — Filament の Create/Edit/Delete(Bulk含む)の表示・可否を自動制御する
    (詳細設計11.1)。Workshop/Slot/Reservation のみ段階1で前倒し実装済み。
    User(デモユーザー保護)は段階5で追加予定(`docs/context.md` 参照)。
    一括削除の業務ルールは Table 側の `visible()` ではなく Policy の `delete()` に書き、
    `DeleteBulkAction::make()->authorizeIndividualRecords('delete')` で効かせる
 - `.github/workflows` — CI(テスト・Pint)と CD(自動デプロイ)。`docs/design.md` 11章
 - `scripts/deploy` — サーバー上で実行するリリース処理。手動・自動の両方から同じものを呼ぶ
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
 (本番反映は GitHub Actions の `production` 環境の承認を経た自動デプロイだけで行う。
 Claude が SSH で直接本番を操作したり、デプロイを起動したりしない)
- Shopify の実 API を叩くテスト(必ず `Http::fake()` で差し替える)
- 屋号・実ドメイン・実在の氏名などの、公開リポジトリに書けない情報
- シードデータに実在の個人情報を思わせる値を入れない(`docs/requirements.md` 7.2)
- 業務ロジックを `Actions` 以外(Filament のフォーム・ジョブ・コントローラ)に書くこと

## Claudeがやりがちなミス

- 「最新安定版を入れる」際にバージョン指定を省略・緩くすると、学習知識ベースの
  古いメジャーバージョンを引いてしまう。`composer require <pkg>` の前に
  `composer show -a <pkg>` 等で実際の最新メジャーを確認してから指定すること
  (2026-08-04、Filament を `^3.3` で入れてしまい v5 系と食い違った)
- モデルの状態遷移メソッド内で `$this->update(['status' => ...])` を使うと、
  `status` が `#[Fillable]` に含まれていない(直接代入を禁止する設計のため)ことで
  マスアサインメント保護に黙って弾かれ、何も変わらない。遷移メソッド内では
  `$this->status = ...; $this->save();` のように直接プロパティへ代入すること
  (2026-08-04)
- Carbon の `diffInSeconds()` は既定で符号付き(過去日時を渡すと負の値)を返す。
  経過秒数がほしいだけなら `diffInSeconds($other, absolute: true)` を明示すること
  (2026-08-04、`/health` の stale 判定で符号を見落として一度テストが落ちた)
