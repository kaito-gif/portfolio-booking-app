# 予約管理システム 詳細設計書

- 版: 1.3（2026-08-03 更新。上位文書のデプロイ自動化に追随）
- 対象: `docs/requirements.md` 版 1.9 / `docs/non-functional-requirements.md` 版 1.4 /
  `docs/design.md` 版 1.5

基本設計で「どう作るか」の方針は決まっている。ここでは**そのまま実装できる粒度**だけを書く。
上位文書と食い違ったときは要件 → 非機能 → 基本設計 → 本書の順で正とし、本書を直す。

---

## 1. この文書の使い方

本書は次の3つだけを扱う。

1. **決めないと手が止まるもの** — 型、制約、シグネチャ、分岐、設定キー
2. **忘れると事故になるもの** — トランザクション境界、冪等キー、補償処理
3. **後から思い出せないもの** — なぜその値なのか

逆に、Filament のフォーム部品の並びやCSSのような**書きながら決めればよいもの**は書かない。
書いても実装時に必ずズレるため、乖離した文書を残す不利益のほうが大きい。

---

## 2. 実行環境とバージョン

### 2.1 確定させる値

| 項目 | 値 | 備考 |
|---|---|---|
| PHP | 8.3 以上（Filament の要求に合わせる） | 段階0でサーバーパネルの設定を確認・変更 |
| Laravel | 採用時点の最新 LTS 系 | メジャー固定（NFR 8） |
| Filament | 採用時点の最新安定版 | 同上。要求 PHP を先に確認（設計 13） |
| MySQL | Xserver 標準（8.0 系） | utf8mb4 / utf8mb4_unicode_ci |
| Node | 22 系（CI でのビルド用のみ） | 本番には持ち込まない（設計 11.1） |

**バージョンは段階0で確定し、本書の本節を書き換える。** 未確定のまま段階1に進まない。
Filament の要求 PHP がサーバーパネルの選択肢に無い場合、その時点で管理画面の方式を
再検討する必要があり、それは段階1以降では取り返せないため。

### 2.2 ドライバ構成

```
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
MAIL_MAILER=smtp
```

Redis が使えない（NFR 2）。`jobs` / `failed_jobs` / `cache` / `sessions` テーブルを
標準のマイグレーションで作る。**`cache` テーブルだけは日次リセットの対象外**（NFR 6.3）。

---

## 3. データベース物理設計

### 3.1 共通規約

- テーブル名は複数形スネークケース、主キーは `id`（`bigIncrements`）
- 日時は `datetime` 型で **JST の壁時計時刻をそのまま保存**する（要件 6.3）。
  `config/app.php` の `timezone` を `Asia/Tokyo` に設定し、アプリ全体で統一する。
  UTC 保存にしないのは、多拠点・多言語を対象外にしており、
  **DB を直接見たときに日付が1日ずれて見える不利益のほうが大きい**ため
- 金額カラムは持たない（決済は対象外）
- 論理削除は使わない。予約は `status` で表現し、行は消さない
- 外部キーは張る。`onDelete` は既定（RESTRICT）とし、**親を消せないことを制約で示す**

### 3.2 `workshops`（講座）

| カラム | 型 | NULL | 既定 | 備考 |
|---|---|---|---|---|
| `id` | bigint unsigned | × | AI | |
| `name` | varchar(100) | × | | 講座名 |
| `description` | text | ○ | null | |
| `duration_minutes` | unsigned smallint | × | | 所要時間。開催終了時刻の算出に使う |
| `shopify_product_id` | varchar(64) | ○ | null | 参照用。連携には使わない |
| `is_active` | boolean | × | true | 開催枠作成時の選択肢の絞り込み用 |
| `created_at` / `updated_at` | timestamp | ○ | | |

`duration_minutes` は表示だけの項目ではない。**無断欠席の判定で「開催終了日時」が要る**
（要件 6.2）。終了日時カラムを持たず、`slots.starts_at + duration_minutes` で都度算出する。

### 3.3 `slots`（開催枠）

| カラム | 型 | NULL | 既定 | 備考 |
|---|---|---|---|---|
| `id` | bigint unsigned | × | AI | |
| `workshop_id` | bigint unsigned | × | | FK → `workshops.id` |
| `starts_at` | datetime | × | | JST |
| `capacity` | unsigned smallint | × | | 定員。売り切れ判定には使わない（要件 6.3） |
| `status` | varchar(20) | × | `draft` | `SlotStatus` |
| `shopify_variant_id` | varchar(64) | ○ | null | unique |
| `shopify_inventory_item_id` | varchar(64) | ○ | null | 保存時に解決（設計 5.4） |
| `note` | varchar(255) | ○ | null | 管理用メモ |
| `created_at` / `updated_at` | timestamp | ○ | | |

**インデックス**

| 名前 | 対象 | 用途 |
|---|---|---|
| `slots_variant_unique` | `shopify_variant_id` unique | 1バリアント1枠を DB で保証 |
| `slots_starts_at_index` | `starts_at` | 一覧の絞り込み・日次バッチ（NFR 3.2） |
| `slots_status_starts_at_index` | `status`, `starts_at` | `slots:close` / 在庫差分チェック |

`shopify_variant_id` を nullable にしているのは、**下書き段階では Shopify 側の商品が
まだ無い**ため。「受付中」への遷移時に必須とする（6.2 の遷移条件）。

### 3.4 `reservations`（予約）

| カラム | 型 | NULL | 既定 | 備考 |
|---|---|---|---|---|
| `id` | bigint unsigned | × | AI | |
| `slot_id` | bigint unsigned | × | | FK → `slots.id` |
| `code` | char(15) | × | | unique。`CHK-XXXXX-XXXXX` |
| `name` | varchar(50) | × | | |
| `email` | varchar(255) | × | | |
| `phone` | varchar(20) | ○ | null | 手動登録では必須（Shopify 経由では取れないことがある） |
| `status` | varchar(20) | × | `inventory_pending` | `ReservationStatus` |
| `source` | varchar(20) | × | | `shopify` / `manual` / `seed` |
| `shopify_order_id` | varchar(64) | ○ | null | |
| `shopify_line_item_id` | varchar(64) | ○ | null | |
| `seat_index` | unsigned tinyint | ○ | null | line item 内の何席目か（1始まり） |
| `checked_in_at` | datetime | ○ | null | |
| `cancelled_at` | datetime | ○ | null | |
| `cancelled_by` | varchar(20) | ○ | null | `customer` / `staff` / `system` |
| `created_at` / `updated_at` | timestamp | ○ | | |

**インデックス**

| 名前 | 対象 | 用途 |
|---|---|---|
| `reservations_code_unique` | `code` unique | 照会 |
| `reservations_order_line_seat_unique` | `shopify_order_id`, `shopify_line_item_id`, `seat_index` unique | 冪等性（設計 5.3） |
| `reservations_email_index` | `email` | 一覧の部分一致（前方一致で効く） |
| `reservations_slot_status_index` | `slot_id`, `status` | 確定数の集計・当日リスト |
| `reservations_status_index` | `status` | 無断欠席バッチ |

**MySQL の unique は NULL を重複とみなさない。** 手動登録では Shopify 系3カラムが
すべて NULL になるため、この制約に引っかからない。これは意図した挙動で、
**Shopify 経由の予約にだけ冪等性の歯止めをかける**ことになる。

`source` を持つのは、在庫差分の原因を切り分けるため。手動登録だけがズレている場合と
Webhook 経由がズレている場合とで、疑う場所が変わる。

### 3.5 `users`

標準の `users` に次を追加する。

| カラム | 型 | NULL | 既定 | 備考 |
|---|---|---|---|---|
| `role` | varchar(20) | × | `staff` | `UserRole` |
| `is_demo` | boolean | × | false | 保護・レート制限緩和の対象（要件 7.4 / NFR 5.1） |

`email_verified_at` は使わない（招待ではなく管理者が直接作るため）。

### 3.6 `webhook_events`

| カラム | 型 | NULL | 既定 | 備考 |
|---|---|---|---|---|
| `id` | bigint unsigned | × | AI | |
| `webhook_id` | varchar(64) | × | | unique。`X-Shopify-Webhook-Id` |
| `topic` | varchar(50) | × | | `orders/create` |
| `shopify_order_id` | varchar(64) | ○ | null | 一覧での突き合わせ用 |
| `payload` | longtext | ○ | null | 受信 raw body。90日で NULL 化 |
| `status` | varchar(20) | × | `received` | `WebhookStatus` |
| `attempts` | unsigned tinyint | × | 0 | |
| `next_attempt_at` | datetime | ○ | null | 表示用。実際の再試行はキューが持つ |
| `failure_reason` | text | ○ | null | |
| `processed_at` | datetime | ○ | null | |
| `received_at` | datetime | × | | |
| `created_at` / `updated_at` | timestamp | ○ | | |

**インデックス**: `webhook_id` unique、`status`（ダッシュボードの失敗件数）、`received_at`（`logs:prune`）。

`payload` を `longtext` にしているのは、注文 JSON が数十KBになり得るため。
`json` 型にしないのは、**検索も部分更新もせず、90日後に丸ごと NULL にするだけ**だから。

### 3.7 `mail_logs`

| カラム | 型 | NULL | 既定 | 備考 |
|---|---|---|---|---|
| `id` | bigint unsigned | × | AI | |
| `reservation_id` | bigint unsigned | ○ | null | 代表の予約。FK は張らない（下記） |
| `related_reservation_ids` | json | ○ | null | 注文単位メールの全予約ID |
| `type` | varchar(30) | × | | `confirmed` / `reminder` / `cancelled` |
| `to` | varchar(255) | ○ | | 90日で NULL 化 |
| `subject` | varchar(255) | × | | |
| `body` | longtext | ○ | null | 90日で NULL 化 |
| `status` | varchar(20) | × | `queued` | `queued` / `sent` / `failed` |
| `attempts` | unsigned tinyint | × | 0 | |
| `sent_at` | datetime | ○ | null | |
| `last_error` | text | ○ | null | |
| `created_at` / `updated_at` | timestamp | ○ | | |

**`reservation_id` に外部キーを張らない。** `mail_logs` は日次リセットの対象外で、
`reservations` は毎晩消える（NFR 5.2）。FK があるとリセット時に削除できず、
`ON DELETE SET NULL` にすると翌朝には全行の紐付けが消えて履歴の意味がなくなる。
**予約番号を `subject`・`body` に含めた状態で残す**ことで追跡できる。

`related_reservation_ids` は設計 6.1 への追加。注文単位で1通送る仕様（要件 4.1）のため、
1通が複数予約に対応する。代表1件だけでは「この注文の何席分か」が履歴から読めない。

### 3.8 `audit_logs`

| カラム | 型 | NULL | 既定 | 備考 |
|---|---|---|---|---|
| `id` | bigint unsigned | × | AI | |
| `user_id` | bigint unsigned | ○ | null | システム実行時は NULL。FK は張らない（3.7 と同じ理由） |
| `actor_label` | varchar(100) | × | | `山田（staff）` / `system:demo:reset` など |
| `action` | varchar(50) | × | | 5.6 の一覧 |
| `auditable_type` | varchar(50) | ○ | null | `Reservation` など短縮名 |
| `auditable_id` | bigint unsigned | ○ | null | |
| `changes` | json | ○ | null | `{"before":{...},"after":{...}}` |
| `ip_address` | varchar(45) | ○ | null | IPv6 を考慮して 45 |
| `created_at` | timestamp | × | | `updated_at` は持たない（追記専用） |

**インデックス**: `action`、`created_at`、`(auditable_type, auditable_id)`。

`actor_label` を文字列で持つのは、`users` がリセットで消えた後も
**誰がやったのかを読める形で残す**ため。`user_id` だけだと翌朝には追えない。

### 3.9 マイグレーションの順序

```
1. users への role / is_demo 追加
2. workshops
3. slots            （workshops に依存）
4. reservations     （slots に依存）
5. webhook_events
6. mail_logs
7. audit_logs
```

以降の変更は**カラム追加とインデックス追加のみ**（NFR 7.2）。

---

## 4. Enum とモデル

### 4.1 Enum 定義

すべて backed enum（string）とし、`label(): string` で日本語表示名を返す。
Filament の選択肢はこのメソッドから生成する。**表示名を Blade やフォームに直書きしない。**

```php
enum SlotStatus: string {
    case Draft = 'draft';          // 下書き
    case Open = 'open';            // 受付中
    case Closed = 'closed';        // 締切
    case Cancelled = 'cancelled';  // 中止
    case Completed = 'completed';  // 開催済み
}

enum ReservationStatus: string {
    case InventoryPending = 'inventory_pending'; // 在庫確保待ち
    case Confirmed = 'confirmed';                // 確定
    case Cancelled = 'cancelled';                // キャンセル済み
    case Attended = 'attended';                  // 参加済み
    case NoShow = 'no_show';                     // 無断欠席
}

enum UserRole: string { case Staff = 'staff'; case Admin = 'admin'; }

enum WebhookStatus: string {
    case Received = 'received';     // 受信済み・未処理
    case Processing = 'processing';
    case Processed = 'processed';   // 予約作成まで完了
    case Skipped = 'skipped';       // 対象外（物販のみ等）。異常ではない
    case Failed = 'failed';         // リトライ上限まで失敗
}
```

`Skipped` を `Processed` と分けるのは、**ダッシュボードの失敗件数に混ぜないため**。
物販だけの注文が届くたびに要確認件数が増えると、通知が意味を失う。

### 4.2 状態遷移

`status` への直接代入を禁止し（NFR 8）、遷移はモデルのメソッドだけを入口にする。
不正な遷移は `InvalidStateTransition` 例外を投げる。

**`SlotStatus`**

| From | To | 入口 | 条件 |
|---|---|---|---|
| Draft | Open | `Slot::open()` | `shopify_variant_id` と `shopify_inventory_item_id` が解決済み |
| Open | Closed | `Slot::close()` | — |
| Draft / Open / Closed | Cancelled | `Slot::cancel()` | — |
| Closed | Completed | `Slot::complete()` | 開催終了日時を過ぎている |
| Open | Completed | — | **不可**（必ず Closed を経由する） |

Open から Completed への直行を塞ぐのは、**締切を経ずに開催済みになると
その間に届いた Webhook の扱いが未定義になる**ため。`slots:close` は
`slots:complete` より前（0:05 と 0:30）に走らせて順序を保証する。

**`ReservationStatus`**

| From | To | 入口 |
|---|---|---|
| InventoryPending | Confirmed | `Reservation::confirm()` |
| InventoryPending | Cancelled | `Reservation::cancel()`（在庫確保失敗時の後始末） |
| Confirmed | Cancelled | `Reservation::cancel()` |
| Confirmed | Attended | `Reservation::checkIn()` |
| Confirmed | NoShow | `Reservation::markNoShow()` |
| Attended / Cancelled / NoShow | — | 終端 |

**キャンセル済みからの復帰を用意しない。** 戻せるようにすると在庫を再度押さえる
必要があり、失敗時の分岐が増える。誤操作時は新規に登録し直す運用とする。

### 4.3 モデルの主なメソッド

| モデル | メソッド | 内容 |
|---|---|---|
| `Slot` | `endsAt(): CarbonImmutable` | `starts_at + workshop.duration_minutes` |
| | `confirmedCount(): int` | `status IN (confirmed, attended, no_show)` の件数 |
| | `isBookable(): bool` | `status === Open` |
| | `cancelDeadline(): CarbonImmutable` | 開催日前日 23:59:59（要件 4.3） |
| `Reservation` | `isCancellableByCustomer(): bool` | `Confirmed` かつ `now <= slot->cancelDeadline()` |
| | `lookupUrl(): string` | 予約番号を prefill した C-1 の URL |
| `User` | `isAdmin(): bool` / `isDemo(): bool` | |

**`confirmedCount()` に `attended` と `no_show` を含める。** 在庫差分の計算式
`Shopify在庫 + 確定予約数 = 定員`（設計 4.3）は、開催後も成立していなければならない。
参加済みになった瞬間に席が空いた扱いになると、開催翌日から全枠が差分として並ぶ。

---

## 5. Actions（業務ロジック）

### 5.1 `CreateReservation`

```php
final class CreateReservation
{
    /**
     * @throws SlotNotBookableException  枠が受付中でない
     * @throws InventoryUnavailableException 在庫確保に失敗（$reserveInventory=true 時のみ）
     */
    public function execute(CreateReservationData $data): Reservation;
}

final class CreateReservationData
{
    public function __construct(
        public readonly Slot $slot,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly ReservationSource $source,
        public readonly bool $reserveInventory,     // 手動登録のみ true
        public readonly ?string $shopifyOrderId = null,
        public readonly ?string $shopifyLineItemId = null,
        public readonly ?int $seatIndex = null,
        public readonly bool $sendMail = true,      // 注文単位でまとめる場合は false
    ) {}
}
```

**処理手順**

```
1. $slot->isBookable() を検査。false なら SlotNotBookableException
2. トランザクション開始
     2-1. 予約番号を採番（6.1）
     2-2. status = inventory_pending で行を作る
     2-3. 一意制約違反（QueryException 1062）は「既に作成済み」とみなし、
          既存行を返してトランザクションを閉じる（冪等）
   コミット
3. $reserveInventory === false のとき
     3-1. $reservation->confirm()
     3-2. audit_logs に reservation.created
     3-3. $sendMail なら SendReservationMail を dispatch
     3-4. return
4. $reserveInventory === true のとき（手動登録・同期）
     4-1. InventoryService::adjust($slot, -1) を同期実行
          失敗 → 予約行を削除し InventoryUnavailableException（要件 5.2）
     4-2. 成功 → confirm() を試みる
          ここで失敗 → AdjustShopifyInventory(+1) を priority キューに積み、
                       audit_logs に inventory.compensated を記録して再送出（設計 4.1）
     4-3. audit_logs に reservation.created / inventory.decremented（前後値つき）
     4-4. $sendMail なら SendReservationMail を dispatch
```

**手順3で在庫を触らない理由**は設計3のとおり（Shopify が購入時点で減らしている）。
**手順2-3で一意制約違反を成功扱いにする**のは、Webhook が二重に届いた場合に
例外ではなく静かに収束させるため。ここを例外にすると `webhook_events` が
`failed` に落ち、運用が「異常ではない再送」を毎回確認することになる。

### 5.2 `CancelReservation`

```php
final class CancelReservation
{
    /** @throws ReservationNotCancellableException */
    public function execute(
        Reservation $reservation,
        CancelledBy $by,        // customer / staff / system
        ?User $actor = null,
        bool $restoreInventory = true,   // system rollback では false
        bool $sendCancelledMail = true,  // system rollback では false
    ): Reservation;
}
```

**処理手順**

```
1. 権限と期限の検査
     - $by === customer のとき $reservation->isCancellableByCustomer() が必須
     - $by === staff のとき status === Confirmed のみ（期限は問わない・要件 4.3）
     - $by === system は**内部ロールバック専用モード**
       （Webhook 取り込み中の巻き戻し。顧客導線では使わない）
2. トランザクション開始
     2-1. cancel()。cancelled_at / cancelled_by を記録
     2-2. audit_logs に reservation.cancelled
   コミット（顧客にはここで完了を返す）
3. $restoreInventory=true かつ status が confirmed だった場合のみ
   AdjustShopifyInventory(+1) を dispatch
   （inventory_pending からのキャンセルは在庫を押さえていないので積まない）
4. $sendCancelledMail=true の場合のみ SendReservationMail(cancelled) を dispatch
```

**手順3の条件を落とすと在庫が増え続ける。** 手動登録の在庫確保に失敗した予約は
在庫を減らしていないため、戻してはいけない。ここは 5.1 の 4-1 と対になっている。

**Webhook 取り込み中の内部ロールバック**（line item の all-or-nothing）では、
`CancelReservation` を `by=system, restoreInventory=false, sendCancelledMail=false` で呼ぶ。
このケースは顧客に見えるキャンセルではなく、失敗した作成処理の巻き戻しであるため。

### 5.3 `ImportOrderReservations`

```php
final class ImportOrderReservations
{
    /** @return ImportResult 作成した予約・スキップ理由・失敗理由 */
    public function execute(array $orderPayload): ImportResult;
}
```

**処理手順**

```
1. payload.line_items を走査
2. 各 line item について
     2-0. `line_item.id` が無い場合は failed（仕様違反）として記録し、
          この line item の処理を打ち切る
     2-1. variant_id に対応する slot を引く。無ければ skip（理由: 物販/未登録）
     2-2. slot->isBookable() が false なら skip（理由: 締切/中止/下書き）
     2-3. quantity 回、seat_index = 1..quantity で CreateReservation を呼ぶ
          （reserveInventory=false, sendMail=false）
     2-4. 途中で例外が出たら、この line item で作成済みの予約を
          CancelReservation(system, restoreInventory=false, sendCancelledMail=false)
          で取り消し、この line item を failed に記録
3. 1件でも予約を作れた かつ failed が無い → 確定メールを1通 dispatch（全予約番号を列挙）
4. 戻り値
     - 作成 > 0 かつ failed = 0        → WebhookStatus::Processed
     - 作成 = 0 かつ failed = 0        → WebhookStatus::Skipped（理由を連結）
     - failed > 0                      → WebhookStatus::Failed（ジョブを再試行させる）
```

**line item 単位で all-or-nothing にする**（設計 5.2）。数量3のうち2件だけ作られた状態を
残すと、在庫と予約数が合わないうえ、再実行しても残り1件だけを作る判定ができない。

`line_item.id` は冪等性制約の構成要素（`shopify_line_item_id`）として必須とし、
**欠損は「起こり得ない入力」ではなく「仕様違反の失敗」**として扱う。欠損時に
処理を続けると、DB ユニーク制約が効かない経路を作ってしまうため。

### 5.4 `CheckInReservation`

当日リストからのチェックイン。`Confirmed → Attended` に遷移し、`checked_in_at` を記録、
`audit_logs` に `reservation.checked_in` を残す。取り消し（誤チェックイン）は
`Attended → Confirmed` を管理者のみに許可する。**この1本だけ終端からの復帰を認める**のは、
当日現場で押し間違いが起きるうえ、在庫に影響しないため。

### 5.5 例外一覧

| 例外 | 発生元 | 呼び出し側の扱い |
|---|---|---|
| `SlotNotBookableException` | `CreateReservation` | 画面: バリデーションエラー / Webhook: skip |
| `InventoryUnavailableException` | `CreateReservation` | 画面: エラー表示。予約は作られない |
| `ReservationNotCancellableException` | `CancelReservation` | 画面: 期限切れの案内 |
| `InvalidStateTransition` | モデル | **握らない。** バグなので 500 で落とす |
| `ShopifyApiException` | `ShopifyClient` | 同期呼び出しなら上位へ / ジョブならリトライ |

`InvalidStateTransition` だけは捕捉しない。**握ると不正な遷移が静かに通る**ため、
落として気づけるようにする。

### 5.6 `audit_logs.action` の一覧

| action | 記録する内容（`changes`） |
|---|---|
| `auth.login_succeeded` / `auth.login_failed` | `{"email":"..."}`（パスワードは書かない） |
| `reservation.created` | 作成後の主要項目 |
| `reservation.updated` | 変更前後の差分のみ（氏名・メール・電話はマスク） |
| `reservation.cancelled` | `{"by":"customer"}` |
| `reservation.checked_in` / `reservation.check_in_reverted` | |
| `inventory.decremented` / `inventory.incremented` | `{"before":8,"after":7,"slot_id":3}` |
| `inventory.compensated` | 補償実行（設計 4.1） |
| `inventory.reset` | `demo:reset` による上書き |
| `user.role_changed` / `user.created` / `user.deleted` | |
| `webhook.retried_manually` | |
| `mail.resent_manually` | |

**在庫系は必ず前後の値を入れる**（NFR 6.1）。ズレの追跡はここしか手がかりがない。

**`audit_logs` の PII 方針は「保存しない」で確定。**
`changes` には氏名・メール・電話・メール本文を入れず、必要な場合は
予約ID・スロットID・状態・件数のみを残す。識別が必要なメールアドレスは
`sha256(lowercase(email))` のハッシュ値で代替する。

---

## 6. 予約番号

### 6.1 生成

```
形式: CHK-XXXXX-XXXXX（15文字固定）
文字種: 23456789ABCDEFGHJKMNPQRSTVWXYZ（31文字）
        0/1 と I/O/U/L を除外（電話での読み上げ・目視の取り違え防止・設計 6.3）
組み合わせ: 31^10 ≒ 8.2 × 10^14
```

`random_int()` を使う（`rand()` は使わない）。生成 → INSERT を試み、
一意制約違反なら再生成し、**最大5回まで**。5回失敗したら例外で落とす。
事前に SELECT で存在確認しない。競合が残るうえ、クエリが増えるだけのため。

### 6.2 使用箇所

- 顧客照会（C-1 の入力値）
- 確定・リマインド・キャンセルの各メール本文
- CSV 出力・当日リスト
- 照会画面のレート制限キー（8.3）

---

## 7. Shopify 連携

### 7.1 `ShopifyClient`

```php
final class ShopifyClient
{
    /** @throws ShopifyApiException */
    public function graphql(string $query, array $variables = []): array;
}
```

- エンドポイント: `https://{shop}.myshopify.com/admin/api/{version}/graphql.json`
- ヘッダ: `X-Shopify-Access-Token`
- タイムアウト: 接続5秒 / 応答10秒
- リトライ: HTTP 429 と 5xx のみ、`Retry-After` を尊重して最大3回（同期呼び出し時）
- **`userErrors` が空でない応答は例外にする。** GraphQL は HTTP 200 で
  業務エラーを返すため、ステータスコードだけ見ていると失敗を成功と誤認する
- ログ: リクエストの query 名と変数のうち ID のみ。**トークンは出さない**（NFR 6.2）

### 7.2 `InventoryService`

```php
final class InventoryService
{
    /** バリアントから inventory item ID を解決（枠の保存時に1度だけ） */
    public function resolveInventoryItemId(string $variantId): string;

    /** 在庫を delta 分だけ増減する。戻り値は変更後の available */
    public function adjust(Slot $slot, int $delta, string $reason): int;

    /** 在庫を絶対値で上書きする（demo:reset 専用） */
    public function set(Slot $slot, int $quantity): void;

    /** 複数枠の available をまとめて取得（在庫差分チェック用） */
    public function fetchAvailable(Collection $slots): array; // [slotId => int]
}
```

**GraphQL の使い分け**

| メソッド | mutation / query | 備考 |
|---|---|---|
| `resolveInventoryItemId` | `query { productVariant(id:) { inventoryItem { id } } }` | 枠の保存時のみ |
| `adjust` | `inventoryAdjustQuantities`（`name: "available"`, `reason: "correction"`） | delta 指定。**現在値を読んでから書かない**（競合するため） |
| `set` | `inventorySetQuantities`（`ignoreCompareQuantity: true`） | リセット時のみ絶対値で上書き |
| `fetchAvailable` | `query { nodes(ids: [...]) { ... on InventoryItem { inventoryLevel(locationId:) { quantities(names:["available"]) { quantity } } } } }` | **1回50件までまとめる** |

`adjust` で delta を使うのは、read-modify-write にすると
**手動登録と Webhook が同時に走ったときに片方の更新が消える**ため。
`set` を `demo:reset` に限定するのも同じ理由で、絶対値での書き込みは
他の更新を踏み潰す操作だと明示しておく。

`fetchAvailable` をまとめるのは、開催枠数 × 96 回/日の呼び出しを
実質「バッチ回数 × 数回」に落とすため（NFR 6.3 の見積もりの根拠）。

### 7.3 Webhook 受信

**ミドルウェア `VerifyShopifyWebhook`**

```
1. $request->getContent() で raw body を取得（パース前・設計 5.1）
2. hash_hmac('sha256', $rawBody, $secret, true) を base64 で符号化
3. hash_equals() で X-Shopify-Hmac-Sha256 と比較。不一致は 401（本文なし）
4. X-Shopify-Webhook-Id / X-Shopify-Topic が欠けていれば 400
```

**`csrf` から除外**し、`throttle` も掛けない。Shopify の再送を弾くと復旧できなくなる。

**コントローラ `ShopifyOrderController::ordersCreate`**

```
1. webhook_events に status=received で INSERT
     - webhook_id の一意制約違反 → 既受信。何もせず 200
     - payload に `line_items[*].id` が無い場合でもこの時点では弾かない
       （受信は成功として記録し、ジョブ側で failed に落とす）
2. ProcessShopifyOrder を dispatch
3. 200 を返す（ここまでを最短で通す。要件の5秒以内）
```

**この段階で payload の中身を検査しない。** 検査を入れるほど 5 秒に近づく。
不正な payload はジョブ側で `failed` にすればよい。

### 7.4 設定値

| キー | 環境変数 | 備考 |
|---|---|---|
| `services.shopify.shop_domain` | `SHOPIFY_SHOP_DOMAIN` | |
| `services.shopify.access_token` | `SHOPIFY_ACCESS_TOKEN` | ログ・リポジトリに出さない |
| `services.shopify.webhook_secret` | `SHOPIFY_WEBHOOK_SECRET` | |
| `services.shopify.api_version` | `SHOPIFY_API_VERSION` | 例 `2026-01`。**ハードコードしない** |
| `services.shopify.location_id` | `SHOPIFY_LOCATION_ID` | 単一ロケーション（要件 5.5） |

起動時に未設定を検知したいので、`config` から読む箇所で `?? throw` はせず、
**段階0で `/health` に設定値の有無（値そのものは返さない）を含めて確認する**。

---

## 8. ジョブ

| ジョブ | queue | tries | backoff（秒） | timeout | 失敗時 |
|---|---|---|---|---|---|
| `ProcessShopifyOrder` | `default` | 5 | 60, 300, 900, 1800, 3600 | 60 | `webhook_events.status=failed` + 管理者へ通知 |
| `AdjustShopifyInventory` | `priority` | 5 | 60, 300, 900, 1800, 3600 | 30 | `audit_logs` に記録 + 通知 |
| `SendReservationMail` | `default` | 3 | 60, 300, 900 | 30 | `mail_logs.status=failed` + 通知 |

`queue:work` は `--queue=priority,default` で起動し、**補償の在庫戻しを先に流す**。

### 8.1 共通の作法

- すべて `ShouldQueue`。コンストラクタには**モデルではなく ID を渡す**
  （`SerializesModels` は日次リセットで消えた行を復元できず、失敗ジョブの再実行が壊れる）
- 実行冒頭で対象行の存在を確認し、無ければ**成功として終える**（リセット後の残骸対策）
- `failed(Throwable $e)` で状態更新と通知を行う。**通知はここ1箇所に集約する**
- `WithoutOverlapping` は使わない。キュー実行自体が `withoutOverlapping()` の
  スケジューラ配下で直列化されているため（設計 9）

### 8.2 `ProcessShopifyOrder`

```
handle():
  1. webhook_events を取得。status が processed/skipped なら即 return（冪等）
  2. status=processing、attempts++、next_attempt_at を更新
  3. payload を json_decode。失敗なら failure_reason に記録して failed（再試行しない）
  4. ImportOrderReservations を実行
  5. 戻り値の status を webhook_events に反映、processed_at を記録
     Failed のときは例外を再送出してキューに再試行させる
```

手順1の再入判定を入れないと、**手動再実行で予約が二重に作られる**。
`reservations` の一意制約が最後の砦だが、そこに頼ると `seat_index` が
ずれた瞬間に破れるため、状態でも塞ぐ。

### 8.3 `AdjustShopifyInventory`

```
handle(int $slotId, int $delta, string $reason, ?int $reservationId):
  1. slot を取得。無ければ return（リセット後）
  2. InventoryService::adjust($slot, $delta, $reason)
  3. audit_logs に inventory.incremented / decremented（前後値つき）
```

**このジョブは冪等でない。** 同じジョブが2回実行されると在庫が2回動く。
キューの再試行は例外時のみで、成功後の再実行経路（手動再実行）は
`failed_jobs` からのみとし、**成功したジョブを再実行できる導線を作らない**。

### 8.4 `SendReservationMail`

```
handle(string $type, array $reservationIds, string $to):
  1. mail_logs に status=queued で行を作る（初回のみ。再試行時は既存行を更新）
  2. Mailable をレンダリングし body を保存
  3. Mail::send。成功で status=sent / sent_at
  4. 例外時は attempts++ と last_error を保存して再送出
```

**本文を保存してから送る。** 送信後に保存すると、送信は成功したのに
プレビューが空という状態が起き得る。デモでは**画面で見せられること**が
実送信より重要（設計7）なので、保存を先に置く。

---

## 9. HTTP ルート

| メソッド | パス | 名前 | ミドルウェア | 用途 |
|---|---|---|---|---|
| GET | `/` | `home` | — | `/admin` へリダイレクト |
| GET | `/health` | `health` | — | 死活監視（NFR 6.4） |
| GET | `/r/lookup` | `lookup.form` | — | C-1。`?code=` で prefill |
| POST | `/r/lookup` | `lookup.submit` | `throttle:lookup` | 照会実行 |
| GET | `/r/{reservation}` | `reservation.show` | `signed` | C-2 |
| POST | `/r/{reservation}/cancel` | `reservation.cancel` | `signed` | キャンセル実行 |
| POST | `/webhooks/shopify/orders-create` | `webhook.orders` | `shopify.hmac`（CSRF 除外） | 受信 |
| — | `/admin/*` | — | Filament | 管理画面 |

**C-2 を署名付き URL にする。** 照会成功時に
`URL::temporarySignedRoute('reservation.show', now()->addMinutes(30), [...])` へ
リダイレクトする。セッションに持たせないのは、共用端末での閲覧が残るため。
連番でない予約番号（要件 6.3）と署名の二重で、URL を知られただけでは開けない。

**メール本文には署名付き URL を載せない。** 30分で切れるものをメールに書くと、
翌日読んだ顧客が必ず失敗する。メールには `/r/lookup?code=CHK-...` を載せ、
**メールアドレスの入力を1回挟む**。

### 9.1 `/health` の応答

```json
{ "status": "ok", "schedule_last_run_at": "2026-08-03T09:04:00+09:00", "lag_seconds": 42 }
```

- `cache` に保存した `schedule.last_run_at` を読む（NFR 6.4）
- 最終実行から **600 秒**を超えていたら `status: "stale"` で **503**
- DB 接続に失敗したら 503
- **個人情報・バージョン・設定値そのものは返さない**（NFR 6.4）

---

## 10. 顧客画面（C-1 / C-2）

### 10.1 入力とバリデーション

**C-1 予約照会**

| 項目 | ルール | エラー文言 |
|---|---|---|
| `code` | `required`, `regex:/\A[A-Z0-9]{3}-[A-Z0-9]{5}-[A-Z0-9]{5}\z/` | 予約番号の形式が正しくありません |
| `email` | `required`, `email:rfc`, `max:255` | メールアドレスの形式が正しくありません |

入力値は**大文字化と全角→半角の正規化を先に通す**（`FormRequest::prepareForValidation`）。
電話で聞いた番号を手で打つ画面なので、大小と全角で弾くと問い合わせが増える。

**照会失敗時の応答（設計 8.3）**

```
1. 形式エラーは通常のバリデーションエラーとして返す（存在の有無は漏れない）
2. 形式が正しい場合、code で検索し email を hash_equals で比較
3. 不一致・不存在のいずれでも
     - 同一の文言「予約番号またはメールアドレスが一致しません」
     - 応答時間を揃えるため、不存在時も usleep でダミーの比較時間を挟む
     - レート制限は成功・失敗を問わず消費する
```

**C-2 のキャンセル**

| 条件 | 挙動 |
|---|---|
| 期限内・`Confirmed` | 確認ダイアログ（`<form>` の二段階）→ 実行 |
| 期限切れ | ボタンを出さず、「前日23:59を過ぎたため、お電話でご連絡ください」と表示 |
| 既にキャンセル済み | 状態を表示するのみ |

### 10.2 アクセシビリティ実装（Lighthouse 100 が要件）

- すべての入力に `<label for>`。プレースホルダをラベル代わりにしない
- エラーは `aria-describedby` で入力に紐づけ、サマリを `role="alert"` で先頭に置く
- 送信後は結果見出し（`<h1>` または `tabindex="-1"` の要素）へフォーカスを移す
- コントラスト比 4.5:1 以上。**Tailwind の `text-gray-400` を本文に使わない**
- `<html lang="ja">`、ページごとに固有の `<title>`
- キャンセルボタンは `<button>`。`<a>` に `onclick` を付けない

---

## 11. 管理画面（Filament）

### 11.1 認可

| 対象 | staff | admin |
|---|---|---|
| 予約の閲覧・作成・修正・キャンセル・チェックイン | ○ | ○ |
| 当日リスト・CSV 出力 | ○ | ○ |
| Webhook イベント・メール履歴の閲覧と再実行 | ○ | ○ |
| 講座・開催枠の作成・編集 | × | ○ |
| ユーザー管理 | × | ○ |
| 期限切れ予約のキャンセル | × | ○ |

Policy を必ず作り、**ナビゲーションの非表示と両方で塞ぐ**（NFR 5.1）。

**デモユーザーの保護**（要件 7.4）は `UserPolicy` で
`update` / `delete` / `updatePassword` / `changeRole` を `is_demo` で拒否し、
`UserResource` 側でも該当行のアクションを非表示にする。

### 11.2 `ReservationResource`

**テーブル列**: 予約番号 / 講座 / 開催日時 / 氏名 / メール / 電話 / 状態 / 経路 / チェックイン

**フィルタ**（要件 5.2）

| フィルタ | 実装 |
|---|---|
| 開催日 | 日付範囲（`slots.starts_at`） |
| 講座 | `SelectFilter`（`workshop_id`） |
| 予約状態 | `SelectFilter`（複数選択） |
| 氏名・メール・予約番号 | 検索ボックスの部分一致（`searchable`） |

**ページング 50 件固定。** N+1 を避けるため `with(['slot.workshop'])` を既定にする。

**CSV 出力**: ヘッダ行 + 表示中の絞り込みを引き継ぐ。UTF-8 **BOM 付き**、改行は CRLF。

```
予約番号, 講座名, 開催日時, 氏名, メールアドレス, 電話番号, 状態, 経路, 予約日時, チェックイン日時
```

`fputcsv` に渡す前に、`=` `+` `-` `@` で始まる値の先頭に `'` を付ける。
**Excel で開いた際の数式インジェクションを防ぐ**ため。氏名やメモに `=` が入る余地がある。

**フォーム**（A-4 手動登録）

| 項目 | ルール |
|---|---|
| 開催枠 | 必須。**`status = open` の枠のみ選択肢に出す** |
| 氏名 | 必須、最大50 |
| メール | 必須、`email:rfc`、最大255 |
| 電話 | 必須、`regex:/\A[0-9+\-]{10,20}\z/` |

保存は `CreateReservation`（`reserveInventory: true`）を呼ぶだけにする。
**Filament のフォーム内に在庫処理を書かない**（設計1）。
`InventoryUnavailableException` は
「Shopify の在庫を確保できませんでした。予約は登録されていません」として画面に返す。

### 11.3 `SlotResource`

- 一覧: 開催日時 / 講座 / 定員 / 確定数 / 状態 / バリアントID
- **確定数はサブクエリ（`withCount`）で取る。** 一覧で `confirmedCount()` を呼ぶと N+1 になる
- 保存時、`shopify_variant_id` が変わったら `resolveInventoryItemId` を呼び直す
- 「受付中にする」アクションは、inventory item が解決済みのときだけ有効（4.2）
- 削除は**予約が1件も無い枠のみ**許可する（FK が RESTRICT なので DB でも防がれる）

### 11.4 `DailyRoster`（A-5 当日リスト）

- 日付を選ぶと、その日の開催枠を開始時刻順に並べる
- 枠ごとに 氏名 / 電話 / 予約番号 / チェックイン欄
- チェックインはインライン操作（`CheckInReservation` を呼ぶ）
- 印刷用 CSS（`@media print`）でナビゲーションとボタンを消し、枠ごとに改ページ
- 既定の対象日は「今日」。**未来日も選べる**（前日に印刷する運用があるため）

### 11.5 `WebhookEvents` / `MailLogs`

| ページ | 表示 | アクション |
|---|---|---|
| `WebhookEvents` | 受信日時 / topic / 注文ID / 状態 / 試行回数 / 失敗理由 | 手動再実行（`failed` のみ）、payload 表示（整形済み） |
| `MailLogs` | 送信日時 / 種別 / 宛先 / 件名 / 状態 / エラー | 本文プレビュー、再送（`failed` のみ） |

手動再実行はいずれも `audit_logs` に記録する。**`failed` 以外に再実行ボタンを出さない**
（8.3 のとおり、成功済みの在庫操作を再実行すると在庫が壊れる）。

### 11.6 ダッシュボードのウィジェット

| ウィジェット | クエリ | 閾値表示 |
|---|---|---|
| `TodayReservations` | 本日作成された予約数 / 本日開催の確定数 | — |
| `UpcomingSlots` | 直近7日の開催枠と `確定数 / 定員` | 満席を強調 |
| `WebhookFailures` | `webhook_events.status = failed` + `failed_jobs` の件数 | 1件以上で赤（NFR 6.3） |
| `InventoryDrift` | 7.2 の `fetchAvailable` の結果と突き合わせ | 1件以上で赤 |

**`InventoryDrift` は画面表示のたびに Shopify を叩かない。** `inventory:check`（15分ごと）が
結果を `cache` に書き、ウィジェットはそれを読んで**最終確認時刻とあわせて表示**する。
画面を開くたびに API を呼ぶと、複数人がダッシュボードを開いたときにレート制限に触れる。

---

## 12. メール

| 種別 | 件名 | 本文の要素 |
|---|---|---|
| `confirmed` | 【chanoka】ワークショップのご予約を承りました | 講座名・開催日時・会場案内・**予約番号（複数可）**・照会URL・キャンセル期限・返金は別対応である旨 |
| `reminder` | 【chanoka】明日のワークショップのご案内 | 講座名・開催日時・予約番号・照会URL・持ち物 |
| `cancelled` | 【chanoka】ご予約のキャンセルを承りました | 講座名・開催日時・予約番号・**返金は別途対応する旨**（要件 4.3） |

- テキストパートを必ず持つ（HTML のみにしない）
- 送信元は `.env` の `MAIL_FROM_ADDRESS`。**デモ環境である旨をフッタに入れる**
- 確定メールは注文単位で1通。予約番号を箇条書きで並べる（要件 4.1）
- リマインドは**予約単位**で送る。同一メールアドレスに複数枠があっても分ける
  （枠ごとに開催時刻が違い、まとめると誤読される）

---

## 13. バッチコマンド

すべて `artisan` コマンドとして実装し、**単体で手動実行できる**ようにする。
`--dry-run` を持たせるのは影響が戻せないもの（`demo:reset` / `logs:prune`）のみ。

| コマンド | 処理 | 冪等性 |
|---|---|---|
| `slots:close` | `status=open` かつ `starts_at` の前日23:59を過ぎた枠を `closed` に | ○ |
| `slots:complete` | `status=closed` かつ `endsAt()` を過ぎた枠を `completed` に | ○ |
| `reservations:mark-no-show` | `completed` 枠の `confirmed` 予約を `no_show` に | ○ |
| `reservations:remind` | 翌日開催の `open`/`closed` 枠の `confirmed` 予約にリマインドを積む | **要注意（下記）** |
| `inventory:check` | 差分を検出し `cache` に保存、1件以上なら通知 | ○ |
| `demo:reset` | 15.1 | ○ |
| `logs:prune` | 90日超過分の `mail_logs.body` / `mail_logs.to` / `webhook_events.payload` を NULL 化し、`audit_logs` は 90日超過行を削除 | ○ |
| `schedule:heartbeat` | `cache` に `schedule.last_run_at` を書く | ○ |

**`reservations:remind` の二重送信を防ぐ。** 日次1回の想定だが、cron が詰まって
同日に2回走る可能性がある。`mail_logs` に
「同一予約・同一 `type=reminder`・同一日付」の行が既にあれば積まない、で塞ぐ。
`withoutOverlapping()` は同時実行しか防がず、**時間をあけた再実行は防げない**。

各コマンドは処理件数を標準出力とアプリケーションログに1行で出す。
0件のときも出す。**「動いたが0件」と「動かなかった」を区別できるようにする**ため。

### 13.1 スケジュール定義

```php
$schedule->command('queue:work --queue=priority,default --stop-when-empty --max-time=50')
         ->everyMinute()->withoutOverlapping();
$schedule->command('schedule:heartbeat')->everyMinute();
$schedule->command('inventory:check')->everyFifteenMinutes()->withoutOverlapping();
$schedule->command('slots:close')->dailyAt('00:05');
$schedule->command('slots:complete')->dailyAt('00:30');
$schedule->command('reservations:mark-no-show')->dailyAt('00:35');
$schedule->command('demo:reset')->dailyAt('03:00');
$schedule->command('logs:prune')->dailyAt('03:30');
$schedule->command('reservations:remind')->dailyAt('07:00');
```

`slots:complete` と `mark-no-show` を 0:30 / 0:35 に分けるのは、
**枠が `completed` になってから欠席判定を回す**という順序依存があるため（設計 9）。

---

## 14. 通知

| 契機 | 条件 | 抑止キー | 抑止時間 |
|---|---|---|---|
| Webhook 処理の最終失敗 | ジョブの `failed()` | `notify:webhook:{webhook_id}` | 30分 |
| メール送信の最終失敗 | 同上 | `notify:mail:{mail_log_id}` | 30分 |
| 在庫戻しの最終失敗 | 同上 | `notify:inventory:{slot_id}` | 30分 |
| 在庫差分の検出 | `inventory:check` で1件以上 | `notify:drift:{slot_id}` | 30分 |

- 宛先は `config('booking.admin_notification_email')`（`.env` から）
- 抑止は `Cache::add($key, true, 1800)` の戻り値で判定する。
  **`has` → `put` の2段階にしない**（同一分内に複数ジョブが失敗すると両方通り抜ける）
- 本文には対象の識別子と管理画面への URL を入れる。**payload 本文は入れない**（NFR 6.2）

---

## 15. デモ運用

### 15.1 `demo:reset` の手順

```
0. ガード: APP_ENV === 'production' なら即終了（要件・NFR 7.3）
1. 対象テーブルを truncate
     reservations → slots → workshops → users（is_demo=false も含め全件）
     ※ audit_logs / mail_logs / webhook_events / cache / jobs は残す
2. failed_jobs は削除する（前日の失敗が翌日のダッシュボードに残らないように）
3. シードを投入
     - 講座 3件
     - 開催枠: 今日から14日先まで、1日1〜2枠。状態は open を基本に
       closed / completed / cancelled を1つずつ混ぜる（状態遷移を見せるため）
     - 予約: 各枠に 0〜定員-1 件。埋まり具合に差をつける
     - ユーザー: admin 1 / staff 1（いずれも is_demo=true）
4. 各枠の Shopify 在庫を set(capacity - 確定予約数) で上書き
5. audit_logs に inventory.reset を1件記録（枠ごとの前後値つき）
```

**手順4は要件 7.4・設計9と同じ意図を具体化したもの。**
シードで予約を入れる以上、在庫は `capacity` そのものではなく
`capacity - 確定予約数` でなければ、リセット直後から在庫差分ウィジェットが
全枠を異常として並べる。**リセット後は差分0になること**を判定基準にする。

### 15.2 シードの個人情報（要件 7.2）

- 氏名: `見本 太郎` `試用 花子` のように**明らかに架空と分かるもの**
- メール: `sample+01@example.com`（`example.com` は予約ドメイン。実在しない）
- 電話: `090-0000-0001` 形式の連番
- 実在しそうな組み合わせを避ける。**デモ閲覧者が個人情報だと誤認しないこと**を優先する

---

## 16. テスト

### 16.1 Feature テスト（設計10 + NFR 9.1）

| # | テスト | 検証内容 |
|---|---|---|
| 1 | `webhook_invalid_hmac_returns_401` | 署名不一致で 401、`webhook_events` に行が作られない |
| 2 | `duplicate_webhook_creates_single_reservation` | 同じ `webhook_id` を2回 POST して予約1件 |
| 2b | `same_order_with_different_webhook_id_is_idempotent` | 一意制約による二重の歯止め（設計 5.3） |
| 3 | `quantity_two_creates_two_reservations` | `seat_index` が 1,2 で採番される |
| 4 | `closed_slot_order_is_skipped_with_reason` | 予約0件、`status=skipped`、理由が入る |
| 5 | `manual_registration_rolls_back_on_inventory_failure` | 予約が残らない |
| 6 | `lookup_rate_limit_blocks_after_five_attempts` | 6回目が 429 |
| 7 | `mail_failure_does_not_break_reservation` | 予約は `confirmed`、`mail_logs.status=failed` |
| 8 | `db_failure_after_inventory_decrement_queues_compensation` | `AdjustShopifyInventory(+1)` が積まれる |

追加で押さえるもの。

| # | テスト | 理由 |
|---|---|---|
| 9 | `mixed_order_creates_reservations_only_for_slots` | 物販混在（要件 4.1）。skip と failed の取り違えが起きやすい |
| 10 | `customer_cannot_cancel_after_deadline` | 期限判定の境界（前日23:59:59 と 翌0:00:00） |
| 11 | `lookup_response_is_identical_for_unknown_and_mismatched` | 列挙対策（NFR 5.1） |
| 12 | `demo_user_cannot_be_deleted_or_role_changed` | Policy と URL 直叩きの両方 |
| 13 | `demo_reset_is_idempotent_and_leaves_no_drift` | 2回流して同じ結果 + 差分0（15.1） |
| 14 | `health_returns_503_when_schedule_is_stale` | 死活監視が機能すること |
| 15 | `webhook_fails_when_line_item_id_missing` | `line_item.id` 欠損を仕様違反として failed に落とす（5.3） |

Shopify API は `Http::fake()`。実 API を叩くテストは書かない（NFR 9.1）。

### 16.2 テストの前提

- `RefreshDatabase` を使い、SQLite ではなく **MySQL で走らせる**
  （本番と同じ照合順序・一意制約の NULL 挙動に依存しているため）
- 時刻依存のテストは `Carbon::setTestNow()` で固定する
- `Mail::fake()` / `Queue::fake()` は**必要なテストだけ**で使う。
  常時 fake にするとジョブの中身が一度も動かない

---

## 17. 実装順とチェックリスト

設計 12 の段階に、本書の節を割り当てたもの。**各段階の終わりに動くものが残る。**

| 段階 | 実装 | 完了条件 |
|---|---|---|
| 0 | 2章の確定 / デプロイ疎通（手動→自動）/ `/health` の骨組み | 本番 URL で空の Laravel と `/health` が応答し、main への push で更新される |
| 1 | 3章（DB）/ 4章（Enum・モデル）/ 5.1・5.2 / 11.2・11.3 | 手で枠と予約を作れる。在庫処理は未接続 |
| 2 | 7章 / 8.2 / 5.3 / 11.5 | 購入で予約が自動登録される。テスト 1〜4, 9 |
| 3 | 12章（メール）/ 11.2 の CSV / 11.4 | 業務が一周する。テスト 7 |
| 4 | 5.2 の在庫戻し / 8.3 / 9〜10章（顧客画面） | 双方向連携。テスト 5, 6, 8, 10, 11 |
| 5 | 15章 / 11.1 の保護 / 11.6 / 13〜14章 | デモ公開。テスト 12, 13, 14 |

**段階1で `CreateReservation` を先に作る**（設計 12）。段階2で書き直さないため。
**段階4より前に顧客画面を作らない**。キャンセルが動かない照会画面は、
見せても評価につながらないうえ、後から作り直すことになる。

---

## 18. 上位文書からの変更・追加点

本書で新たに決めた、上位文書との差分。**いずれも上位文書側へ反映済み**
（要件 1.7 / NFR 1.2 / 設計 1.3）。以降に差分が出た場合はこの表に追記し、
同じように上位文書へ戻す。

| 箇所 | 内容 | 理由 |
|---|---|---|
| 設計 6.1 | `mail_logs.related_reservation_ids` を追加 | 注文単位メールが複数予約に対応する（3.7） |
| 設計 6.1 | `reservations.source` / `cancelled_at` / `cancelled_by` を追加 | 在庫差分の原因切り分けと監査（3.4） |
| 設計 6.1 | `audit_logs.actor_label` を追加 | リセット後も操作者を読めるように（3.8） |
| 設計 6.1 | `workshops.is_active` を追加 | 枠作成時の選択肢の絞り込み |
| 設計 9 | `slots:complete` と `mark-no-show` を 0:30 / 0:35 に分離 | 順序依存があるため（13.1） |
| 設計 9 | `demo:reset` の在庫上書きを `capacity - 確定予約数` に修正 | そのままだとリセット直後に差分が全枠出る（15.1） |
| 要件 6.2 | `Open → Completed` の直行を不可と明示 | 締切を経ない場合の Webhook の扱いが未定義になる（4.2） |
| NFR 6.3 | 在庫差分ウィジェットは `cache` を読む（画面から API を叩かない） | 同時閲覧でレート制限に触れる（11.6） |

---

## 19. 未決事項

上位文書から引き継ぐもの（NFR 12・設計 13）に加えて、本書で残ったもの。

- **Shopify Admin API のバージョン**（7.4）。採用時点の安定版を `.env` に置く
- `inventoryAdjustQuantities` の `reason` に使える値。API バージョンで変わるため、
  段階2の実装時に実 API のスキーマで確認する
- Xserver の SMTP 送信数上限。上限次第でリマインドの送信間隔を分散させる（NFR 12）
- 会場案内・持ち物などメール本文の固定文言（12章）
