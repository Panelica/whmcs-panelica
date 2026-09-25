<?php

use PHPUnit\Framework\TestCase;

/**
 * What gets written when the panel answers with something unexpected.
 *
 * The bandwidth branch asks whether the figure it wants is actually there -
 * isset($bw['data']['bytes_used']) - and writes nothing when it is not. The disk
 * branch two lines above only asked whether "data" existed at all, then read
 * two keys out of it. Any other shape - a list, an envelope from a different
 * call, a payload the panel changed - and PHP shouted about undefined keys while
 * zero went onto the customer's service.
 *
 * A zero is worse than nothing here: nothing leaves the last known figures
 * alone, while zero looks like a real reading and says the customer is using no
 * disk at all.
 */
final class UsageRowShapeTest extends TestCase
{
    private function panelAnswering(array $diskEnvelope, array $bwEnvelope): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson($diskEnvelope);
        $api->queueJson($bwEnvelope);

        return $api;
    }

    public function testAnUnexpectedDiskPayloadWritesNothingRatherThanZero(): void
    {
        $api = $this->panelAnswering(
            ['status' => 'success', 'data' => [['id' => 'something-else']]],
            ['status' => 'success', 'data' => ['bytes_used' => 1048576]]
        );

        $row = panelica_usageRowFor($api, ['id' => 'acct-1']);

        $this->assertArrayNotHasKey('diskusage', $row);
        $this->assertArrayNotHasKey('disklimit', $row);
        $this->assertSame(1, $row['bwusage'], 'the figure that did arrive is still written');
    }

    public function testAHalfFilledDiskPayloadIsNotTrusted(): void
    {
        $api = $this->panelAnswering(
            ['status' => 'success', 'data' => ['used_mb' => 120]],
            ['status' => 'success', 'data' => []]
        );

        $row = panelica_usageRowFor($api, ['id' => 'acct-1']);

        $this->assertArrayNotHasKey('diskusage', $row);
        $this->assertArrayNotHasKey('disklimit', $row);
    }

    public function testTheOrdinaryAnswerIsWrittenAsBefore(): void
    {
        $api = $this->panelAnswering(
            ['status' => 'success', 'data' => ['used_mb' => 120, 'quota_mb' => 5120]],
            ['status' => 'success', 'data' => ['bytes_used' => 2097152]]
        );

        $row = panelica_usageRowFor($api, ['id' => 'acct-1']);

        $this->assertSame(120, $row['diskusage']);
        $this->assertSame(5120, $row['disklimit']);
        $this->assertSame(2, $row['bwusage']);
    }

    public function testAnUnlimitedQuotaIsStillWritten(): void
    {
        $api = $this->panelAnswering(
            ['status' => 'success', 'data' => ['used_mb' => 40, 'quota_mb' => 0]],
            ['status' => 'success', 'data' => ['bytes_used' => 0]]
        );

        $row = panelica_usageRowFor($api, ['id' => 'acct-1']);

        $this->assertSame(0, $row['disklimit'], 'zero is how WHMCS says unlimited');
        $this->assertSame(40, $row['diskusage']);
    }

    public function testNothingIsWrittenWhenNeitherCallAnswered(): void
    {
        $api = $this->panelAnswering(
            ['status' => 'success', 'data' => 'not an array'],
            ['status' => 'success', 'data' => []]
        );

        $this->assertSame([], panelica_usageRowFor($api, ['id' => 'acct-1']));
    }
}
