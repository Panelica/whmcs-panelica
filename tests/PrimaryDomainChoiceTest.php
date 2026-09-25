<?php

use PHPUnit\Framework\TestCase;

/**
 * Which of the account's websites is "the" website.
 *
 * The client area picked whichever row the panel happened to list first. The
 * panel does not promise an order: the handler behind /v1/accounts/:id/domains
 * runs Find(&domains) with no ORDER BY, and PostgreSQL is free to return rows
 * in whatever order the plan produced - an order that changes after an update
 * moves a row, after a vacuum, or when the planner switches scans.
 *
 * For a service with one website nothing is at stake. For a customer who added
 * a second one, every request that reads this is a fresh HTTP call: the
 * dashboard names one website while the SSL button, the DNS tab and the PHP
 * version selector could act on the other, with nothing on screen to say so.
 *
 * The row set carries no "primary" flag - measured against a live panel, the
 * fields are id, domain_name, user_id, ... created_at, updated_at - so the
 * oldest one is the closest thing to a fact: it is the website this module
 * provisioned when the service was ordered.
 */
final class PrimaryDomainChoiceTest extends TestCase
{
    private function response(array $rows): array
    {
        return ['status' => 'success', 'data' => $rows];
    }

    public function testTheOldestWebsiteWinsNoMatterWhichOneIsListedFirst(): void
    {
        $newer = ['id' => 'dom-new', 'domain_name' => 'aaa-added-later.com', 'created_at' => '2026-08-01T10:00:00Z'];
        $older = ['id' => 'dom-old', 'domain_name' => 'zzz-ordered.com', 'created_at' => '2026-01-05T09:00:00Z'];

        $this->assertSame('dom-old', panelica_primaryDomain($this->response([$newer, $older]))['id']);
        $this->assertSame('dom-old', panelica_primaryDomain($this->response([$older, $newer]))['id'], 'the answer must not depend on row order');
    }

    public function testWithoutTimestampsTheChoiceIsStillStable(): void
    {
        $a = ['id' => 'dom-a', 'domain_name' => 'alpha.com'];
        $b = ['id' => 'dom-b', 'domain_name' => 'beta.com'];

        $this->assertSame(
            panelica_primaryDomain($this->response([$a, $b]))['id'],
            panelica_primaryDomain($this->response([$b, $a]))['id']
        );
    }

    public function testASingleWebsiteIsUnaffected(): void
    {
        $only = ['id' => 'dom-1', 'domain_name' => 'only.com', 'created_at' => '2026-03-03T00:00:00Z'];

        $this->assertSame('dom-1', panelica_primaryDomain($this->response([$only]))['id']);
    }

    public function testAnAccountWithNoWebsiteYieldsNothing(): void
    {
        $this->assertSame(array(), panelica_primaryDomain($this->response([])));
        $this->assertSame(array(), panelica_primaryDomain(['status' => 'success']));
    }

    /**
     * The trap in my own change: a row that is not an array, or a payload that
     * is not a list at all, must not take the client area down with it.
     */
    public function testRubbishRowsAreSkippedRatherThanFatal(): void
    {
        $good = ['id' => 'dom-1', 'domain_name' => 'good.com', 'created_at' => '2026-03-03T00:00:00Z'];

        $this->assertSame('dom-1', panelica_primaryDomain($this->response(['nonsense', $good]))['id']);
        $this->assertSame(array(), panelica_primaryDomain(['status' => 'success', 'data' => 'nonsense']));
    }

    public function testAnUnparseableTimestampDoesNotBeatARealOne(): void
    {
        $broken = ['id' => 'dom-broken', 'domain_name' => 'aaa.com', 'created_at' => 'not a date'];
        $real   = ['id' => 'dom-real', 'domain_name' => 'zzz.com', 'created_at' => '2026-01-05T09:00:00Z'];

        $this->assertSame('dom-real', panelica_primaryDomain($this->response([$broken, $real]))['id']);
        $this->assertSame('dom-real', panelica_primaryDomain($this->response([$real, $broken]))['id']);
    }

    /**
     * The sibling door: every self-service action resolves its website through
     * panelica_ctx(), and it was reading element zero too. The screen and the
     * action have to name the same website.
     */
    public function testSelfServiceActionsResolveTheSameWebsiteAsTheScreen(): void
    {
        $newer = ['id' => 'dom-new', 'domain_name' => 'aaa-added-later.com', 'created_at' => '2026-08-01T10:00:00Z'];
        $older = ['id' => 'dom-old', 'domain_name' => 'zzz-ordered.com', 'created_at' => '2026-01-05T09:00:00Z'];

        foreach ([[$newer, $older], [$older, $newer]] as $order) {
            $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
            $api->queueJson(['status' => 'success', 'data' => [
                ['id' => 'acct-1', 'username' => 'wmtuser', 'role' => 'USER'],
            ]]);
            $api->queueJson(['status' => 'success', 'data' => $order]);

            list($account, $domainId) = panelica_ctx($api, ['username' => 'wmtuser']);

            $this->assertSame('acct-1', $account['id']);
            $this->assertSame('dom-old', $domainId);
        }
    }
}
