<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The website serves the package's browser verifier from web/public/vendor/verity (the website is
 * a separate site with no vendor/ directory). The copy must be byte-for-byte the installed release's
 * reviewed files, as listed in its SOURCE-MANIFEST.txt; after a package upgrade, copy them again:
 *   cp vendor/sprouthub/verity-engine/resources/js/{drand,index,verify-record,verity}.js web/public/vendor/verity/
 *   cp vendor/sprouthub/verity-engine/resources/js/verify-page/verity-verify.js web/public/vendor/verity/verify-page/
 */
class WebVerifierCopyTest extends TestCase
{
    private const FILES = ['drand.js', 'index.js', 'verify-record.js', 'verity.js', 'verify-page/verity-verify.js'];

    public function test_the_website_copy_matches_the_installed_release_and_its_manifest(): void
    {
        $root = dirname(__DIR__, 2);
        $package = $root.'/vendor/sprouthub/verity-engine';
        $manifest = file_get_contents($package.'/SOURCE-MANIFEST.txt');

        foreach (self::FILES as $f) {
            $copy = hash_file('sha256', "{$root}/web/public/vendor/verity/{$f}");
            $this->assertSame(hash_file('sha256', "{$package}/resources/js/{$f}"), $copy, "web/public/vendor/verity/{$f} differs from the installed package");
            $this->assertStringContainsString("{$copy}  resources/js/{$f}", $manifest, "{$f} is not the file in the release's SOURCE-MANIFEST.txt");
        }
    }
}
