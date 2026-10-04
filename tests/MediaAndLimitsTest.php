<?php
namespace App\Tests;

use App\Video\{AssetService,DraftService};
use App\Workspace\WorkspaceStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MediaAndLimitsTest extends TestCase
{
    public function testValidMediaQuotaOwnershipAndExpiredCleanup(): void
    {
        $sample=dirname(__DIR__).'/var/sample/welcome.mp4';
        self::assertFileExists($sample,'Run this suite in the Docker image, which generates the fixture.');
        $store=new WorkspaceStore(sys_get_temp_dir().'/media-test-'.bin2hex(random_bytes(6)));
        $workspace=$store->create('media');$other=$store->create('other');
        $assets=new AssetService($store,$sample);$first=null;
        for($i=0;$i<5;$i++) {
            $temp=tempnam(sys_get_temp_dir(),'mp4');copy($sample,$temp);
            $asset=$assets->upload($workspace,new UploadedFile($temp,'my-video.mp4','video/mp4',null,true));
            self::assertEqualsWithDelta(8,$asset['duration'],0.1);$first??=$asset;
        }
        self::assertCount(6,$assets->list($workspace));
        self::assertNull($assets->path($other,$first['id']));
        $path=$assets->path($workspace,$first['id']);self::assertFileExists($path);
        $temp=tempnam(sys_get_temp_dir(),'mp4');copy($sample,$temp);
        try {$assets->upload($workspace,new UploadedFile($temp,'sixth.mp4','video/mp4',null,true));self::fail('Sixth upload accepted');}
        catch(\DomainException $e){self::assertSame(429,$e->getCode());unlink($temp);}
        $store->db()->executeStatement('UPDATE workspaces SET expires_at=0 WHERE id=?',[$workspace]);
        self::assertSame(1,$store->cleanup());self::assertFileDoesNotExist($path);
        self::assertSame(0,(int)$store->db()->fetchOne('SELECT COUNT(*) FROM assets WHERE workspace_id=?',[$workspace]));
        self::assertCount(1,$assets->list($other));
    }
    public function testWorkspaceCreationAndRequestRatesAreBounded(): void
    {
        $store=new WorkspaceStore(sys_get_temp_dir().'/rate-test-'.bin2hex(random_bytes(6)));
        for($i=0;$i<15;$i++)$store->create('one-ip');
        try{$store->create('one-ip');self::fail('Workspace creation limit missing');}
        catch(\DomainException $e){self::assertSame(429,$e->getCode());}
        for($i=0;$i<3;$i++)$store->throttle('example',3,3600);
        $this->expectException(\DomainException::class);$store->throttle('example',3,3600);
    }
    public function testMp4UploadCountsAttachmentBytesTowardGlobalLimit(): void
    {
        $store=new WorkspaceStore(sys_get_temp_dir().'/storage-test-'.bin2hex(random_bytes(6)));
        $workspace=$store->create('storage');
        $store->db()->insert('attachments',['id'=>str_repeat('a',32),'workspace_id'=>$workspace,'asset_id'=>'sample','kind'=>'cover','language'=>'','filename'=>str_repeat('a',32).'.jpg','label'=>'fixture','bytes'=>256*1024*1024,'mime'=>'image/jpeg']);
        $temp=tempnam(sys_get_temp_dir(),'mp4');copy(dirname(__DIR__).'/var/sample/welcome.mp4',$temp);
        try{(new AssetService($store,'unused'))->upload($workspace,new UploadedFile($temp,'video.mp4',null,null,true));self::fail('Combined storage quota bypassed');}
        catch(\DomainException $e){self::assertSame(429,$e->getCode());}
        finally{if(is_file($temp))unlink($temp);}
    }
}
