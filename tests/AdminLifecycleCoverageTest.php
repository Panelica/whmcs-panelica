<?php

use PHPUnit\Framework\TestCase;

/**
 * The WHMCS lifecycle doors an admin action opens: suspend, unsuspend,
 * change password, change package.
 *
 * Each one is exercised through the real WHMCS-facing function with the fake
 * transport injected, so the account lookup, the request that goes on the
 * wire, and the message that comes back are all the production ones.
 */
final class AdminLifecycleCoverageTest extends TestCase
{
    protected function setUp(): void
    {
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

    /** Queue the account listing panelica_requireAccount reads first. */
    private function queueAccount(FakePanelicaAPI $api): void
    {
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER'],
        ]]);
    }

    // ---------------------------------------------------------------
    // panelica_SuspendAccount
    // ---------------------------------------------------------------

    public function testSuspendPostsAgainstTheAccountThePanelHolds(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $this->queueAccount($api);
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        $result = panelica_SuspendAccount($this->params());

        $this->assertSame('success', $result);
        $this->assertCount(2, $api->sent);
        $this->assertSame('GET', $api->sent[0]['method']);
        $this->assertStringContainsString('/v1/accounts', $api->sent[0]['url']);
        $this->assertSame('POST', $api->sent[1]['method']);
        $this->assertStringContainsString('/v1/accounts/acct-1/suspend', $api->sent[1]['url']);
    }

    public function testSuspendWithoutAUsernameTouchesNothing(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        panelica_test_useApi($api);

        $result = panelica_SuspendAccount($this->params(['username' => '']));

        $this->assertSame('This service has no username set in WHMCS.', $result);
        $this->assertSame([], $api->sent);
    }

    public function testSuspendNamesAnAccountThePanelDoesNotHave(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        panelica_test_useApi($api);

        $result = panelica_SuspendAccount($this->params());

        $this->assertSame('Account "bob" was not found on the Panelica server.', $result);
    }

    public function testSuspendRelaysThePanelsRefusal(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $this->queueAccount($api);
        $api->queueJson(['status' => 'error', 'error' => 'account is protected'], 409);
        panelica_test_useApi($api);

        $result = panelica_SuspendAccount($this->params());

        $this->assertStringContainsString('account is protected', $result);
    }

    // ---------------------------------------------------------------
    // panelica_UnsuspendAccount
    // ---------------------------------------------------------------

    public function testUnsuspendPostsAgainstTheAccountThePanelHolds(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $this->queueAccount($api);
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        $result = panelica_UnsuspendAccount($this->params());

        $this->assertSame('success', $result);
        $last = end($api->sent);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('/v1/accounts/acct-1/unsuspend', $last['url']);
    }

    public function testUnsuspendReportsAMissingAccountInsteadOfGuessing(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        panelica_test_useApi($api);

        $result = panelica_UnsuspendAccount($this->params());

        $this->assertSame('Account "bob" was not found on the Panelica server.', $result);
        $this->assertCount(1, $api->sent, 'nothing beyond the lookup went out');
    }

    // ---------------------------------------------------------------
    // panelica_ChangePassword
    // ---------------------------------------------------------------

    public function testAPasswordShorterThanEightIsRefusedBeforeAnyRequest(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        panelica_test_useApi($api);

        $result = panelica_ChangePassword($this->params(['password' => 'short12']));

        $this->assertSame('Panelica requires a password of at least 8 characters.', $result);
        $this->assertSame([], $api->sent);
    }

    public function testAnEightCharacterPasswordIsTheFloorNotBelowIt(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $this->queueAccount($api);
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        $result = panelica_ChangePassword($this->params(['password' => 'exactly8']));

        $this->assertSame('success', $result);
    }

    public function testTheNewPasswordRidesTheChangePasswordEndpoint(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $this->queueAccount($api);
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        panelica_ChangePassword($this->params(['password' => 'N3wSecret!']));

        $last = end($api->sent);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('/v1/accounts/acct-1/change-password', $last['url']);
        $this->assertSame(['new_password' => 'N3wSecret!'], json_decode($last['body'], true));
    }

    public function testChangePasswordRelaysThePanelsRefusal(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $this->queueAccount($api);
        $api->queueJson(['status' => 'error', 'error' => 'password too weak'], 422);
        panelica_test_useApi($api);

        $result = panelica_ChangePassword($this->params(['password' => 'longenough']));

        $this->assertStringContainsString('password too weak', $result);
    }

    // ---------------------------------------------------------------
    // panelica_ChangePackage
    // ---------------------------------------------------------------

    public function testAPlanUuidIsUsedDirectlyWithoutLookingUpPlans(): void
    {
        $uuid = '3f2c8a10-9b4d-4e6f-8a1b-2c3d4e5f6a7b';
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $this->queueAccount($api);
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        $result = panelica_ChangePackage($this->params(['configoption1' => $uuid]));

        $this->assertSame('success', $result);
        $this->assertCount(2, $api->sent, 'no plan listing was needed');
        $last = end($api->sent);
        $this->assertSame('PATCH', $last['method']);
        $this->assertStringContainsString('/v1/accounts/acct-1', $last['url']);
        $this->assertSame(['plan_id' => $uuid], json_decode($last['body'], true));
    }

    public function testATypedPlanNameIsResolvedThroughThePlanListing(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-9', 'name' => 'Gold', 'slug' => 'gold'],
        ]]);
        $this->queueAccount($api);
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        $result = panelica_ChangePackage($this->params(['configoption1' => 'Gold']));

        $this->assertSame('success', $result);
        $last = end($api->sent);
        $this->assertSame(['plan_id' => 'plan-9'], json_decode($last['body'], true));
    }

    public function testAProductWithNoPlanConfiguredSaysSoAndStops(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        panelica_test_useApi($api);

        $result = panelica_ChangePackage($this->params(['configoption1' => '']));

        $this->assertStringContainsString('No Panelica plan configured', $result);
        $this->assertSame([], $api->sent);
    }

    public function testAPlanNameThePanelDoesNotKnowIsNamedInTheError(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        panelica_test_useApi($api);

        $result = panelica_ChangePackage($this->params(['configoption1' => 'Gold']));

        $this->assertSame('Panelica plan "Gold" was not found on the server.', $result);
    }

    public function testManagedModeMovesTheAccountAndCollectsSupersededVersions(): void
    {
        $params = $this->params([
            'configoption1' => PANELICA_MANAGED_PLAN,
            'packageid'     => 7,
        ]);

        // The spec/hash is the module's own; ask it, do not re-derive it here.
        $spec = panelica_managedPlanSpec($params);
        $keepSlug = 'whmcs-p7-' . $spec['hash'];

        $currentPlan = [
            'id'                => 'plan-cur',
            'slug'              => $keepSlug,
            'cpu_limit_percent' => $spec['advanced']['cpu_limit_percent'],
            'memory_limit_mb'   => $spec['advanced']['memory_limit_mb'],
            'process_limit'     => $spec['advanced']['process_limit'],
        ];

        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        // ensureManagedPlan: findPlanBySlug -> listPlans (found, limits intact)
        $api->queueJson(['status' => 'success', 'data' => [$currentPlan]]);
        // requireAccount -> listAccounts
        $this->queueAccount($api);
        // updateAccount PATCH
        $api->queueJson(['status' => 'success']);
        // gcManagedPlans -> listPlans: current + superseded + another product's
        $api->queueJson(['status' => 'success', 'data' => [
            $currentPlan,
            ['id' => 'plan-old', 'slug' => 'whmcs-p7-deadbeef'],
            ['id' => 'plan-other', 'slug' => 'whmcs-p8-cafebabe'],
        ]]);
        // deletePlan for the superseded version only
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        $result = panelica_ChangePackage($params);

        $this->assertSame('success', $result);

        $deletes = array_values(array_filter($api->sent, fn ($r) => $r['method'] === 'DELETE'));
        $this->assertCount(1, $deletes, 'exactly one superseded version is collected');
        $this->assertStringContainsString('/v1/plans/plan-old', $deletes[0]['url']);
    }
}
