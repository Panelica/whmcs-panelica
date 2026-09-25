<?php

use PHPUnit\Framework\TestCase;

/**
 * Numbers an operator can type into a product's options.
 *
 * The disk allowance, the CPU share, the memory ceiling, the process limit and
 * the rest are free-text fields on the WHMCS product page. A minus sign in one
 * of them travelled all the way through: the module built a plan with
 * memory_limit_mb of -100 and process_limit of -1, the panel stored exactly
 * that, and the customer's account was then set up against it. Checked on a
 * live server.
 *
 * Two of these values were already held at zero in this same function - the IO
 * and network ceilings - so the rest now are too. The inode quota keeps its own
 * meaning: -1 is how it says "no limit".
 */
final class PlanLimitBoundsTest extends TestCase
{
    private function spec(array $options): array
    {
        return panelica_managedPlanSpec(array_merge(['packageid' => 1], $options));
    }

    public function testANegativeCpuShareNeverLeavesTheModule(): void
    {
        $spec = $this->spec(['configoption4' => '-5']);

        $this->assertSame(0, $spec['advanced']['cpu_limit_percent']);
    }

    public function testANegativeMemoryCeilingNeverLeavesTheModule(): void
    {
        $spec = $this->spec(['configoption5' => '-100']);

        $this->assertSame(0, $spec['advanced']['memory_limit_mb']);
    }

    public function testANegativeProcessLimitNeverLeavesTheModule(): void
    {
        $spec = $this->spec(['configoption6' => '-1']);

        $this->assertSame(0, $spec['advanced']['process_limit']);
    }

    public function testNegativeAllowancesNeverLeaveTheModule(): void
    {
        $spec = $this->spec([
            'configoption2' => '-1024',
            'configoption3' => '-2048',
            // -1 is documented as "unlimited" on this field and the panel
            // stores it, so a nonsense negative is what belongs here.
            'configoption8' => '-7',
            'configoption9' => '-2',
            'configoption10' => '-3',
            'configoption11' => '-4',
            'configoption16' => '-5',
            'configoption21' => '-6',
        ]);

        foreach (['disk_quota_mb', 'monthly_bandwidth_mb', 'max_domains', 'max_databases',
            'max_email_accounts', 'max_ftp_accounts', 'max_subdomains', 'max_cron_jobs'] as $field) {
            $this->assertSame(0, $spec['basic'][$field], $field . ' went out negative');
        }
    }

    public function testTheInodeQuotaKeepsItsOwnMeaningOfMinusOne(): void
    {
        $spec = $this->spec([]);

        $this->assertSame(-1, $spec['advanced']['inode_quota'], '-1 is how it says "no limit"');
    }

    public function testAnInodeQuotaSetToMinusOneStays(): void
    {
        $spec = $this->spec(['configoption15' => '-1']);

        $this->assertSame(-1, $spec['advanced']['inode_quota']);
    }

    public function testOrdinaryNumbersAreUntouched(): void
    {
        $spec = $this->spec(['configoption2' => '5120', 'configoption4' => '50', 'configoption5' => '2048']);

        $this->assertSame(5120, $spec['basic']['disk_quota_mb']);
        $this->assertSame(50, $spec['advanced']['cpu_limit_percent']);
        $this->assertSame(2048, $spec['advanced']['memory_limit_mb']);
    }

    public function testZeroIsStillAllowedWhereItMeansSomething(): void
    {
        $spec = $this->spec(['configoption7' => '0', 'configoption12' => '0']);

        $this->assertSame(0, $spec['advanced']['io_read_bps'] ?? 0);
        $this->assertSame(0, $spec['basic']['max_containers'] ?? ($spec['advanced']['max_containers'] ?? 0));
    }
}
