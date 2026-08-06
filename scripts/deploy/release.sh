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

echo '==> パッケージ検出キャッシュを再構築'
# composer install はCIランナー上でのみ実行し(要件8)、本番サーバーでは実行しない。
# また rsync は --exclude '/bootstrap/cache/*' のため、CI が作った packages.php は
# 本番へ転送されない。bootstrap/cache/packages.php は存在する限り中身を検証せず
# 使われ続ける(PackageManifest::getManifest())ため、明示的に再構築しないと、
# 初回デプロイ時点のパッケージ一覧のまま固まり、後から追加したパッケージ
# (Filament・Livewire等)のサービスプロバイダが永久に読み込まれない
# (2026-08-06、/admin と /livewire/* が404になる不具合で発覚)。
"$PHP_BIN" artisan package:discover --ansi

echo '==> マイグレーション'
"$PHP_BIN" artisan migrate --force

echo '==> キャッシュを再構築'
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan event:cache

# view:cache は使わない。filament-panels::page 等の Filament ページコンポーネントは
# 「現在アクティブなパネル」というランタイムコンテキストに依存して解決されるため、
# リクエスト外で全 Blade ビューを一括コンパイルする view:cache と構造的に相性が悪く、
# ComponentTagCompiler が例外を投げてデプロイ全体が失敗する(2026-08-06 に本番で実際に発生)。
# view:cache を使わなくても各ビューは初回リクエスト時に自動コンパイルされ
# storage/framework/views にキャッシュされるため、動作上の実害は無い

# Filament は段階1で導入する。それまでコマンドが存在しないため有無を見てから呼ぶ
if "$PHP_BIN" artisan list --raw | grep -q '^filament:optimize'; then
    echo '==> Filament の最適化'
    "$PHP_BIN" artisan filament:optimize
fi

echo '==> 完了'
