<?php

use PHPUnit\Framework\TestCase;

/**
 * A client ordering a second hosting service on the same panel.
 *
 * The panel keeps one account per email address and refuses a second one with
 * "emailAlreadyExists". The module sent the client's address as it was, so the
 * client's second service could not be provisioned at all: WHMCS showed
 * "Failed to create - apiErrors.users.emailAlreadyExists" for an order that
 * had been paid for. Measured against a live panel.
 *
 * The first account keeps the plain address. A later one gets a tagged address
 * (bob+whmcs77@example.test) that still delivers to the same inbox.
 */
final class SecondServiceEmailTest extends TestCase
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
            'serviceid' => 77,
            'username' => 'bob2',
            'password' => 'Str0ng!Pass-2026',
            'domain' => 'second.test',
            'configoption1' => '11111111-2222-4333-8444-555555555555',
            'clientsdetails' => ['firstname' => 'Bob', 'lastname' => 'Smith', 'email' => 'bob@example.test'],
        ], $overrides);
    }

    private function emailSentIn(FakePanelicaAPI $api, int $call): ?string
    {
        $body = json_decode((string) $api->sent[$call]['body'], true);

        return is_array($body) ? ($body['email'] ?? null) : null;
    }

    /** What the panel says today: an untranslated key in "details". */
    private function queueEmailTaken(FakePanelicaAPI $api): void
    {
        $api->queueJson(['status' => 'error', 'error' => 'Failed to create', 'details' => 'apiErrors.users.emailAlreadyExists'], 400);
    }

    public function testTheFirstServiceKeepsThePlainAddress(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);                 // duplicate check
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-1']]);  // create account
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'dom-1']]);   // create website

        $this->assertSame('success', panelica_createAccountWith($api, $this->params()));
        $this->assertSame('bob@example.test', $this->emailSentIn($api, 1));
        $this->assertCount(3, $api->sent, 'no extra call on the ordinary path');
    }

    public function testASecondServiceIsCreatedWithATaggedAddress(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);                 // duplicate check
        $this->queueEmailTaken($api);                                            // create: address taken
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-2']]);  // create again, tagged
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'dom-2']]);   // create website

        $this->assertSame('success', panelica_createAccountWith($api, $this->params()));
        $this->assertSame('bob@example.test', $this->emailSentIn($api, 1));
        $this->assertSame('bob+whmcs77@example.test', $this->emailSentIn($api, 2));
    }

    public function testATranslatedRefusalIsRecognisedByAskingThePanel(): void
    {
        // Once the panel translates the reason, the key is gone from the reply;
        // the address being on another account is what decides.
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->queueJson(['status' => 'error', 'error' => 'Failed to create', 'details' => 'This email address is already in use.'], 400);
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'acct-1', 'username' => 'bob', 'email' => 'Bob@Example.test', 'role' => 'USER']]]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-2']]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'dom-2']]);

        $this->assertSame('success', panelica_createAccountWith($api, $this->params()));
        $this->assertSame('bob+whmcs77@example.test', $this->emailSentIn($api, 3));
    }

    public function testAnUnrelatedRefusalIsReportedNotRetried(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->queueJson(['status' => 'error', 'error' => 'Failed to create', 'details' => 'plan not found'], 400);
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'acct-9', 'username' => 'someone', 'email' => 'someone@example.test']]]);

        $result = panelica_createAccountWith($api, $this->params());

        $this->assertStringContainsString('plan not found', $result);
        $this->assertCount(3, $api->sent, 'the account was not created a second time');
    }

    public function testAnAddressThatAlreadyCarriesATagIsNotTaggedTwice(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $this->queueEmailTaken($api);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'acct-2']]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'dom-2']]);

        panelica_createAccountWith($api, $this->params(['clientsdetails' => ['firstname' => 'Bob', 'lastname' => 'Smith', 'email' => 'bob+shop@example.test']]));

        $this->assertSame('bob+whmcs77@example.test', $this->emailSentIn($api, 2));
    }

    public function testWithoutAServiceIdTheRefusalIsReported(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $this->queueEmailTaken($api);

        $result = panelica_createAccountWith($api, $this->params(['serviceid' => null]));

        $this->assertNotSame('success', $result);
        $this->assertCount(2, $api->sent, 'nothing to tag the address with, so no second attempt');
    }
}
