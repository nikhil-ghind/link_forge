<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pre-aggregated per-link/per-day rollups. Analytics ranges longer than
     * `linkforge.analytics.rollup_threshold_days` are served from here so the
     * dashboard never scans millions of click_events rows.
     */
    public function up(): void
    {
        Schema::create('daily_link_stats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('link_id');
            $table->date('stat_date');

            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('unique_visitors')->default(0);
            $table->unsignedBigInteger('bot_clicks')->default(0);

            // Small top-N maps kept as JSON: {"google.com": 120, "t.co": 44}
            $table->json('referrers')->nullable();
            $table->json('countries')->nullable();
            $table->json('devices')->nullable();
            $table->json('browsers')->nullable();

            $table->timestamps();

            $table->unique(['link_id', 'stat_date'], 'daily_link_date_unique');
            $table->index('stat_date', 'daily_stat_date_idx');

            $table->foreign('link_id')
                ->references('id')->on('links')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_link_stats');
    }
};
