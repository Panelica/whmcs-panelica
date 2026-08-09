<?php

use PHPUnit\Framework\TestCase;

/**
 * The small decisions that shape what an operator sees and what the module
 * will let through: scope checks, the advanced override parser, and the
 * figures printed on the client area.
 */
final class HelpersTest extends TestCase
{
    public function testWildcardScopesSatisfyEverythingTheModuleNeeds(): void
    {
        $this->assertSame([], panelica_missingScopes(['*:*']));
    }

    public function testAResourceWildcardOnlyCoversItsOwnResource(): void
    {
        $missing = panelica_missingScopes(['accounts:*']);

        $this->assertNotContains('accounts:read', $missing);
        $this->assertNotContains('accounts:delete', $missing);
        $this->assertContains('domains:write', $missing);
    }

    public function testAnEmptyKeyIsReportedAsMissingEverything(): void
    {
        $this->assertSame(
            ['accounts:read', 'accounts:write', 'accounts:delete', 'domains:write', 'plans:read', 'plans:write', 'bandwidth:read'],
            panelica_missingScopes([])
        );
    }

    public function testALookalikeScopeDoesNotCount(): void
    {
        $this->assertContains('accounts:read', panelica_missingScopes(['accounts:readonly', 'accounts']));
    }

    public function testAdvancedOverridesKeepOnlyKnownKeysAndTheirTypes(): void
    {
        $parsed = panelica_parseAdvanced("php_memory_limit = 512\nnot_a_real_key = 5\n\n# comment line\n");

        $this->assertArrayNotHasKey('not_a_real_key', $parsed);
        $this->assertArrayNotHasKey('# comment line', $parsed);
    }

    public function testAdvancedOverridesIgnoreLinesWithoutAValue(): void
    {
        $this->assertSame([], panelica_parseAdvanced("just a sentence\n   \n"));
    }

    public function testAdvancedOverrideKeysAreCaseInsensitiveAndTrimmed(): void
    {
        $whitelist = panelica_advancedWhitelist();
        $key = (string) array_key_first($whitelist);
        $parsed = panelica_parseAdvanced('  ' . strtoupper($key) . ' =  7  ');

        $this->assertArrayHasKey($key, $parsed);
    }

    public function testZeroOrLessMeansUnlimited(): void
    {
        $this->assertSame('unlimited', panelica_formatMb(0));
        $this->assertSame('unlimited', panelica_formatMb(-1));
    }

    public function testWholeGigabytesArePrintedAsGigabytes(): void
    {
        $this->assertSame('1 GB', panelica_formatMb(1024));
        $this->assertSame('5 GB', panelica_formatMb(5120));
    }

    public function testAnythingElseStaysInMegabytes(): void
    {
        $this->assertSame('1536 MB', panelica_formatMb(1536));
        $this->assertSame('50 MB', panelica_formatMb(50));
    }

    public function testAccountDomainNamesAreLowercasedAndToleratePayloadShapes(): void
    {
        $names = panelica_acctDomainNames(['data' => [
            ['domain_name' => 'Mine.TEST'],
            ['name' => 'second.test'],
            ['domain' => 'third.test'],
            ['unexpected' => 'ignored.test'],
            'not-an-array',
        ]]);

        $this->assertSame(['mine.test', 'second.test', 'third.test'], $names);
    }
}
