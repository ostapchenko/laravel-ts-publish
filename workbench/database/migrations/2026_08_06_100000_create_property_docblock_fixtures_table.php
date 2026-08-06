<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backs the PropertyDocblock* fixtures that exercise
     * ModelAttributeResolver::refineWithPropertyDocblock() — a vague `array`
     * cast refined by a class-level `@property`/`@property-read` docblock tag.
     */
    public function up(): void
    {
        Schema::create('property_docblock_fixtures', function (Blueprint $table) {
            $table->id();
            $table->json('tags')->nullable();
            $table->json('related_users')->nullable();
            $table->json('meta_info')->nullable();
            $table->json('owner_snapshot')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_docblock_fixtures');
    }
};
