<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();

            $table->foreignId('owner_id')
                  ->constrained('users')
                  ->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();

            $table->string('currency_code', 3)->default('IDR');

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->string('public_summary_token', 64)->nullable()->unique();

            $table->enum('status', ['planning', 'ongoing', 'finished', 'cancelled'])
                  ->default('planning');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
