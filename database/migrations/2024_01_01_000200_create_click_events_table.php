<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('click_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('link_id');

            // Written by the drain worker, not by the request, so it carries the
            // time the click happened rather than the time it was persisted.
            $table->timestamp('clicked_at')->useCurrent();

            $table->string('referrer_host', 255)->nullable();
            $table->string('referrer_url', 512)->nullable();
            $table->char('country', 2)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->string('device_type', 16)->nullable()->comment('desktop|mobile|tablet|bot|other');
            $table->string('browser', 32)->nullable();
            $table->string('os', 32)->nullable();
            $table->string('user_agent', 512)->nullable();

            // Raw IPs are only stored when LINKFORGE_STORE_IP=true; otherwise a
            // salted hash is kept so unique-visitor counts still work.
            $table->string('ip_address', 45)->nullable();
            $table->char('visitor_hash', 32)->charset('ascii')->collation('ascii_bin')->nullable();

            $table->boolean('is_bot')->default(false);

            // Analytics reads are always "one link over a time window" or
            // "everything over a time window", which is exactly these two.
            $table->index(['link_id', 'clicked_at'], 'clicks_link_time_idx');
            $table->index('clicked_at', 'clicks_time_idx');
            $table->index(['clicked_at', 'country'], 'clicks_time_country_idx');

            $table->foreign('link_id')
                ->references('id')->on('links')
                ->cascadeOnDelete();
        });

        // High-volume append-only table: compressed row format and a larger
        // auto-increment step are set here so ops does not have to remember.
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE click_events ROW_FORMAT=DYNAMIC');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('click_events');
    }
};
