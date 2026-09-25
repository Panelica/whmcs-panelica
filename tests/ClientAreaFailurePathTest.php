<?php

use PHPUnit\Framework\TestCase;

/**
 * What the client area does when a request does not come back as JSON.
 *
 * Every tab talks to panelica_Api over fetch and reads the reply as JSON. When
 * the reply is not JSON - a session that expired into a login page, a fatal, a
 * gateway error - that promise rejects. The listing paths noticed and said
 * "Load failed"; the paths that act did not, so a customer pressing Delete or
 * Restore watched the button disable itself and stay that way, with nothing on
 * screen. They reload and try again, which is exactly the wrong instinct with a
 * restore.
 *
 * Rather than bolt a failure branch onto thirteen callers, the two places that
 * actually talk to the server answer with the same shape the callers already
 * handle: {ok:false, error:...}. Every "else" branch that existed then does its
 * job, buttons come back, and the message says what happened.
 */
final class ClientAreaFailurePathTest extends TestCase
{
    private function template(): string
    {
        return file_get_contents(__DIR__ . '/../modules/servers/panelica/templates/overview.tpl');
    }

    public function testTheSharedHelperNeverLeavesACallerHanging(): void
    {
        preg_match('/function pnlApi\(op,tab,data\)\{(.*?)\n\}/s', $this->template(), $m);

        $this->assertNotEmpty($m, 'the shared helper is gone');
        $this->assertStringContainsString('catch(pnlFail)', str_replace(' ', '', $m[1]),
            'a non-JSON reply would reject and no caller would hear');
    }

    public function testTheDirectFetchHelperAlsoAnswersInsteadOfRejecting(): void
    {
        preg_match('/function pnlGet\(qs\)\{(.*?)\n\}/s', $this->template(), $m);

        $this->assertNotEmpty($m, 'the direct-fetch helper is gone');
        $this->assertStringContainsString('catch(pnlFail)', str_replace(' ', '', $m[1]));
    }

    public function testTheFailureShapeIsTheOneEveryCallerAlreadyHandles(): void
    {
        preg_match('/function pnlFail\(e\)\{(.*?)\}/s', $this->template(), $m);

        $this->assertNotEmpty($m, 'the failure handler is gone');
        $this->assertStringContainsString('ok:false', str_replace(' ', '', $m[1]));
        $this->assertStringContainsString('error:', str_replace(' ', '', $m[1]));
    }

    public function testEveryFunctionThatTalksToTheServerHasAFailurePath(): void
    {
        $template = $this->template();
        $unhandled = [];

        preg_match_all('/function ((?:pnl|fm)\w+)\([^)]*\)\{(.*?)\n(?=function |\}\n|<\/script>)/s', $template, $fns, PREG_SET_ORDER);

        foreach ($fns as $fn) {
            [, $name, $body] = $fn;

            if (strpos($body, 'fetch(') === false) {
                continue;
            }

            if (strpos($body, '.catch(') === false) {
                $unhandled[] = $name;
            }
        }

        $this->assertSame([], $unhandled, 'a request can still reject with nobody listening');
    }

    public function testEveryDestructiveActionStillAsksFirst(): void
    {
        $template = $this->template();

        foreach (['function pnlDel(', 'function pnlRestore(', 'function pnlBkRestore('] as $fn) {
            $start = strpos($template, $fn);
            $this->assertNotFalse($start, $fn . ' is gone');
            $this->assertStringContainsString('confirm(', substr($template, $start, 200), $fn . ' stopped asking');
        }
    }
}
