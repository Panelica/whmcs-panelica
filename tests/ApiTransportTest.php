<?php

use PHPUnit\Framework\TestCase;

/**
 * What the module actually puts on the wire, and what it makes of the answer.
 *
 * Every one of these is a promise the panel relies on: the signature covers
 * exactly what the backend recomputes, the secret never leaves the server,
 * and a DELETE is signed without the body it still sends. Get any of them
 * wrong and every call fails with an authentication error that looks like a
 * key problem.
 */
final class ApiTransportTest extends TestCase
{
    private function api(string $host = 'panel.test', int $port = 8443): FakePanelicaAPI
    {
        return new FakePanelicaAPI($host, $port, 'pk_live_key', 'sk_live_secret');
    }

    public function testTheSecretIsNeverSent(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->listPlans();

        $wire = json_encode($api->sent);

        $this->assertStringNotContainsString('sk_live_secret', $wire);
        $this->assertSame('pk_live_key', $api->lastHeader('X-API-Key'));
    }

    public function testTheSignatureCoversMethodPathTimestampAndBody(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'a1']]);
        $api->createAccount('bob', 'pw', 'bob@example.test', 'Bob', 'plan-1');

        $timestamp = $api->lastHeader('X-Timestamp');
        $body = end($api->sent)['body'];
        $expected = hash_hmac('sha256', 'POST' . '/v1/accounts' . $timestamp . $body, 'sk_live_secret');

        $this->assertSame($expected, $api->lastHeader('X-Signature'));
    }

    public function testADeleteIsSignedWithoutItsBodyButStillSendsIt(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success']);
        $api->deleteFiles('dom-1', ['/public_html/old.txt']);

        $sent = end($api->sent);
        $timestamp = $api->lastHeader('X-Timestamp');
        $path = str_replace('https://panel.test:8443/api/external', '', $sent['url']);
        $expected = hash_hmac('sha256', 'DELETE' . $path . $timestamp . '', 'sk_live_secret');

        $this->assertNotSame('', $sent['body'], 'the body is still transmitted');
        $this->assertSame($expected, $api->lastHeader('X-Signature'));
    }

    public function testTheRequestGoesToThePublicApiPath(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->listPlans();

        $this->assertSame('https://panel.test:8443/api/external/v1/plans', end($api->sent)['url']);
    }

    public function testAHostGivenAsAUrlWithAPortIsUnderstood(): void
    {
        $api = new FakePanelicaAPI('https://panel.test:9443/', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->listPlans();

        $this->assertSame('https://panel.test:9443/api/external/v1/plans', end($api->sent)['url']);
    }

    public function testAMissingPortFallsBackToThePanelDefault(): void
    {
        $api = new FakePanelicaAPI('panel.test', 0, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->listPlans();

        $this->assertSame('https://panel.test:8443/api/external/v1/plans', end($api->sent)['url']);
    }

    public function testASelfSignedCertificateIsRetriedOnceWithoutVerification(): void
    {
        $api = $this->api();
        $api->queueCurlError(60, 'SSL certificate problem: self signed certificate');
        $api->queueJson(['status' => 'success', 'data' => []]);

        $api->listPlans();

        $this->assertSame([true, false], $api->verifyFlags);
        $this->assertTrue($api->tlsFallbackUsed);
    }

    public function testAConnectionFailureIsNotRetriedWithoutVerification(): void
    {
        $api = $this->api();
        $api->queueCurlError(7, 'Failed to connect to panel.test port 8443: Connection refused');

        try {
            $api->listPlans();
            $this->fail('a refused connection must raise');
        } catch (PanelicaAPIException $e) {
            $this->assertStringContainsString('Connection refused', $e->getMessage());
        }

        $this->assertSame([true], $api->verifyFlags, 'a refused connection is not a certificate problem');
    }

    public function testAnErrorEnvelopeBecomesTheMessageTheOperatorSees(): void
    {
        $api = $this->api();
        $api->queueJson(['status' => 'error', 'error' => 'Plan not found', 'details' => 'id=plan-9', 'code' => 'NOT_FOUND'], 404);

        try {
            $api->listPlans();
            $this->fail('an error envelope must raise');
        } catch (PanelicaAPIException $e) {
            $this->assertStringContainsString('Plan not found', $e->getMessage());
            $this->assertStringContainsString('id=plan-9', $e->getMessage());
            $this->assertSame(404, $e->getHttpStatus());
            $this->assertSame('NOT_FOUND', $e->getApiCode());
        }
    }

    public function testAnHtmlErrorPageIsReportedAsSuchRatherThanAsJson(): void
    {
        $api = $this->api();
        $api->queueRaw('<html><body><h1>502 Bad Gateway</h1></body></html>', 502);

        try {
            $api->listPlans();
            $this->fail('a non-JSON body must raise');
        } catch (PanelicaAPIException $e) {
            $this->assertStringContainsString('502', $e->getMessage());
            $this->assertStringContainsString('Bad Gateway', $e->getMessage());
        }
    }

    public function testAnUnconfiguredKeyIsRefusedBeforeAnyRequestIsMade(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, '', '');

        $this->expectException(PanelicaAPIException::class);

        try {
            $api->listPlans();
        } finally {
            $this->assertSame([], $api->sent, 'nothing is sent without credentials');
        }
    }
}
