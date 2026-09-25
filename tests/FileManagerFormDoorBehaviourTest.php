<?php

use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the four file-manager form doors, walked end to end.
 *
 * FileManagerDoorsTest proves the strict confinement helper itself and — by
 * reading the source — that every door mentions it. This file exercises the
 * doors as functions: a fake panel behind panelica_getApi, the request in
 * $_POST, and three things observed afterwards: what reached the panel, what
 * flash message was left for the customer, and that a refused path never
 * turned into an API write.
 */
final class FileManagerFormDoorBehaviourTest extends TestCase
{
    private const ACCOUNT = 'acct-1';
    private const ROOT = '/home/mine';

    protected function setUp(): void
    {
        ModuleCallLog::reset();
        $_POST = [];
        unset($_SESSION['panelica_flash']);
    }

    protected function tearDown(): void
    {
        panelica_test_clearApi();
        $_POST = [];
        unset($_SESSION['panelica_flash']);
    }

    private function params(): array
    {
        return [
            'serverhostname' => 'panel.test',
            'serverip' => 'panel.test',
            'serverport' => 8443,
            'serverpassword' => 'pk',
            'serveraccesshash' => 'sk',
            'username' => 'mine',
        ];
    }

    /**
     * A panel that knows the account and answers the root lookup. The doors ask
     * in this order: the account listing, then the accessible directories, then
     * (only when the path survives) the file operation itself.
     */
    private function panel(): FakePanelicaAPI
    {
        $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
        $api->queueJson(['status' => 'success', 'data' => [
            ['id' => self::ACCOUNT, 'username' => 'mine', 'status' => 'active'],
        ]]);
        $api->queueJson(['status' => 'success', 'data' => [
            'directories' => [self::ROOT . '/'], // trailing slash, as a panel may send it
        ]]);

        return $api;
    }

    private function calledPaths(FakePanelicaAPI $api): array
    {
        return array_map(fn ($sent) => $sent['method'] . ' ' . parse_url($sent['url'], PHP_URL_PATH), $api->sent);
    }

    private function lastBody(FakePanelicaAPI $api): array
    {
        $last = end($api->sent);

        return json_decode($last['body'], true);
    }

    // ---------------------------------------------------------------- FmMkdir

    public function testMkdirInsideTheAccountCreatesAFolderAndFlashesSuccess(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);
        $_POST = ['fm_path' => self::ROOT . '/public_html', 'fm_name' => 'logs'];

        $this->assertSame('', panelica_FmMkdir($this->params()));

        $this->assertContains('POST /api/external/v1/files', $this->calledPaths($api));
        $this->assertSame(
            ['user_id' => self::ACCOUNT, 'path' => self::ROOT . '/public_html', 'name' => 'logs', 'type' => 'folder'],
            $this->lastBody($api)
        );
        $this->assertSame(['type' => 'success', 'msg' => 'Folder created.'], panelica_takeFlash());
    }

    public function testMkdirWithAnEmptyNameIsRefusedBeforeAnyFileCall(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_path' => self::ROOT, 'fm_name' => ''];

        panelica_FmMkdir($this->params());

        $flash = panelica_takeFlash();
        $this->assertSame('danger', $flash['type']);
        $this->assertSame('Folder name is required.', $flash['msg']);
        $this->assertNotContains('POST /api/external/v1/files', $this->calledPaths($api));
    }

    public function testMkdirWithAnEmptyPathIsRefused(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_path' => '', 'fm_name' => 'logs'];

        panelica_FmMkdir($this->params());

        $this->assertSame('danger', panelica_takeFlash()['type']);
        $this->assertNotContains('POST /api/external/v1/files', $this->calledPaths($api));
    }

    public function testMkdirOutsideTheAccountNeverReachesThePanel(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_path' => '/etc', 'fm_name' => 'evil'];

        panelica_FmMkdir($this->params());

        $flash = panelica_takeFlash();
        $this->assertSame('danger', $flash['type']);
        $this->assertSame('That path is not inside this account.', $flash['msg']);
        $this->assertNotContains('POST /api/external/v1/files', $this->calledPaths($api));
    }

    public function testMkdirWithAWalkUpPathIsRefused(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_path' => self::ROOT . '/../theirs', 'fm_name' => 'x'];

        panelica_FmMkdir($this->params());

        $this->assertSame('danger', panelica_takeFlash()['type']);
        $this->assertNotContains('POST /api/external/v1/files', $this->calledPaths($api));
    }

    // -------------------------------------------------------------- FmNewFile

    public function testNewFileInsideTheAccountCreatesAFileAndFlashesSuccess(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);
        $_POST = ['fm_path' => self::ROOT, 'fm_name' => 'notes.txt'];

        panelica_FmNewFile($this->params());

        $this->assertContains('POST /api/external/v1/files', $this->calledPaths($api));
        $this->assertSame(
            ['user_id' => self::ACCOUNT, 'path' => self::ROOT, 'name' => 'notes.txt', 'type' => 'file'],
            $this->lastBody($api)
        );
        $this->assertSame(['type' => 'success', 'msg' => 'File created.'], panelica_takeFlash());
    }

    public function testNewFileWithAnEmptyNameIsRefused(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_path' => self::ROOT, 'fm_name' => '   '];

        panelica_FmNewFile($this->params());

        $flash = panelica_takeFlash();
        $this->assertSame('danger', $flash['type']);
        $this->assertSame('File name is required.', $flash['msg']);
        $this->assertNotContains('POST /api/external/v1/files', $this->calledPaths($api));
    }

    public function testNewFileInANeighbourDirectorySharingTheNameStartIsRefused(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_path' => self::ROOT . 'r/www', 'fm_name' => 'x']; // /home/miner

        panelica_FmNewFile($this->params());

        $this->assertSame('danger', panelica_takeFlash()['type']);
        $this->assertNotContains('POST /api/external/v1/files', $this->calledPaths($api));
    }

    // ----------------------------------------------------------------- FmSave

    public function testSaveInsideTheAccountWritesTheContent(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);
        $_POST = ['fm_file' => self::ROOT . '/public_html/index.php', 'fm_content' => "<?php echo 'hi';\n"];

        panelica_FmSave($this->params());

        $this->assertContains('PUT /api/external/v1/files/content', $this->calledPaths($api));
        $this->assertSame(
            ['user_id' => self::ACCOUNT, 'path' => self::ROOT . '/public_html/index.php', 'content' => "<?php echo 'hi';\n"],
            $this->lastBody($api)
        );
        $this->assertSame(['type' => 'success', 'msg' => 'File saved.'], panelica_takeFlash());
    }

    public function testSaveWithAnEmptyContentStillSavesTheEmptyFile(): void
    {
        // Emptying a file is a legitimate edit; only the path is required.
        $api = $this->panel();
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);
        $_POST = ['fm_file' => self::ROOT . '/notes.txt', 'fm_content' => ''];

        panelica_FmSave($this->params());

        $this->assertContains('PUT /api/external/v1/files/content', $this->calledPaths($api));
        $this->assertSame('', $this->lastBody($api)['content']);
        $this->assertSame('success', panelica_takeFlash()['type']);
    }

    public function testSaveWithoutAPathIsRefused(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_content' => 'orphan text'];

        panelica_FmSave($this->params());

        $flash = panelica_takeFlash();
        $this->assertSame('danger', $flash['type']);
        $this->assertSame('Missing file path.', $flash['msg']);
        $this->assertNotContains('PUT /api/external/v1/files/content', $this->calledPaths($api));
    }

    public function testSaveOutsideTheAccountNeverWrites(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_file' => '/etc/passwd', 'fm_content' => 'root::0:0::/:/bin/sh'];

        panelica_FmSave($this->params());

        $this->assertSame('That path is not inside this account.', panelica_takeFlash()['msg']);
        $this->assertNotContains('PUT /api/external/v1/files/content', $this->calledPaths($api));
    }

    // --------------------------------------------------------------- FmDelete

    public function testDeleteInsideTheAccountMovesToTrashNotPermanent(): void
    {
        $api = $this->panel();
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);
        $_POST = ['fm_target' => self::ROOT . '/old-site'];

        panelica_FmDelete($this->params());

        $this->assertContains('DELETE /api/external/v1/files', $this->calledPaths($api));
        $this->assertSame(
            ['user_id' => self::ACCOUNT, 'paths' => [self::ROOT . '/old-site'], 'permanent' => false],
            $this->lastBody($api)
        );
        $this->assertSame(['type' => 'success', 'msg' => 'Deleted (moved to trash).'], panelica_takeFlash());
    }

    public function testDeleteWithoutATargetIsRefused(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = [];

        panelica_FmDelete($this->params());

        $flash = panelica_takeFlash();
        $this->assertSame('danger', $flash['type']);
        $this->assertSame('Missing target path.', $flash['msg']);
        $this->assertNotContains('DELETE /api/external/v1/files', $this->calledPaths($api));
    }

    public function testDeleteOutsideTheAccountNeverDeletes(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_target' => '/home/theirs/public_html'];

        panelica_FmDelete($this->params());

        $this->assertSame('That path is not inside this account.', panelica_takeFlash()['msg']);
        $this->assertNotContains('DELETE /api/external/v1/files', $this->calledPaths($api));
    }

    public function testDeleteWithAWalkUpNeverDeletes(): void
    {
        $api = $this->panel();
        panelica_test_useApi($api);
        $_POST = ['fm_target' => self::ROOT . '/../theirs'];

        panelica_FmDelete($this->params());

        $this->assertSame('danger', panelica_takeFlash()['type']);
        $this->assertNotContains('DELETE /api/external/v1/files', $this->calledPaths($api));
    }

    public function testDeleteOfTheRootItselfIsAllowedOnlyAsTheRoot(): void
    {
        // The strict helper accepts the root itself; the door passes it on as a
        // single-path trash move. Whether that is wise is the panel's decision;
        // the module's contract is only "never outside, never permanent".
        $api = $this->panel();
        $api->queueJson(['status' => 'success']);
        panelica_test_useApi($api);
        $_POST = ['fm_target' => self::ROOT];

        panelica_FmDelete($this->params());

        $this->assertSame([self::ROOT], $this->lastBody($api)['paths']);
        $this->assertFalse($this->lastBody($api)['permanent']);
    }

    // ------------------------------------------------- panel without a root

    public function testAnAccountWithoutAnAccessibleDirectoryRefusesEveryDoor(): void
    {
        foreach (['panelica_FmMkdir', 'panelica_FmNewFile', 'panelica_FmSave', 'panelica_FmDelete'] as $door) {
            $api = new FakePanelicaAPI('panel.test', 8443, 'pk', 'sk');
            $api->queueJson(['status' => 'success', 'data' => [
                ['id' => self::ACCOUNT, 'username' => 'mine', 'status' => 'active'],
            ]]);
            $api->queueJson(['status' => 'success', 'data' => ['directories' => []]]);
            panelica_test_useApi($api);
            $_POST = [
                'fm_path' => '/home/mine', 'fm_name' => 'x',
                'fm_file' => '/home/mine/a.txt', 'fm_content' => 'x',
                'fm_target' => '/home/mine/a.txt',
            ];

            $door($this->params());

            $this->assertSame('danger', panelica_takeFlash()['type'], $door);

            foreach ($this->calledPaths($api) as $called) {
                $this->assertStringNotContainsString('POST /api/external/v1/files', $called, $door);
                $this->assertStringNotContainsString('PUT /api/external/v1/files', $called, $door);
                $this->assertStringNotContainsString('DELETE /api/external/v1/files', $called, $door);
            }

            panelica_test_clearApi();
            $_POST = [];
        }
    }
}
