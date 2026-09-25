<?php

use PHPUnit\Framework\TestCase;

/**
 * A slug that promises a spec must not be trusted before the spec is on it.
 *
 * ensureManagedPlan writes the plan in two strokes: POST creates it — already
 * carrying its final slug, whmcs-p{pid}-{hash} — and a PATCH then applies the
 * cgroups/PHP columns the create endpoint does not accept. The hash in the
 * slug is the module's whole story for "this plan matches these product
 * options": any later call that finds the slug returns the plan id at once,
 * with no PATCH and no verification (panelica.php, the early-return branch of
 * panelica_ensureManagedPlan).
 *
 * So when the PATCH fails — a timeout, a restart, a 500 between the two
 * strokes — the attempt errors out correctly, but the plan it half-built
 * stays behind wearing the full slug. The WHMCS retry, and every account this
 * product ever creates afterwards, finds that slug, trusts it, and attaches
 * customers to a plan whose kernel limits are the panel's own defaults
 * (memory 512 MB, 50 processes) instead of what the product sold — silently,
 * forever, because the verification step lives only in the creation branch.
 */
final class ManagedPlanPartialCreationTest extends TestCase
{
    /** Product limits far from the panel's column defaults (512 MB / 50 procs). */
    private function params(): array
    {
        return [
            'packageid'     => 52,
            'packagename'   => 'Partial Creation',
            'configoption1' => PANELICA_MANAGED_PLAN,
            'configoption5' => '4096', // Memory Limit (MB)
            'configoption6' => '300',  // Max Processes
        ];
    }

    public function testAFailedPatchDoesNotPretendThePlanIsReady(): void
    {
        $api = new FakePanelicaAPI('panel.example.com', 8443, 'pk_test_key', 'sk_test_secret');
        $params = $this->params();
        $spec = panelica_managedPlanSpec($params);
        $slug = 'whmcs-p52-' . $spec['hash'];

        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'plan-half', 'slug' => $slug]], 201);
        $api->queueJson(['status' => 'error', 'error' => 'database restarting'], 500);

        try {
            panelica_ensureManagedPlan($api, $params);
            $this->fail('A plan whose advanced limits were never applied must not be handed out.');
        } catch (Exception $e) {
            $this->assertStringContainsString('database restarting', $e->getMessage());
        }
    }

    public function testTheRetryDoesNotTrustTheHalfBuiltPlanBareSlug(): void
    {
        $params = $this->params();
        $spec = panelica_managedPlanSpec($params);
        $slug = 'whmcs-p52-' . $spec['hash'];

        // The retry after the failure above: the panel now lists the plan under
        // its final slug, but with the advanced columns still at the panel's
        // own defaults - the PATCH never ran.
        $api = new FakePanelicaAPI('panel.example.com', 8443, 'pk_test_key', 'sk_test_secret');
        $api->queueJson(['status' => 'success', 'data' => [[
            'id'   => 'plan-half',
            'slug' => $slug,
            'cpu_limit_percent' => 100,
            'memory_limit_mb'   => 512, // product sold 4096
            'process_limit'     => 50,  // product sold 300
        ]]]);
        // Answers for a repair PATCH + re-verification, if the module makes them.
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'plan-half']]);
        $api->queueJson(['status' => 'success', 'data' => [[
            'id' => 'plan-half', 'slug' => $slug,
            'cpu_limit_percent' => $spec['advanced']['cpu_limit_percent'],
            'memory_limit_mb'   => $spec['advanced']['memory_limit_mb'],
            'process_limit'     => $spec['advanced']['process_limit'],
        ]]]);

        $planId = null;
        try {
            $planId = panelica_ensureManagedPlan($api, $params);
        } catch (Exception $e) {
            // Refusing with an error would also be honest - not silent success.
            $this->assertNotSame('', $e->getMessage());
            return;
        }

        $this->assertSame('plan-half', $planId);

        $patched = [];
        foreach ($api->sent as $request) {
            if ($request['method'] === 'PATCH') {
                $patched = array_merge($patched, (array) json_decode($request['body'], true));
            }
        }

        $this->assertNotEmpty($patched,
            'The listed plan wears the spec slug but still has the panel-default limits '
            . '(512 MB / 50 procs vs the 4096 MB / 300 procs the product sold); '
            . 'returning its id without repairing or refusing attaches customers to it silently.');
        $this->assertSame(4096, $patched['memory_limit_mb'] ?? null);
        $this->assertSame(300, $patched['process_limit'] ?? null);
    }
}
