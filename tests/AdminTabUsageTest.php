<?php

use PHPUnit\Framework\TestCase;

/**
 * The admin service tab reads the same disk figures the nightly usage job
 * reads, and it used to ask the same half question: is "used_mb" there? It then
 * printed "quota_mb" without ever asking about it. A missing quota is not a
 * blank on that line - panelica_formatMb(0) prints "unlimited", so an admin
 * looking at a failed or changed payload is told the account has no disk limit
 * at all. That is the worst possible reading to invent, because it is the one
 * that makes someone stop worrying.
 */
final class AdminTabUsageTest extends TestCase
{
    private function panelWithDisk(array $diskEnvelope): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => 'acct-1', 'username' => 'wmtuser', 'role' => 'USER', 'status' => 'active'],
        ]]);
        $api->queueJson($diskEnvelope);

        return $api;
    }

    public function testAMissingQuotaIsNotPrintedAsUnlimited(): void
    {
        $api = $this->panelWithDisk(['status' => 'success', 'data' => ['used_mb' => 120]]);

        $fields = panelica_adminTabFieldsWith($api, ['username' => 'wmtuser']);

        $this->assertArrayNotHasKey('Disk Usage', $fields, 'half an answer is not an answer');
        $this->assertSame('acct-1', $fields['Panelica Account ID'], 'the rest of the tab still renders');
    }

    public function testAnUnexpectedPayloadShapeIsIgnored(): void
    {
        $api = $this->panelWithDisk(['status' => 'success', 'data' => [['id' => 'x']]]);

        $fields = panelica_adminTabFieldsWith($api, ['username' => 'wmtuser']);

        $this->assertArrayNotHasKey('Disk Usage', $fields);
    }

    public function testACompleteAnswerIsStillPrinted(): void
    {
        $api = $this->panelWithDisk(['status' => 'success', 'data' => ['used_mb' => 120, 'quota_mb' => 5120]]);

        $fields = panelica_adminTabFieldsWith($api, ['username' => 'wmtuser']);

        $this->assertSame('120 MB / 5 GB', $fields['Disk Usage']);
    }

    public function testAGenuinelyUnlimitedQuotaStillReadsUnlimited(): void
    {
        $api = $this->panelWithDisk(['status' => 'success', 'data' => ['used_mb' => 120, 'quota_mb' => 0]]);

        $fields = panelica_adminTabFieldsWith($api, ['username' => 'wmtuser']);

        $this->assertSame('120 MB / unlimited', $fields['Disk Usage'], 'a real zero still means unlimited');
    }

    /**
     * The trap in my own change: pulling the API construction out of the try
     * block would have let a constructor failure escape as a fatal on the WHMCS
     * admin page instead of rendering as an error row.
     */
    public function testAnAccountLookupFailureStillRendersAsARow(): void
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => []]);

        $fields = panelica_adminTabFieldsWith($api, ['username' => 'nobody']);

        $this->assertArrayHasKey('Panelica', $fields);
        $this->assertStringStartsWith('Error: ', $fields['Panelica']);
    }

    public function testTheWhmcsEntryPointStillReturnsAnArray(): void
    {
        $fields = panelica_AdminServicesTabFields(['username' => '', 'serverip' => '127.0.0.1']);

        $this->assertIsArray($fields);
        $this->assertArrayHasKey('Panelica', $fields);
    }
}
