<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_splits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')
                  ->constrained('transactions')
                  ->cascadeOnDelete();

            $table->foreignId('member_id')
                  ->constrained('trip_members')
                  ->cascadeOnDelete();

            $table->decimal('amount', 14, 2);

            $table->timestamps();

            $table->unique(['transaction_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_splits');
    }
};
