# 予約管理システム 基本設計書

- 版: 1.5（2026-08-03 更新。11章のデプロイを GitHub Actions による自動デプロイに変更）
- 対象: `docs/requirements.md` 版 1.9
- 非機能要件の詳細は `docs/non-functional-requirements.md` を参照
- カラム定義・シグネチャなどの実装粒度は `docs/detailed-design.md` を参照

要件書で「何を作るか」は決まっている。ここでは「どう作るか」だけを書く。
要件と食い違ったときは要件書を正とし、こちらを直す。

---

## 1. 設計の中心にある考え

**予約が生まれる経路は3つある。** Shopify の Webhook、管理画面からの手動登録、
デモ環境のシードである。この3つが別々に予約行を作り、別々に在庫を触ると、
在庫のズレが必ず起きる。

そこで**予約の作成とキャンセルを `Actions` に1つずつ用意し、どの経路からも
そこだけを呼ぶ**。Filament のフォームにも、ジョブにも、業務ロジックを書かない。
この一点を守るために以降の構成が決まっている。

---

## 2. 全体構成

```
[Shopify] --orders/create webhook--> [Laravel]
                                       ├─ /admin      … Filament（スタッフ・管理者）
                                       ├─ /r/...      … 予約照会（素の Blade + Tailwind）
                                       └─ /webhooks/shopify/orders-create
[Laravel] --Admin GraphQL（在庫増減）--> [Shopify]
```

| 要素 | 採用するもの | 理由 |
|---|---|---|
| フレームワーク | Laravel | 要件どおり |
| 管理画面 | Filament | 要件 10 で決定済み |
| 顧客照会画面 | 素の Blade + Tailwind | 下記 |
| DB | 本番: MariaDB 10.5 系 / ローカル・CI: MySQL 8.0 | Xserver 共用サーバーの標準エンジンが MariaDB のため（詳細設計 2.1） |
| キュー | database ドライバ | Redis が使えない |
| キャッシュ・セッション | database ドライバ | 同上 |
| メール | SMTP + 送信履歴の保存 | 7章 |

**顧客照会画面に Filament を使わない。** 要件 7.3 で C-1・C-2 のみ Lighthouse
ユーザー補助 100 を目標にしている。既製UIではマークアップを握れないため100は狙えない。
逆に管理画面は Filament に任せ、数値目標を置かない。

Filament は採用時点の最新安定版を使い、**その PHP 要件に合わせてサーバーパネルの
PHP を先に上げる**。段階0でここを確認する（要件8）。

---

## 3. ディレクトリ構成

```
app/
├─ Models/            Workshop, Slot, Reservation, User, WebhookEvent, AuditLog, MailLog
├─ Enums/             SlotStatus, ReservationStatus, UserRole, WebhookStatus
├─ Actions/           CreateReservation, CancelReservation, ImportOrderReservations
├─ Services/Shopify/  ShopifyClient, InventoryService
├─ Jobs/              ProcessShopifyOrder, AdjustShopifyInventory, SendReservationMail
├─ Http/
│   ├─ Middleware/    VerifyShopifyWebhook
│   └─ Controllers/
│       ├─ Webhooks/  ShopifyOrderController
│       └─ Customer/  LookupController, ReservationController
├─ Filament/
│   ├─ Resources/     WorkshopResource, SlotResource, ReservationResource, UserResource
│   ├─ Pages/         DailyRoster, WebhookEvents, MailLogs
│   └─ Widgets/       TodayReservations, UpcomingSlots, WebhookFailures, InventoryDrift
└─ Console/Commands/  slots:close, slots:complete, reservations:mark-no-show,
                      reservations:remind, demo:reset, logs:prune
```

### Actions の責務

| クラス | 責務 |
|---|---|
| `CreateReservation` | 開催枠・氏名・メール・電話を受け取り、予約番号を発行して1件作る。在庫を押さえるかどうかは引数で切り替える |
| `CancelReservation` | 予約を「キャンセル済み」にし、在庫戻しジョブを積む |
| `ImportOrderReservations` | 注文1件から予約を必要数まとめて作る。`CreateReservation` を呼ぶ |

在庫を押さえるかを引数にしているのは、**Shopify 経由の予約では在庫を触ってはいけない**
ため。購入時点で Shopify が既に1減らしている。手動登録のときだけ押さえる。

---

## 4. 在庫整合の設計

要件 4.2 と 4.3 で、手動登録とキャンセルの性格が違う。**設計でも意図的に非対称にする。**

### 4.1 手動登録は同期

```
DB トランザクション開始
  └ 予約行を「inventory_pending」で作る
コミット
  └ Shopify 在庫を1減らす（同期・失敗したら例外）
  └ 成功したら予約を「confirmed」に更新
```

在庫の更新に失敗したら予約を削除（または即時キャンセル）し、予約は残らない（要件 5.2）。
在庫を押さえないまま予約を確定させると、Shopify から追加で売れて**定員を超える**。
定員超過は業務上いちばん重い事故なので、ここは遅くても同期で守る。

外部 API 呼び出しは DB ロールバックの対象外なので、**在庫減算成功後に DB 更新で失敗**
した場合の補償を必ず入れる。具体的には `AdjustShopifyInventory(+1)` を優先キューに積み、
`audit_logs` に「補償実行」を記録して運用画面に表示する。

### 4.2 キャンセルは非同期

```
DB トランザクション開始
  └ 予約を「キャンセル済み」にする
コミット（顧客にはこの時点で完了を返す）
  └ AdjustShopifyInventory ジョブを積む（+1・リトライあり）
```

在庫戻しが遅れて起きるのは「売り逃し」だけで、定員超過は起きない。
顧客の操作を Shopify API の応答性に巻き込まないほうが体験が良く、
失敗したときの被害も軽い側に倒れる。**安全側がどちらかで同期・非同期を決めている。**

### 4.3 それでも残るズレを見えるようにする

外部APIとDBを跨ぐ以上、ズレはゼロにできない。検知できるようにして運用で拾う。

ダッシュボードに `InventoryDrift` ウィジェットを置き、
**`Shopify 在庫 + 確定予約数 ≠ 定員` の開催枠**を一覧する。
1枚足すだけで、破綻していないことを画面で示せる。

**ウィジェットは画面表示のたびに Shopify を叩かない。** 15分ごとの `inventory:check`
（NFR 6.3）が結果をキャッシュに書き、ウィジェットはそれを最終確認時刻とあわせて表示する。
画面を開くたびに API を呼ぶと、複数人がダッシュボードを開いただけでレート制限に近づく。

在庫を動かした操作は `audit_logs` に前後の値付きで残す（要件 7.4）。

---

## 5. Shopify 連携

### 5.1 Webhook の受信

1. `VerifyShopifyWebhook` で HMAC を検証する。**raw body に対して計算する**
   （パース後の body では署名が合わない）。失敗は 401
2. `webhook_events` に `webhook_id` のユニーク制約付きで登録。重複なら何もせず 200
3. `ProcessShopifyOrder` を dispatch して即 200（要件の5秒以内）

`line_item.id` が欠損した payload も受信自体は記録して 200 を返し、ジョブ側で
**仕様違反として `failed` に落とす**。受信経路で弾くと、再送や調査の履歴が残らないため。

### 5.2 注文から予約への変換

`ProcessShopifyOrder` の中身。

- `line_items` をすべて走査する
- `line_item.id` が無いものは仕様違反として失敗扱いにする（予約は作らない）
- `variant_id` が `slots` に登録済みで、状態が「受付中」のものだけを対象にする
- `quantity` の数だけ `CreateReservation` を呼ぶ（在庫は押さえない）
- 1つの line item 内では **all-or-nothing**（必要数を作れない場合、その line item で作成済みの予約を
  **内部ロールバック**として取り消す。顧客通知はしない）
- 最後に**注文単位で確定メールを1通**送る（対象 line item がすべて成功したときのみ、要件 4.1）

対象外だったものはエラーにせず、`webhook_events.failure_reason` に理由を書いて
処理済みにする。締切済みの枠、開催枠に紐づかない物販がこれに当たる。
理由が管理画面から読めれば運用は回る。

対象 line item で失敗が出た注文は `webhook_events.status=failed` にし、`failure_reason` に
「どの line item が何件不足したか」を残す。この場合は確定メールを送らない。
返金や別案内は業務要件として不要なので、管理画面での確認と手動再実行のみを運用導線にする。

### 5.3 冪等性は二重に持たせる

`webhook_id` での排除に加えて、`reservations` に
**`(shopify_order_id, shopify_line_item_id, seat_index)` のユニーク制約**を張る。

この制約を成立させるため、`shopify_line_item_id`（`line_item.id`）は
Webhook 処理における必須入力として扱う。

前者だけだと、Shopify が別の webhook ID で同じ注文を送ってきたときに素通りする。
要件 5.5 が「冪等性の担保は必須」と言っている以上、DB 側にも歯止めを置く。

### 5.4 在庫の更新

在庫の API はバリアントIDではなく **inventory item と location の組**で指定する。
毎回バリアントから引き直すとリクエストが倍になるため、
**開催枠の保存時に一度解決して `slots.shopify_inventory_item_id` に保存する。**

location は単一に固定し、IDは設定値として持つ（要件 5.5）。

---

## 6. データ設計

### 6.1 テーブル

| テーブル | 主なカラム |
|---|---|
| `workshops` | `name`, `description`, `duration_minutes`, `shopify_product_id`, `is_active` |
| `slots` | `workshop_id`, `starts_at`, `capacity`, `status`, `shopify_variant_id`(unique), `shopify_inventory_item_id` |
| `reservations` | `slot_id`, `code`(unique), `name`, `email`, `phone`, `status`, `source`, `shopify_order_id`, `shopify_line_item_id`, `seat_index`, `checked_in_at`, `cancelled_at`, `cancelled_by` |
| `users` | `name`, `email`, `password`, `role`, `is_demo` |
| `webhook_events` | `webhook_id`(unique), `topic`, `payload`, `status`, `attempts`, `next_attempt_at`, `failure_reason` |
| `audit_logs` | `user_id`, `actor_label`, `action`, `auditable_type`, `auditable_id`, `changes`, `ip_address`, `created_at` |
| `mail_logs` | `reservation_id`, `related_reservation_ids`, `type`, `to`, `subject`, `body`, `status`, `attempts`, `sent_at`, `last_error` |

要件書のエンティティに対する追加は次のとおり。いずれも理由がある。

- `slots.shopify_inventory_item_id` — 在庫更新のたびに引き直さないため（5.4）
- `reservations.shopify_line_item_id` / `seat_index` — 冪等性のユニーク制約に要る（5.3）
- `mail_logs` — 送信履歴のプレビュー画面に要る（7章）
- `mail_logs.related_reservation_ids` — 確定メールは注文単位で1通のため、
  1通が複数の予約に対応する。代表1件だけでは何席分のメールか履歴から読めない
- `reservations.source`（`shopify` / `manual` / `seed`） — 在庫差分が出たときに
  どの経路を疑うかが変わる。手動登録と Webhook のどちらがズレたのかを切り分ける
- `reservations.cancelled_at` / `cancelled_by` — 顧客とスタッフのどちらが取り消したかを残す
- `audit_logs.actor_label` — `users` は日次リセットで消えるため、`user_id` だけだと
  翌朝には誰の操作か追えない。表示名を文字列で持って残す
- `workshops.is_active` — 開催枠を作るときの講座の選択肢を絞る

### 6.2 状態

PHP の enum で定義し、**遷移はモデルのメソッド経由に限定する**。
コントローラやフォームから `status` を直接代入させない。

| 対象 | 値 |
|---|---|
| `SlotStatus` | `draft` / `open` / `closed` / `cancelled` / `completed` |
| `ReservationStatus` | `inventory_pending` / `confirmed` / `cancelled` / `attended` / `no_show` |
| `UserRole` | `staff` / `admin` |

### 6.3 予約番号

形式は **`CHK-XXXXX-XXXXX`**。文字種は数字と大文字英字から `I` `O` `U` `L` を
除いたもの。乱数で生成し、ユニーク制約に当たったら作り直す。

除外しているのは**電話で読み上げたときに取り違えないため**。
電話予約を受ける業務なので、ここは実運用で効く。連番を使わないのは要件 6.3 のとおり。

---

## 7. メール

実送信する。あわせて**送信内容を `mail_logs` に保存し、管理画面にプレビュー画面を置く**。

デモ環境では、閲覧者が自分のアドレスを入れずに操作することがある。
また共用サーバには送信数の上限があり、迷惑メール判定のリスクもある。
送信履歴が画面で見られれば、**メールが届かない状況でも「何が送られるはずか」を
見せられる。** 発注者への見せ場としてもこちらのほうが確実。

| 種別 | 契機 |
|---|---|
| 予約確定 | Webhook からの登録時（注文単位で1通）、手動登録時 |
| リマインド | 開催日の前日朝（日次バッチ） |
| キャンセル完了 | キャンセル実行時 |

送信は `SendReservationMail` ジョブ経由にして、失敗しても業務処理を巻き込まない。

### 7.1 再送ポリシー

- **自動再送はしない。** 送信に失敗したら即座に `mail_logs.status=failed`、
  `last_error` にエラーを保存する（ジョブの `tries`/`backoff` は設定しない）
- 管理画面 `MailLogs` に「再送」アクションを置き、`failed` のみ手動で再投入できるようにする
- 手動再送は既存の `mail_logs` 行を更新するだけで、新しい行は作らない
  （再送を繰り返しても履歴が1行に保たれる）

在庫戻し（`AdjustShopifyInventory`）・Webhook 処理と違い、メール送信の失敗は
在庫やデータの不整合を起こさない。**自動で待たせるより、すぐ画面に失敗として
出して人が判断できる状態にするほうが、デモとしても運用としても分かりやすい**
と判断し、他ジョブと揃えた自動再送は採用しなかった。

メール本文と宛先は個人情報なので、保存期間を 90 日とし、期限超過分は削除する（9章）。

---

## 8. 画面設計

### 8.1 管理画面（Filament）

| 要件の画面 | 実装 |
|---|---|
| A-2 ダッシュボード | Widget 4枚（本日の予約数 / 直近の開催枠と埋まり具合 / Webhook 失敗件数 / 在庫差分） |
| A-3 予約一覧 | `ReservationResource` のテーブル。フィルタと CSV 出力 |
| A-4 予約詳細・手動登録 | 同 Resource のフォーム。保存時に `CreateReservation` を呼ぶ |
| A-5 当日リスト | カスタム Page。印刷用スタイルとチェックイン |
| A-6 / A-7 開催枠 | `SlotResource` |
| A-8 講座 | `WorkshopResource` |
| A-9 ユーザー管理 | `UserResource`（管理者のみ・デモユーザーは保護） |
| — | `WebhookEvents` ページ（失敗の確認と手動再実行） |
| — | `MailLogs` ページ（送信履歴のプレビュー） |

CSV は**表示中の絞り込み条件をそのまま引き継ぎ**、UTF-8 BOM 付きで出す（要件 5.2）。
件数は多くないので同期出力でよい。

### 8.2 顧客画面（素の Blade）

- C-1 予約照会 — 予約番号とメールの入力
- C-2 予約詳細 — 内容の確認とキャンセル

ラベルは `for` で関連付け、エラーは `aria-describedby` と `role="alert"` で伝える。
送信後はフォーカスを結果へ移す。コントラスト比を満たす配色にする。
**この2画面だけ Lighthouse を測り、ユーザー補助 100 を確認する。**

### 8.3 照会の総当たり対策

`RateLimiter` を2本使う。**予約番号ごとに10分5回**、**IP ごとに10分30回**（要件 7.2）。

失敗しても成功しても予約番号側のカウントを消費する。
**存在しない予約番号と、メールが一致しない場合とで、文言も応答時間も変えない。**
差が出ると予約番号の存在を確認できてしまう。

### 8.4 ログインのレート制限

メールごとに10分5回。ただし **`users.is_demo` が立つアカウントは10分30回**に緩める。
公開したデモアカウントは全閲覧者が同じメールで入るため、通常の制限だと
1人の打ち間違いで全員が締め出される（NFR 5.1）。

---

## 9. バッチ

cron はこの1本だけ登録する。

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

| 時刻 | 処理 | 備考 |
|---|---|---|
| 毎分 | `queue:work --stop-when-empty --max-time=50` | `withoutOverlapping()` |
| 0:05 | `slots:close` | 前日23:59を過ぎた開催枠を締切に |
| 0:30 | `slots:complete` | |
| 0:35 | `reservations:mark-no-show` | `slots:complete` の後に回す（下記） |
| 毎分 | `schedule:run` の最終実行時刻を記録 | `/health` が返す値（NFR 6.4） |
| 15分ごと | `inventory:check` | 在庫差分を検出し、1件以上なら通知（NFR 6.3） |
| 3:00 | `demo:reset` | |
| 3:30 | `logs:prune` | `mail_logs.body` / `mail_logs.to` / `webhook_events.payload` の90日超過分を消し、`audit_logs` の90日超過行を削除 |
| 7:00 | `reservations:remind` | |

**`slots:complete` と `reservations:mark-no-show` を同時刻に置かない。** 無断欠席は
「開催済みになった枠の確定予約」を対象にするため、枠の状態更新が先に終わっている
必要がある。同時刻だと実行順が保証されず、判定対象が丸ごと漏れる日が出る。

`--max-time=50` は、次の分の起動と重ならないよう自分から終わるため。
`withoutOverlapping()` は要件 5.4 のとおり必須。二重送信と在庫の二重更新を防ぐ。

`AdjustShopifyInventory` が最終失敗したジョブと、`SendReservationMail` が失敗した
`mail_logs` 行（7.1のとおり自動再送はしない）は、管理画面の失敗件数ウィジェットに
表示し、当日中に手動再実行する運用にする。

### `demo:reset` の中身

1. **`audit_logs` / `mail_logs` / `webhook_events` / `cache` を除いて初期化する**（要件 7.4）
2. シードを投入する。実在の個人情報を思わせる値は入れない（要件 7.2）
3. 開催枠に紐づくバリアントの **Shopify 在庫を `capacity - 確定予約数` で上書きする**（要件 7.4）

手順3を単純に `capacity` にしない。シードは各枠に予約を入れるため、
在庫を定員そのものに戻すと `Shopify在庫 + 確定予約数 ≠ 定員` となり、
**リセット直後から在庫差分ウィジェットに全枠が並ぶ**。破綻していないことを
画面で示すためのウィジェット（4.3）が、毎朝異常表示になっては意味がない。
**リセット後に差分が0件であること**をこのコマンドの合格基準にする。

ログ系3テーブルを残すのは、90日の保持期間を成立させるため。毎晩消すと `logs:prune` が
一度も発火せず、送信履歴と受信履歴を後から追えなくなる。`cache` は通知の抑止状態を持つ。

`audit_logs` は PII を保存しない（氏名・メール・電話・メール本文は入れない）方針とし、
90日超過分は行削除する。件数統計を残す必要があるのは `mail_logs` / `webhook_events` 側のみ。

何度流しても同じ結果になるようにする。`APP_ENV=production` では動かないガードを入れる。

### デモアカウントの保護

`users.is_demo` が立っているアカウントは、権限変更・削除・パスワード変更の
対象外にする（要件 7.4）。Filament の Policy と、該当アクションの非表示の
**両方**で塞ぐ。片方だけだと URL 直叩きで通る。

---

## 10. テスト

Feature テストで次の6つを固める。ここが壊れると業務が壊れる箇所に絞る。

1. HMAC 検証に失敗した Webhook が 401 になる
2. 同じ Webhook を2回送っても予約が1件しかできない
3. 数量2の注文で予約が2件できる
4. 締切済みの開催枠への注文で予約ができず、理由が記録される
5. 手動登録で在庫更新が失敗したとき、予約が残らない
6. 照会画面のレート制限が効く

Shopify API は `Http::fake()` で差し替える。実 API を叩くテストは書かない。
GitHub Actions で実行する（第1弾と体裁を揃える）。CI はローカルと同じ `docker compose` を
起動して実行し、テストの実行環境をローカルと一致させる。

---

## 11. デプロイ

### 11.1 流れ

**GitHub Actions から SSH/rsync で転送する。** 実体は次の5段で、
`.github/workflows/deploy.yml` と `scripts/deploy/release.sh` に分かれている。

1. **事前チェック** — `ci.yml` を `workflow_call` で呼び、テストと Pint が通ることを確認する
2. **ビルド** — ランナー上で `composer install --no-dev -o` と `npm run build`
3. **承認** — `production` 環境の承認待ちで止まる。ここを通すまで本番は変わらない
4. **転送** — `rsync --delete`。`.env` / `storage` / `public/storage` / `tests` /
   `docs` / `docker` は送らない
5. **リリース処理** — サーバー上で `release.sh` を実行し、`migrate --force` →
   `config:cache` / `route:cache` / `view:cache` / `event:cache` →
   （存在すれば）`filament:optimize`

最後に `/health` の応答を確認して終わる。**通らなければデプロイジョブを失敗させる。**

### 11.2 なぜこの形か

| 判断 | 理由 |
|---|---|
| ローカル転送ではなく CI | 手元の状態（未コミットの変更・ビルド漏れ）が本番に混ざらない |
| ビルドを CI で行う | Node を本番に持ち込まない制約（要件 8）を守りつつ、手作業を消す |
| リリース処理をスクリプトに切り出す | 手動デプロイと自動デプロイで同じ手順を実行できる |
| `production` 環境の承認を挟む | `migrate --force` が人の判断なしに本番へ流れる状態を作らない |
| `rsync --delete` の前に `artisan` の存在を確認 | 転送先を間違えたときに消してしまうのを防ぐ |
| `pull_request` を契機にしない | 公開リポジトリのため、fork からの PR に Secrets を渡さない（NFR 7.2） |

**PHP CLI はフルパスで指定する。** 共用サーバーの既定 `php` が 8.3 系とは限らないため、
`DEPLOY_PHP_BIN` で明示し、`release.sh` はそれを使う。

### 11.3 サーバー側に一度だけ用意するもの

自動デプロイが触らない（＝転送対象外の）ものは、初回に手で用意する。

- `.env`（本番値。リポジトリには入れない）
- `storage/` 配下と `bootstrap/cache` の書き込み権限、`storage:link`
- ドキュメントルートを `public` に向ける設定
- cron（`schedule:run` を毎分）
- CI 用公開鍵の登録

### 11.4 巻き戻し

**ゼロダウンタイム要件はなく、リリースの世代管理もしない。** 戻すときは
直前のコミットに戻して再デプロイする。DB は日次バックアップ（NFR 4.3）が最後の手段になるため、
**破壊的なマイグレーションを流す前だけは手で DB のバックアップを取る**。
自動化していないのは、共用サーバー上の DB 認証情報をデプロイ経路に通したくないため。

**この手順を段階0で一度通し切る。** まとめてデプロイすると必ず詰まる（要件 10）。
順序も要件 10 に従い、**手動で1回成功させたあとに自動化に載せる**。

---

## 12. 段階ごとの実装順

要件 10 の段階に、この設計での作業を割り当てたもの。

| 段階 | この設計での作業 |
|---|---|
| 0 | PHP バージョン確認、`storage:link`、デプロイ手順の疎通 |
| 1 | Models / Enums / `CreateReservation` / Filament の Resource 一式 |
| 2 | `VerifyShopifyWebhook` / `ProcessShopifyOrder` / `webhook_events` |
| 3 | メールと `mail_logs` / CSV / 当日リスト |
| 4 | `CancelReservation` / `AdjustShopifyInventory` / 顧客照会画面 |
| 5 | `demo:reset` / デモユーザー保護 / 在庫差分ウィジェット |

段階1の時点で `CreateReservation` を作っておく。段階2で Webhook から呼ぶときに
書き直さずに済む。**ここを後回しにすると、この設計の前提が崩れる。**

---

## 13. 未決事項

- Filament の具体的なバージョンと、それが要求する PHP バージョン
  （採用時に確認し、サーバーパネルの設定を合わせる）
- SMTP の送信元アドレスと、Xserver 側の送信数上限
- シードで作る講座・開催枠の件数と期間の取り方
