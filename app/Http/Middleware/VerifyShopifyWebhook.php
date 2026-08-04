<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 詳細設計7.3。raw body に対して HMAC を検証する（パース後の body では署名が合わない）。
 * Shopify の再送を弾かないよう、csrf・throttle の対象外で運用する（design.md 5.1）。
 */
class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.shopify.webhook_secret');
        $signature = (string) $request->header('X-Shopify-Hmac-Sha256');

        $computed = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if ($signature === '' || ! hash_equals($computed, $signature)) {
            abort(401);
        }

        if ($request->header('X-Shopify-Webhook-Id') === null || $request->header('X-Shopify-Topic') === null) {
            abort(400);
        }

        return $next($request);
    }
}
