<?php

use PHPUnit\Framework\TestCase;

/**
 * The file manager's other doors.
 *
 * The AJAX endpoint confines every path it is given to the account's own
 * directory. The four form-post handlers - new folder, new file, save, delete -
 * took the path straight from the request and handed it to the panel. The panel
 * refuses to leave the account's home, checked on a live server, so this is the
 * module's own guard being uniform rather than a hole being closed.
 *
 * The strict variant matters here. Confining by falling back to the root is
 * right for a listing: ask for somewhere odd, get shown your own home. It is
 * very wrong for delete - "somewhere odd" would become "delete your home
 * directory" - so these doors refuse instead.
 */
final class FileManagerDoorsTest extends TestCase
{
    public function testAPathInsideTheAccountIsAccepted(): void
    {
        $this->assertSame(
            '/home/bob/public_html/index.php',
            panelica_requireConfinedPath('/home/bob/public_html/index.php', '/home/bob')
        );
    }

    public function testTheAccountRootItselfIsAccepted(): void
    {
        $this->assertSame('/home/bob', panelica_requireConfinedPath('/home/bob/', '/home/bob'));
    }

    public function testAPathOutsideTheAccountIsRefusedRatherThanRedirected(): void
    {
        $this->expectException(Exception::class);

        panelica_requireConfinedPath('/etc/passwd', '/home/bob');
    }

    public function testARefusalNeverBecomesTheRootItself(): void
    {
        // The listing helper answers "/home/bob" here. For a delete that would
        // mean removing the customer's whole account directory.
        $this->assertSame('/home/bob', panelica_confinePath('/etc/passwd', '/home/bob'));

        try {
            panelica_requireConfinedPath('/etc/passwd', '/home/bob');
            $this->fail('the strict variant let it through');
        } catch (Exception $e) {
            $this->addToAssertionCount(1);
        }
    }

    public function testANeighbourWithTheSameNameStartIsRefused(): void
    {
        $this->expectException(Exception::class);

        panelica_requireConfinedPath('/home/bobby/secrets.txt', '/home/bob');
    }

    public function testAWalkUpIsRefused(): void
    {
        $this->expectException(Exception::class);

        panelica_requireConfinedPath('/home/bob/../alice/notes.txt', '/home/bob');
    }

    public function testAnEmptyPathIsRefused(): void
    {
        $this->expectException(Exception::class);

        panelica_requireConfinedPath('', '/home/bob');
    }

    public function testWithoutAKnownRootNothingIsAccepted(): void
    {
        $this->expectException(Exception::class);

        panelica_requireConfinedPath('/home/bob/index.php', '');
    }

    public function testEveryFileManagerDoorConfinesWhatItWasGiven(): void
    {
        $source = file_get_contents(__DIR__ . '/../modules/servers/panelica/panelica.php');
        $unconfined = [];

        foreach (['FmMkdir', 'FmNewFile', 'FmSave', 'FmDelete'] as $door) {
            preg_match('/function panelica_' . $door . '\(array \$params\)\s*\{(.*?)\n\}/s', $source, $m);

            if (empty($m) || !str_contains($m[1], 'panelica_requireConfinedPath')) {
                $unconfined[] = $door;
            }
        }

        $this->assertSame([], $unconfined);
    }
}
