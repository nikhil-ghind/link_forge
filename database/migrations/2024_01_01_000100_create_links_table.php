<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('links', function (Blueprint $table) {
            $table->bigIncrements('id');

            // Slugs are ASCII base62; a binary collation gives a case-sensitive
            // exact-match index and keeps the unique key compact.
            $table->string('slug', 32)->charset('ascii')->collation('ascii_bin');

            $table->string('target_url', 2048);

            // Deterministic hash of the target used to detect duplicates
            // without indexing a 2KB column.
            $table->char('target_hash', 64)->charset('ascii')->collation('ascii_bin');

            $table->string('title', 255)->nullable();
            $table->string('domain', 255)->nullable()->comment('host of target_url, denormalised for reporting');

            $table->unsignedTinyInteger('redirect_status')->default(302);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_custom_alias')->default(false);

            $table->unsignedBigInteger('max_clicks')->nullable()->comment('null = unlimited');
            $table->unsignedBigInteger('click_count')->default(0)->comment('denormalised counter maintained by the click drain');

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();

            $table->string('created_by', 100)->nullable();
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique('slug', 'links_slug_unique');
            $table->index('target_hash', 'links_target_hash_idx');
            $table->index(['is_active', 'created_at'], 'links_active_created_idx');
            $table->index('click_count', 'links_click_count_idx');
            $table->index('expires_at', 'links_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('links');
    }
};
