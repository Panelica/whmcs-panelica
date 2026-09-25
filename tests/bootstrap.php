<?php

/**
 * Loads the module the way WHMCS would, minus WHMCS.
 *
 * The module guards itself with `if (!defined('WHMCS')) die`, and calls a
 * handful of WHMCS globals. Only the ones the tested code paths actually
 * touch are stubbed here; anything else would be a test pretending to
 * exercise something it does not.
 */

define('WHMCS', true);

/** Records what the module asked WHMCS to log, so tests can assert on masking. */
final class ModuleCallLog
{
    /** @var array<int, array{action: string, request: mixed, response: mixed, mask: array}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

if (!function_exists('logModuleCall')) {
    function logModuleCall($module, $action, $request, $response, $processed = null, $mask = [])
    {
        ModuleCallLog::$calls[] = [
            'action' => (string) $action,
            'request' => $request,
            'response' => $response,
            'mask' => is_array($mask) ? $mask : [],
        ];
    }
}

require_once __DIR__ . '/doubles/Capsule.php';
require_once __DIR__ . '/../modules/servers/panelica/lib/PanelicaAPI.php';
require_once __DIR__ . '/../modules/servers/panelica/panelica.php';

/**
 * A PanelicaAPI that answers from a queue instead of the network.
 *
 * Every request is recorded with the exact URL, headers and body the real
 * class would have put on the wire, so the signature and the transport are
 * tested without a panel to talk to.
 */
class FakePanelicaAPI extends PanelicaAPI
{
    /** @var array<int, array> */
    public array $sent = [];

    /** @var array<int, array{status: int, body: string, errno: int, error: string}> */
    public array $queue = [];

    /** @var array<int, bool> TLS verification flag per call, in order. */
    public array $verifyFlags = [];

    /** @var array<int, int> Seconds the client asked to wait, in order - never actually slept. */
    public array $pauses = [];

    protected function pause($seconds)
    {
        $this->pauses[] = (int) $seconds;
    }

    public function queueJson(array $payload, int $status = 200): void
    {
        $this->queue[] = ['status' => $status, 'body' => json_encode($payload), 'errno' => 0, 'error' => ''];
    }

    public function queueRaw(string $body, int $status = 200): void
    {
        $this->queue[] = ['status' => $status, 'body' => $body, 'errno' => 0, 'error' => ''];
    }

    public function queueCurlError(int $errno, string $message): void
    {
        $this->queue[] = ['status' => 0, 'body' => '', 'errno' => $errno, 'error' => $message];
    }

    protected function execCurl($method, $url, array $headers, $bodyString, $verifyTls)
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $bodyString];
        $this->verifyFlags[] = (bool) $verifyTls;

        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('FakePanelicaAPI: no response queued for ' . $method . ' ' . $url);
        }

        return [$next['status'], $next['body'], $next['errno'], $next['error']];
    }

    /** Header value the last request carried, or null. */
    public function lastHeader(string $name): ?string
    {
        $last = end($this->sent);

        if ($last === false) {
            return null;
        }

        foreach ($last['headers'] as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}

/**
 * Inject a fake transport for a WHMCS-facing function that calls
 * panelica_getApi() internally. Pair with panelica_test_clearApi() in tearDown.
 */
function panelica_test_useApi(FakePanelicaAPI $api): void
{
    $GLOBALS['PANELICA_TEST_API'] = $api;
}

/** Remove the injected transport so nothing leaks between tests. */
function panelica_test_clearApi(): void
{
    unset($GLOBALS['PANELICA_TEST_API']);
}
