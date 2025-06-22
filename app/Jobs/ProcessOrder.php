<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Product;
use App\Services\RedisInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 60;

    protected $productId;
    protected $quantity;
    protected $userId;
    protected $preOrderIdentifier;

    public function __construct(int $productId, int $quantity, int $userId, ?string $preOrderIdentifier = null)
    {
        $this->productId = $productId;
        $this->quantity = $quantity;
        $this->userId = $userId;
        $this->preOrderIdentifier = $preOrderIdentifier;

        $this->onQueue(env('REDIS_QUEUE', 'order-processing-queue'));
    }

    public function handle(RedisInventoryService $redisInventoryService): void
    {
        Log::info("Processing order job started for ProductID: {$this->productId}, Quantity: {$this->quantity}, UserID: {$this->userId}, Identifier: {$this->preOrderIdentifier}");

        try {
            DB::transaction(function () use ($redisInventoryService) {
                $product = Product::lockForUpdate()->find($this->productId);

                if (!$product) {
                    Log::error("Product ID: {$this->productId} not found during order processing.");
                    throw new \Exception("Product not found.");
                }

                if ($product->stock < $this->quantity) {
                    Log::warning("DB stock mismatch for product {$this->productId}. DB stock: {$product->stock}, Requested: {$this->quantity}. Rolling back Redis stock.");
                    $redisInventoryService->rollbackStock($this->productId, $this->quantity);
                    throw new \Exception("Insufficient database stock for product.");
                }

                $product->stock -= $this->quantity;
                $product->save();

                Order::create([
                    'product_id' => $this->productId,
                    'quantity' => $this->quantity,
                    'user_id' => $this->userId,
                    'status' => 'completed',
                ]);

                Log::info("Order successfully processed for ProductID: {$this->productId}, Quantity: {$this->quantity}, UserID: {$this->userId}. New DB stock: {$product->stock}");
            });
        } catch (\Exception $e) {
            Log::error("Order processing failed for ProductID: {$this->productId}, UserID: {$this->userId}. Error: " . $e->getMessage());
            throw $e; # Re-throw to allow Laravel's queue worker to handle retries/failures
        }
    }

    public function failed(\Throwable $exception)
    {
        Log::critical("ProcessOrder Job failed permanently for ProductID: {$this->productId}, Quantity: {$this->quantity}, UserID: {$this->userId}. Reason: " . $exception->getMessage());
        $redisInventoryService = app(RedisInventoryService::class);
        $redisInventoryService->rollbackStock($this->productId, $this->quantity);
        Log::info("Redis stock rolled back on Job permanent failure for product {$this->productId}.");
    }
}
