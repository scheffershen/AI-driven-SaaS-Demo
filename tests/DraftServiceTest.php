<?php
namespace App\Tests;

use App\Workspace\WorkspaceStore;
use App\Video\DraftService;
use App\Video\AssetService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class DraftServiceTest extends TestCase
{
    private WorkspaceStore $store;
    private DraftService $drafts;
    private string $workspace;
    protected function setUp(): void
    {
        $this->store = new WorkspaceStore(sys_get_temp_dir().'/video-test-'.bin2hex(random_bytes(6)));
        $this->workspace = $this->store->create('test-'.bin2hex(random_bytes(6)));
        $this->drafts = new DraftService($this->store);
    }
    private function payload(): array
    {
        return ['title' => 'Welcome', 'summary' => 'A short introduction.', 'category' => 'getting-started', 'language' => 'en', 'content_date' => '2026-10-03', 'asset_id' => 'sample'];
    }
    public function testIdenticalRetryReturnsOneDraftAndChangedPayloadConflicts(): void
    {
        $key = bin2hex(random_bytes(16));
        $first = $this->drafts->save($this->workspace, $this->payload(), $key);
        self::assertSame($first, $this->drafts->save($this->workspace, $this->payload(), $key));
        self::assertFalse($first['published']); self::assertSame('draft', $first['status']);
        self::assertCount(1, $this->drafts->list($this->workspace));
        self::assertSame($first, $this->drafts->find($this->workspace, null, $key));
        $this->expectException(\DomainException::class);
        $this->drafts->save($this->workspace, array_replace($this->payload(), ['title' => 'Changed']), $key);
    }
    public function testWorkspaceIsolationAndExpiry(): void
    {
        $draft = $this->drafts->save($this->workspace, $this->payload(), bin2hex(random_bytes(16)));
        $other = $this->store->create('other');
        self::assertNull($this->drafts->find($other, $draft['id'], null));
        $this->store->db()->executeStatement('UPDATE workspaces SET expires_at=0 WHERE id=?', [$this->workspace]);
        $this->expectException(\DomainException::class);
        $this->drafts->list($this->workspace);
    }
    public function testQuotaStillAllowsRetryAtTwentyDrafts(): void
    {
        $key = bin2hex(random_bytes(16));
        for ($i=0; $i<20; $i++) { $this->drafts->save($this->workspace, $this->payload(), $i===0 ? $key : bin2hex(random_bytes(16))); }
        self::assertCount(20, $this->drafts->list($this->workspace));
        $this->drafts->save($this->workspace, $this->payload(), $key);
        $this->expectException(\DomainException::class);
        $this->drafts->save($this->workspace, $this->payload(), bin2hex(random_bytes(16)));
    }
    public function testRejectsInvalidFieldsAndUnknownAsset(): void
    {
        foreach ([['title'=>''], ['category'=>'fake'], ['language'=>'fake'], ['content_date'=>'2026-02-30'], ['asset_id'=>'another-workspace-asset'], ['published'=>true]] as $change) {
            try { $this->drafts->save($this->workspace, array_replace($this->payload(), $change), bin2hex(random_bytes(16))); self::fail('Accepted invalid metadata'); }
            catch (\DomainException $e) { self::assertNotEmpty($e->getMessage()); }
        }
        self::assertCount(0, $this->drafts->list($this->workspace));
    }
    public function testInvalidAndOversizedUploadAreRejected(): void
    {
        $assets = new AssetService($this->store, '/nonexistent/sample.mp4');
        $path = tempnam(sys_get_temp_dir(), 'bad-video'); file_put_contents($path, 'not an MP4');
        try { $assets->upload($this->workspace, new UploadedFile($path, 'fake.mp4', 'video/mp4', null, true)); self::fail('Accepted invalid MP4'); }
        catch (\DomainException $e) { self::assertNotEmpty($e->getMessage()); }
        $handle = fopen($path, 'w'); ftruncate($handle, 20*1024*1024+1); fclose($handle);
        try { $assets->upload($this->workspace, new UploadedFile($path, 'large.mp4', 'video/mp4', null, true)); self::fail('Accepted oversized MP4'); }
        catch (\DomainException $e) { self::assertStringContainsString('20 MiB', $e->getMessage()); }
        unlink($path);
        self::assertCount(1, $assets->list($this->workspace));
    }
}
