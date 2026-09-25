<?php

use PHPUnit\Framework\TestCase;

/**
 * Claims the README makes about how the module behaves.
 *
 * A README is read by people deciding whether to install something and by
 * operators working out why it did what it did, so a sentence that is not true
 * of the code costs them time. These pin the two claims that describe runtime
 * behaviour rather than intent.
 *
 * What decides whether a client-area tab appears is the API key's scopes -
 * panelica_selfServiceCaps and nothing else. The module never asks the panel
 * what version it is, so it cannot hide a feature an older panel lacks: the tab
 * is offered and the call fails when it is used. The README used to say the
 * opposite.
 */
final class DocumentedBehaviourTest extends TestCase
{
    private function readme(): string
    {
        return file_get_contents(__DIR__ . '/../README.md');
    }

    private function moduleSource(): string
    {
        return file_get_contents(__DIR__ . '/../modules/servers/panelica/panelica.php')
            . file_get_contents(__DIR__ . '/../modules/servers/panelica/lib/PanelicaAPI.php');
    }

    public function testTheModuleDoesNotClaimToHideFeaturesByPanelVersion(): void
    {
        $this->assertStringNotContainsString(
            'simply hide the features they don\'t support',
            $this->readme(),
            'nothing in the module looks at the panel version'
        );
    }

    public function testNothingInTheModuleLooksAtThePanelVersion(): void
    {
        $source = $this->moduleSource();

        $this->assertSame(0, preg_match_all('/version_compare|panel_version|serverVersion/', $source),
            'if version gating is added, the README claim can come back');
    }

    public function testWhatShowsATabIsTheKeysScopes(): void
    {
        $withoutEmail = panelica_selfServiceCaps(['accounts:read']);
        $withEmail = panelica_selfServiceCaps(['email:write', 'accounts:read']);

        $this->assertFalse($withoutEmail['email']);
        $this->assertTrue($withEmail['email']);
    }

    public function testAKeyWithNoScopesOffersNothing(): void
    {
        foreach (panelica_selfServiceCaps([]) as $tab => $allowed) {
            $this->assertFalse($allowed, $tab . ' was offered to a key with no scopes');
        }
    }

    public function testTheCredentialsMaskingClaimHolds(): void
    {
        ModuleCallLog::reset();

        panelica_log('probe', ['password' => 'the-service-password', 'serveraccesshash' => 'sk_live_the_secret'], ['ok' => true]);

        $call = ModuleCallLog::$calls[0];

        $this->assertContains('the-service-password', $call['mask']);
        $this->assertContains('sk_live_the_secret', $call['mask']);
    }
}
