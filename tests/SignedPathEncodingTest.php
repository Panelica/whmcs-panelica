<?php

use PHPUnit\Framework\TestCase;

/**
 * The signature has to cover the path the panel sees, and the panel does not
 * see what we send. It builds its string from r.URL.Path, which Go has already
 * percent-decoded, plus r.URL.RawQuery, which it has not. We were signing the
 * encoded path, so the two agreed only as long as every id was a plain UUID.
 *
 * Put one space in a value that travels in the path - a backup filename is the
 * only user-chosen one - and the two strings differ, the panel rejects the
 * request, and the customer is told the signature failed. Measured against the
 * live panel: "ab-nonexistent.tar.gz" reached the endpoint and came back with a
 * filename-format error, while "a b-nonexistent.tar.gz" came back HTTP 401.
 *
 * The query string is the opposite case and must stay encoded, because the
 * panel compares it raw.
 */
final class SignedPathEncodingTest extends TestCase
{
    private function apiThatAnswersOnce(): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk-secret');
        $api->queueJson(['status' => 'success', 'data' => []]);

        return $api;
    }

    /** What the panel itself will compute for the request we just sent. */
    private function signatureThePanelExpects(FakePanelicaAPI $api, string $method, string $body = ''): string
    {
        $last = end($api->sent);
        $url = parse_url($last['url']);
        $path = rawurldecode($url['path']);           // Go decodes the path...
        if (isset($url['query']) && $url['query'] !== '') {
            $path .= '?' . $url['query'];            // ...and leaves the query raw.
        }
        $path = preg_replace('#^/api/external#', '', $path);

        return hash_hmac('sha256', $method . $path . $this->headerValue($api, 'X-Timestamp') . $body, 'sk-secret');
    }

    private function headerValue(FakePanelicaAPI $api, string $name): string
    {
        $last = end($api->sent);
        foreach ($last['headers'] as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return '';
    }

    public function testASpaceInAPathSegmentIsStillSignedTheWayThePanelReadsIt(): void
    {
        $api = $this->apiThatAnswersOnce();
        $api->deleteBackup('a b.tar.zst');

        $this->assertSame(
            $this->signatureThePanelExpects($api, 'DELETE'),
            $this->headerValue($api, 'X-Signature'),
            'the panel decodes the path before signing it'
        );
    }

    public function testTheEncodedFormIsStillWhatTravelsOnTheWire(): void
    {
        $api = $this->apiThatAnswersOnce();
        $api->deleteBackup('a b.tar.zst');

        $last = end($api->sent);
        $this->assertStringContainsString('a%20b.tar.zst', $last['url'], 'the URL itself stays escaped');
    }

    public function testNonAsciiInAPathSegmentIsSignedDecodedToo(): void
    {
        $api = $this->apiThatAnswersOnce();
        $api->restoreBackup('yedek-şubat.tar.zst');

        $this->assertSame(
            $this->signatureThePanelExpects($api, 'POST', '[]'),
            $this->headerValue($api, 'X-Signature')
        );
    }

    /**
     * The trap in my own change: the query string must NOT be decoded, because
     * the panel compares it raw. Decoding both would simply move the failure.
     */
    public function testAQueryStringStaysEncodedInTheSignature(): void
    {
        $api = $this->apiThatAnswersOnce();
        $api->listFiles('user-1', '/home/a b');

        $this->assertSame(
            $this->signatureThePanelExpects($api, 'GET'),
            $this->headerValue($api, 'X-Signature')
        );

        $last = end($api->sent);
        $this->assertStringContainsString('path=%2Fhome%2Fa%20b', $last['url']);
    }

    public function testAnOrdinaryUuidPathIsSignedExactlyAsBefore(): void
    {
        $api = $this->apiThatAnswersOnce();
        $api->getDiskUsage('3f2b9c1e-0000-4a11-8c2d-000000000001');

        $this->assertSame(
            $this->signatureThePanelExpects($api, 'GET'),
            $this->headerValue($api, 'X-Signature'),
            'nothing changes for the paths that already worked'
        );
    }
}
