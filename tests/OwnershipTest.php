<?php

use PHPUnit\Framework\TestCase;

/**
 * Who is allowed to delete what in the client area.
 *
 * The module signs its requests with a server-wide key, so the panel's own
 * role checks do not narrow a delete down to one account: whatever id the
 * client area submits, the panel will act on it. panelica_ownedIds is
 * therefore the only thing standing between a customer and another
 * customer's cron job, mailbox or database user, and it has to answer the
 * question the same way every time.
 *
 * The backups helper in the same file already states the rule it follows -
 * a resource of unknown scope is NOT owned - and the FTP branch follows it
 * too. Cron and MySQL did the opposite: an item carrying neither owner nor
 * domain was treated as belonging to whoever happened to be asking.
 */
final class OwnershipTest extends TestCase
{
    private function api(array $items): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk_test', 'sk_test');
        $api->queueJson(['status' => 'success', 'data' => $items]);

        return $api;
    }

    public function testCronJobWithNoOwnerAndNoDomainIsNotOwned(): void
    {
        $api = $this->api([['id' => 'cron-of-somebody-else']]);

        $owned = panelica_ownedIds($api, 'cron', 'account-1', 'domain-1');

        $this->assertSame([], $owned);
    }

    public function testDatabaseUserWithNoOwnerAndNoDomainIsNotOwned(): void
    {
        $api = $this->api([['id' => 'dbuser-of-somebody-else']]);

        $owned = panelica_ownedIds($api, 'mysql', 'account-1', 'domain-1');

        $this->assertSame([], $owned);
    }

    public function testCronJobOfThisAccountIsOwned(): void
    {
        $api = $this->api([
            ['id' => 'mine', 'user_id' => 'account-1', 'domain_id' => 'domain-1'],
            ['id' => 'theirs', 'user_id' => 'account-2', 'domain_id' => 'domain-2'],
        ]);

        $this->assertSame(['mine'], panelica_ownedIds($api, 'cron', 'account-1', 'domain-1'));
    }

    public function testCronJobOnThisDomainIsOwnedEvenWithoutAnOwnerField(): void
    {
        $api = $this->api([['id' => 'mine', 'domain_id' => 'domain-1']]);

        $this->assertSame(['mine'], panelica_ownedIds($api, 'cron', 'account-1', 'domain-1'));
    }

    public function testDatabaseUserOnThisDomainIsOwned(): void
    {
        $api = $this->api([
            ['id' => 'mine', 'domain_id' => 'domain-1'],
            ['id' => 'theirs', 'domain_id' => 'domain-9'],
        ]);

        $this->assertSame(['mine'], panelica_ownedIds($api, 'mysql', 'account-1', 'domain-1'));
    }

    public function testFtpAccountOfAnotherAccountIsNotOwned(): void
    {
        $api = $this->api([
            ['id' => 'mine', 'user_id' => 'account-1'],
            ['id' => 'theirs', 'user_id' => 'account-2'],
            ['id' => 'unscoped'],
        ]);

        $this->assertSame(['mine'], panelica_ownedIds($api, 'ftp', 'account-1', 'domain-1'));
    }

    public function testBackupOfUnknownScopeIsNotOwned(): void
    {
        $this->assertFalse(panelica_backupOwnedByAccount([], ['mine.test']));
        $this->assertFalse(panelica_backupOwnedByAccount(['domain_names' => []], ['mine.test']));
    }

    public function testBackupIsOwnedOnlyWhenEveryDomainInItIsOurs(): void
    {
        $this->assertTrue(panelica_backupOwnedByAccount(['domain_names' => ['Mine.test']], ['mine.test']));
        $this->assertFalse(panelica_backupOwnedByAccount(['domain_names' => ['mine.test', 'theirs.test']], ['mine.test']));
    }
}
