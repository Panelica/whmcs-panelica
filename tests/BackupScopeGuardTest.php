<?php

use PHPUnit\Framework\TestCase;

/**
 * The form-post door onto "create backup".
 *
 * The client area has two doors onto backup creation. The AJAX endpoint
 * (panelica_Api, op=create, tab=backups) refuses to hand the panel an empty
 * domain list and says why in its own comment: "Passing an empty domain list
 * makes the panel create a server-wide backup (all accounts) — a client must
 * never be able to do that." It first collects THIS account's domain ids and
 * backs those up only.
 *
 * The form-post handler panelica_CreateBackup — reachable by any signed-in
 * customer at clientarea.php?action=productdetails&modop=custom&a=CreateBackup,
 * because it is whitelisted in panelica_ClientAreaAllowedFunctions() — calls
 * $api->createBackup(array(), $name) with an EMPTY domain list, which is the
 * exact call the AJAX door forbids.
 *
 * Measured against the live test panel (2026-08-11): createBackup(array())
 * returned a server-wide archive with domain_count=4 spanning
 * ["qwe.com","exp.com","enterprise.vip-diomand.com","asd.com"] — four other
 * customers' domains. A customer owning none of those could trigger a full
 * backup of every other tenant's data (resource abuse + cross-tenant archive)
 * simply by POSTing to that door.
 */
final class BackupScopeGuardTest extends TestCase
{
    private function functionBody(string $fn): string
    {
        $src = file_get_contents(__DIR__ . '/../modules/servers/panelica/panelica.php');

        if (!preg_match('/function ' . preg_quote($fn, '/') . '\(array \$params\)\s*\{(.*?)\n\}/s', $src, $m)) {
            $this->fail("could not locate {$fn} in panelica.php");
        }

        return $m[1];
    }

    public function testCreateBackupIsReachableByClients(): void
    {
        // Establishes the door is client-invocable, so the scoping below matters.
        $this->assertContains('CreateBackup', panelica_ClientAreaAllowedFunctions());
    }

    public function testFormPostCreateBackupNeverAsksForAServerWideBackup(): void
    {
        $body = $this->functionBody('panelica_CreateBackup');

        // An empty domain list == server-wide backup (measured: 4 other tenants'
        // domains). The form-post door must scope to this account's own domains,
        // exactly as the AJAX door already does.
        $this->assertDoesNotMatchRegularExpression(
            '/createBackup\(\s*array\(\s*\)/',
            $body,
            'panelica_CreateBackup hands the panel an empty domain list -> server-wide, cross-tenant backup'
        );
    }

    public function testFormPostCreateBackupScopesToTheAccountsDomains(): void
    {
        $body = $this->functionBody('panelica_CreateBackup');

        // The secure shape mirrors the AJAX branch: enumerate the account's own
        // domain ids from listAccountDomains and pass only those to createBackup.
        $mentionsDomains = str_contains($body, 'listAccountDomains')
            || str_contains($body, 'panelica_ctx')
            || str_contains($body, 'domain_ids');

        $this->assertTrue(
            $mentionsDomains,
            'panelica_CreateBackup does not scope the backup to this account\'s domains'
        );
    }
}
