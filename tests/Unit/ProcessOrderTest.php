<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Product;
use App\Models\Order;
use App\Jobs\ProcessOrder;
use App\Services\RedisInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery;

class ProcessOrderTest extends TestCase
{
    use RefreshDatabase; // 每次測試後刷新資料庫

    protected function setUp(): void
    {
        parent::setUp();
        // 確保 Redis DB 為測試環境的 DB 號
        config(['database.redis.inventory.database' => 9]);
        Log::spy(); // 監聽 Log

        // Mock RedisInventoryService 以隔離外部依賴
        $this->redisInventoryServiceMock = Mockery::mock(RedisInventoryService::class);
        $this->app->instance(RedisInventoryService::class, $this->redisInventoryServiceMock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_processes_order_successfully_and_deducts_db_stock()
    {
        $product = Product::factory()->create(['stock' => 10]);
        $quantity = 5;
        $userId = 1;

        $this->redisInventoryServiceMock->shouldReceive('rollbackStock')->never();

        $job = new ProcessOrder($product->id, $quantity, $userId);
        $job->handle($this->redisInventoryServiceMock); // 直接調用 handle 方法

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 5, // 10 - 5 = 5
        ]);

        $this->assertDatabaseHas('orders', [
            'product_id' => $product->id,
            'quantity' => $quantity,
            'user_id' => $userId,
            'status' => 'completed',
        ]);

        Log::shouldHaveReceived('info')->withArgs(function ($message) {
            return Str::contains($message, 'Order successfully processed');
        })->once();
    }

    /** @test */
    public function it_rolls_back_redis_stock_if_db_stock_is_insufficient()
    {
        $product = Product::factory()->create(['stock' => 3]); // DB 庫存不足
        $quantity = 5;
        $userId = 1;

        $this->redisInventoryServiceMock->shouldReceive('rollbackStock')
                                        ->once()
                                        ->with($product->id, $quantity);

        $job = new ProcessOrder($product->id, $quantity, $userId);

        // 預期會拋出異常
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient database stock for product.');

        try {
            $job->handle($this->redisInventoryServiceMock);
        } finally {
            // 確認資料庫庫存未被修改 (事務回滾)
            $this->assertDatabaseHas('products', [
                'id' => $product->id,
                'stock' => 3,
            ]);
            // 確認訂單未被創建
            $this->assertDatabaseMissing('orders', [
                'product_id' => $product->id,
                'user_id' => $userId,
            ]);

            Log::shouldHaveReceived('warning')->withArgs(function ($message) {
                return Str::contains($message, 'DB stock mismatch');
            })->once();
            Log::shouldHaveReceived('error')->withArgs(function ($message) {
                return Str::contains($message, 'Order processing failed');
            })->once();
        }
    }

    /** @test */
    public function it_rolls_back_redis_stock_if_product_not_found()
    {
        $productId = 999; // 不存在的商品ID
        $quantity = 5;
        $userId = 1;

        $this->redisInventoryServiceMock->shouldReceive('rollbackStock')
                                        ->never(); // 如果產品不存在，Job 在事務外就會失敗，不會調用rollbackStock

        $job = new ProcessOrder($productId, $quantity, $userId);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Product not found.');

        try {
            $job->handle($this->redisInventoryServiceMock);
        } finally {
            Log::shouldHaveReceived('error')->withArgs(function ($message) {
                return Str::contains($message, 'not found during order processing');
            })->once();
        }
    }

    /** @test */
    public function it_calls_failed_method_and_rolls_back_redis_stock_on_permanent_failure()
    {
        $product = Product::factory()->create(['stock' => 10]);
        $quantity = 5;
        $userId = 1;

        // 模擬 handle 方法中發生一個未預期的錯誤
        $this->redisInventoryServiceMock->shouldReceive('rollbackStock')
                                        ->once()
                                        ->with($product->id, $quantity)
                                        ->andReturnUsing(function($id, $qty) {
                                            // 這裡可以加上你希望的 Log 檢查
                                            Log::info("Mock Redis rollbackStock called for product {$id} with quantity {$qty}");
                                        });

        $job = new ProcessOrder($product->id, $quantity, $userId);

        // 模擬一個例外
        $exception = new \Exception('Simulated unexpected error');

        // 直接調用 failed 方法，通常這是由 Laravel 佇列系統在 Job 達到最大重試次數後調用的
        $job->failed($exception);

        Log::shouldHaveReceived('critical')->withArgs(function ($message) use ($exception) {
            return Str::contains($message, 'ProcessOrder Job failed permanently') && Str::contains($message, $exception->getMessage());
        })->once();

        Log::shouldHaveReceived('info')->withArgs(function ($message) {
            return Str::contains($message, 'Mock Redis rollbackStock called');
        })->once();
    }
}
