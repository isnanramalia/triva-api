<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trip_id')
                  ->constrained('trips')
                  ->cascadeOnDelete();

            $table->foreignId('created_by_member_id')
                  ->constrained('trip_members')
                  ->cascadeOnDelete();

            $table->foreignId('paid_by_member_id')
                  ->constrained('trip_members')
                  ->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();
            
            $table->dateTime('date');

            $table->decimal('total_amount', 14, 2);

            $table->enum('split_type', ['equal', 'shares', 'itemized', 'adjustment'])
                  ->default('equal');

            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['trip_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
