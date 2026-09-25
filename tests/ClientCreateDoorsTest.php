<?php

use PHPUnit\Framework\TestCase;

/**
 * Behaviour coverage for the client-area CREATE doors.
 *
 * Every door reachable through clientarea.php?modop=custom&a=CreateX resolves
 * the account context first (accounts list + account domains), reads its input
 * from $_POST, and either sends exactly one create call to the panel and sets a
 * success flash, or refuses with a danger flash before anything is created.
 *
 * These tests exercise the doors themselves (panelica_CreateEmail and friends),
 * with the transport injected through panelica_test_useApi(), asserting both
 * the flash the customer sees and the request the panel actually received.
 */
final class ClientCreateDoorsTest extends TestCase
{
    private const ACCOUNT = 'a1';
    private const DOMAIN = 'd1';

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        unset($_SESSION['panelica_flash']);
    }

    protected function tearDown(): void
    {
        panelica_test_clearApi();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        unset($_SESSION['panelica_flash']);
    }

    /** A panel with the account, its one domain, then whatever else is queued. */
    private function panel(?array $domains = null): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => self::ACCOUNT, 'username' => 'mine', 'role' => 'USER', 'status' => 'active'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => $domains ?? [
            ['id' => self::DOMAIN, 'domain_name' => 'mine.test', 'created_at' => '2026-01-01T00:00:00Z'],
        ]]);

        return $api;
    }

    private function params(): array
    {
        return ['username' => 'mine'];
    }

    /** "METHOD /path" for every request the fake saw. */
    private function calledPaths(FakePanelicaAPI $api): array
    {
        return array_map(fn ($s) => $s['method'] . ' ' . parse_url($s['url'], PHP_URL_PATH), $api->sent);
    }

    /** The decoded body of the last request. */
    private function lastBody(FakePanelicaAPI $api): array
    {
        $last = end($api->sent);
        $this->assertNotFalse($last, 'no request reached the panel');

        return $last['body'] !== '' && $last['body'] !== null ? (array) json_decode($last['body'], true) : [];
    }

    private function assertFlash(string $type, string $msg): void
    {
        $flash = panelica_takeFlash();
        $this->assertIsArray($flash, 'no flash was set');
        $this->assertSame($type, $flash['type']);
        $this->assertSame($msg, $flash['msg']);
    }

    /** No POST/PUT/DELETE reached the panel — only the context lookups did. */
    private function assertNothingCreated(FakePanelicaAPI $api): void
    {
        foreach ($api->sent as $s) {
            $this->assertSame('GET', $s['method'], 'a write reached the panel: ' . $s['method'] . ' ' . $s['url']);
        }
    }

    /* ---------------- Email ---------------- */

    public function testCreateEmailHappyPathPostsToTheAccountsPrimaryDomain(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'e1']]);
        $_POST = ['email_user' => 'info', 'email_pass' => 'secret123', 'email_quota' => '0'];
        panelica_test_useApi($api);

        panelica_CreateEmail($this->params());

        $this->assertFlash('success', 'Email account created.');
        $this->assertContains('POST /api/external/v1/email-accounts', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame(self::DOMAIN, $body['domain_id']);
        $this->assertSame('info', $body['username']);
        $this->assertSame('secret123', $body['password']);
        $this->assertArrayNotHasKey('quota_mb', $body, 'a zero quota must be omitted, not sent');
    }

    public function testCreateEmailRefusesAnEmptyMailboxName(): void
    {
        $api = $this->panel();
        $_POST = ['email_user' => '', 'email_pass' => 'secret123'];
        panelica_test_useApi($api);

        panelica_CreateEmail($this->params());

        $this->assertFlash('danger', 'Please enter a mailbox name.');
        $this->assertNothingCreated($api);
    }

    public function testCreateEmailRefusesAShortPassword(): void
    {
        $api = $this->panel();
        $_POST = ['email_user' => 'info', 'email_pass' => 'short'];
        panelica_test_useApi($api);

        panelica_CreateEmail($this->params());

        $this->assertFlash('danger', 'Password must be at least 8 characters.');
        $this->assertNothingCreated($api);
    }

    public function testCreateEmailRefusesWhenTheAccountHasNoWebsiteYet(): void
    {
        $api = $this->panel([]);
        $_POST = ['email_user' => 'info', 'email_pass' => 'secret123'];
        panelica_test_useApi($api);

        panelica_CreateEmail($this->params());

        $this->assertFlash('danger', 'No website found for this account yet.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- FTP ---------------- */

    public function testCreateFtpHappyPathCarriesTheAccountAndDomain(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'f1']]);
        $_POST = ['ftp_user' => 'deploy', 'ftp_pass' => 'secret123', 'ftp_dir' => '/public_html'];
        panelica_test_useApi($api);

        panelica_CreateFtp($this->params());

        $this->assertFlash('success', 'FTP account created.');
        $this->assertContains('POST /api/external/v1/ftp-accounts', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame(self::ACCOUNT, $body['user_id']);
        $this->assertSame(self::DOMAIN, $body['domain_id']);
        $this->assertSame('deploy', $body['ftp_username']);
        $this->assertSame('/public_html', $body['directory']);
    }

    public function testCreateFtpRefusesAnEmptyUsername(): void
    {
        $api = $this->panel();
        $_POST = ['ftp_user' => '', 'ftp_pass' => 'secret123'];
        panelica_test_useApi($api);

        panelica_CreateFtp($this->params());

        $this->assertFlash('danger', 'Please enter an FTP username.');
        $this->assertNothingCreated($api);
    }

    public function testCreateFtpRefusesAShortPassword(): void
    {
        $api = $this->panel();
        $_POST = ['ftp_user' => 'deploy', 'ftp_pass' => '1234567'];
        panelica_test_useApi($api);

        panelica_CreateFtp($this->params());

        $this->assertFlash('danger', 'Password must be at least 8 characters.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- Subdomain ---------------- */

    public function testCreateSubdomainHappyPath(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 's1']]);
        $_POST = ['sub_name' => 'blog'];
        panelica_test_useApi($api);

        panelica_CreateSubdomain($this->params());

        $this->assertFlash('success', 'Subdomain created.');
        $this->assertContains('POST /api/external/v1/domains/' . self::DOMAIN . '/subdomains', $this->calledPaths($api));
        $this->assertSame('blog', $this->lastBody($api)['name']);
    }

    public function testCreateSubdomainRefusesAnEmptyName(): void
    {
        $api = $this->panel();
        $_POST = ['sub_name' => '   '];
        panelica_test_useApi($api);

        panelica_CreateSubdomain($this->params());

        $this->assertFlash('danger', 'Please enter a subdomain name.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- DNS ---------------- */

    public function testCreateDnsRecordUppercasesTheTypeAndDefaultsTheTtl(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'r1']]);
        $_POST = ['dns_type' => 'txt', 'dns_name' => '_dmarc', 'dns_content' => 'v=DMARC1; p=none', 'dns_ttl' => '0'];
        panelica_test_useApi($api);

        panelica_CreateDnsRecord($this->params());

        $this->assertFlash('success', 'DNS record created.');
        $this->assertContains('POST /api/external/v1/dns/zones/' . self::DOMAIN . '/records', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame('TXT', $body['type']);
        $this->assertSame('_dmarc', $body['name']);
        $this->assertSame('v=DMARC1; p=none', $body['content']);
        $this->assertSame(3600, $body['ttl'], 'a non-positive TTL falls back to 3600');
    }

    public function testCreateDnsRecordRefusesWhenAFieldIsMissing(): void
    {
        $api = $this->panel();
        $_POST = ['dns_type' => 'A', 'dns_name' => 'www', 'dns_content' => ''];
        panelica_test_useApi($api);

        panelica_CreateDnsRecord($this->params());

        $this->assertFlash('danger', 'Record type, name and content are all required.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- Cron ---------------- */

    public function testCreateCronDefaultsEveryScheduleFieldToStar(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'c1']]);
        $_POST = ['cron_command' => 'php /home/mine/task.php'];
        panelica_test_useApi($api);

        panelica_CreateCron($this->params());

        $this->assertFlash('success', 'Cron job created.');
        $this->assertContains('POST /api/external/v1/cron-jobs', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame(self::DOMAIN, $body['domain_id']);
        $this->assertSame('cron', $body['task_name']);
        $this->assertSame('php /home/mine/task.php', $body['command']);
        foreach (['minute', 'hour', 'day_of_month', 'month', 'day_of_week'] as $field) {
            $this->assertSame('*', $body[$field], $field . ' did not default to *');
        }
        $this->assertTrue($body['enabled']);
    }

    public function testCreateCronRefusesAnEmptyCommand(): void
    {
        $api = $this->panel();
        $_POST = ['cron_command' => ''];
        panelica_test_useApi($api);

        panelica_CreateCron($this->params());

        $this->assertFlash('danger', 'The command to run is required.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- Forwarder ---------------- */

    public function testCreateForwarderHappyPath(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'fw1']]);
        $_POST = ['fwd_source' => 'sales', 'fwd_dest' => 'team@elsewhere.test'];
        panelica_test_useApi($api);

        panelica_CreateForwarder($this->params());

        $this->assertFlash('success', 'Forwarder created.');
        $this->assertContains('POST /api/external/v1/domains/' . self::DOMAIN . '/email-forwarders', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame('sales', $body['source']);
        $this->assertSame('team@elsewhere.test', $body['destination']);
    }

    public function testCreateForwarderRefusesWhenEitherSideIsMissing(): void
    {
        $api = $this->panel();
        $_POST = ['fwd_source' => 'sales', 'fwd_dest' => ''];
        panelica_test_useApi($api);

        panelica_CreateForwarder($this->params());

        $this->assertFlash('danger', 'Both source and destination are required.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- Autoresponder (door itself; the scope helper has its own test) ---------------- */

    public function testCreateAutoresponderHappyPathThroughTheDoor(): void
    {
        // Door: ctx (accounts, domains), then requireOwnedMailbox runs its own
        // ctx (accounts, domains) plus the account's mailbox listing, then create.
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => self::ACCOUNT, 'username' => 'mine', 'role' => 'USER', 'status' => 'active'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => self::DOMAIN, 'domain_name' => 'mine.test', 'created_at' => '2026-01-01T00:00:00Z'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => [['id' => 'mailbox-mine']]]);
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'ar1']]);
        $_POST = ['ar_email_id' => 'mailbox-mine', 'ar_subject' => 'Away', 'ar_message' => 'Back Monday.'];
        panelica_test_useApi($api);

        panelica_CreateAutoresponder($this->params());

        $this->assertFlash('success', 'Autoresponder created.');
        $this->assertContains('POST /api/external/v1/domains/' . self::DOMAIN . '/email-autoresponders', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame('mailbox-mine', $body['email_account_id']);
        $this->assertSame('Away', $body['subject']);
        $this->assertSame('Back Monday.', $body['message']);
    }

    public function testCreateAutoresponderRefusesWhenAFieldIsMissing(): void
    {
        $api = $this->panel();
        $_POST = ['ar_email_id' => 'mailbox-mine', 'ar_subject' => '', 'ar_message' => 'Back Monday.'];
        panelica_test_useApi($api);

        panelica_CreateAutoresponder($this->params());

        $this->assertFlash('danger', 'Email account, subject and message are required.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- MySQL user ---------------- */

    public function testCreateMysqlUserHappyPath(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'u1']]);
        $_POST = ['db_user' => 'shop', 'db_pass' => 'secret123'];
        panelica_test_useApi($api);

        panelica_CreateMysqlUser($this->params());

        $this->assertFlash('success', 'Database user created.');
        $this->assertContains('POST /api/external/v1/mysql-users', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame(self::DOMAIN, $body['domain_id']);
        $this->assertSame('shop', $body['username']);
        $this->assertSame('secret123', $body['password']);
    }

    public function testCreateMysqlUserRefusesAPasswordShorterThanEightCharacters(): void
    {
        $api = $this->panel();
        $_POST = ['db_user' => 'shop', 'db_pass' => '1234567'];
        panelica_test_useApi($api);

        panelica_CreateMysqlUser($this->params());

        $this->assertFlash('danger', 'Username and a password of at least 8 characters are required.');
        $this->assertNothingCreated($api);
    }

    public function testCreateMysqlUserRefusesAnEmptyUsername(): void
    {
        $api = $this->panel();
        $_POST = ['db_user' => '', 'db_pass' => 'secret123'];
        panelica_test_useApi($api);

        panelica_CreateMysqlUser($this->params());

        $this->assertFlash('danger', 'Username and a password of at least 8 characters are required.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- Redirect ---------------- */

    public function testCreateRedirectDefaultsTo301(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success', 'data' => ['id' => 'rd1']]);
        $_POST = ['rdr_source' => '/old', 'rdr_dest' => 'https://mine.test/new'];
        panelica_test_useApi($api);

        panelica_CreateRedirect($this->params());

        $this->assertFlash('success', 'Redirect created.');
        $this->assertContains('POST /api/external/v1/domains/' . self::DOMAIN . '/redirects', $this->calledPaths($api));
        $body = $this->lastBody($api);
        $this->assertSame('/old', $body['source_path']);
        $this->assertSame('https://mine.test/new', $body['destination_url']);
        $this->assertSame('301', $body['redirect_type']);
    }

    public function testCreateRedirectRefusesWhenSourceOrDestinationIsMissing(): void
    {
        $api = $this->panel();
        $_POST = ['rdr_source' => '', 'rdr_dest' => 'https://mine.test/new'];
        panelica_test_useApi($api);

        panelica_CreateRedirect($this->params());

        $this->assertFlash('danger', 'Source path and destination URL are required.');
        $this->assertNothingCreated($api);
    }

    /* ---------------- SSL ---------------- */

    public function testIssueSslHappyPathTargetsThePrimaryDomain(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);

        panelica_IssueSsl($this->params());

        $this->assertFlash('success', 'SSL certificate requested — issuance runs in the background and may take a minute.');
        $this->assertContains('POST /api/external/v1/ssl/domains/' . self::DOMAIN . '/issue', $this->calledPaths($api));
    }

    public function testIssueSslRefusesWhenTheAccountHasNoWebsiteYet(): void
    {
        $api = $this->panel([]);
        panelica_test_useApi($api);

        panelica_IssueSsl($this->params());

        $this->assertFlash('danger', 'No website found for this account yet.');
        $this->assertNothingCreated($api);
    }
}
