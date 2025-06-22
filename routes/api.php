<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Services\RedisInventoryService;
use App\Models\Product;
use OpenApi\Annotations as OA;

Route::post('/place-order', [OrderController::class, 'placeOrder']);

/**
 * @OA\Post(
 * path="/api/init-stock",
 * summary="初始化商品和Redis庫存",
 * tags={"Stock"},
 * operationId="initStock",
 * @OA\RequestBody(
 * required=true,
 * description="庫存初始化請求參數",
 * @OA\JsonContent(
 * required={"product_id", "initial_db_stock", "initial_redis_stock", "product_name"},
 * @OA\Property(property="product_id", type="integer", example=1, description="商品ID"),
 * @OA\Property(property="product_name", type="string", example="限定版潮鞋", description="商品名稱"),
 * @OA\Property(property="initial_db_stock", type="integer", example=100, description="初始資料庫庫存"),
 * @OA\Property(property="initial_redis_stock", type="integer", example=100, description="初始Redis庫存")
 * )
 * ),
 * @OA\Response(
 * response=200,
 * description="商品及 Redis 庫存初始化成功",
 * @OA\JsonContent(
 * @OA\Property(property="message", type="string", example="商品及 Redis 庫存初始化成功！"),
 * @OA\Property(property="product_id", type="integer", example=1),
 * @OA\Property(property="db_stock", type="integer", example=100),
 * @OA\Property(property="redis_stock", type="integer", example=100)
 * )
 * ),
 * @OA\Response(
 * response=422,
 * description="驗證失敗",
 * @OA\JsonContent(
 * @OA\Property(property="message", type="string", example="The given data was invalid."),
 * @OA\Property(property="errors", type="object")
 * )
 * )
 * )
 */
Route::post('/init-stock', function (Request $request, RedisInventoryService $redisInventoryService) {
    $request->validate([
        'product_id' => 'required|integer',
        'initial_db_stock' => 'required|integer|min:0',
        'initial_redis_stock' => 'required|integer|min:0',
        'product_name' => 'required|string|max:255'
    ]);

    $productId = $request->input('product_id');
    $initialDbStock = $request->input('initial_db_stock');
    $initialRedisStock = $request->input('initial_redis_stock');
    $productName = $request->input('product_name');

    $product = Product::updateOrCreate(
        ['id' => $productId],
        ['name' => $productName, 'stock' => $initialDbStock]
    );

    $redisInventoryService->initStock($productId, $initialRedisStock);

    return response()->json([
        'message' => '商品及 Redis 庫存初始化成功！',
        'product_id' => $product->id,
        'db_stock' => $product->stock,
        'redis_stock' => $redisInventoryService->getStock($product->id)
    ]);
});

/**
 * @OA\Get(
 * path="/api/get-redis-stock/{productId}",
 * summary="查詢Redis中的商品庫存",
 * tags={"Stock"},
 * operationId="getRedisStock",
 * @OA\Parameter(
 * name="productId",
 * in="path",
 * required=true,
 * description="商品ID",
 * @OA\Schema(type="integer", example=1)
 * ),
 * @OA\Response(
 * response=200,
 * description="成功獲取Redis庫存",
 * @OA\JsonContent(
 * @OA\Property(property="product_id", type="integer", example=1),
 * @OA\Property(property="redis_stock", type="integer", example=99)
 * )
 * )
 * )
 */
Route::get('/get-redis-stock/{productId}', function (int $productId, RedisInventoryService $redisInventoryService) {
    $stock = $redisInventoryService->getStock($productId);
    return response()->json(['product_id' => $productId, 'redis_stock' => $stock]);
});

/**
 * @OA\Get(
 * path="/api/get-db-stock/{productId}",
 * summary="查詢資料庫中的商品庫存",
 * tags={"Stock"},
 * operationId="getDbStock",
 * @OA\Parameter(
 * name="productId",
 * in="path",
 * required=true,
 * description="商品ID",
 * @OA\Schema(type="integer", example=1)
 * ),
 * @OA\Response(
 * response=200,
 * description="成功獲取資料庫庫存",
 * @OA\JsonContent(
 * @OA\Property(property="product_id", type="integer", example=1),
 * @OA\Property(property="db_stock", type="integer", example=99)
 * )
 * )
 * )
 */
Route::get('/get-db-stock/{productId}', function (int $productId) {
    $product = Product::find($productId);
    if (!$product) {
        return response()->json(['message' => '商品不存在'], 404);
    }
    return response()->json(['product_id' => $productId, 'db_stock' => $product->stock]);
});
