#!/usr/bin/env bash
#
# 転送後に本番サーバー上で実行するリリース処理。
# GitHub Actions の deploy ジョブから ssh 経由で呼ぶ(.github/workflows/deploy.yml)。
# 手動デプロイのときも同じものを実行できるよう、引数なしで完結させている。
#
# 環境変数:
#   PHP_BIN ... 使用する PHP CLI(既定 php)。Xserver では 8.3 系のフルパスを渡す
#
# DB のバックアップはここで取らない。破壊的なマイグレーションを流すときは
# 事前に手で取得する(docs/design.md 11章)。
set -euo pipefail

PHP_BIN="${PHP_BIN:-php}"

if [ ! -f artisan ]; then
    echo 'artisan が見つかりません。カレントがアプリケーションのルートか確認してください' >&2
    exit 1
fi

echo '==> マイグレーション'
"$PHP_BIN" artisan migrate --force

echo '==> キャッシュを再構築'
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache
"$PHP_BIN" artisan event:cache

# Filament は段階1で導入する。それまでコマンドが存在しないため有無を見てから呼ぶ
if "$PHP_BIN" artisan list --raw | grep -q '^filament:optimize'; then
    echo '==> Filament の最適化'
    "$PHP_BIN" artisan filament:optimize
fi

echo '==> 完了'
