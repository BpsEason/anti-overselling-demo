<?php

namespace App\Http\Controllers;

use App\Services\RedisInventoryService;
use App\Jobs\ProcessOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenApi\Annotations as OA;

/**
 * @OA\Info(
 * version="1.0.0",
 * title="Anti-Overselling Demo API",
 * description="高併發防超賣交易系統 API 文件",
 * @OA\Contact(
 * email="your.email@example.com"
 * ),
 * @OA\License(
 * name="MIT License",
 * url="https://opensource.org/licenses/MIT"
 * )
 * )
 *
 * @OA\Server(
 * url=L5_SWAGGER_CONST_HOST,
 * description="開發環境 API 伺服器"
 * )
 *
 * @OA\Tag(
 * name="Order",
 * description="訂單相關 API"
 * )
 * @OA\Tag(
 * name="Stock",
 * description="庫存查詢與初始化 API"
 * )
 */
class OrderController extends Controller
{
    protected $redisInventoryService;

    public function __construct(RedisInventoryService $redisInventoryService)
    {
        $this->redisInventoryService = $redisInventoryService;
    }

    /**
     * @OA\Post(
     * path="/api/place-order",
     * summary="下單並扣減庫存",
     * tags={"Order"},
     * operationId="placeOrder",
     * @OA\RequestBody(
     * required=true,
     * description="訂單請求參數",
     * @OA\JsonContent(
     * required={"product_id", "quantity", "user_id"},
     * @OA\Property(property="product_id", type="integer", example=1, description="商品ID"),
     * @OA\Property(property="quantity", type="integer", example=1, description="購買數量"),
     * @OA\Property(property="user_id", type="integer", example=101, description="用戶ID")
     * )
     * ),
     * @OA\Response(
     * response=202,
     * description="訂單已提交，正在處理中",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="訂單已提交，正在處理中。"),
     * @OA\Property(property="order_identifier", type="string", example="a1b2c3d4-e5f6-7890-1234-567890abcdef")
     * )
     * ),
     * @OA\Response(
     * response=400,
     * description="庫存不足",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="商品庫存不足，請稍後再試。")
     * )
     * ),
     * @OA\Response(
     * response=422,
     * description="驗證失敗",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="The given data was invalid."),
     * @OA\Property(property="errors", type="object")
     * )
     * ),
     * @OA\Response(
     * response=500,
     * description="訂單處理異常",
     * @OA\JsonContent(
     * @OA\Property(property="message", type="string", example="訂單處理異常，請稍後再試。")
     * )
     * )
     * )
     */
    public function placeOrder(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'user_id' => 'required|integer',
        ]);

        $productId = $request->input('product_id');
        $quantity = $request->input('quantity');
        $userId = $request->input('user_id');

        $preOrderIdentifier = Str::uuid()->toString();

        Log::info("Received order request for ProductID: {$productId}, Quantity: {$quantity}, UserID: {$userId}, Identifier: {$preOrderIdentifier}");

        try {
            $preDeducted = $this->redisInventoryService->preDecrementStock($productId, $quantity);

            if (!$preDeducted) {
                Log::warning("Redis pre-deduction failed for product {$productId}: insufficient stock.");
                return response()->json([
                    'message' => '商品庫存不足，請稍後再試。'
                ], 400);
            }

            ProcessOrder::dispatch($productId, $quantity, $userId, $preOrderIdentifier);

            Log::info("Redis pre-deduction successful, Job dispatched for ProductID: {$productId}, Quantity: {$quantity}, UserID: {$userId}.");

            return response()->json([
                'message' => '訂單已提交，正在處理中。',
                'order_identifier' => $preOrderIdentifier
            ], 202);

        } catch (\Exception $e) {
            Log::error("Order request processing failed for ProductID: {$productId}, UserID: {$userId}. Error: " . $e->getMessage());

            if (isset($preDeducted) && $preDeducted) {
                $this->redisInventoryService->rollbackStock($productId, $quantity);
                Log::warning("API error occurred after Redis pre-deduction, rolling back stock for ProductID: {$productId}, Quantity: {$quantity}.");
            }

            return response()->json([
                'message' => '訂單處理異常，請稍後再試。'
            ], 500);
        }
    }
}
