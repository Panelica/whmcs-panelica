<?php

use PHPUnit\Framework\TestCase;

/**
 * The panel address printed into a page.
 *
 * It is built the same way twice, from the server's hostname or IP as the
 * operator typed it. The admin link escapes it before putting it in an href;
 * the client area handed it to the template raw, and the template prints it
 * into an href and into two more links beside it.
 *
 * The value is the operator's own, not a customer's, so this is a quote in a
 * hostname breaking the markup on every customer's product page rather than
 * anything a customer can aim - but the two places build the same string and
 * only one of them was careful, and the careful one shows what was intended.
 */
final class PanelUrlEscapingTest extends TestCase
{
    private function clientAreaUrl(string $hostname): string
    {
        $out = panelica_ClientArea([
            'serverhostname' => $hostname,
            'serverip' => '198.51.100.10',
            'serverport' => 8443,
            'serviceid' => 7,
        ]);

        return $out['vars']['panelUrl'];
    }

    public function testAnOrdinaryHostnameIsUntouched(): void
    {
        $this->assertSame('https://panel.example.com:8443/', $this->clientAreaUrl('panel.example.com'));
    }

    public function testAQuoteInTheHostnameCannotBreakOutOfTheAttribute(): void
    {
        $url = $this->clientAreaUrl('panel.example.com" onmouseover="alert(1)');

        $this->assertStringNotContainsString('" onmouseover="', $url);
        $this->assertStringContainsString('&quot;', $url);
    }

    public function testAngleBracketsAreEscapedToo(): void
    {
        $url = $this->clientAreaUrl('panel.example.com<script>');

        $this->assertStringNotContainsString('<script>', $url);
    }

    public function testTheAdminLinkStillEscapesTheSameWay(): void
    {
        $link = panelica_AdminLink([
            'serverhostname' => 'panel.example.com" onmouseover="alert(1)',
            'serverip' => '198.51.100.10',
            'serverport' => 8443,
        ]);

        $this->assertStringNotContainsString('" onmouseover="alert(1)"', $link);
        $this->assertStringContainsString('&quot;', $link);
    }

    public function testTheServiceIdIsAlwaysANumber(): void
    {
        $out = panelica_ClientArea([
            'serverhostname' => 'panel.example.com',
            'serverip' => '198.51.100.10',
            'serverport' => 8443,
            'serviceid' => '7"; alert(1); //',
        ]);

        $this->assertSame(7, $out['vars']['serviceId']);
    }
}
