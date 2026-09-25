<?php

use PHPUnit\Framework\TestCase;

/**
 * What the operator is told when provisioning half-succeeded.
 *
 * The account is made first and the website second. If the website fails the
 * account is removed again, so a retry from WHMCS starts from a clean slate -
 * and the message says so: "(account rolled back)".
 *
 * It said so whether or not the removal worked. When the panel refused it too,
 * the operator was told the account had been rolled back while it was still
 * sitting there: the retry then failed with "already exists", and nobody knew
 * an orphan had been left behind, or under which name.
 */
final class CreateAccountRollbackTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleCallLog::reset();
    }

    private function params(array $overrides = []): array
    {
        return array_merge([
            'serverhostname' => 'panel.test',
            'serverport' => 8443,
            'serverpassword' => 'pk',
            'serveraccesshash' => 'sk',
            'username' => 'bob',
            'password' => 'Str0ng!Pass-2026',
            'domain' => 'bob.test',
            'configoption1' => '11111111-2222-4333-8444-555555555555',
            'clientsdetails' => ['firstname' => 'Bob', 'lastname' => 'Smith', 'email' => 'bob@example.test'],
        ], $overrides);
    }

    /** A panel that creates the account, then refuses the website. */
    private function panelRefusingTheWebsite(bool $rollbackWorks): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);                         // duplicate check
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-1']]);          // create account
        $api->queueJson(['status' => 'error', 'error' => 'domain already hosted'], 409); // create website

        $rollbackWorks
            ? $api->queueJson(['status' => 'success'])
            : $api->queueJson(['status' => 'error', 'error' => 'account is busy'], 500);

        return $api;
    }

    public function testTheAccountIsRemovedWhenTheWebsiteFails(): void
    {
        $api = $this->panelRefusingTheWebsite(true);

        $result = panelica_createAccountWith($api, $this->params());

        $this->assertNotSame('success', $result);
        $this->assertStringContainsString('rolled back', $result);

        $deleted = array_filter($api->sent, fn ($s) => $s['method'] === 'DELETE');
        $this->assertNotEmpty($deleted, 'nothing was rolled back');
    }

    public function testItDoesNotClaimARollbackThatDidNotHappen(): void
    {
        $api = $this->panelRefusingTheWebsite(false);

        $result = panelica_createAccountWith($api, $this->params());

        $this->assertNotSame('success', $result);
        $this->assertStringNotContainsString('rolled back', $result, 'the operator was told a lie');
    }

    public function testItNamesTheAccountLeftBehind(): void
    {
        $api = $this->panelRefusingTheWebsite(false);

        $result = panelica_createAccountWith($api, $this->params());

        $this->assertStringContainsString('bob', $result, 'the orphan is unnamed');
    }

    public function testTheWebsiteFailureIsStillReported(): void
    {
        $api = $this->panelRefusingTheWebsite(false);

        $this->assertStringContainsString('domain already hosted', panelica_createAccountWith($api, $this->params()));
    }

    public function testAnAccountWithNoDomainIsSimplyCreated(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-1']]);

        $this->assertSame('success', panelica_createAccountWith($api, $this->params(['domain' => ''])));
    }

    public function testAUsernameAlreadyOnThePanelIsRefusedBeforeAnythingIsMade(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'acct-x', 'username' => 'bob', 'role' => 'USER']]]);

        $result = panelica_createAccountWith($api, $this->params());

        $this->assertStringContainsString('already exists', $result);
        $this->assertCount(1, $api->sent, 'it went on to create something anyway');
    }
}
