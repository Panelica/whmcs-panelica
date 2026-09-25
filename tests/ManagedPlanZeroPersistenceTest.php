<?php

use PHPUnit\Framework\TestCase;

/**
 * "0 = disabled" and "off" must actually reach the panel.
 *
 * The panel's plan CREATE endpoint binds the JSON into a Go struct and saves
 * it with GORM, and most plan columns carry a database default (max_cron_jobs
 * 3, cron_jobs_enabled/backup_enabled/ftp_access_enabled/mysql_access_enabled/
 * ssl_enabled all true). A zero or false in the create payload is the Go zero
 * value, so GORM omits the column and the database default wins.
 *
 * Measured live against a Panelica panel: a plan created with
 *   max_cron_jobs=0, cron_jobs_enabled=false, backup_enabled=false,
 *   ftp_access_enabled=false, mysql_access_enabled=false, ssl_enabled=false
 * came back as
 *   max_cron_jobs=3, and every one of those flags true.
 * The PATCH endpoint (raw column map) was measured to persist the very same
 * zeros and falses correctly.
 *
 * So a product whose options say "Max Cron Jobs: 0 = disabled" or
 * "Backups Enabled: off" silently provisions cron enabled with 3 jobs and
 * backups on — the module reports success and nobody is told. The only
 * channel where these values stick is the PATCH the module already makes for
 * the advanced columns; these fields have to ride along on it.
 */
final class ManagedPlanZeroPersistenceTest extends TestCase
{
    /** Product: cron disabled (0), backups off, FTP/MySQL/SSL switched off via overrides. */
    private function params(): array
    {
        return [
            'packageid'      => 41,
            'packagename'    => 'Zero Persistence',
            'configoption1'  => PANELICA_MANAGED_PLAN,
            'configoption21' => '0',   // Max Cron Jobs, "0 = disabled"
            'configoption20' => 'off', // Backups Enabled
            'configoption24' => "ftp_access_enabled=0\nmysql_access_enabled=0\nssl_enabled=0",
        ];
    }

    /** Queue the four answers a clean ensureManagedPlan run asks for. */
    private function primeCleanRun(FakePanelicaAPI $api, array $params): string
    {
        $spec = panelica_managedPlanSpec($params);
        $slug = 'whmcs-p' . $params['packageid'] . '-' . $spec['hash'];

        // findPlanBySlug: nothing yet.
        $api->queueJson(['status' => 'success', 'data' => []]);
        // createPlan: echoes what the real panel would KEEP, not what was sent.
        $api->queueJson(['status' => 'success', 'data' => [
            'id' => 'plan-fresh', 'slug' => $slug,
            'max_cron_jobs' => 3, 'cron_jobs_enabled' => true, 'backup_enabled' => true,
            'ftp_access_enabled' => true, 'mysql_access_enabled' => true, 'ssl_enabled' => true,
        ]], 201);
        // updatePlan: accepted.
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'plan-fresh']]);
        // verification findPlanBySlug: the three columns the module checks are right.
        $api->queueJson(['status' => 'success', 'data' => [[
            'id' => 'plan-fresh', 'slug' => $slug,
            'cpu_limit_percent' => $spec['advanced']['cpu_limit_percent'],
            'memory_limit_mb'   => $spec['advanced']['memory_limit_mb'],
            'process_limit'     => $spec['advanced']['process_limit'],
        ]]]);

        return $slug;
    }

    /** Every column the PATCH channel asked to set, across all PATCH requests. */
    private function patchedColumns(FakePanelicaAPI $api): array
    {
        $columns = [];
        foreach ($api->sent as $request) {
            if ($request['method'] === 'PATCH') {
                $body = json_decode($request['body'], true);
                if (is_array($body)) {
                    $columns = array_merge($columns, $body);
                }
            }
        }
        return $columns;
    }

    public function testCronDisabledTravelsOnTheChannelWhereItSticks(): void
    {
        $api = new FakePanelicaAPI('panel.example.com', 8443, 'pk_test_key', 'sk_test_secret');
        $params = $this->params();
        $this->primeCleanRun($api, $params);

        panelica_ensureManagedPlan($api, $params);
        $patched = $this->patchedColumns($api);

        $this->assertArrayHasKey('max_cron_jobs', $patched,
            'The panel is measured to turn max_cron_jobs=0 into 3 on create; 0 only survives via PATCH.');
        $this->assertSame(0, $patched['max_cron_jobs']);
        $this->assertArrayHasKey('cron_jobs_enabled', $patched,
            'The panel is measured to turn cron_jobs_enabled=false into true on create; false only survives via PATCH.');
        $this->assertFalse($patched['cron_jobs_enabled']);
    }

    public function testBackupsOffTravelsOnTheChannelWhereItSticks(): void
    {
        $api = new FakePanelicaAPI('panel.example.com', 8443, 'pk_test_key', 'sk_test_secret');
        $params = $this->params();
        $this->primeCleanRun($api, $params);

        panelica_ensureManagedPlan($api, $params);
        $patched = $this->patchedColumns($api);

        $this->assertArrayHasKey('backup_enabled', $patched,
            'The panel is measured to turn backup_enabled=false into true on create; false only survives via PATCH.');
        $this->assertFalse($patched['backup_enabled']);
    }

    public function testSwitchedOffFeatureOverridesTravelOnTheChannelWhereTheyStick(): void
    {
        $api = new FakePanelicaAPI('panel.example.com', 8443, 'pk_test_key', 'sk_test_secret');
        $params = $this->params();
        $this->primeCleanRun($api, $params);

        panelica_ensureManagedPlan($api, $params);
        $patched = $this->patchedColumns($api);

        foreach (['ftp_access_enabled', 'mysql_access_enabled', 'ssl_enabled'] as $flag) {
            $this->assertArrayHasKey($flag, $patched,
                sprintf('The panel is measured to turn %s=false into true on create; false only survives via PATCH.', $flag));
            $this->assertFalse($patched[$flag]);
        }
    }
}
