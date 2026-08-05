<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 詳細設計7.1。GraphQL Admin API のみを扱う薄いラッパー。
 */
final class ShopifyClient
{
    public function __construct(
        private readonly string $shopDomain,
        private readonly string $accessToken,
        private readonly string $apiVersion,
    ) {}

    /** @throws ShopifyApiException */
    public function graphql(string $query, array $variables = []): array
    {
        $this->logRequest($query, $variables);

        try {
            $response = Http::baseUrl("https://{$this->shopDomain}/admin/api/{$this->apiVersion}")
                ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken])
                ->connectTimeout(5)
                ->timeout(10)
                ->retry(3, fn (int $attempt, Throwable $e) => $this->retryDelayMs($e), fn (Throwable $e) => $this->isRetryable($e))
                // 変数無しのクエリで $variables=[] のまま渡すと JSON化で配列([])になり、
                // Shopify側がオブジェクトを期待して「Invalid variables parameter」で
                // 拒否する。空のときだけオブジェクトとして送る。
                ->post('/graphql.json', ['query' => $query, 'variables' => $variables === [] ? (object) [] : $variables]);
        } catch (ConnectionException|RequestException $e) {
            throw new ShopifyApiException('Shopify APIへの接続に失敗しました', previous: $e);
        }

        if ($response->failed()) {
            throw new ShopifyApiException("Shopify APIがエラーを返しました（HTTP {$response->status()}）");
        }

        $body = $response->json() ?? [];

        if (! empty($body['errors'])) {
            throw new ShopifyApiException('Shopify GraphQLエラー: '.json_encode($body['errors'], JSON_UNESCAPED_UNICODE));
        }

        $data = $body['data'] ?? [];
        $userErrors = $this->extractUserErrors($data);

        if ($userErrors !== []) {
            throw new ShopifyApiException('Shopify userErrors: '.json_encode($userErrors, JSON_UNESCAPED_UNICODE));
        }

        return $data;
    }

    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function retryDelayMs(Throwable $e): int
    {
        if ($e instanceof RequestException && $e->response->hasHeader('Retry-After')) {
            return ((int) $e->response->header('Retry-After')) * 1000;
        }

        return 500;
    }

    /** @return array<int, array<string, mixed>> */
    private function extractUserErrors(array $data): array
    {
        $errors = [];

        foreach ($data as $value) {
            if (is_array($value) && is_array($value['userErrors'] ?? null)) {
                $errors = [...$errors, ...$value['userErrors']];
            }
        }

        return $errors;
    }

    private function logRequest(string $query, array $variables): void
    {
        preg_match('/\b(?:query|mutation)\s+(\w+)/', $query, $matches);

        $ids = collect($variables)
            ->filter(fn ($value, $key) => is_string($key) && str_ends_with(strtolower($key), 'id'))
            ->all();

        Log::info('shopify.graphql', [
            'operation' => $matches[1] ?? 'anonymous',
            'ids' => $ids,
        ]);
    }
}
