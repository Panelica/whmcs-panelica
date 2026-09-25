<?php

use PHPUnit\Framework\TestCase;

/**
 * The static surface WHMCS reads before it ever talks to a panel: the module
 * metadata, the admin custom buttons, and the product configuration options.
 *
 * These are contracts with WHMCS itself - a renamed key or a changed default
 * silently changes what every product using the module does - so the shapes
 * are pinned here as behaviour, not re-derived.
 */
final class AdminSurfaceCoverageTest extends TestCase
{
    // ---------------------------------------------------------------
    // panelica_MetaData
    // ---------------------------------------------------------------

    public function testMetaDataDescribesTheModuleToWhmcs(): void
    {
        $meta = panelica_MetaData();

        $this->assertSame('Panelica', $meta['DisplayName']);
        $this->assertSame('1.1', $meta['APIVersion']);
        $this->assertTrue($meta['RequiresServer']);
    }

    public function testMetaDataPointsBothPortsAtThePanelPort(): void
    {
        $meta = panelica_MetaData();

        $this->assertSame('8443', $meta['DefaultSSLPort']);
        $this->assertSame('8443', $meta['DefaultNonSSLPort']);
    }

    public function testMetaDataCarriesExactlyTheKnownKeys(): void
    {
        $this->assertSame(
            ['DisplayName', 'APIVersion', 'RequiresServer', 'DefaultSSLPort', 'DefaultNonSSLPort'],
            array_keys(panelica_MetaData())
        );
    }

    // ---------------------------------------------------------------
    // panelica_AdminCustomButtonArray
    // ---------------------------------------------------------------

    public function testTheOnlyAdminButtonIsSyncFromPanel(): void
    {
        $this->assertSame(
            ['Sync From Panel' => 'Sync'],
            panelica_AdminCustomButtonArray([])
        );
    }

    public function testTheSyncButtonTargetsAFunctionThatExists(): void
    {
        foreach (panelica_AdminCustomButtonArray([]) as $suffix) {
            $this->assertTrue(
                function_exists('panelica_' . $suffix),
                "button points at panelica_{$suffix}, which does not exist"
            );
        }
    }

    // ---------------------------------------------------------------
    // panelica_ConfigOptions
    // ---------------------------------------------------------------

    public function testThereAreTwentyFourConfigOptions(): void
    {
        $this->assertCount(24, panelica_ConfigOptions());
    }

    public function testThePlanOptionComesFirstAndLoadsLive(): void
    {
        $options = panelica_ConfigOptions();
        $first = array_key_first($options);

        $this->assertSame('plan', $first);
        $this->assertSame('dropdown', $options['plan']['Type']);
        $this->assertSame('panelica_LoaderPlans', $options['plan']['Loader']);
        $this->assertTrue($options['plan']['SimpleMode']);
    }

    public function testThePlanLoaderFunctionExists(): void
    {
        $this->assertTrue(function_exists(panelica_ConfigOptions()['plan']['Loader']));
    }

    public function testTheManagedPlanSentinelIsPinned(): void
    {
        // Stored in configoption1 for every managed-mode product; a changed
        // value would strand existing products on a sentinel nobody matches.
        $this->assertSame('__managed__', PANELICA_MANAGED_PLAN);
    }

    public function testEveryOptionHasAFriendlyNameAndAType(): void
    {
        foreach (panelica_ConfigOptions() as $key => $def) {
            $this->assertNotSame('', trim($def['FriendlyName'] ?? ''), "option {$key} has no FriendlyName");
            $this->assertContains($def['Type'], ['dropdown', 'text', 'textarea'], "option {$key} type");
        }
    }

    public function testTheResourceDefaultsAreThePinnedOnes(): void
    {
        $options = panelica_ConfigOptions();

        $expected = [
            'diskquota'     => '5120',
            'bandwidth'     => '51200',
            'cpulimit'      => '100',
            'memlimit'      => '1024',
            'proclimit'     => '100',
            'iolimit'       => '0',
            'maxdomains'    => '1',
            'maxdb'         => '5',
            'maxemail'      => '10',
            'maxftp'        => '5',
            'maxcontainers' => '0',
            'phpmem'        => '256',
            'inodequota'    => '-1',
            'maxsub'        => '10',
            'netbw'         => '0',
            'maxcron'       => '5',
            'phpexec'       => '30',
            'phpupload'     => '64',
        ];

        foreach ($expected as $key => $default) {
            $this->assertSame($default, $options[$key]['Default'], "default of {$key}");
        }
    }

    public function testTheDropdownsOfferThePinnedChoices(): void
    {
        $options = panelica_ConfigOptions();

        $this->assertSame('none,jailed,full', $options['sshlevel']['Options']);
        $this->assertSame('none', $options['sshlevel']['Default']);

        $this->assertSame('strict,monitor,oversell', $options['quotamode']['Options']);
        $this->assertSame('strict', $options['quotamode']['Default']);

        $this->assertSame('on,off', $options['waf']['Options']);
        $this->assertSame('on', $options['waf']['Default']);

        $this->assertSame('on,off', $options['backup']['Options']);
        $this->assertSame('on', $options['backup']['Default']);
    }

    public function testTheAdvancedOverridesBoxIsATextarea(): void
    {
        $options = panelica_ConfigOptions();

        $this->assertSame('textarea', $options['advanced']['Type']);
        $this->assertSame('advanced', array_key_last($options));
    }
}
