<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_members', function (Blueprint $table) {
            $table->id();

            $table->foreignId('trip_id')
                  ->constrained('trips')
                  ->cascadeOnDelete();

            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->string('guest_name')->nullable();
            $table->string('guest_contact')->nullable();

            $table->enum('role', ['admin', 'member'])
                  ->default('member');

            $table->decimal('balance', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['trip_id', 'user_id']);
            $table->index(['trip_id', 'guest_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_members');
    }
};
