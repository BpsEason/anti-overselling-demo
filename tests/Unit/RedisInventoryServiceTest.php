<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\RedisInventoryService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery; // 引入 Mockery

class RedisInventoryServiceTest extends TestCase
{
    protected $redisService;
    protected $productId;
    protected $prefix = 'product:stock:';

    protected function setUp(): void
    {
        parent::setUp();
        // 確保使用獨立的 Redis DB 進行測試，不影響生產數據
        config(['database.redis.inventory.database' => 9]); // 使用 DB 9 進行測試

        $this->redisService = new RedisInventoryService();
        // 每次測試生成唯一的 productId，確保測試獨立性
        $this->productId = rand(1000, 9999);
        Redis::connection('inventory')->del($this->prefix . $this->productId); // 清理測試數據
    }

    protected function tearDown(): void
    {
        Redis::connection('inventory')->del($this->prefix . $this->productId); // 測試後清理
        Mockery::close(); // 確保 Mockery 實例被關閉
        parent::tearDown();
    }

    /** @test */
    public function it_can_initialize_stock()
    {
        $initialStock = 100;
        $this->redisService->initStock($this->productId, $initialStock);
        $this->assertEquals($initialStock, $this->redisService->getStock($this->productId));
    }

    /** @test */
    public function it_can_get_current_stock()
    {
        $this->redisService->initStock($this->productId, 50);
        $this->assertEquals(50, $this->redisService->getStock($this->productId));
    }

    /** @test */
    public function it_can_pre_decrement_stock_successfully()
    {
        $this->redisService->initStock($this->productId, 10);
        $result = $this->redisService->preDecrementStock($this->productId, 5);
        $this->assertTrue($result);
        $this->assertEquals(5, $this->redisService->getStock($this->productId));
    }

    /** @test */
    public function it_returns_false_and_rolls_back_if_pre_decrement_results_in_negative_stock()
    {
        $this->redisService->initStock($this->productId, 3);
        $result = $this->redisService->preDecrementStock($this->productId, 5); // 嘗試扣減超過現有庫存
        $this->assertFalse($result);
        $this->assertEquals(3, $this->redisService->getStock($this->productId)); // 庫存應該回滾到初始值
    }

    /** @test */
    public function it_can_rollback_stock()
    {
        $this->redisService->initStock($this->productId, 5);
        $this->redisService->preDecrementStock($this->productId, 2); // 扣減 2
        $this->assertEquals(3, $this->redisService->getStock($this->productId));

        $this->redisService->rollbackStock($this->productId, 2); // 回滾 2
        $this->assertEquals(5, $this->redisService->getStock($this->productId));
    }

    /** @test */
    public function it_handles_concurrent_pre_decrement_correctly()
    {
        $initialStock = 10;
        $this->redisService->initStock($this->productId, $initialStock);

        $deductQuantity = 1;
        $concurrentRequests = 15; // 模擬 15 個併發請求

        $successfulDeductions = 0;
        $failedDeductions = 0;

        $promises = [];
        for ($i = 0; $i < $concurrentRequests; $i++) {
            $promises[] = \GuzzleHttp\Promise\Coroutine::of(function () use ($deductQuantity) {
                // 直接調用服務方法，模擬併發
                return $this->redisService->preDecrementStock($this->productId, $deductQuantity);
            });
        }

        // 使用 GuzzleHttp\Promise\Utils::all 來等待所有協程完成
        // 需要在 composer.json 中加入 "guzzlehttp/guzzle": "^7.0"
        \GuzzleHttp\Promise\Utils::all($promises)->wait()->each(function ($result) use (&$successfulDeductions, &$failedDeductions) {
            if ($result) {
                $successfulDeductions++;
            } else {
                $failedDeductions++;
            }
        });

        // 成功的扣減次數應該等於初始庫存
        $this->assertEquals($initialStock, $successfulDeductions);
        // 失敗的扣減次數應該是總請求數 - 初始庫存
        $this->assertEquals($concurrentRequests - $initialStock, $failedDeductions);
        // 最終 Redis 庫存應該為 0
        $this->assertEquals(0, $this->redisService->getStock($this->productId));
    }
}
