<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for the client-area DELETE doors, taken through the doors
 * themselves rather than the shared guard helper (ClientDeleteGuardTest covers
 * panelica_deleteOwnedItem directly).
 *
 * For every door: an owned id is deleted and the customer sees the success
 * flash; an empty id is refused with the door's own "Missing ... id" message
 * before a single request leaves for the panel; and a foreign id is refused
 * with the deliberately vague "Item not found." while nothing is deleted.
 *
 * The backup doors (RestoreBackup / DeleteBackup) get the same treatment with
 * their filename-and-contents ownership rule.
 */
final class ClientDeleteDoorsTest extends TestCase
{
    private const ACCOUNT = 'a1';
    private const DOMAIN = 'd1';

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        unset($_SESSION['panelica_flash']);
    }

    protected function tearDown(): void
    {
        panelica_test_clearApi();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        unset($_SESSION['panelica_flash']);
    }

    /** Panel answering the context lookups, then the tab listing. */
    private function panel(array $items): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => self::ACCOUNT, 'username' => 'mine', 'role' => 'USER', 'status' => 'active'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => self::DOMAIN, 'domain_name' => 'mine.test', 'created_at' => '2026-01-01T00:00:00Z'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => $items]);

        return $api;
    }

    private function params(): array
    {
        return ['username' => 'mine'];
    }

    private function calledPaths(FakePanelicaAPI $api): array
    {
        return array_map(fn ($s) => $s['method'] . ' ' . parse_url($s['url'], PHP_URL_PATH), $api->sent);
    }

    private function assertFlash(string $type, string $msg): void
    {
        $flash = panelica_takeFlash();
        $this->assertIsArray($flash, 'no flash was set');
        $this->assertSame($type, $flash['type']);
        $this->assertSame($msg, $flash['msg']);
    }

    /**
     * door, owned item row (as the tab listing returns it), the DELETE path the
     * panel must receive for id "mine-1", the missing-id message, the success
     * flash.
     */
    public static function doorProvider(): array
    {
        $owned = ['id' => 'mine-1', 'user_id' => self::ACCOUNT, 'domain_id' => self::DOMAIN];

        return [
            'email' => ['panelica_DeleteEmail', $owned, '/api/external/v1/email-accounts/mine-1', 'Missing email id.', 'Email account deleted.'],
            'ftp' => ['panelica_DeleteFtp', $owned, '/api/external/v1/ftp-accounts/mine-1', 'Missing FTP id.', 'FTP account deleted.'],
            'subdomain' => ['panelica_DeleteSubdomain', $owned, '/api/external/v1/subdomains/mine-1', 'Missing subdomain id.', 'Subdomain deleted.'],
            'dns record' => ['panelica_DeleteDnsRecord', $owned, '/api/external/v1/dns/records/mine-1', 'Missing DNS record id.', 'DNS record deleted.'],
            'cron job' => ['panelica_DeleteCron', $owned, '/api/external/v1/cron-jobs/mine-1', 'Missing cron job id.', 'Cron job deleted.'],
            'forwarder' => ['panelica_DeleteForwarder', $owned, '/api/external/v1/email-forwarders/mine-1', 'Missing forwarder id.', 'Forwarder deleted.'],
            'autoresponder' => ['panelica_DeleteAutoresponder', $owned, '/api/external/v1/email-autoresponders/mine-1', 'Missing autoresponder id.', 'Autoresponder deleted.'],
            'mysql user' => ['panelica_DeleteMysqlUser', $owned, '/api/external/v1/mysql-users/mine-1', 'Missing database user id.', 'Database user deleted.'],
            'redirect' => ['panelica_DeleteRedirect', $owned, '/api/external/v1/redirects/mine-1', 'Missing redirect id.', 'Redirect deleted.'],
        ];
    }

    #[DataProvider('doorProvider')]
    public function testAnOwnedItemIsDeletedWithASuccessFlash(string $door, array $ownedItem, string $deletePath, string $missingMsg, string $successMsg): void
    {
        $api = $this->panel([$ownedItem]);
        $api->queueJson(['status' => 'success']);
        $_POST = ['id' => 'mine-1'];
        panelica_test_useApi($api);

        $door($this->params());

        $this->assertFlash('success', $successMsg);
        $this->assertContains('DELETE ' . $deletePath, $this->calledPaths($api));
    }

    #[DataProvider('doorProvider')]
    public function testAnEmptyIdIsRefusedBeforeAnyRequestLeaves(string $door, array $ownedItem, string $deletePath, string $missingMsg, string $successMsg): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $_POST = [];
        panelica_test_useApi($api);

        $door($this->params());

        $this->assertFlash('danger', $missingMsg);
        $this->assertSame([], $api->sent, 'the panel was contacted despite the missing id');
    }

    #[DataProvider('doorProvider')]
    public function testAForeignIdIsRefusedAndNothingIsDeleted(string $door, array $ownedItem, string $deletePath, string $missingMsg, string $successMsg): void
    {
        $api = $this->panel([$ownedItem]);
        $_POST = ['id' => 'foreign-9'];
        panelica_test_useApi($api);

        $door($this->params());

        $this->assertFlash('danger', 'Item not found.');
        foreach ($this->calledPaths($api) as $path) {
            $this->assertStringStartsNotWith('DELETE ', $path, 'a delete reached the panel for a foreign id');
            $this->assertStringNotContainsString('foreign-9', $path, 'the foreign id reached the panel');
        }
    }

    /* ---------------- Backup doors ---------------- */

    /** Panel for the backup doors: account, domains, then the backup listing. */
    private function backupPanel(array $backups): FakePanelicaAPI
    {
        return $this->panel($backups);
    }

    public function testDeleteBackupDeletesAnOwnedArchive(): void
    {
        $api = $this->backupPanel([
            ['filename' => 'mine-only.tar.gz', 'domain_names' => ['mine.test']],
        ]);
        $api->queueJson(['status' => 'success']);
        $_POST = ['filename' => 'mine-only.tar.gz'];
        panelica_test_useApi($api);

        panelica_DeleteBackup($this->params());

        $this->assertFlash('success', 'Backup deleted.');
        $this->assertContains('DELETE /api/external/v1/backups/mine-only.tar.gz', $this->calledPaths($api));
    }

    public function testDeleteBackupRefusesAnEmptyFilenameBeforeAnyRequestLeaves(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $_POST = [];
        panelica_test_useApi($api);

        panelica_DeleteBackup($this->params());

        $this->assertFlash('danger', 'Missing backup filename.');
        $this->assertSame([], $api->sent);
    }

    public function testDeleteBackupRefusesAnArchiveContainingAnotherAccountsDomain(): void
    {
        $api = $this->backupPanel([
            ['filename' => 'nightly-all.tar.gz', 'domain_names' => ['mine.test', 'theirs.test']],
        ]);
        $_POST = ['filename' => 'nightly-all.tar.gz'];
        panelica_test_useApi($api);

        panelica_DeleteBackup($this->params());

        $this->assertFlash('danger', 'Backup not found.');
        foreach ($this->calledPaths($api) as $path) {
            $this->assertStringStartsNotWith('DELETE ', $path, 'the foreign backup was deleted anyway');
        }
    }

    public function testRestoreBackupRestoresAnOwnedArchive(): void
    {
        $api = $this->backupPanel([
            ['filename' => 'mine-only.tar.gz', 'domain_names' => ['mine.test']],
        ]);
        $api->queueJson(['status' => 'success']);
        $_POST = ['filename' => 'mine-only.tar.gz'];
        panelica_test_useApi($api);

        panelica_RestoreBackup($this->params());

        $this->assertFlash('success', 'Restore started — it runs in the background.');
        $this->assertContains('POST /api/external/v1/backups/mine-only.tar.gz/restore', $this->calledPaths($api));
    }

    public function testRestoreBackupRefusesAnEmptyFilenameBeforeAnyRequestLeaves(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $_POST = [];
        panelica_test_useApi($api);

        panelica_RestoreBackup($this->params());

        $this->assertFlash('danger', 'Missing backup filename.');
        $this->assertSame([], $api->sent);
    }

    public function testRestoreBackupRefusesAnArchiveContainingAnotherAccountsDomain(): void
    {
        $api = $this->backupPanel([
            ['filename' => 'nightly-all.tar.gz', 'domain_names' => ['mine.test', 'theirs.test']],
        ]);
        $_POST = ['filename' => 'nightly-all.tar.gz'];
        panelica_test_useApi($api);

        panelica_RestoreBackup($this->params());

        $this->assertFlash('danger', 'Backup not found.');
        foreach ($this->calledPaths($api) as $path) {
            $this->assertStringNotContainsString('/restore', $path, 'the foreign backup was restored anyway');
        }
    }

    public function testRestoreBackupRefusesABackupWithUnknownContents(): void
    {
        // No domain_names on the row: scope unknown, treated as not owned —
        // the safe default the ownership rule promises.
        $api = $this->backupPanel([
            ['filename' => 'mystery.tar.gz'],
        ]);
        $_POST = ['filename' => 'mystery.tar.gz'];
        panelica_test_useApi($api);

        panelica_RestoreBackup($this->params());

        $this->assertFlash('danger', 'Backup not found.');
        foreach ($this->calledPaths($api) as $path) {
            $this->assertStringNotContainsString('/restore', $path);
        }
    }

    /* ---------------- The whitelist itself ---------------- */

    /**
     * Pin the client-invocable surface: exactly these 28 functions, no more, no
     * fewer, and each one actually defined. A name added to the whitelist
     * without a function (or vice versa) is a door that either 404s for
     * customers or silently widens what they can invoke.
     */
    public function testTheAllowedFunctionWhitelistIsExactlyTheTwentyEightKnownDoors(): void
    {
        $expected = [
            'CreateEmail', 'DeleteEmail',
            'CreateFtp', 'DeleteFtp',
            'CreateSubdomain', 'DeleteSubdomain',
            'CreateDnsRecord', 'DeleteDnsRecord',
            'CreateCron', 'DeleteCron',
            'IssueSsl',
            'CreateForwarder', 'DeleteForwarder',
            'CreateAutoresponder', 'DeleteAutoresponder',
            'CreateMysqlUser', 'DeleteMysqlUser',
            'CreateRedirect', 'DeleteRedirect',
            'CreateBackup', 'RestoreBackup', 'DeleteBackup',
            'FmMkdir', 'FmNewFile', 'FmSave', 'FmDelete', 'FmAjax',
            'Api',
        ];

        $actual = panelica_ClientAreaAllowedFunctions();

        $this->assertCount(28, $expected, 'the pin itself must stay at 28');
        $this->assertSame($expected, $actual);

        foreach ($actual as $name) {
            $this->assertTrue(
                function_exists('panelica_' . $name),
                'whitelisted door panelica_' . $name . ' is not defined'
            );
        }
    }
}
