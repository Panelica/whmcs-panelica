<?php

use PHPUnit\Framework\TestCase;

/**
 * Single sign-on and the plan dropdown loader - the two places where the
 * admin/product UI meets a live panel.
 */
final class SsoAndPlanLoaderCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleCallLog::reset();
    }

    protected function tearDown(): void
    {
        panelica_test_clearApi();
    }

    private function params(): array
    {
        return [
            'serverhostname'   => 'panel.test',
            'serverport'       => 8443,
            'serverpassword'   => 'pk',
            'serveraccesshash' => 'sk',
            'username'         => 'bob',
        ];
    }

    // ---------------------------------------------------------------
    // panelica_ServiceSingleSignOn
    // ---------------------------------------------------------------

    public function testSsoHandsWhmcsTheOneTimeUrl(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => ['url' => 'https://panel.test:8443/sso?token=abc']]);
        panelica_test_useApi($api);

        $result = panelica_ServiceSingleSignOn($this->params());

        $this->assertSame(
            ['success' => true, 'redirectTo' => 'https://panel.test:8443/sso?token=abc'],
            $result
        );
        $last = end($api->sent);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('/v1/accounts/acct-1/sso-login', $last['url']);
    }

    public function testAPanelThatReturnsNoUrlIsAnErrorNotABlankRedirect(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => []]);
        panelica_test_useApi($api);

        $result = panelica_ServiceSingleSignOn($this->params());

        $this->assertFalse($result['success']);
        $this->assertSame('The panel did not return a login URL.', $result['errorMsg']);
        $this->assertArrayNotHasKey('redirectTo', $result);
    }

    public function testSsoForAnUnknownAccountFailsWithTheAccountNamed(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        panelica_test_useApi($api);

        $result = panelica_ServiceSingleSignOn($this->params());

        $this->assertFalse($result['success']);
        $this->assertSame('Account "bob" was not found on the Panelica server.', $result['errorMsg']);
    }

    public function testSsoRelaysAPanelRefusal(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER'],
        ]]);
        $api->queueJson(['status' => 'error', 'error' => 'sso requires a root key'], 403);
        panelica_test_useApi($api);

        $result = panelica_ServiceSingleSignOn($this->params());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('sso requires a root key', $result['errorMsg']);
    }

    // ---------------------------------------------------------------
    // panelica_LoaderPlans
    // ---------------------------------------------------------------

    public function testTheManagedOptionAlwaysComesFirst(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-1', 'name' => 'Gold'],
        ]]);
        panelica_test_useApi($api);

        $options = panelica_LoaderPlans($this->params());

        $this->assertSame(PANELICA_MANAGED_PLAN, array_key_first($options));
    }

    public function testLivePlansAreListedWithHumanReadableAllowances(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-1', 'name' => 'Gold', 'disk_quota_mb' => 10240, 'monthly_bandwidth_mb' => 51200],
            ['id' => 'plan-2', 'name' => 'Tiny', 'disk_quota_mb' => 500],
            ['id' => 'plan-3', 'name' => 'Bare'],
        ]]);
        panelica_test_useApi($api);

        $options = panelica_LoaderPlans($this->params());

        $this->assertSame('Gold (10 GB disk, 50 GB bw)', $options['plan-1']);
        $this->assertSame('Tiny (500 MB disk)', $options['plan-2']);
        $this->assertSame('Bare', $options['plan-3'], 'no allowance figures, no parenthesis');
    }

    public function testModuleManagedPlanVersionsAreHiddenFromThePicker(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-1', 'name' => 'Gold', 'slug' => 'gold'],
            ['id' => 'plan-m', 'name' => 'WHMCS: Product 7 [abc]', 'slug' => 'whmcs-p7-abcdef12'],
        ]]);
        panelica_test_useApi($api);

        $options = panelica_LoaderPlans($this->params());

        $this->assertArrayHasKey('plan-1', $options);
        $this->assertArrayNotHasKey('plan-m', $options);
    }

    public function testADeadPanelStillYieldsTheManagedOptionAlone(): void
    {
        // An empty dropdown would let a Save wipe configoption1; the managed
        // sentinel must survive any panel outage.
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueCurlError(7, 'Failed to connect to panel.test port 8443');
        panelica_test_useApi($api);

        $options = panelica_LoaderPlans($this->params());

        $this->assertSame([PANELICA_MANAGED_PLAN], array_keys($options));
    }

    public function testUnlimitedDiskReadsAsUnlimited(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-1', 'name' => 'Everything', 'disk_quota_mb' => -1],
        ]]);
        panelica_test_useApi($api);

        $options = panelica_LoaderPlans($this->params());

        $this->assertSame('Everything (unlimited disk)', $options['plan-1']);
    }
}
