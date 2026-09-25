<?php

use PHPUnit\Framework\TestCase;

/**
 * The SSL and PHP-version tabs must honour the {ok:false} failure shape the
 * client area was hardened to speak — the same one every other loader obeys.
 *
 * pnlGet() was changed so a reply that is not JSON (an expired session that
 * became a login page, a fatal, a gateway 502) resolves to {ok:false,error:...}
 * instead of rejecting, and the contract the hardening states is that "every
 * else branch does its job, the message says what happened". The list loader
 * (pnlLoad) obeys it — `if(!d.ok){...show error...}` — and so does the
 * deliverability loader (pnlDelivRender: `if(!d||!d.ok||!d.deliverability)`).
 *
 * pnlLoadSsl and pnlLoadSettings do NOT. pnlLoadSsl never inspects d.ok; it
 * reads d.ssl, and on the failure sentinel that key is absent, so `has` becomes
 * false and it renders a definitive "No certificate active" plus a "Get free
 * Let's Encrypt certificate" button. A customer whose certificate is live sees,
 * during a transient panel hiccup, that they have none and is invited to issue
 * one — the exact class of "the reply was an error but the tab pretended it was
 * data" that the failure-path work set out to remove. pnlLoadSettings has the
 * milder version of the same fault: it swallows the failure and shows an empty
 * PHP-version dropdown with no word of what went wrong.
 *
 * Both loaders should guard on the ok flag before rendering a state, the way
 * pnlLoad and pnlDelivRender already do. Until they do, this fails.
 */
final class ClientAreaSslStatusFailurePathTest extends TestCase
{
    private function template(): string
    {
        return file_get_contents(__DIR__ . '/../modules/servers/panelica/templates/overview.tpl');
    }

    /** Body of a top-level JS function, from its opening to the next `function `. */
    private function functionBody(string $signature): string
    {
        $tpl = $this->template();
        $start = strpos($tpl, $signature);
        $this->assertNotFalse($start, $signature . ' is gone');
        $end = strpos($tpl, "\nfunction ", $start + strlen($signature));
        if ($end === false) {
            $end = strlen($tpl);
        }
        return substr($tpl, $start, $end - $start);
    }

    public function testTheListLoaderAlreadyHonoursTheFailureShape(): void
    {
        // Control: proves the contract exists and is obeyed elsewhere, so the
        // two failures below are omissions, not a design the module never made.
        $this->assertStringContainsString('d.ok', $this->functionBody('function pnlLoad(tab)'),
            'the list loader stopped checking the ok flag');
    }

    public function testTheSslLoaderMustNotRenderStatusFromAFailedReply(): void
    {
        $body = $this->functionBody('function pnlLoadSsl()');

        $this->assertStringContainsString('d.ok', $body,
            'pnlLoadSsl never inspects the ok flag: on {ok:false} it reads the '
            . 'absent d.ssl and renders "No certificate active" + an issuance '
            . 'button, telling a customer with a live certificate they have none.');
    }

    public function testThePhpSettingsLoaderMustNotSwallowAFailedReply(): void
    {
        $body = $this->functionBody('function pnlLoadSettings()');

        $this->assertStringContainsString('d.ok', $body,
            'pnlLoadSettings ignores the ok flag and silently renders an empty '
            . 'PHP-version dropdown on failure, with no word of what happened.');
    }
}
