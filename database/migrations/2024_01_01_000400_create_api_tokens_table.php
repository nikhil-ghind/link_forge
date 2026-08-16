<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 100);

            // Only the SHA-256 of the token is stored; the plaintext is shown
            // once at issue time.
            $table->char('token_hash', 64)->charset('ascii')->collation('ascii_bin');

            $table->string('abilities', 255)->default('links:read,links:write,analytics:read');
            $table->unsignedInteger('rate_limit_per_minute')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'api_tokens_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
