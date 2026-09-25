<?php

use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for the small helpers the bigger flows lean on:
 * plan resolution, managed-plan garbage collection, the advanced-column
 * verifier, the bandwidth map, the account root, the deletable-tab map and
 * the flash cycle. Each one is exercised as a function against a fake panel;
 * nothing here reads the source text.
 */
final class HelperBehaviourCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleCallLog::reset();
        unset($_SESSION['panelica_flash']);
    }

    protected function tearDown(): void
    {
        panelica_test_clearApi();
        unset($_SESSION['panelica_flash']);
    }

    private function api(): FakePanelicaAPI
    {
        return new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
    }

    private function calledPaths(FakePanelicaAPI $api): array
    {
        return array_map(fn ($sent) => $sent['method'] . ' ' . parse_url($sent['url'], PHP_URL_PATH), $api->sent);
    }

    // ------------------------------------------------- panelica_resolvePlanId

    public function testAnEmptyPlanConfigurationIsAnError(): void
    {
        $api = $this->api();

        try {
            panelica_resolvePlanId($api, '   ');
            $this->fail('an unconfigured product resolved to a plan');
        } catch (Exception $e) {
            $this->assertStringContainsString('No Panelica plan configured', $e->getMessage());
        }

        $this->assertSame([], $api->sent, 'nothing was asked of the panel');
    }

    public function testAUuidIsUsedDirectlyWithoutAskingThePanel(): void
    {
        $api = $this->api();
        $uuid = 'A1B2C3D4-1111-2222-3333-444455556666';

        $this->assertSame($uuid, panelica_resolvePlanId($api, $uuid));
        $this->assertSame([], $api->sent, 'a UUID needs no lookup');
    }

    public function testThePlanNameIsMatchedCaseInsensitively(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-gold', 'name' => 'Gold', 'slug' => 'gold-2026'],
            ['id' => 'plan-silver', 'name' => 'Silver', 'slug' => 'silver'],
        ]]);

        $this->assertSame('plan-gold', panelica_resolvePlanId($api, 'GOLD'));
    }

    public function testThePlanSlugIsMatchedToo(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-gold', 'name' => 'Gold', 'slug' => 'gold-2026'],
        ]]);

        $this->assertSame('plan-gold', panelica_resolvePlanId($api, 'gold-2026'));
    }

    public function testAnUnknownPlanNameNamesItselfInTheError(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-gold', 'name' => 'Gold', 'slug' => 'gold'],
        ]]);

        try {
            panelica_resolvePlanId($api, 'platinum');
            $this->fail('an unknown plan resolved');
        } catch (Exception $e) {
            $this->assertStringContainsString('"platinum"', $e->getMessage());
        }
    }

    public function testTheManagedSentinelDelegatesToEnsureManagedPlan(): void
    {
        // The reuse path: the plan version for the current spec already exists
        // with the advanced limits in place, so the sentinel resolves to its id
        // after a single listing and no create or patch.
        $params = ['packageid' => 7];
        $spec = panelica_managedPlanSpec($params);
        $slug = 'whmcs-p7-' . $spec['hash'];

        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            array_merge(['id' => 'managed-plan-id', 'slug' => $slug], $spec['advanced']),
        ]]);

        $this->assertSame('managed-plan-id', panelica_resolvePlanId($api, PANELICA_MANAGED_PLAN, $params));
        $this->assertSame(['GET /api/external/v1/plans'], $this->calledPaths($api));
    }

    // ----------------------------------------------- panelica_gcManagedPlans

    public function testGcDeletesOnlySupersededVersionsOfThisProduct(): void
    {
        $params = ['packageid' => 1];
        $spec = panelica_managedPlanSpec($params);
        $keepSlug = 'whmcs-p1-' . $spec['hash'];

        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'keep-me', 'slug' => $keepSlug],
            ['id' => 'stale-version', 'slug' => 'whmcs-p1-deadbeef'],
            ['id' => 'other-product', 'slug' => 'whmcs-p12-deadbeef'],
            ['id' => 'hand-made', 'slug' => 'gold'],
        ]]);
        $api->queueJson(['status' => 'success']); // the one delete

        panelica_gcManagedPlans($api, $params);

        $paths = $this->calledPaths($api);
        $this->assertContains('DELETE /api/external/v1/plans/stale-version', $paths);

        foreach (['keep-me', 'other-product', 'hand-made'] as $spared) {
            foreach ($paths as $path) {
                $this->assertStringNotContainsString($spared, $path);
            }
        }
    }

    public function testGcDoesNotConfuseProductOneWithProductTwelve(): void
    {
        // "whmcs-p1-" is not a prefix of "whmcs-p12-..." because the dash is
        // part of the prefix. Product 1's GC must never touch product 12's plans.
        $params = ['packageid' => 1];

        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'p12-current', 'slug' => 'whmcs-p12-aaaaaaaa'],
            ['id' => 'p12-stale', 'slug' => 'whmcs-p12-bbbbbbbb'],
        ]]);

        panelica_gcManagedPlans($api, $params);

        $this->assertSame(['GET /api/external/v1/plans'], $this->calledPaths($api), 'nothing was deleted');
    }

    public function testGcSwallowsAnInUsePlanAndStillCollectsTheRest(): void
    {
        $params = ['packageid' => 1];

        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'still-in-use', 'slug' => 'whmcs-p1-11111111'],
            ['id' => 'truly-stale', 'slug' => 'whmcs-p1-22222222'],
        ]]);
        $api->queueJson(['status' => 'error', 'message' => 'plan has accounts'], 409);
        $api->queueJson(['status' => 'success']);

        panelica_gcManagedPlans($api, $params); // must not throw

        $paths = $this->calledPaths($api);
        $this->assertContains('DELETE /api/external/v1/plans/still-in-use', $paths);
        $this->assertContains('DELETE /api/external/v1/plans/truly-stale', $paths);
    }

    public function testGcWithoutAProductIdDoesNothing(): void
    {
        $api = $this->api();

        panelica_gcManagedPlans($api, []);
        panelica_gcManagedPlans($api, ['packageid' => 0]);

        $this->assertSame([], $api->sent);
    }

    public function testGcSurvivesAnUnreachablePanel(): void
    {
        $api = $this->api();
        $api->queueCurlError(7, 'Failed to connect');

        panelica_gcManagedPlans($api, ['packageid' => 1]); // must not throw

        $this->addToAssertionCount(1);
    }

    // ---------------------------------------- panelica_planAdvancedMismatch

    public function testAPlanCarryingAllAdvancedColumnsMatches(): void
    {
        $advanced = ['cpu_limit_percent' => 100, 'memory_limit_mb' => 1024, 'process_limit' => 100];

        $this->assertNull(panelica_planAdvancedMismatch($advanced, $advanced));
    }

    public function testNumericStringsCountAsTheSameValue(): void
    {
        // JSON round-trips and panel responses may stringify numbers; the
        // comparison casts both sides.
        $this->assertNull(panelica_planAdvancedMismatch(
            ['cpu_limit_percent' => '100', 'memory_limit_mb' => '1024', 'process_limit' => '100'],
            ['cpu_limit_percent' => 100, 'memory_limit_mb' => 1024, 'process_limit' => 100]
        ));
    }

    public function testAMissingColumnIsNamed(): void
    {
        $this->assertSame('memory_limit_mb', panelica_planAdvancedMismatch(
            ['cpu_limit_percent' => 100, 'process_limit' => 100],
            ['cpu_limit_percent' => 100, 'memory_limit_mb' => 1024, 'process_limit' => 100]
        ));
    }

    public function testAWrongValueIsNamed(): void
    {
        $this->assertSame('process_limit', panelica_planAdvancedMismatch(
            ['cpu_limit_percent' => 100, 'memory_limit_mb' => 1024, 'process_limit' => 3],
            ['cpu_limit_percent' => 100, 'memory_limit_mb' => 1024, 'process_limit' => 100]
        ));
    }

    public function testAnEmptyPlanNamesTheFirstColumn(): void
    {
        $this->assertSame('cpu_limit_percent', panelica_planAdvancedMismatch(
            [],
            ['cpu_limit_percent' => 100, 'memory_limit_mb' => 1024, 'process_limit' => 100]
        ));
    }

    // ------------------------------------------- panelica_planBandwidthMap

    public function testTheBandwidthMapKeysPlanIdsToTheirAllowance(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'plan-a', 'monthly_bandwidth_mb' => 51200],
            ['id' => 'plan-b', 'monthly_bandwidth_mb' => 0],
            ['id' => 'plan-c'], // panel answered without the column
            ['monthly_bandwidth_mb' => 999], // a row without an id is unusable
        ]]);

        $this->assertSame(
            ['plan-a' => 51200, 'plan-b' => 0, 'plan-c' => 0],
            panelica_planBandwidthMap($api)
        );
    }

    public function testAnUnreachablePanelYieldsAnEmptyMapNotAnError(): void
    {
        $api = $this->api();
        $api->queueCurlError(7, 'Failed to connect');

        $this->assertSame([], panelica_planBandwidthMap($api));
    }

    // ------------------------------------------------ panelica_accountRoot

    public function testTheFirstAccessibleDirectoryIsTheRootTrailingSlashTrimmed(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => [
            'directories' => ['/home/mine/', '/home/mine/public_html'],
        ]]);

        $this->assertSame('/home/mine', panelica_accountRoot($api, 'acct-1'));
        $this->assertSame(['GET /api/external/v1/files/accessible-directories'], $this->calledPaths($api));
    }

    public function testNoDirectoriesMeansNoRoot(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => ['directories' => []]]);

        $this->assertSame('', panelica_accountRoot($api, 'acct-1'));
    }

    public function testAnUnexpectedShapeMeansNoRoot(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => ['something' => 'else']]);

        $this->assertSame('', panelica_accountRoot($api, 'acct-1'));
    }

    // ---------------------------------------------- panelica_deletableTabs

    public function testTheTenDeletableTabsMapToTheirApiCalls(): void
    {
        $this->assertSame([
            'email' => 'deleteEmail',
            'forwarders' => 'deleteForwarder',
            'autoresponders' => 'deleteAutoresponder',
            'ftp' => 'deleteFtp',
            'subdomains' => 'deleteSubdomain',
            'dns' => 'deleteDnsRecord',
            'cron' => 'deleteCron',
            'redirects' => 'deleteRedirect',
            'mysql' => 'deleteMysqlUser',
            'backups' => 'deleteBackup',
        ], panelica_deletableTabs());
    }

    public function testEveryMappedDeleteMethodActuallyExistsOnTheApiClient(): void
    {
        foreach (panelica_deletableTabs() as $tab => $method) {
            $this->assertTrue(
                method_exists(PanelicaAPI::class, $method),
                sprintf('tab "%s" maps to a method the client does not have: %s', $tab, $method)
            );
        }
    }

    // ------------------------------------ panelica_setFlash / takeFlash

    public function testAFlashIsReadBackOnceAndThenGone(): void
    {
        panelica_setFlash('success', 'It worked.');

        $this->assertSame(['type' => 'success', 'msg' => 'It worked.'], panelica_takeFlash());
        $this->assertNull(panelica_takeFlash(), 'a flash is consumed by reading it');
    }

    public function testTakingAFlashThatWasNeverSetGivesNull(): void
    {
        $this->assertNull(panelica_takeFlash());
    }

    public function testALaterFlashReplacesAnEarlierUnreadOne(): void
    {
        panelica_setFlash('danger', 'First problem.');
        panelica_setFlash('success', 'All fine after all.');

        $this->assertSame(['type' => 'success', 'msg' => 'All fine after all.'], panelica_takeFlash());
        $this->assertNull(panelica_takeFlash());
    }
}
