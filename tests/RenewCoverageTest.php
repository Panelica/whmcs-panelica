<?php

use PHPUnit\Framework\TestCase;

/**
 * Renewal-invoice-paid hook.
 *
 * Panelica accounts are not time-bound, so Renew has exactly one job: if the
 * account sits suspended, switch it back on. Everything else - including a
 * panel that cannot be reached at all - deliberately answers 'success' so a
 * paid renewal is never blocked by a panel hiccup. That swallow-and-succeed
 * behaviour is a documented product decision; these tests pin it so a future
 * change is a conscious one.
 */
final class RenewCoverageTest extends TestCase
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

    public function testASuspendedAccountIsSwitchedBackOn(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER', 'status' => 'suspended'],
        ]]);
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        $result = panelica_Renew($this->params());

        $this->assertSame('success', $result);
        $this->assertCount(2, $api->sent);
        $last = end($api->sent);
        $this->assertSame('POST', $last['method']);
        $this->assertStringContainsString('/v1/accounts/acct-1/unsuspend', $last['url']);
    }

    public function testAnActiveAccountIsLeftAlone(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER', 'status' => 'active'],
        ]]);
        panelica_test_useApi($api);

        $result = panelica_Renew($this->params());

        $this->assertSame('success', $result);
        $this->assertCount(1, $api->sent, 'only the lookup went out');
    }

    public function testAnAccountThePanelDoesNotHaveStillRenews(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        panelica_test_useApi($api);

        $result = panelica_Renew($this->params());

        $this->assertSame('success', $result);
        $this->assertCount(1, $api->sent);
    }

    public function testADeadPanelDoesNotBlockTheRenewal(): void
    {
        // Pinned product decision, not an accident: billing must not stall on
        // the panel. The failure is logged, the invoice flow continues.
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueCurlError(7, 'Failed to connect to panel.test port 8443');
        panelica_test_useApi($api);

        $result = panelica_Renew($this->params());

        $this->assertSame('success', $result);
    }

    public function testAFailedUnsuspendIsSwallowedButLogged(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'bob', 'role' => 'USER', 'status' => 'suspended'],
        ]]);
        $api->queueJson(['status' => 'error', 'error' => 'panel busy'], 500);
        panelica_test_useApi($api);

        $result = panelica_Renew($this->params());

        $this->assertSame('success', $result, 'pinned: renewal never blocks on the panel');

        $logged = array_column(ModuleCallLog::$calls, 'response');
        $this->assertContains('panel busy', $logged, 'the swallowed failure is at least on the record');
    }
}
