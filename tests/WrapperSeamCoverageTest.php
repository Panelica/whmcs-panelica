<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * The thin WHMCS-facing wrappers around already-tested workers, plus the
 * CreateAccount paths the rollback suite leaves open.
 *
 * SyncButtonTest and UsageUpdateTest drive panelica_syncWith /
 * panelica_usageUpdateWith directly; here the real entry points WHMCS calls
 * (panelica_Sync, panelica_UsageUpdate, panelica_CreateAccount) are exercised
 * through the injected-API seam, so the wrapper-to-worker wiring is covered
 * too.
 */
final class WrapperSeamCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        ModuleCallLog::reset();
    }

    protected function tearDown(): void
    {
        panelica_test_clearApi();
    }

    private function params(array $extra = []): array
    {
        return array_merge([
            'serverhostname'   => 'panel.test',
            'serverport'       => 8443,
            'serverpassword'   => 'pk',
            'serveraccesshash' => 'sk',
            'username'         => 'bob',
        ], $extra);
    }

    // ---------------------------------------------------------------
    // panelica_Sync (the admin button entry point)
    // ---------------------------------------------------------------

    public function testTheSyncEntryPointWritesUsageOntoTheService(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'plan-1', 'monthly_bandwidth_mb' => 51200]]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER', 'plan_id' => 'plan-1']]]);
        $api->queueJson(['status' => 'success', 'data' => ['used_mb' => 300, 'quota_mb' => 10240]]);
        $api->queueJson(['status' => 'success', 'data' => ['bytes_used' => 5242880]]);
        panelica_test_useApi($api);

        $result = panelica_Sync($this->params(['serviceid' => 42]));

        $this->assertSame('success', $result);
        $this->assertCount(1, Capsule::$updates);
        $this->assertSame('tblhosting', Capsule::$updates[0]['table']);
        $this->assertSame(42, Capsule::$updates[0]['where']['id']);
        $this->assertSame(300, Capsule::$updates[0]['values']['diskusage']);
    }

    // ---------------------------------------------------------------
    // panelica_UsageUpdate (the nightly entry point)
    // ---------------------------------------------------------------

    public function testTheNightlyEntryPointSyncsEveryMatchedService(): void
    {
        Capsule::$rows['tblhosting'] = [
            (object) ['id' => 5, 'username' => 'bob'],
            (object) ['id' => 6, 'username' => 'nobody-on-panel'],
        ];

        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'plan-1', 'monthly_bandwidth_mb' => 51200]]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'acct-1', 'username' => 'bob', 'plan_id' => 'plan-1']]]);
        $api->queueJson(['status' => 'success', 'data' => ['used_mb' => 111, 'quota_mb' => 2048]]);
        $api->queueJson(['status' => 'success', 'data' => ['bytes_used' => 1048576]]);
        panelica_test_useApi($api);

        $result = panelica_UsageUpdate($this->params(['serverid' => 3]));

        $this->assertSame('success', $result);
        $this->assertCount(1, Capsule::$updates, 'only the service the panel knows is written');
        $this->assertSame(5, Capsule::$updates[0]['where']['id']);
        $this->assertSame(111, Capsule::$updates[0]['values']['diskusage']);
        $this->assertSame(51200, Capsule::$updates[0]['values']['bwlimit']);
    }

    public function testTheNightlyEntryPointReportsAnUnreachablePanel(): void
    {
        Capsule::$rows['tblhosting'] = [(object) ['id' => 5, 'username' => 'bob']];

        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        // Plan map is best-effort and swallows this one...
        $api->queueCurlError(7, 'Failed to connect to panel.test port 8443');
        // ...but the account listing failure is the job failing.
        $api->queueCurlError(7, 'Failed to connect to panel.test port 8443');
        panelica_test_useApi($api);

        $result = panelica_UsageUpdate($this->params(['serverid' => 3]));

        $this->assertNotSame('success', $result);
        $this->assertStringContainsString('Failed to connect', $result);
        $this->assertSame([], Capsule::$updates);
    }

    // ---------------------------------------------------------------
    // panelica_CreateAccount (gaps around the rollback suite)
    // ---------------------------------------------------------------

    private function createParams(array $extra = []): array
    {
        return $this->params(array_merge([
            'configoption1'  => '3f2c8a10-9b4d-4e6f-8a1b-2c3d4e5f6a7b',
            'username'       => 'bob',
            'password'       => 'S3cretPass!',
            'clientsdetails' => ['email' => 'bob@example.com', 'firstname' => 'Bob', 'lastname' => 'Client'],
        ], $extra));
    }

    public function testCreateAccountSendsTheServiceDetailsToThePanel(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]); // idempotency lookup
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-new']]);
        panelica_test_useApi($api);

        $result = panelica_CreateAccount($this->createParams());

        $this->assertSame('success', $result);
        $last = end($api->sent);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('/v1/accounts', $last['url']);
        $this->assertSame([
            'username'  => 'bob',
            'password'  => 'S3cretPass!',
            'email'     => 'bob@example.com',
            'full_name' => 'Bob Client',
            'plan_id'   => '3f2c8a10-9b4d-4e6f-8a1b-2c3d4e5f6a7b',
        ], json_decode($last['body'], true));
    }

    public function testTheOrderedDomainIsProvisionedForTheNewAccount(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-new']]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'dom-1']]);
        panelica_test_useApi($api);

        $result = panelica_CreateAccount($this->createParams(['domain' => 'Example.COM']));

        $this->assertSame('success', $result);
        $last = end($api->sent);
        $this->assertStringContainsString('/v1/domains', $last['url']);
        $this->assertSame(
            ['name' => 'example.com', 'user_id' => 'acct-new'],
            json_decode($last['body'], true),
            'the domain travels lowercased, bound to the fresh account'
        );
    }

    public function testAServiceWithoutAUsernameIsRefusedBeforeAnyRequest(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        panelica_test_useApi($api);

        $result = panelica_CreateAccount($this->createParams(['username' => '']));

        $this->assertSame('WHMCS did not generate a username for this service.', $result);
        $this->assertSame([], $api->sent);
    }

    public function testAShortServicePasswordIsRefusedBeforeAnyRequest(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        panelica_test_useApi($api);

        $result = panelica_CreateAccount($this->createParams(['password' => 'short12']));

        $this->assertStringContainsString('at least 8 characters', $result);
        $this->assertSame([], $api->sent);
    }

    public function testAClientWithoutAnEmailIsRefusedBeforeAnyRequest(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        panelica_test_useApi($api);

        $result = panelica_CreateAccount($this->createParams([
            'clientsdetails' => ['email' => '', 'firstname' => 'Bob', 'lastname' => 'Client'],
        ]));

        $this->assertSame('The client has no email address; Panelica requires one.', $result);
        $this->assertSame([], $api->sent);
    }
}
