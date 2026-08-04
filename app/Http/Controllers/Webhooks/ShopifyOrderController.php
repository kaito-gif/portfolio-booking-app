<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyOrder;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * 詳細設計7.3。この段階では payload の中身を検査しない（要件の5秒以内に応答するため）。
 * 不正な payload はジョブ側で failed に落とす。
 */
class ShopifyOrderController extends Controller
{
    public function ordersCreate(Request $request): Response
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true);

        try {
            $event = WebhookEvent::create([
                'webhook_id' => (string) $request->header('X-Shopify-Webhook-Id'),
                'topic' => (string) $request->header('X-Shopify-Topic'),
                'shopify_order_id' => is_array($payload) && isset($payload['id']) ? (string) $payload['id'] : null,
                'payload' => $rawBody,
                'received_at' => now(),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                // 既受信の webhook_id。何もせず200（設計5.1・7.3の冪等性）
                return response('', 200);
            }

            throw $e;
        }

        try {
            ProcessShopifyOrder::dispatch($event->id);
        } catch (Throwable) {
            // sync キュー（ローカル・テスト）ではジョブ例外がここまで伝播する。
            // 受信応答は queue ドライバやジョブの成否と独立させる（設計7.3）。
            // 本番の database キューでは dispatch() は INSERT のみで例外は起きない。
        }

        return response('', 200);
    }
}
