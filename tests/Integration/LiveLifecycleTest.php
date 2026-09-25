<?php

use PHPUnit\Framework\TestCase;
use WHMCS\Database\Capsule;

/**
 * The module against a real Panelica panel.
 *
 * Everything else in this suite proves the module builds the right request and
 * makes sense of a reply it was handed. This proves the panel agrees: that the
 * signature it computes is the one the backend recomputes, that the endpoints
 * are where the module thinks they are, and that an account really is created,
 * suspended, moved between plans and removed.
 *
 * Skipped unless a panel is named, so `composer test` stays offline:
 *
 *   PANELICA_TEST_HOST=panel.example.com \
 *   PANELICA_TEST_KEY=pk_live_... \
 *   PANELICA_TEST_SECRET=sk_live_... \
 *   vendor/bin/phpunit --testsuite integration
 *
 * Every account it creates carries the prefix below and is removed again,
 * including after a failure - see tearDownAfterClass. Point it at a test
 * panel, never at one with customers on it.
 */
final class LiveLifecycleTest extends TestCase
{
    /** Accounts made by this run, removed at the end whatever happens. */
    private static array $created = [];

    private static string $prefix = 'wmt';

    public static function tearDownAfterClass(): void
    {
        if (self::$created === []) {
            return;
        }

        $api = self::api();

        foreach (self::$created as $username) {
            try {
                $account = $api->findAccountByUsername($username);

                if ($account !== null && !empty($account['id'])) {
                    $api->deleteAccount($account['id']);
                }
            } catch (Exception $e) {
                fwrite(STDERR, "\nleft behind on the panel: {$username} ({$e->getMessage()})\n");
            }
        }

        self::$created = [];
    }

    protected function setUp(): void
    {
        if (getenv('PANELICA_TEST_HOST') === false || getenv('PANELICA_TEST_KEY') === false) {
            $this->markTestSkipped('No panel named; set PANELICA_TEST_HOST / _KEY / _SECRET to run the live suite.');
        }

        Capsule::reset();
        ModuleCallLog::reset();
    }

    private static function api(): PanelicaAPI
    {
        // The suite's own set-up and clean-up wait out the panel's rate limit:
        // a clean-up refused with 429 left accounts behind on the panel. The
        // module's functions under test are left exactly as WHMCS runs them.
        return (new PanelicaAPI(
            (string) getenv('PANELICA_TEST_HOST'),
            (int) (getenv('PANELICA_TEST_PORT') ?: 8443),
            (string) getenv('PANELICA_TEST_KEY'),
            (string) getenv('PANELICA_TEST_SECRET'),
            true
        ))->waitOnRateLimit(65);
    }

    /** WHMCS hands the module an array shaped like this. */
    private function params(array $overrides = []): array
    {
        return array_merge([
            'serverhostname' => (string) getenv('PANELICA_TEST_HOST'),
            'serverip' => (string) getenv('PANELICA_TEST_HOST'),
            'serverport' => (string) (getenv('PANELICA_TEST_PORT') ?: 8443),
            'serverpassword' => (string) getenv('PANELICA_TEST_KEY'),
            'serveraccesshash' => (string) getenv('PANELICA_TEST_SECRET'),
            'serverid' => 1,
            'serviceid' => 1001,
            'domain' => '',
            'configoption1' => $this->firstPlanId(),
            'clientsdetails' => [
                'firstname' => 'Module',
                'lastname' => 'Test',
                'email' => 'module-test@example.test',
            ],
        ], $overrides);
    }

    private function firstPlanId(): string
    {
        static $planId = null;

        if ($planId === null) {
            $named = getenv('PANELICA_TEST_PLAN');

            if ($named !== false && $named !== '') {
                return $planId = (string) $named;
            }

            $plans = self::api()->listPlans();
            $planId = (string) ($plans['data'][0]['id'] ?? '');
        }

        return $planId;
    }

    /** A username nobody else is using, remembered for cleanup. */
    private function freshUsername(): string
    {
        $username = self::$prefix . substr(bin2hex(random_bytes(4)), 0, 5);
        self::$created[] = $username;

        return $username;
    }

    private function accountOf(string $username): ?array
    {
        return self::api()->findAccountByUsername($username);
    }

    public function testTheServerDetailsAreAcceptedByThePanel(): void
    {
        $result = panelica_TestConnection($this->params());

        $this->assertTrue($result['success'], $result['error'] ?? '');
    }

    public function testWrongCredentialsAreReportedRatherThanSilentlyAccepted(): void
    {
        $result = panelica_TestConnection($this->params(['serveraccesshash' => 'sk_live_not_the_secret']));

        $this->assertFalse($result['success']);
        $this->assertNotSame('', (string) ($result['error'] ?? ''));
    }

    public function testThePlanDropdownIsFilledFromThePanel(): void
    {
        $plans = panelica_LoaderPlans($this->params());

        $this->assertNotEmpty($plans, 'the product page would offer no plan to sell');
        $this->assertArrayHasKey($this->firstPlanId(), $plans);
    }

    public function testAnAccountIsCreatedSuspendedResumedAndRemoved(): void
    {
        $username = $this->freshUsername();
        $params = $this->params(['username' => $username, 'password' => 'Str0ng!Pass-2026']);

        $this->assertSame('success', panelica_CreateAccount($params));

        $account = $this->accountOf($username);
        $this->assertNotNull($account, 'the panel has no account by that name');
        $this->assertSame('active', strtolower((string) $account['status']));

        $this->assertSame('success', panelica_SuspendAccount($params));
        $this->assertSame('suspended', strtolower((string) $this->accountOf($username)['status']));

        $this->assertSame('success', panelica_UnsuspendAccount($params));
        $this->assertSame('active', strtolower((string) $this->accountOf($username)['status']));

        $this->assertSame('success', panelica_ChangePassword($this->params([
            'username' => $username,
            'password' => 'An0ther!Pass-2026',
        ])));

        $this->assertSame('success', panelica_TerminateAccount($params));
        $this->assertNull($this->accountOf($username), 'the account outlived its termination');
    }

    public function testAccountUsageComesBackFromThePanel(): void
    {
        $username = $this->freshUsername();
        $params = $this->params(['username' => $username, 'password' => 'Str0ng!Pass-2026']);

        $this->assertSame('success', panelica_CreateAccount($params));

        Capsule::$rows['tblhosting'] = [(object) ['id' => 4242, 'username' => $username]];

        $this->assertSame('success', panelica_usageUpdateWith(self::api(), 1));

        $written = null;

        foreach (Capsule::$updates as $update) {
            if (($update['where']['id'] ?? null) === 4242) {
                $written = $update['values'];
            }
        }

        $this->assertNotNull($written, 'the nightly sync wrote nothing for a live account');
        $this->assertArrayHasKey('disklimit', $written);
        $this->assertGreaterThan(0, (int) $written['disklimit'], 'the plan quota did not come through');
        $this->assertArrayHasKey('lastupdate', $written);

        panelica_TerminateAccount($params);
    }

    public function testMovingAnAccountToAnotherPlanIsAcceptedByThePanel(): void
    {
        // The dropdown leads with the "Managed by WHMCS" sentinel, which is not
        // a plan on the panel: choosing it makes the module build one from the
        // product's own options, and that needs a WHMCS product behind it.
        $plans = panelica_LoaderPlans($this->params());
        $ids = array_values(array_filter(array_keys($plans), fn ($id) => $id !== PANELICA_MANAGED_PLAN));

        if (count($ids) < 2) {
            $this->markTestSkipped('the panel offers a single plan, so there is nothing to move between');
        }

        $username = $this->freshUsername();
        $params = $this->params(['username' => $username, 'password' => 'Str0ng!Pass-2026', 'configoption1' => $ids[0]]);

        $this->assertSame('success', panelica_CreateAccount($params));

        $moved = panelica_ChangePackage($this->params([
            'username' => $username,
            'password' => 'Str0ng!Pass-2026',
            'configoption1' => $ids[1],
        ]));

        $this->assertSame('success', $moved);

        panelica_TerminateAccount($params);
    }

    public function testTerminatingAnAccountThePanelNeverHadIsNotAnError(): void
    {
        $result = panelica_TerminateAccount($this->params([
            'username' => self::$prefix . 'nosuchacct',
            'password' => 'Str0ng!Pass-2026',
        ]));

        $this->assertSame('success', $result, 'WHMCS would be stuck retrying a service that is already gone');
    }

    public function testTheSameUsernameIsNotCreatedTwice(): void
    {
        $username = $this->freshUsername();
        $params = $this->params(['username' => $username, 'password' => 'Str0ng!Pass-2026']);

        $this->assertSame('success', panelica_CreateAccount($params));

        $second = panelica_CreateAccount($params);

        $this->assertNotSame('success', $second);
        $this->assertStringContainsString('already exists', $second);

        panelica_TerminateAccount($params);
    }

    public function testAPasswordThePanelWouldRefuseIsCaughtBeforeTheAccountIsMade(): void
    {
        $username = $this->freshUsername();

        $result = panelica_CreateAccount($this->params(['username' => $username, 'password' => 'short']));

        $this->assertNotSame('success', $result);
        $this->assertNull($this->accountOf($username), 'a half-made account was left on the panel');
    }

    public function testAClientWithNoEmailAddressIsRefusedBeforeTheAccountIsMade(): void
    {
        $username = $this->freshUsername();

        $result = panelica_CreateAccount($this->params([
            'username' => $username,
            'password' => 'Str0ng!Pass-2026',
            'clientsdetails' => ['firstname' => 'No', 'lastname' => 'Mail', 'email' => ''],
        ]));

        $this->assertNotSame('success', $result);
        $this->assertNull($this->accountOf($username));
    }

    public function testTheKeyCarriesEveryScopeTheModuleNeeds(): void
    {
        $me = self::api()->me();
        $granted = $me['data']['scopes'] ?? [];

        $this->assertSame([], panelica_missingScopes($granted), 'the panel key is missing scopes the module uses');
    }

    /**
     * The guard added to the form-post deletes asks the panel for the account's
     * own items before it lets a delete through. If any of those listings does
     * not answer on a real panel, every delete in the client area breaks - so
     * this walks the whole set against the live server.
     */
    public function testTheOwnershipGuardCanAskThePanelAboutEveryTab(): void
    {
        $username = $this->freshUsername();
        $params = $this->params(['username' => $username, 'password' => 'Str0ng!Pass-2026']);

        $this->assertSame('success', panelica_CreateAccount($params));

        $api = self::api();
        $account = $this->accountOf($username);
        $domains = $api->listAccountDomains($account['id']);
        $domainId = (string) ($domains['data'][0]['id'] ?? '');

        foreach (array_keys(panelica_deletableTabs()) as $tab) {
            if ($tab === 'backups') {
                continue; // backups have their own guard, exercised below
            }

            $owned = panelica_ownedIds($api, $tab, $account['id'], $domainId);

            $this->assertIsArray($owned, "the panel would not answer for the {$tab} tab");
            $this->assertNotContains('', $owned);
        }

        panelica_TerminateAccount($params);
    }

    public function testAnItemThisAccountDoesNotOwnIsRefusedAgainstTheRealPanel(): void
    {
        $username = $this->freshUsername();
        $params = $this->params(['username' => $username, 'password' => 'Str0ng!Pass-2026']);

        $this->assertSame('success', panelica_CreateAccount($params));

        try {
            panelica_deleteOwnedItem(self::api(), $params, 'cron', '00000000-0000-4000-8000-000000000000');
            $this->fail('an id this account does not own was passed to the panel');
        } catch (Exception $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        }

        panelica_TerminateAccount($params);
    }

    public function testABackupThisAccountDoesNotOwnIsRefusedAgainstTheRealPanel(): void
    {
        $username = $this->freshUsername();
        $params = $this->params(['username' => $username, 'password' => 'Str0ng!Pass-2026']);

        $this->assertSame('success', panelica_CreateAccount($params));

        try {
            panelica_requireOwnedBackup(self::api(), $params, 'someone-elses-backup.tar.gz');
            $this->fail('a backup this account does not own was accepted');
        } catch (Exception $e) {
            $this->assertStringContainsString('not found', strtolower($e->getMessage()));
        }

        panelica_TerminateAccount($params);
    }

    /**
     * Managed mode, which builds the plan on the panel from the product's own
     * options. It is the module's biggest moving part and had no coverage at
     * all: it creates a plan, sets the cgroup limits in a second call, and then
     * verifies they landed - a verification that fails the whole provisioning
     * if the panel does not report them back.
     */
    public function testAManagedPlanIsBuiltOnThePanelAndReusedNotDuplicated(): void
    {
        $params = $this->params([
            'packageid' => 990001,
            'packagename' => 'Module Test Managed',
            'configoption1' => PANELICA_MANAGED_PLAN,
            'configoption2' => '2048',
            'configoption3' => '20480',
            'configoption4' => '25',
            'configoption5' => '512',
            'configoption6' => '40',
        ]);

        $api = self::api();
        $planId = panelica_ensureManagedPlan($api, $params);

        try {
            $this->assertNotSame('', $planId);

            // Asked again for the same product and options: the same plan, not
            // a second copy of it.
            $this->assertSame($planId, panelica_ensureManagedPlan($api, $params));

            $spec = panelica_managedPlanSpec($params);
            $plan = $api->findPlanBySlug('whmcs-p990001-' . $spec['hash']);

            $this->assertNotNull($plan);
            $this->assertSame(25, (int) $plan['cpu_limit_percent'], 'the kernel CPU limit did not land');
            $this->assertSame(512, (int) $plan['memory_limit_mb'], 'the memory limit did not land');
            $this->assertSame(40, (int) $plan['process_limit'], 'the process limit did not land');
        } finally {
            try {
                $api->deletePlan($planId);
            } catch (Exception $e) {
                fwrite(STDERR, "\nleft behind on the panel: plan {$planId}\n");
            }
        }
    }

    public function testThePanelRefusesToRemoveAPlanAnAccountIsStillOn(): void
    {
        $params = $this->params([
            'packageid' => 990002,
            'packagename' => 'Module Test Managed In Use',
            'configoption1' => PANELICA_MANAGED_PLAN,
            'configoption2' => '1024',
        ]);

        $api = self::api();
        $planId = panelica_ensureManagedPlan($api, $params);
        $username = $this->freshUsername();

        try {
            $this->assertSame('success', panelica_CreateAccount($this->params([
                'username' => $username,
                'password' => 'Str0ng!Pass-2026',
                'configoption1' => $planId,
            ])));

            // The garbage collector leans on this: it asks the panel to remove
            // superseded versions and treats a refusal as "still in use".
            try {
                $api->deletePlan($planId);
                $this->fail('the panel removed a plan an account was still on');
            } catch (Exception $e) {
                $this->addToAssertionCount(1);
            }

            $this->assertNotNull($this->accountOf($username), 'the account went with the plan');
        } finally {
            panelica_TerminateAccount($this->params(['username' => $username]));

            try {
                $api->deletePlan($planId);
            } catch (Exception $e) {
                fwrite(STDERR, "\nleft behind on the panel: plan {$planId}\n");
            }
        }
    }
}
