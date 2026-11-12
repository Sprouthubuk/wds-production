<?php

namespace App\Console\Commands;

use App\Support\VerityKeyChecks;
use Sprouthub\Verity\Console\CheckCommand;
use Sprouthub\Verity\Support\CodeFingerprint;
use Sprouthub\Verity\Support\SecurityChecks;

/**
 * verity:check, plus WDS's key-format row (App\Support\VerityKeyChecks). Verity's own check only looks at
 * whether VERITY_SEED_KEY is set, so a key missing its "base64:" prefix passed and publishing then crashed.
 * This replaces the package's command under the same name, with the same options and exit rules as
 * Sprouthub\Verity\Console\CheckCommand::handle(): every Verity row runs (and is recorded on the audit
 * chain) unchanged; the WDS row is listed first.
 */
class VerityCheck extends CheckCommand
{
    public function handle(): int
    {
        $wds = VerityKeyChecks::run();
        $rows = array_merge($wds, SecurityChecks::runAndRecord());
        $fp = CodeFingerprint::current();
        $fail = (bool) array_filter($rows, fn ($r) => ! $r['ok'] && $r['level'] === 'FAIL');
        if ($this->option('json')) {
            $this->line((string) json_encode(['version' => CodeFingerprint::version()] + $fp + ['ok' => ! $fail, 'checks' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Verity '.CodeFingerprint::version().' ('.app()->environment().')'."  engine {$fp['engine_sha256']}  draw path {$fp['draw_path_sha256']}");
            foreach ($rows as $r) {
                $tag = $r['ok'] ? '<info> ok </info>' : ($r['level'] === 'FAIL' ? '<error>FAIL</error>' : '<comment>warn</comment>');
                $this->line("{$tag} {$r['label']}".(! $r['ok'] && $r['note'] ? " — {$r['note']}" : ''));
            }
        }

        return $fail && (app()->isProduction() || $this->option('strict')) ? self::FAILURE : self::SUCCESS;
    }
}
