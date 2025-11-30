<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trip_id')
                  ->constrained('trips')
                  ->cascadeOnDelete();

            $table->foreignId('from_member_id')
                  ->constrained('trip_members')
                  ->cascadeOnDelete();

            $table->foreignId('to_member_id')
                  ->constrained('trip_members')
                  ->cascadeOnDelete();

            $table->decimal('amount', 14, 2);

            $table->enum('status', ['pending', 'confirmed'])
                  ->default('pending');

            $table->foreignId('created_by_member_id')
                  ->constrained('trip_members')
                  ->cascadeOnDelete();

            $table->dateTime('confirmed_at')->nullable();

            $table->timestamps();

            $table->index(['trip_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
