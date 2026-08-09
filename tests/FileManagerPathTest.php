<?php

use PHPUnit\Framework\TestCase;

/**
 * The file manager's own idea of where the account may look.
 *
 * The panel refuses to leave an account's home - a listing of "<home>/../.."
 * comes back 403, checked against a live server - so this guard is the module's
 * second line rather than the only one. It was not holding: an empty root, which
 * is what an unavailable directories call leaves behind, turned the check off
 * altogether and passed whatever path arrived, and the prefix test had no
 * boundary, so /home/bob let /home/bobby through.
 */
final class FileManagerPathTest extends TestCase
{
    public function testTheAccountRootItselfIsAllowed(): void
    {
        $this->assertSame('/home/bob', panelica_confinePath('/home/bob', '/home/bob'));
        $this->assertSame('/home/bob', panelica_confinePath('/home/bob/', '/home/bob'));
    }

    public function testAPathInsideTheAccountRootIsAllowed(): void
    {
        $this->assertSame('/home/bob/public_html', panelica_confinePath('/home/bob/public_html', '/home/bob'));
        $this->assertSame('/home/bob/a/b', panelica_confinePath('/home/bob/a/b/', '/home/bob'));
    }

    public function testAnEmptyPathFallsBackToTheRoot(): void
    {
        $this->assertSame('/home/bob', panelica_confinePath('', '/home/bob'));
    }

    public function testANeighbourWhoseNameStartsTheSameIsNotInside(): void
    {
        $this->assertSame('/home/bob', panelica_confinePath('/home/bobby', '/home/bob'));
        $this->assertSame('/home/bob', panelica_confinePath('/home/bob-backup/secrets', '/home/bob'));
    }

    public function testAPathOutsideTheRootFallsBackToIt(): void
    {
        $this->assertSame('/home/bob', panelica_confinePath('/etc', '/home/bob'));
        $this->assertSame('/home/bob', panelica_confinePath('/home/alice/public_html', '/home/bob'));
    }

    public function testAWalkUpIsRefusedEvenWhenItStartsInside(): void
    {
        $this->assertSame('/home/bob', panelica_confinePath('/home/bob/../alice', '/home/bob'));
        $this->assertSame('/home/bob', panelica_confinePath('/home/bob/a/../../alice', '/home/bob'));
        $this->assertSame('/home/bob', panelica_confinePath('/home/bob/..', '/home/bob'));
    }

    public function testADotDotInsideANameIsNotAWalkUp(): void
    {
        $this->assertSame('/home/bob/my..notes', panelica_confinePath('/home/bob/my..notes', '/home/bob'));
    }

    public function testWithoutAKnownRootNothingIsAccepted(): void
    {
        $this->expectException(Exception::class);

        panelica_confinePath('/etc/passwd', '');
    }
}
