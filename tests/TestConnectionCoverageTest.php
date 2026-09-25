<?php

use PHPUnit\Framework\TestCase;

/**
 * Setup -> Servers -> Test Connection.
 *
 * The button is the operator's first contact with a new panel, so what it says
 * matters: a green light must mean provisioning will actually work (all scopes
 * present), and every failure must arrive as a message, never a fatal.
 */
final class TestConnectionCoverageTest extends TestCase
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
        ];
    }

    public function testAFullyScopedKeyConnects(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => ['scopes' => ['*:*']]]);
        panelica_test_useApi($api);

        $result = panelica_TestConnection($this->params());

        $this->assertSame(['success' => true, 'error' => ''], $result);
    }

    public function testItAsksThePanelWhoTheKeyIs(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => ['scopes' => ['*:*']]]);
        panelica_test_useApi($api);

        panelica_TestConnection($this->params());

        $this->assertCount(1, $api->sent);
        $this->assertSame('GET', $api->sent[0]['method']);
        $this->assertStringContainsString('/v1/me', $api->sent[0]['url']);
    }

    public function testPerResourceWildcardsSatisfyTheScopeCheck(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            'scopes' => ['accounts:*', 'domains:*', 'plans:*', 'bandwidth:*'],
        ]]);
        panelica_test_useApi($api);

        $result = panelica_TestConnection($this->params());

        $this->assertTrue($result['success']);
    }

    public function testAKeyMissingAScopeIsRefusedAndTheScopeIsNamed(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            'scopes' => ['accounts:read', 'accounts:write', 'accounts:delete', 'domains:write', 'plans:read', 'plans:write'],
        ]]);
        panelica_test_useApi($api);

        $result = panelica_TestConnection($this->params());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('bandwidth:read', $result['error']);
        $this->assertStringContainsString('missing required scopes', $result['error']);
    }

    public function testAnAnswerWithoutASuccessStatusIsNotAGreenLight(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['hello' => 'world']);
        panelica_test_useApi($api);

        $result = panelica_TestConnection($this->params());

        $this->assertFalse($result['success']);
        $this->assertSame('Unexpected response from the Panelica API.', $result['error']);
    }

    public function testAConnectionFailureBecomesAMessageNotAFatal(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueCurlError(7, 'Failed to connect to panel.test port 8443');
        panelica_test_useApi($api);

        $result = panelica_TestConnection($this->params());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Failed to connect', $result['error']);
    }

    public function testAPanelErrorEnvelopeIsReportedAsItsMessage(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'error', 'error' => 'invalid API key'], 401);
        panelica_test_useApi($api);

        $result = panelica_TestConnection($this->params());

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('invalid API key', $result['error']);
    }

    // ---------------------------------------------------------------
    // panelica_missingScopes on its own
    // ---------------------------------------------------------------

    public function testEveryRequirementIsMissingFromAnEmptyGrant(): void
    {
        $this->assertSame(
            ['accounts:read', 'accounts:write', 'accounts:delete', 'domains:write', 'plans:read', 'plans:write', 'bandwidth:read'],
            panelica_missingScopes([])
        );
    }

    public function testTheExactScopeListSatisfiesTheCheck(): void
    {
        $granted = ['accounts:read', 'accounts:write', 'accounts:delete', 'domains:write', 'plans:read', 'plans:write', 'bandwidth:read'];

        $this->assertSame([], panelica_missingScopes($granted));
    }

    public function testTheGlobalWildcardSatisfiesEverything(): void
    {
        $this->assertSame([], panelica_missingScopes(['*:*']));
    }

    public function testAReadOnlyWildcardDoesNotCoverAnotherResource(): void
    {
        $missing = panelica_missingScopes(['accounts:*', 'plans:*', 'bandwidth:*']);

        $this->assertSame(['domains:write'], $missing);
    }
}
