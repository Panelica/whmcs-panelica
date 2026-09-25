<?php

use PHPUnit\Framework\TestCase;

/**
 * Termination is the one door that reports success when it cannot find the
 * account, and that is deliberate: an account already deleted on the panel must
 * not leave WHMCS stuck on a service it can never close.
 *
 * But "I looked for it and it is gone" and "I never had anything to look for"
 * are not the same sentence. Every other door - suspend, unsuspend, change
 * password, change package - goes through panelica_requireAccount(), which
 * refuses a service with no username at all. Termination looked the username up
 * directly, so an empty one searched for nothing, found nothing, and told WHMCS
 * the account had been deleted. WHMCS then closes the service while the panel
 * keeps the account, its files and its disk, and nobody is ever told.
 */
final class TerminateIdentityTest extends TestCase
{
    private function panelWith(array $accounts): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => $accounts]);

        return $api;
    }

    public function testAServiceWithNoUsernameIsNotReportedAsTerminated(): void
    {
        $api = $this->panelWith([
            ['id' => 'acct-1', 'username' => 'wmtuser', 'role' => 'USER'],
        ]);

        $result = panelica_terminateAccountWith($api, ['username' => '']);

        $this->assertNotSame('success', $result, 'nothing was identified, so nothing was terminated');
        $this->assertStringContainsString('username', strtolower((string) $result));
    }

    public function testWhitespaceIsNotAUsernameEither(): void
    {
        $api = $this->panelWith([]);

        $result = panelica_terminateAccountWith($api, ['username' => '   ']);

        $this->assertNotSame('success', $result);
    }

    /**
     * The deliberate behaviour this must not disturb: a real username whose
     * account is already gone from the panel still closes cleanly.
     */
    public function testAnAccountAlreadyGoneFromThePanelStillCountsAsTerminated(): void
    {
        $api = $this->panelWith([
            ['id' => 'acct-2', 'username' => 'someoneelse', 'role' => 'USER'],
        ]);

        $result = panelica_terminateAccountWith($api, ['username' => 'wmtuser']);

        $this->assertSame('success', $result);
    }

    public function testAnExistingAccountIsStillDeleted(): void
    {
        $api = $this->panelWith([
            ['id' => 'acct-1', 'username' => 'wmtuser', 'role' => 'USER'],
        ]);
        $api->queueJson(['status' => 'success', 'data' => []]);

        $result = panelica_terminateAccountWith($api, ['username' => 'wmtuser']);

        $this->assertSame('success', $result);
        $last = end($api->sent);
        $this->assertSame('DELETE', $last['method']);
        $this->assertStringContainsString('acct-1', $last['url']);
    }

    /**
     * The trap in my own change: a panel that never answers must still be
     * reported as a failure rather than as a completed termination.
     */
    public function testAPanelFailureIsStillReportedRatherThanSwallowed(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueCurlError(6, 'Could not resolve host');

        $result = panelica_terminateAccountWith($api, ['username' => 'wmtuser']);

        $this->assertNotSame('success', $result);
    }

    public function testTheWhmcsEntryPointStillReturnsAString(): void
    {
        $this->assertIsString(panelica_TerminateAccount(['username' => '', 'serverip' => '127.0.0.1']));
    }
}
