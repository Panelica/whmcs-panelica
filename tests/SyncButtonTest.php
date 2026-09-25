<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * The admin's "Sync From Panel" button, next to the nightly job that does the
 * same work.
 *
 * Both read an account's usage off the panel and write it onto the service. The
 * nightly run also writes the bandwidth allowance that comes with the plan; the
 * button did not - so an operator who moved a customer to a bigger plan and
 * pressed Sync saw the new disk allowance appear and the old bandwidth
 * allowance stay, until the nightly run corrected it hours later.
 *
 * They now decide what "usage" means in one place.
 */
final class SyncButtonTest extends TestCase
{
    protected function setUp(): void
    {
        Capsule::reset();
        ModuleCallLog::reset();
    }

    private function panel(): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'plan-1', 'monthly_bandwidth_mb' => 51200]]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'acct-1', 'username' => 'bob', 'plan_id' => 'plan-1']]]);
        $api->queueJson(['status' => 'success', 'data' => ['used_mb' => 300, 'quota_mb' => 10240]]);
        $api->queueJson(['status' => 'success', 'data' => ['bytes_used' => 5242880]]);

        return $api;
    }

    private function params(): array
    {
        return [
            'serverhostname' => 'panel.test',
            'serverport' => 8443,
            'serverpassword' => 'pk',
            'serveraccesshash' => 'sk',
            'username' => 'bob',
            'serviceid' => 77,
        ];
    }

    public function testTheButtonWritesTheSameFiguresAsTheNightlyRun(): void
    {
        panelica_syncWith($this->panel(), $this->params());

        $this->assertCount(1, Capsule::$updates);
        $written = Capsule::$updates[0]['values'];

        $this->assertSame(300, $written['diskusage']);
        $this->assertSame(10240, $written['disklimit']);
        $this->assertSame(5, $written['bwusage']);
        $this->assertSame(51200, $written['bwlimit'], 'the plan allowance the nightly run writes');
    }

    public function testItWritesAgainstTheServiceItWasPressedOn(): void
    {
        panelica_syncWith($this->panel(), $this->params());

        $this->assertSame(77, Capsule::$updates[0]['where']['id']);
    }

    public function testNothingIsClaimedWhenThePanelAnsweredNothing(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'acct-1', 'username' => 'bob']]]);
        $api->queueJson(['status' => 'error', 'error' => 'busy'], 500);
        $api->queueJson(['status' => 'error', 'error' => 'busy'], 500);

        panelica_syncWith($api, $this->params());

        $this->assertSame([], Capsule::$updates);
    }

    public function testAnAccountThePanelDoesNotHaveIsReportedNotWritten(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);
        $api->queueJson(['status' => 'success', 'data' => []]);

        $result = panelica_syncWith($api, $this->params());

        $this->assertNotSame('success', $result);
        $this->assertSame([], Capsule::$updates);
    }
}
