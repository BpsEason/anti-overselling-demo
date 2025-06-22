<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class RedisInventoryService
{
    protected $redis;
    protected $prefix = 'product:stock:';

    public function __construct()
    {
        $this->redis = Redis::connection('inventory');
    }

    public function initStock(int $productId, int $stock): void
    {
        $this->redis->set($this->prefix . $productId, $stock);
        Log::info("Redis stock for product {$productId} initialized to {$stock}.");
    }

    public function getStock(int $productId): int
    {
        return (int) $this->redis->get($this->prefix . $productId);
    }

    public function preDecrementStock(int $productId, int $quantity): bool
    {
        $newStock = $this->redis->decrby($this->prefix . $productId, $quantity);

        if ($newStock < 0) {
            $this->redis->incrby($this->prefix . $productId, $quantity);
            Log::warning("Redis pre-decrement failed for product {$productId}: insufficient stock. Attempted: {$quantity}, Current: " . ($newStock + $quantity));
            return false;
        }

        Log::info("Redis pre-decrement successful for product {$productId}: deducted {$quantity}, new stock: {$newStock}.");
        return true;
    }

    public function rollbackStock(int $productId, int $quantity): void
    {
        $this->redis->incrby($this->prefix . $productId, $quantity);
        Log::info("Redis stock rolled back for product {$productId}: added {$quantity}.");
    }
}
