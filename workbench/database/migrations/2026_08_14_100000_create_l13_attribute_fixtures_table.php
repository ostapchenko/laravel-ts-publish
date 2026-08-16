<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Named to not match Laravel13Attributes's conventional table name (laravel13_attributes),
        // so #[Table] is load-bearing: an ignored attribute would fail the column lookup outright.
        Schema::create('l13_attribute_fixtures', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('secret_token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('l13_attribute_fixtures');
    }
};
