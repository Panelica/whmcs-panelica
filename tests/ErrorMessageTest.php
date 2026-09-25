<?php

use PHPUnit\Framework\TestCase;

/**
 * What the panel's errors read like by the time somebody sees them.
 *
 * The panel answers with two fields: "error", which is a translation key it has
 * not translated, and "details", which is the sentence. The module joined them
 * with a dash and handed the result to whoever asked - including a customer in
 * the client area, who was shown things like
 *
 *   apiErrors.external.email.createFailed — email address already exists
 *
 * with the useful half hidden behind the machine key. Both of those were taken
 * off a live panel.
 *
 * The sentence now leads. The key is not thrown away - it stays reachable as
 * the API code and in the module log, which is where an operator looks for it.
 */
final class ErrorMessageTest extends TestCase
{
    private function apiRefusing(array $envelope, int $status = 400): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson($envelope, $status);

        return $api;
    }

    public function testTheSentenceIsWhatIsShownNotTheKey(): void
    {
        $api = $this->apiRefusing([
            'status' => 'error',
            'error' => 'apiErrors.external.email.createFailed',
            'details' => 'email address already exists',
        ]);

        try {
            $api->listPlans();
            $this->fail('an error envelope must raise');
        } catch (PanelicaAPIException $e) {
            $this->assertSame('email address already exists', $e->getMessage());
        }
    }

    public function testTheKeyIsStillReachableForWhoeverNeedsIt(): void
    {
        $api = $this->apiRefusing([
            'status' => 'error',
            'error' => 'apiErrors.external.email.createFailed',
            'details' => 'email address already exists',
        ]);

        try {
            $api->listPlans();
        } catch (PanelicaAPIException $e) {
            $this->assertSame('apiErrors.external.email.createFailed', $e->getApiCode());
        }
    }

    public function testAnExplicitCodeStillWins(): void
    {
        $api = $this->apiRefusing([
            'status' => 'error',
            'error' => 'apiErrors.something',
            'details' => 'it did not work',
            'code' => 'PLAN_NOT_FOUND',
        ]);

        try {
            $api->listPlans();
        } catch (PanelicaAPIException $e) {
            $this->assertSame('PLAN_NOT_FOUND', $e->getApiCode());
            $this->assertSame('it did not work', $e->getMessage());
        }
    }

    public function testAKeyWithNothingToExplainItIsStillShown(): void
    {
        $api = $this->apiRefusing(['status' => 'error', 'error' => 'apiErrors.external.plan.notFound']);

        try {
            $api->listPlans();
        } catch (PanelicaAPIException $e) {
            $this->assertStringContainsString('apiErrors.external.plan.notFound', $e->getMessage(),
                'with no sentence to show, the key is better than nothing');
        }
    }

    public function testAPlainMessageIsLeftAlone(): void
    {
        $api = $this->apiRefusing(['status' => 'error', 'error' => 'The plan is in use by 3 accounts.']);

        try {
            $api->listPlans();
        } catch (PanelicaAPIException $e) {
            $this->assertSame('The plan is in use by 3 accounts.', $e->getMessage());
        }
    }

    public function testAPlainMessageKeepsItsDetails(): void
    {
        $api = $this->apiRefusing([
            'status' => 'error',
            'error' => 'The plan is in use.',
            'details' => 'accounts: 3',
        ]);

        try {
            $api->listPlans();
        } catch (PanelicaAPIException $e) {
            $this->assertStringContainsString('The plan is in use.', $e->getMessage());
            $this->assertStringContainsString('accounts: 3', $e->getMessage());
        }
    }

    public function testAnEmptyEnvelopeStillSaysSomethingUseful(): void
    {
        $api = $this->apiRefusing(['status' => 'error'], 503);

        try {
            $api->listPlans();
        } catch (PanelicaAPIException $e) {
            $this->assertStringContainsString('503', $e->getMessage());
        }
    }
}
