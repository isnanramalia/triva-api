<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')
                  ->constrained('transactions')
                  ->cascadeOnDelete();

            $table->string('name');
            $table->decimal('unit_price', 14, 2);
            $table->integer('qty')->default(1);

            $table->json('raw_data')->nullable();

            $table->timestamps();

            $table->index('transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_items');
    }
};
