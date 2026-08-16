<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;

class IssueApiToken extends Command
{
    protected $signature = 'linkforge:token
        {name : A label for this token, e.g. "dashboard" or "ci"}
        {--abilities=links:read,links:write,analytics:read : Comma-separated abilities}
        {--rate-limit= : Per-minute request cap for this token}';

    protected $description = 'Issue an API token for the management and analytics API';

    public function handle(): int
    {
        $abilities = array_filter(array_map('trim', explode(',', (string) $this->option('abilities'))));
        $rateLimit = $this->option('rate-limit') !== null ? (int) $this->option('rate-limit') : null;

        [$token, $plaintext] = ApiToken::issue($this->argument('name'), $abilities, $rateLimit);

        $this->info("Issued token #{$token->id} ({$token->name}).");
        $this->newLine();
        $this->line($plaintext);
        $this->newLine();
        $this->warn('Only the hash is stored — copy this value now, it cannot be shown again.');

        return self::SUCCESS;
    }
}
