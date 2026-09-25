<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * The panel's per-key rate limit (60 requests a minute by default).
 *
 * The nightly usage run asks two questions per service and never waited when
 * the panel said "slow down": measured against a live panel with 60 services,
 * services 30 to 60 got no figures at all - yet each was stamped "last updated
 * now", because the plan's bandwidth allowance kept its row from being empty,
 * and the log said "Updated usage for 60 service(s)". Overage billing then ran
 * on stale numbers.
 */
final class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        ModuleCallLog::reset();
    }

    /** The panel's own 429 reply. */
    private function rateLimited(int $retryAfter = 60): array
    {
        return ['status' => 429, 'body' => json_encode([
            'status' => 'error',
            'error' => [
                'code' => 'RATE_LIMIT_EXCEEDED',
                'message' => 'Rate limit exceeded for this API key (minute window)',
                'limit' => 60,
                'retry_after' => $retryAfter,
                'window' => 'minute',
            ],
        ]), 'errno' => 0, 'error' => ''];
    }

    private function json(array $payload, int $status = 200): array
    {
        return ['status' => $status, 'body' => json_encode($payload), 'errno' => 0, 'error' => ''];
    }

    public function testARateLimitedReplySaysWhatHappened(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queue[] = $this->rateLimited();

        try {
            $api->me();
            $this->fail('a refused request must throw');
        } catch (PanelicaAPIException $e) {
            $this->assertSame(429, $e->getHttpStatus());
            $this->assertSame('RATE_LIMIT_EXCEEDED', $e->getApiCode());
            $this->assertStringContainsString('Rate limit exceeded', $e->getMessage());
            $this->assertStringContainsString('60 seconds', $e->getMessage());
        }
    }

    public function testTheClientAreaDoesNotWait(): void
    {
        // A customer clicking a button must not sit through a minute's wait.
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queue[] = $this->rateLimited();

        try {
            $api->me();
        } catch (PanelicaAPIException $e) {
        }

        $this->assertCount(1, $api->sent);
        $this->assertSame([], $api->pauses);
    }

    public function testWhenAskedToWaitTheRequestIsRepeatedAfterThePause(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->waitOnRateLimit(65);
        $api->queue[] = $this->rateLimited(42);
        $api->queue[] = $this->json(['status' => 'success', 'data' => ['name' => 'key']]);

        $this->assertSame('key', $api->me()['data']['name']);
        $this->assertSame([42], $api->pauses, 'waits as long as the panel says');
        $this->assertCount(2, $api->sent);
    }

    public function testTheWaitIsCappedAndTheRequestGivenUpOnEventually(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->waitOnRateLimit(65);
        $api->queue[] = $this->rateLimited(300);
        $api->queue[] = $this->rateLimited(300);
        $api->queue[] = $this->rateLimited(300);

        try {
            $api->me();
            $this->fail('still refused after the retries');
        } catch (PanelicaAPIException $e) {
            $this->assertSame(429, $e->getHttpStatus());
        }

        $this->assertSame([65, 65], $api->pauses, 'never longer than asked, and not for ever');
        $this->assertCount(3, $api->sent);
    }

    public function testTheNightlyRunWaitsInsteadOfSkippingAService(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queue[] = $this->json(['status' => 'success', 'data' => [['id' => 'plan-1', 'monthly_bandwidth_mb' => 5120]]]);
        $api->queue[] = $this->json(['status' => 'success', 'data' => [['id' => 'acct-1', 'username' => 'bob', 'plan_id' => 'plan-1']]]);
        $api->queue[] = $this->rateLimited();
        $api->queue[] = $this->json(['status' => 'success', 'data' => ['used_mb' => 120, 'quota_mb' => 500]]);
        $api->queue[] = $this->json(['status' => 'success', 'data' => ['bytes_used' => 2097152]]);
        Capsule::$rows['tblhosting'] = [(object) ['id' => 42, 'username' => 'bob']];

        $this->assertSame('success', panelica_usageUpdateWith($api, 1));

        $this->assertNotEmpty($api->pauses);
        $this->assertCount(1, Capsule::$updates);
        $this->assertSame(120, Capsule::$updates[0]['values']['diskusage']);
        $this->assertSame(2, Capsule::$updates[0]['values']['bwusage']);
    }

    public function testAServiceWithNoFiguresIsNotStampedEvenWithAPlanAllowance(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queue[] = $this->json(['status' => 'success', 'data' => [['id' => 'plan-1', 'monthly_bandwidth_mb' => 5120]]]);
        $api->queue[] = $this->json(['status' => 'success', 'data' => [['id' => 'acct-1', 'username' => 'bob', 'plan_id' => 'plan-1']]]);
        $api->queue[] = $this->json(['status' => 'error', 'error' => 'panel busy'], 500);
        $api->queue[] = $this->json(['status' => 'error', 'error' => 'panel busy'], 500);
        Capsule::$rows['tblhosting'] = [(object) ['id' => 42, 'username' => 'bob']];

        panelica_usageUpdateWith($api, 1);

        $this->assertSame([], Capsule::$updates, 'the allowance alone is not a reading');
        $logged = end(ModuleCallLog::$calls)['response'];
        $this->assertStringContainsString('Updated usage for 0 service(s)', $logged);
        $this->assertStringContainsString('1 could not be read', $logged);
    }

    public function testTheSyncButtonDoesNotStampAnAllowanceAlone(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queue[] = $this->json(['status' => 'error', 'error' => 'panel busy'], 500);
        $api->queue[] = $this->json(['status' => 'error', 'error' => 'panel busy'], 500);

        $this->assertSame([], panelica_usageRowFor($api, ['id' => 'acct-1', 'plan_id' => 'plan-1'], ['plan-1' => 5120]));
    }
}
