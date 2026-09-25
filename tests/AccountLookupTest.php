<?php

use PHPUnit\Framework\TestCase;

/**
 * Which user the module takes for "the hosting account".
 *
 * The panel's account listing is a listing of users, and the panel's own
 * administrators are in it - root included, checked against a live server. The
 * lookup matched on username alone, so a WHMCS service whose username happened
 * to equal an administrator's name pointed the module straight at that
 * administrator: suspend would suspend them, change password would change
 * theirs, and terminate would delete them.
 *
 * Only a hosting account can be one. A user the panel describes as anything
 * else is not this module's to manage, and a listing that carries no role at
 * all - an older panel - is taken at face value, so nothing that works today
 * stops working.
 */
final class AccountLookupTest extends TestCase
{
    private function panelHolding(array $users): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => $users]);

        return $api;
    }

    public function testThePanelsOwnRootUserIsNotAHostingAccount(): void
    {
        $api = $this->panelHolding([
            ['id' => 'u-root', 'username' => 'root', 'role' => 'ROOT'],
            ['id' => 'u-bob', 'username' => 'bob', 'role' => 'USER'],
        ]);

        $this->assertNull($api->findAccountByUsername('root'));
    }

    public function testAnAdministratorIsNotAHostingAccount(): void
    {
        $api = $this->panelHolding([['id' => 'u-a', 'username' => 'ops', 'role' => 'ADMIN']]);

        $this->assertNull($api->findAccountByUsername('ops'));
    }

    public function testAResellerIsNotAHostingAccount(): void
    {
        $api = $this->panelHolding([['id' => 'u-r', 'username' => 'partner', 'role' => 'RESELLER']]);

        $this->assertNull($api->findAccountByUsername('partner'));
    }

    public function testAHostingAccountIsStillFound(): void
    {
        $api = $this->panelHolding([
            ['id' => 'u-root', 'username' => 'root', 'role' => 'ROOT'],
            ['id' => 'u-bob', 'username' => 'bob', 'role' => 'USER'],
        ]);

        $found = $api->findAccountByUsername('bob');

        $this->assertNotNull($found);
        $this->assertSame('u-bob', $found['id']);
    }

    public function testTheNameIsStillMatchedWhateverTheCase(): void
    {
        $api = $this->panelHolding([['id' => 'u-bob', 'username' => 'Bob', 'role' => 'user']]);

        $this->assertNotNull($api->findAccountByUsername('  bob '));
    }

    public function testAPanelThatSendsNoRoleIsTakenAtFaceValue(): void
    {
        $api = $this->panelHolding([['id' => 'u-old', 'username' => 'bob']]);

        $this->assertNotNull($api->findAccountByUsername('bob'), 'an older panel would stop working');
    }

    public function testNothingMatchesWhenTheNameIsNotThere(): void
    {
        $api = $this->panelHolding([['id' => 'u-bob', 'username' => 'bob', 'role' => 'USER']]);

        $this->assertNull($api->findAccountByUsername('someone-else'));
    }
}
