<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * The nightly usage sync, and what it claims to have done.
 *
 * WHMCS shows the "last updated" stamp on the service and operators read it
 * as "these figures are from last night". The job stamped it before it had
 * anything to write, so a panel that refused every usage call still produced
 * a service that looked freshly synced with month-old numbers on it.
 */
final class UsageUpdateTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        ModuleCallLog::reset();
    }

    /** @param array<int, array> $queue responses in the order the module asks for them */
    private function runUsageUpdate(array $accounts, array $services, array $queue): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);            // listPlans
        $api->queueJson(['status' => 'success', 'data' => $accounts]);     // listAccounts

        foreach ($queue as $response) {
            $api->queue[] = $response;
        }

        Capsule::$rows['tblhosting'] = array_map(fn ($s) => (object) $s, $services);

        panelica_usageUpdateWith($api, 1);

        return $api;
    }

    private function json(array $payload): array
    {
        return ['status' => 200, 'body' => json_encode($payload), 'errno' => 0, 'error' => ''];
    }

    private function failure(): array
    {
        return ['status' => 500, 'body' => json_encode(['status' => 'error', 'error' => 'panel busy']), 'errno' => 0, 'error' => ''];
    }

    public function testUsageIsWrittenBackToTheService(): void
    {
        $this->runUsageUpdate(
            [['id' => 'acct-1', 'username' => 'bob']],
            [['id' => 42, 'username' => 'bob']],
            [
                $this->json(['status' => 'success', 'data' => ['used_mb' => 120, 'quota_mb' => 5000]]),
                $this->json(['status' => 'success', 'data' => ['bytes_used' => 2097152]]),
            ]
        );

        $this->assertCount(1, Capsule::$updates);
        $written = Capsule::$updates[0]['values'];

        $this->assertSame(120, $written['diskusage']);
        $this->assertSame(5000, $written['disklimit']);
        $this->assertSame(2, $written['bwusage']);
        $this->assertArrayHasKey('lastupdate', $written);
    }

    public function testAServiceIsNotStampedAsSyncedWhenNothingCouldBeRead(): void
    {
        $this->runUsageUpdate(
            [['id' => 'acct-1', 'username' => 'bob']],
            [['id' => 42, 'username' => 'bob']],
            [$this->failure(), $this->failure()]
        );

        $this->assertSame([], Capsule::$updates, 'nothing was read, so nothing is claimed');
    }

    public function testPartialFiguresAreStillWrittenAndStamped(): void
    {
        $this->runUsageUpdate(
            [['id' => 'acct-1', 'username' => 'bob']],
            [['id' => 42, 'username' => 'bob']],
            [
                $this->json(['status' => 'success', 'data' => ['used_mb' => 7, 'quota_mb' => 100]]),
                $this->failure(),
            ]
        );

        $this->assertCount(1, Capsule::$updates);
        $this->assertSame(7, Capsule::$updates[0]['values']['diskusage']);
        $this->assertArrayNotHasKey('bwusage', Capsule::$updates[0]['values']);
    }

    public function testAServiceWithNoMatchingPanelAccountIsLeftAlone(): void
    {
        $this->runUsageUpdate(
            [['id' => 'acct-1', 'username' => 'bob']],
            [['id' => 42, 'username' => 'someone-else']],
            []
        );

        $this->assertSame([], Capsule::$updates);
    }

    public function testUsernamesAreMatchedRegardlessOfCase(): void
    {
        $this->runUsageUpdate(
            [['id' => 'acct-1', 'username' => 'Bob']],
            [['id' => 42, 'username' => 'bob ']],
            [
                $this->json(['status' => 'success', 'data' => ['used_mb' => 1, 'quota_mb' => 2]]),
                $this->json(['status' => 'success', 'data' => ['bytes_used' => 0]]),
            ]
        );

        $this->assertCount(1, Capsule::$updates);
        $this->assertSame(42, Capsule::$updates[0]['where']['id']);
    }
}
