<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->unsignedBigInteger('user_id');
            $table->string('status')->default('pending');
            $table->timestamps();

            $table->index('product_id');
            $table->index('user_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('orders');
    }
};
