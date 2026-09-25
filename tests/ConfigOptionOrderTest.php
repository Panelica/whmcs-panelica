<?php

use PHPUnit\Framework\TestCase;

/**
 * WHMCS numbers a product's options by their position, not by their name.
 *
 * The managed plan reads them back by number - configoption4 is the CPU share,
 * configoption5 the memory ceiling, and so on to twenty-four. Insert an option
 * in the middle and every number after it shifts: the CPU share is read from
 * whatever now sits at four, and a plan is built from values that belong to
 * other fields. Nothing would fail - the plan would just be wrong, on every
 * product using managed mode.
 *
 * So the order is pinned here, and the dropdowns are pinned to the values the
 * reader accepts: an option offering a word the reader does not know falls back
 * to a default without saying so.
 */
final class ConfigOptionOrderTest extends TestCase
{
    private const EXPECTED_ORDER = [
        'plan', 'diskquota', 'bandwidth', 'cpulimit', 'memlimit', 'proclimit', 'iolimit',
        'maxdomains', 'maxdb', 'maxemail', 'maxftp', 'maxcontainers', 'phpmem', 'sshlevel',
        'inodequota', 'maxsub', 'netbw', 'quotamode', 'waf', 'backup', 'maxcron',
        'phpexec', 'phpupload', 'advanced',
    ];

    public function testTheOptionsAreInTheOrderTheManagedPlanReadsThem(): void
    {
        $this->assertSame(self::EXPECTED_ORDER, array_keys(panelica_ConfigOptions()));
    }

    public function testTheCpuShareIsReadFromTheFieldThatAsksForIt(): void
    {
        $position = array_search('cpulimit', self::EXPECTED_ORDER, true) + 1;
        $spec = panelica_managedPlanSpec(['packageid' => 1, 'configoption' . $position => '42']);

        $this->assertSame(42, $spec['advanced']['cpu_limit_percent']);
    }

    public function testTheDiskQuotaIsReadFromTheFieldThatAsksForIt(): void
    {
        $position = array_search('diskquota', self::EXPECTED_ORDER, true) + 1;
        $spec = panelica_managedPlanSpec(['packageid' => 1, 'configoption' . $position => '7777']);

        $this->assertSame(7777, $spec['basic']['disk_quota_mb']);
    }

    public function testTheAdvancedBoxIsTheLastField(): void
    {
        $options = panelica_ConfigOptions();

        $this->assertSame('advanced', array_key_last($options));
        $this->assertSame('textarea', $options['advanced']['Type']);
    }

    public function testEveryDropdownOffersOnlyWordsTheReaderKnows(): void
    {
        $accepted = [
            'sshlevel' => ['none', 'jailed', 'full'],
            'quotamode' => ['strict', 'monitor', 'oversell'],
            'waf' => ['on', 'off'],
            'backup' => ['on', 'off'],
        ];

        foreach (panelica_ConfigOptions() as $key => $definition) {
            if (($definition['Type'] ?? '') !== 'dropdown' || !isset($accepted[$key])) {
                continue;
            }

            $offered = array_map('trim', explode(',', $definition['Options']));

            $this->assertSame($accepted[$key], $offered, $key . ' offers a value the module would silently ignore');
            $this->assertContains($definition['Default'], $accepted[$key], $key . ' defaults to something unknown');
        }
    }
}
