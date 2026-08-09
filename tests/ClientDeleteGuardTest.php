<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The other way into a delete.
 *
 * The client area has two doors onto the same operations. The AJAX endpoint
 * checks that the id belongs to this account before it deletes anything, and
 * says in a comment why: the module holds a server-wide key, so the panel will
 * remove whatever id it is handed. The form-post handlers - which WHMCS exposes
 * to any signed-in customer through
 * clientarea.php?action=productdetails&modop=custom&a=DeleteCron - took the id
 * straight from $_POST and passed it on.
 *
 * A customer with an id from someone else's account could therefore delete
 * that person's mailbox, cron job, database user, DNS record or backup.
 */
final class ClientDeleteGuardTest extends TestCase
{
    private const ACCOUNT = 'account-mine';
    private const DOMAIN = 'domain-mine';

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
    }

    /**
     * A panel holding one item that belongs to this account and one that does
     * not. The queue mirrors what the guard asks for: the account, its domains,
     * then the listing for the tab.
     */
    private function panel(string $tab, array $items): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::ACCOUNT, 'username' => 'mine', 'status' => 'active']]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::DOMAIN, 'domain_name' => 'mine.test']]]);
        $api->queueJson(['status' => 'success', 'data' => $items]);
        $api->queueJson(['status' => 'success']);

        return $api;
    }

    private function params(): array
    {
        return [
            'serverhostname' => 'panel.test',
            'serverip' => 'panel.test',
            'serverport' => 8443,
            'serverpassword' => 'pk',
            'serveraccesshash' => 'sk',
            'username' => 'mine',
        ];
    }

    /** The paths the fake was asked to call, so a delete can be seen. */
    private function calledPaths(FakePanelicaAPI $api): array
    {
        return array_map(fn ($sent) => $sent['method'] . ' ' . parse_url($sent['url'], PHP_URL_PATH), $api->sent);
    }

    public static function tabProvider(): array
    {
        return [
            'cron job' => ['cron', 'cron-of-someone-else'],
            'database user' => ['mysql', 'dbuser-of-someone-else'],
            'mailbox' => ['email', 'mailbox-of-someone-else'],
            'dns record' => ['dns', 'record-of-someone-else'],
            'forwarder' => ['forwarders', 'forwarder-of-someone-else'],
            'autoresponder' => ['autoresponders', 'autoresponder-of-someone-else'],
            'subdomain' => ['subdomains', 'subdomain-of-someone-else'],
            'redirect' => ['redirects', 'redirect-of-someone-else'],
            'ftp account' => ['ftp', 'ftp-of-someone-else'],
        ];
    }

    #[DataProvider('tabProvider')]
    public function testAnItemFromAnotherAccountIsNotDeleted(string $tab, string $foreignId): void
    {
        $api = $this->panel($tab, [
            ['id' => 'mine-1', 'user_id' => self::ACCOUNT, 'domain_id' => self::DOMAIN],
        ]);

        $threw = false;

        try {
            panelica_deleteOwnedItem($api, $this->params(), $tab, $foreignId);
        } catch (Exception $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the delete was allowed through');

        foreach ($this->calledPaths($api) as $path) {
            $this->assertStringNotContainsString($foreignId, $path, 'a foreign id reached the panel');
        }
    }

    public function testTheAccountsOwnItemIsStillDeleted(): void
    {
        $api = $this->panel('cron', [
            ['id' => 'mine-1', 'user_id' => self::ACCOUNT, 'domain_id' => self::DOMAIN],
        ]);

        panelica_deleteOwnedItem($api, $this->params(), 'cron', 'mine-1');

        $this->assertContains('DELETE /api/external/v1/cron-jobs/mine-1', $this->calledPaths($api));
    }

    public function testAnEmptyIdIsRefused(): void
    {
        $api = $this->panel('cron', []);

        $this->expectException(Exception::class);

        panelica_deleteOwnedItem($api, $this->params(), 'cron', '');
    }

    public function testABackupContainingAnotherAccountsDomainIsNotDeleted(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::ACCOUNT, 'username' => 'mine', 'status' => 'active']]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::DOMAIN, 'domain_name' => 'mine.test']]]);
        $api->queueJson(['status' => 'success', 'data' => [
            ['filename' => 'nightly-all.tar.gz', 'domain_names' => ['mine.test', 'theirs.test']],
        ]]);

        $this->expectException(Exception::class);

        panelica_requireOwnedBackup($api, $this->params(), 'nightly-all.tar.gz');
    }

    public function testABackupOfOnlyThisAccountsDomainsIsAllowed(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::ACCOUNT, 'username' => 'mine', 'status' => 'active']]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::DOMAIN, 'domain_name' => 'mine.test']]]);
        $api->queueJson(['status' => 'success', 'data' => [
            ['filename' => 'mine-only.tar.gz', 'domain_names' => ['mine.test']],
        ]]);

        panelica_requireOwnedBackup($api, $this->params(), 'mine-only.tar.gz');

        $this->addToAssertionCount(1);
    }

    public function testABackupThePanelDoesNotHaveIsRefused(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::ACCOUNT, 'username' => 'mine', 'status' => 'active']]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::DOMAIN, 'domain_name' => 'mine.test']]]);
        $api->queueJson(['status' => 'success', 'data' => []]);

        $this->expectException(Exception::class);

        panelica_requireOwnedBackup($api, $this->params(), 'anything.tar.gz');
    }

    /**
     * Every client-invocable handler that removes something has to go through
     * the guard. A new one added without it is the whole point of this test.
     */
    public function testEveryClientInvocableDeleteGoesThroughTheGuard(): void
    {
        $source = file_get_contents(__DIR__ . '/../modules/servers/panelica/panelica.php');
        $ungated = [];

        foreach (panelica_ClientAreaAllowedFunctions() as $name) {
            if (!preg_match('/^(Delete|Restore)/', $name)) {
                continue;
            }

            if (!preg_match('/function panelica_' . $name . '\(array \$params\)\s*\{(.*?)\n\}/s', $source, $m)) {
                $ungated[] = $name . ' (not found)';
                continue;
            }

            if (!str_contains($m[1], 'panelica_deleteOwnedItem')
                && !str_contains($m[1], 'panelica_requireOwnedBackup')
                && !str_contains($m[1], 'panelica_requireAccount')) {
                $ungated[] = $name;
            }
        }

        $this->assertSame([], $ungated);
    }
}
