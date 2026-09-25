<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scope-to-capability map, one variant at a time.
 *
 * DocumentedBehaviourTest already shows one scope opening one tab and an empty
 * key opening none. What was untested: the master wildcard, the per-resource
 * wildcard, that every tab answers to exactly the scope its map names, and
 * that a read scope never opens a write tab.
 */
final class SelfServiceCapsScopeVariantsTest extends TestCase
{
    /** Tab => the exact scope the module derives it from. */
    private const TAB_SCOPE = [
        'email'     => 'email:write',
        'ftp'       => 'ftp:write',
        'subdomain' => 'domains:write',
        'dns'       => 'dns:write',
        'cron'      => 'accounts:write',
        'ssl'       => 'domains:write',
        'files'     => 'files:read',
        'backup'    => 'backups:read',
        'mysql'     => 'databases:write',
        'redirect'  => 'domains:write',
        'settings'  => 'domains:write',
        'wordpress' => 'accounts:read',
    ];

    public function testTheMasterWildcardOpensEveryTab(): void
    {
        foreach (panelica_selfServiceCaps(['*:*']) as $tab => $allowed) {
            $this->assertTrue($allowed, $tab);
        }
    }

    public function testNoScopesMeansNoTabs(): void
    {
        $caps = panelica_selfServiceCaps([]);

        $this->assertSame(array_keys(self::TAB_SCOPE), array_keys($caps), 'the map covers exactly these tabs');

        foreach ($caps as $tab => $allowed) {
            $this->assertFalse($allowed, $tab);
        }
    }

    public static function tabProvider(): array
    {
        $cases = [];
        foreach (self::TAB_SCOPE as $tab => $scope) {
            $cases[$tab] = [$tab, $scope];
        }

        return $cases;
    }

    #[DataProvider('tabProvider')]
    public function testTheExactScopeOpensItsTabAndOnlyTabsSharingIt(string $tab, string $scope): void
    {
        $caps = panelica_selfServiceCaps([$scope]);

        $this->assertTrue($caps[$tab], $tab . ' should follow ' . $scope);

        foreach ($caps as $otherTab => $allowed) {
            $expected = (self::TAB_SCOPE[$otherTab] === $scope);
            $this->assertSame($expected, $allowed, sprintf(
                'scope %s: tab %s expected %s', $scope, $otherTab, $expected ? 'open' : 'closed'
            ));
        }
    }

    #[DataProvider('tabProvider')]
    public function testThePerResourceWildcardOpensTheSameTabs(string $tab, string $scope): void
    {
        [$resource] = explode(':', $scope, 2);
        $caps = panelica_selfServiceCaps([$resource . ':*']);

        $this->assertTrue($caps[$tab], $tab . ' should follow ' . $resource . ':*');
    }

    public function testAReadScopeDoesNotOpenAWriteTab(): void
    {
        $caps = panelica_selfServiceCaps(['email:read', 'domains:read', 'databases:read', 'ftp:read', 'dns:read']);

        foreach (['email', 'ftp', 'subdomain', 'dns', 'ssl', 'mysql', 'redirect', 'settings'] as $writeTab) {
            $this->assertFalse($caps[$writeTab], $writeTab);
        }
    }

    public function testAWriteScopeAlsoCoversItsOwnReadTabsOnlyViaTheWildcard(): void
    {
        // accounts:write opens cron (its exact scope) but NOT wordpress, whose
        // map asks for accounts:read; only accounts:* or *:* covers both.
        $exact = panelica_selfServiceCaps(['accounts:write']);
        $this->assertTrue($exact['cron']);
        $this->assertFalse($exact['wordpress']);

        $wild = panelica_selfServiceCaps(['accounts:*']);
        $this->assertTrue($wild['cron']);
        $this->assertTrue($wild['wordpress']);
    }

    public function testUnrelatedScopesDoNotBleedIn(): void
    {
        $caps = panelica_selfServiceCaps(['plans:write', 'server:read', 'nonsense']);

        foreach ($caps as $tab => $allowed) {
            $this->assertFalse($allowed, $tab);
        }
    }
}
