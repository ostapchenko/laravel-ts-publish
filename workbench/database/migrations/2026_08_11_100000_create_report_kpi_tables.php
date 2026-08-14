<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('marketing_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('kpis', function (Blueprint $table) {
            $table->id();
            $table->morphs('reportable');
            $table->integer('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpis');
        Schema::dropIfExists('marketing_reports');
        Schema::dropIfExists('sales_reports');
    }
};
