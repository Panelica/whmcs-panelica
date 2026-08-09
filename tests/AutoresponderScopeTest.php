<?php

use PHPUnit\Framework\TestCase;

/**
 * Whose mailbox an autoresponder is attached to.
 *
 * Both client-area doors take the domain from the account's own context, which
 * is right, and then take the mailbox id straight from the request. The panel
 * checks the caller may reach the domain in the URL but does not check that the
 * mailbox belongs to it, and the module signs as an administrator, so that
 * check passes for any domain.
 *
 * The result: a customer could attach an autoresponder to somebody else's
 * mailbox, and that person's address would start replying to its senders with
 * text the attacker wrote.
 *
 * The mailbox now has to be one of this account's, checked the same way every
 * other id in the client area is checked.
 */
final class AutoresponderScopeTest extends TestCase
{
    private const ACCOUNT = 'account-mine';
    private const DOMAIN = 'domain-mine';

    private function panel(array $mailboxes): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::ACCOUNT, 'username' => 'mine', 'status' => 'active']]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => self::DOMAIN, 'domain_name' => 'mine.test']]]);
        $api->queueJson(['status' => 'success', 'data' => $mailboxes]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'ar-1']]);

        return $api;
    }

    private function params(): array
    {
        return [
            'serverhostname' => 'panel.test',
            'serverport' => 8443,
            'serverpassword' => 'pk',
            'serveraccesshash' => 'sk',
            'username' => 'mine',
        ];
    }

    private function createdPaths(FakePanelicaAPI $api): array
    {
        return array_map(fn ($s) => $s['method'] . ' ' . parse_url($s['url'], PHP_URL_PATH), $api->sent);
    }

    public function testAMailboxFromAnotherAccountIsRefused(): void
    {
        $api = $this->panel([['id' => 'mailbox-mine']]);

        $this->expectException(Exception::class);

        try {
            panelica_requireOwnedMailbox($api, $this->params(), 'mailbox-of-someone-else');
        } finally {
            foreach ($this->createdPaths($api) as $path) {
                $this->assertStringNotContainsString('email-autoresponders', $path, 'the autoresponder was created anyway');
            }
        }
    }

    public function testTheAccountsOwnMailboxIsAccepted(): void
    {
        $api = $this->panel([['id' => 'mailbox-mine']]);

        $this->assertSame('mailbox-mine', panelica_requireOwnedMailbox($api, $this->params(), 'mailbox-mine'));
    }

    public function testAnEmptyMailboxIdIsRefused(): void
    {
        $api = $this->panel([['id' => 'mailbox-mine']]);

        $this->expectException(Exception::class);

        panelica_requireOwnedMailbox($api, $this->params(), '');
    }

    public function testBothAutoresponderDoorsCheckTheMailbox(): void
    {
        $source = file_get_contents(__DIR__ . '/../modules/servers/panelica/panelica.php');

        preg_match('/function panelica_CreateAutoresponder\(array \$params\)\s*\{(.*?)\n\}/s', $source, $form);
        preg_match("/if \(\\\$tab === 'autoresponders'\) \{([^}]*)\}/", $source, $ajax);

        $this->assertNotEmpty($form, 'the form-post door is gone');
        $this->assertStringContainsString('panelica_requireOwnedMailbox', $form[1]);

        $this->assertNotEmpty($ajax, 'the AJAX create branch is gone');
        $this->assertStringContainsString('panelica_requireOwnedMailbox', $ajax[1]);
    }
}
