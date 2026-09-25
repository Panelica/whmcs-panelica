<?php

use PHPUnit\Framework\TestCase;

/**
 * "-1 = unlimited" is what the module itself writes under six of the product's
 * option fields. It was not what the module did with them.
 *
 * Negatives were clamped to zero to stop a typed minus sign reaching the panel,
 * and that clamp was applied to every field except the inode quota. So an admin
 * who read the hint under "Disk Quota (MB)" and typed -1 sent 0, and the panel
 * substituted its own default for 0 - measured live: a plan asked for 0 came
 * back holding 5120. The product then provisioned every customer on a 5 GB plan
 * while its settings page said unlimited, and nothing anywhere said otherwise.
 *
 * The panel does support it. Measured live: a plan created with -1 for disk,
 * bandwidth, domains, subdomains and cron jobs came back holding -1 for all
 * five, and a PATCH with -1 for the inode quota and container count held too.
 *
 * So -1 travels, and anything below it - which no field documents and no admin
 * means - is still refused.
 */
final class UnlimitedPlanLimitTest extends TestCase
{
    /** option index => the spec key it lands on */
    private const UNLIMITED_FIELDS = [
        2  => ['basic', 'disk_quota_mb'],
        3  => ['basic', 'monthly_bandwidth_mb'],
        8  => ['basic', 'max_domains'],
        12 => ['advanced', 'max_containers'],
        15 => ['advanced', 'inode_quota'],
        16 => ['basic', 'max_subdomains'],
        21 => ['basic', 'max_cron_jobs'],
    ];

    public function testEveryFieldThatDocumentsUnlimitedActuallySendsIt(): void
    {
        foreach (self::UNLIMITED_FIELDS as $idx => $where) {
            list($group, $key) = $where;
            $spec = panelica_managedPlanSpec(['configoption' . $idx => '-1']);

            $this->assertSame(-1, $spec[$group][$key], "configoption{$idx} ({$key}) documents -1 = unlimited");
        }
    }

    /**
     * The trap in my own change: the clamp existed for a reason. A minus sign
     * that means nothing must still not reach the panel.
     */
    public function testNonsenseNegativesAreStillRefused(): void
    {
        foreach (self::UNLIMITED_FIELDS as $idx => $where) {
            list($group, $key) = $where;
            $spec = panelica_managedPlanSpec(['configoption' . $idx => '-250']);

            $this->assertSame(0, $spec[$group][$key], "configoption{$idx} ({$key}) must not pass -250 on");
        }
    }

    public function testFieldsThatDocumentNoUnlimitedStillClampToZero(): void
    {
        // CPU, memory, processes, PHP memory: no "-1 = unlimited" hint anywhere.
        $spec = panelica_managedPlanSpec([
            'configoption4' => '-1', 'configoption5' => '-1',
            'configoption6' => '-1', 'configoption13' => '-1',
        ]);

        $this->assertSame(0, $spec['advanced']['cpu_limit_percent']);
        $this->assertSame(0, $spec['advanced']['memory_limit_mb']);
        $this->assertSame(0, $spec['advanced']['process_limit']);
        $this->assertSame(0, $spec['advanced']['php_memory_limit_mb']);
    }

    public function testZeroKeepsItsOwnMeaningWhereAFieldGivesItOne(): void
    {
        $spec = panelica_managedPlanSpec(['configoption12' => '0', 'configoption21' => '0']);

        $this->assertSame(0, $spec['advanced']['max_containers'], 'zero containers = Docker off');
        $this->assertSame(0, $spec['basic']['max_cron_jobs']);
        $this->assertFalse($spec['basic']['cron_jobs_enabled'], 'zero cron jobs = cron off');
    }

    public function testUnlimitedCronIsStillCronEnabled(): void
    {
        $spec = panelica_managedPlanSpec(['configoption21' => '-1']);

        $this->assertSame(-1, $spec['basic']['max_cron_jobs']);
        $this->assertTrue($spec['basic']['cron_jobs_enabled']);
    }

    public function testAnUnlimitedPlanFingerprintsDifferentlyFromAZeroOne(): void
    {
        $unlimited = panelica_managedPlanSpec(['configoption2' => '-1'])['hash'];
        $zero      = panelica_managedPlanSpec(['configoption2' => '0'])['hash'];

        $this->assertNotSame($zero, $unlimited, 'a real plan change has to become a real plan switch');
    }
}
