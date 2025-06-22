<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Order;
use App\Services\RedisInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Http\Request; // 確保引入 Request
use App\Http\Controllers\OrderController; // 確保引入 OrderController
use Mockery; // 引入 Mockery

class OrderApiTest extends TestCase
{
    use RefreshDatabase; // 每次測試後刷新資料庫

    protected $productId = 1;
    protected $productName = '測試商品A';
    protected $initialStock = 10;
    protected $userId = 101;

    protected function setUp(): void
    {
        parent::setUp();
        // 確保使用獨立的 Redis DB 進行測試
        config(['database.redis.inventory.database' => 9]);

        // Mock RedisInventoryService 以避免在測試中實際操作 Redis
        $this->redisInventoryService = Mockery::mock(RedisInventoryService::class);
        $this->app->instance(RedisInventoryService::class, $this->redisInventoryService);

        Queue::fake(); // 模擬佇列，不實際執行 Job
        Log::spy(); // 監聽 Log

        // 在所有測試前初始化一個商品
        Product::factory()->create([
            'id' => $this->productId,
            'name' => $this->productName,
            'stock' => $this->initialStock,
        ]);
        // 對於測試，我們模擬 Redis initStock，而不是實際操作 Redis
        $this->redisInventoryService->shouldReceive('initStock')->andReturn(true);
        $this->redisInventoryService->shouldReceive('getStock')->andReturn($this->initialStock); // 預設返回初始庫存
    }

    protected function tearDown(): void
    {
        Mockery::close(); // 確保 Mockery 實例被關閉
        parent::tearDown();
    }

    /** @test */
    public function it_can_initialize_product_and_redis_stock()
    {
        // 對於這個測試，我們需要一個「真正」的 Redis 服務來驗證
        // 所以，在這個特定的測試中，我們要取消 Mock RedisInventoryService
        // 或者，更好的方法是重新建立一個沒有被 Mock 的實例
        $realRedisService = new RedisInventoryService();
        $this->app->instance(RedisInventoryService::class, $realRedisService);

        // 清理一下測試 productId 2 的 Redis 庫存
        Redis::connection('inventory')->del('product:stock:2');

        $response = $this->postJson('/api/init-stock', [
            'product_id' => 2,
            'product_name' => '新商品B',
            'initial_db_stock' => 50,
            'initial_redis_stock' => 50,
        ]);

        $response->assertOk()
                 ->assertJson([
                     'message' => '商品及 Redis 庫存初始化成功！',
                     'product_id' => 2,
                     'db_stock' => 50,
                     'redis_stock' => 50
                 ]);

        $this->assertDatabaseHas('products', ['id' => 2, 'stock' => 50]);
        $this->assertEquals(50, $realRedisService->getStock(2));

        // 清理測試後為 Product ID 2 留下的 Redis 數據
        Redis::connection('inventory')->del('product:stock:2');
    }

    /** @test */
    public function it_can_place_an_order_successfully_and_dispatch_job()
    {
        // 模擬 Redis 預扣成功
        $this->redisInventoryService->shouldReceive('preDecrementStock')
                                    ->once()
                                    ->with($this->productId, 1)
                                    ->andReturn(true);

        $response = $this->postJson('/api/place-order', [
            'product_id' => $this->productId,
            'quantity' => 1,
            'user_id' => $this->userId,
        ]);

        $response->assertAccepted() // HTTP 202 Accepted
                 ->assertJsonStructure(['message', 'order_identifier']);

        // 驗證 Job 是否被派發到佇列
        Queue::assertPushed(\App\Jobs\ProcessOrder::class, 1);
        Queue::assertPushed(\App\Jobs\ProcessOrder::class, function ($job) {
            return $job->productId === $this->productId && $job->quantity === 1 && $job->userId === $this->userId;
        });

        // 驗證資料庫庫存和訂單尚未立即更新 (因為是異步處理)
        $this->assertDatabaseHas('products', ['id' => $this->productId, 'stock' => $this->initialStock]);
        $this->assertDatabaseMissing('orders', ['product_id' => $this->productId, 'user_id' => $this->userId]);
    }

    /** @test */
    public function it_returns_error_if_redis_stock_is_insufficient()
    {
        // 模擬 Redis 預扣失敗
        $this->redisInventoryService->shouldReceive('preDecrementStock')
                                    ->once()
                                    ->with($this->productId, 1)
                                    ->andReturn(false);

        $response = $this->postJson('/api/place-order', [
            'product_id' => $this->productId,
            'quantity' => 1,
            'user_id' => $this->userId,
        ]);

        $response->assertStatus(400) // Bad Request
                 ->assertJson(['message' => '商品庫存不足，請稍後再試。']);

        // 驗證 Job 未被派發
        Queue::assertNotPushed(\App\Jobs\ProcessOrder::class);
    }

    /** @test */
    public function it_handles_api_validation_errors()
    {
        // 這裡不需要 mock preDecrementStock，因為驗證會先失敗
        $this->redisInventoryService->shouldNotReceive('preDecrementStock');

        $response = $this->postJson('/api/place-order', [
            'product_id' => $this->productId,
            'quantity' => 0, // 無效的數量
            'user_id' => 'abc', // 無效的用戶ID
        ]);

        $response->assertStatus(422) // Unprocessable Entity
                 ->assertJsonValidationErrors(['quantity', 'user_id']);

        // 驗證 Job 未被派發
        Queue::assertNotPushed(\App\Jobs\ProcessOrder::class);
    }

    /** @test */
    public function it_can_get_redis_stock()
    {
        // 這個測試需要一個真實的 Redis 服務來驗證
        $realRedisService = new RedisInventoryService();
        $this->app->instance(RedisInventoryService::class, $realRedisService);
        $realRedisService->initStock($this->productId, 77); // 設置一個特定值

        $response = $this->getJson("/api/get-redis-stock/{$this->productId}");

        $response->assertOk()
                 ->assertJson([
                     'product_id' => $this->productId,
                     'redis_stock' => 77
                 ]);
        Redis::connection('inventory')->del('product:stock:' . $this->productId); // 清理
    }

    /** @test */
    public function it_can_get_db_stock()
    {
        $product = Product::find($this->productId); // 獲取當前商品實例
        $product->update(['stock' => 88]); // 更新 DB 庫存為特定值

        $response = $this->getJson("/api/get-db-stock/{$this->productId}");

        $response->assertOk()
                 ->assertJson([
                     'product_id' => $this->productId,
                     'db_stock' => 88
                 ]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_product_db_stock()
    {
        $nonExistentProductId = 9999;
        $response = $this->getJson("/api/get-db-stock/{$nonExistentProductId}");
        $response->assertNotFound()->assertJson(['message' => '商品不存在']);
    }

    /** @test */
    public function it_handles_api_error_after_redis_deduction_and_rolls_back()
    {
        // 這個測試需要一個真實的 Redis 服務來驗證回滾
        $realRedisService = new RedisInventoryService();
        $this->app->instance(RedisInventoryService::class, $realRedisService);

        $initialRedisStock = 10;
        $realRedisService->initStock($this->productId, $initialRedisStock);

        // 模擬 preDecrementStock 成功
        $this->redisInventoryService->shouldReceive('preDecrementStock')
                                    ->once()
                                    ->andReturn(true);
        // 模擬 rollbackStock 被調用
        $this->redisInventoryService->shouldReceive('rollbackStock')
                                    ->once()
                                    ->with($this->productId, 1); // 假設扣減 1

        Log::spy(); // 監聽 Log

        // 我們將直接呼叫控制器的方法，並模擬一個錯誤在其內部發生
        $controller = new OrderController($this->redisInventoryService);

        // 使用 try-catch 模擬控制器內部拋出異常
        try {
            // 模擬在 redisInventoryService->preDecrementStock 之後
            // 但在 ProcessOrder::dispatch 之前或之後立即發生一個導致 API 層異常的錯誤
            // 這裡我們模擬控制器內部因其他原因拋出異常
            Mockery::mock('alias:\Illuminate\Support\Facades\Log')
                   ->shouldReceive('info')
                   ->andThrow(new \Exception('Simulated internal API error after pre-deduction'));

            $request = Request::create('/api/place-order', 'POST', [
                'product_id' => $this->productId,
                'quantity' => 1,
                'user_id' => $this->userId,
            ]);

            $controller->placeOrder($request);

        } catch (\Exception $e) {
            $this->assertEquals('Simulated internal API error after pre-deduction', $e->getMessage());

            // 驗證 Log 訊息
            Log::shouldHaveReceived('error')->withArgs(function ($message) {
                return Str::contains($message, 'Order request processing failed') && Str::contains($message, 'Simulated internal API error');
            })->once();
            Log::shouldHaveReceived('warning')->withArgs(function ($message) {
                return Str::contains($message, 'API error occurred after Redis pre-deduction, rolling back stock');
            })->once();

            // 驗證 Redis 庫存被回滾 (因為 Mock 了 service，這裡實際是驗證 Mock 收到調用)
            $this->redisInventoryService->shouldHaveReceived('rollbackStock');

        } finally {
            // 清理為這個特定測試設置的 Mock
            Mockery::close();
            // 重置 RedisInventoryService 為真實實例，以免影響後續測試
            $this->app->instance(RedisInventoryService::class, new RedisInventoryService());
            // 清理 Product ID 1 的 Redis 庫存，如果它被真實操作過
            Redis::connection('inventory')->del('product:stock:' . $this->productId);
        }
    }
}
