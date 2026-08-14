<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // Uncast on purpose: exercises the DB-native 'geometry' type map entry (Task 12)
            // through CustomKeyPost/SlugPost/UuidPost, which declare no casts() of their own.
            $table->geometry('geo_location')->nullable()->after('metadata');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['geo_location']);
        });
    }
};
