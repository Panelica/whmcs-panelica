<?php

use PHPUnit\Framework\TestCase;

/**
 * The fingerprint that decides whether a managed plan is a new one.
 *
 * The slug carries a hash of the plan's contents, and the module treats a new
 * hash as a new version of the plan: it creates it on the panel, moves the
 * account onto it with a real plan change so the kernel re-applies the limits,
 * and then tries to remove the version that came before.
 *
 * So the hash has to describe what the plan IS, and nothing else. It was built
 * from the array as it happened to be assembled, and an override the operator
 * typed in the advanced box is appended in the order the lines appear - so the
 * same settings, listed in a different order, fingerprinted differently. Every
 * product edit that reordered those lines invented a plan version that was
 * identical to the one already there, and moved every account of that product
 * onto it.
 */
final class ManagedPlanSpecTest extends TestCase
{
    /** Two whitelisted keys with no dedicated option, so both are appended. */
    private function twoAppendedKeys(): array
    {
        $spec = panelica_managedPlanSpec(['packageid' => 1]);
        $spare = array_values(array_diff(array_keys(panelica_advancedWhitelist()), array_keys($spec['advanced'])));

        if (count($spare) < 2) {
            $this->markTestSkipped('needs two whitelisted keys that are not already in the spec');
        }

        return [$spare[0], $spare[1]];
    }

    public function testTheSameSettingsInADifferentOrderAreTheSamePlan(): void
    {
        [$first, $second] = $this->twoAppendedKeys();

        $oneWay = panelica_managedPlanSpec(['packageid' => 1, 'configoption24' => "{$first} = 1\n{$second} = 2"]);
        $otherWay = panelica_managedPlanSpec(['packageid' => 1, 'configoption24' => "{$second} = 2\n{$first} = 1"]);

        $this->assertSame($oneWay['hash'], $otherWay['hash']);
    }

    public function testASettingThatActuallyChangedIsADifferentPlan(): void
    {
        [$first] = $this->twoAppendedKeys();

        $before = panelica_managedPlanSpec(['packageid' => 1, 'configoption24' => "{$first} = 1"]);
        $after = panelica_managedPlanSpec(['packageid' => 1, 'configoption24' => "{$first} = 2"]);

        $this->assertNotSame($before['hash'], $after['hash']);
    }

    public function testChangingADiskQuotaIsADifferentPlan(): void
    {
        $before = panelica_managedPlanSpec(['packageid' => 1, 'configoption2' => '5120']);
        $after = panelica_managedPlanSpec(['packageid' => 1, 'configoption2' => '10240']);

        $this->assertNotSame($before['hash'], $after['hash']);
    }

    public function testTheSameProductTwiceIsTheSamePlan(): void
    {
        $params = ['packageid' => 1, 'configoption2' => '2048', 'configoption5' => '512'];

        $this->assertSame(
            panelica_managedPlanSpec($params)['hash'],
            panelica_managedPlanSpec($params)['hash']
        );
    }

    public function testAnOverrideStillWinsOverTheOptionItReplaces(): void
    {
        [$first] = $this->twoAppendedKeys();

        $spec = panelica_managedPlanSpec(['packageid' => 1, 'configoption24' => "{$first} = 7"]);

        $this->assertArrayHasKey($first, $spec['advanced']);
        $this->assertSame(7, $spec['advanced'][$first]);
    }

    public function testAnSshLevelTheModuleDoesNotKnowFallsBackToNone(): void
    {
        $spec = panelica_managedPlanSpec(['packageid' => 1, 'configoption14' => 'root-everywhere']);

        $this->assertFalse($spec['basic']['ssh_access_enabled']);
    }

    public function testAQuotaModeTheModuleDoesNotKnowFallsBackToStrict(): void
    {
        $spec = panelica_managedPlanSpec(['packageid' => 1, 'configoption18' => 'whatever']);
        $found = $spec['basic']['quota_mode'] ?? ($spec['advanced']['quota_mode'] ?? null);

        $this->assertSame('strict', $found);
    }

    public function testNonNumericOptionsFallBackToTheDefault(): void
    {
        $withRubbish = panelica_managedPlanSpec(['packageid' => 1, 'configoption2' => 'unlimited']);
        $withNothing = panelica_managedPlanSpec(['packageid' => 1]);

        $this->assertSame($withNothing['basic']['disk_quota_mb'], $withRubbish['basic']['disk_quota_mb']);
    }
}
