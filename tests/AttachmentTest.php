<?php
namespace App\Tests;

use App\Video\{AttachmentService,DraftService};
use App\Workspace\WorkspaceStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AttachmentTest extends TestCase
{
    private function file(string $text,string $name): UploadedFile
    {
        $path=tempnam(sys_get_temp_dir(),'attachment');file_put_contents($path,$text);
        return new UploadedFile($path,$name,null,null,true);
    }
    public function testCoverAndFiveTimedCaptionsPersistWithVerifiedDraft(): void
    {
        $store=new WorkspaceStore(sys_get_temp_dir().'/attachment-'.bin2hex(random_bytes(6)));
        $workspace=$store->create('attachment');$other=$store->create('other');
        $service=new AttachmentService($store);
        $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==');
        $cover=$service->upload($workspace,'sample','cover','',$this->file($png,'cover.png'));
        $payload=['title'=>'Welcome','summary'=>'A description.','category'=>'training','language'=>'it','content_date'=>'2026-10-03','asset_id'=>'sample','cover_id'=>$cover['id']];
        foreach(['en','fr','de','it','es'] as $language) {
            $caption=$service->upload($workspace,'sample','caption',$language,$this->file("WEBVTT\n\n00:00:00.000 --> 00:00:03.000\nHello\n",$language.'.vtt'));
            $payload['subtitle_'.$language]=$caption['id'];
        }
        $drafts=new DraftService($store);$saved=$drafts->save($workspace,$payload,bin2hex(random_bytes(16)));
        self::assertSame($cover['id'],$saved['cover_id']);self::assertFalse($saved['published']);
        self::assertCount(6,$service->list($workspace));self::assertNull($service->record($other,$cover['id']));
        self::assertSame($saved,$drafts->find($workspace,$saved['id'],null));
        $payload['subtitle_fr']=$payload['subtitle_en'];self::assertArrayHasKey('subtitle_fr',$drafts->validate($workspace,$payload));
        $store->db()->executeStatement('UPDATE workspaces SET expires_at=0 WHERE id=?',[$workspace]);
        $path=$store->dataDir.'/uploads/'.$service->record($workspace,$cover['id'],false)['filename'];
        $store->cleanup();self::assertFileDoesNotExist($path);
    }
    public function testRejectsFakeCoverMalformedCaptionsAndWrongVideoBinding(): void
    {
        $store=new WorkspaceStore(sys_get_temp_dir().'/bad-attachment-'.bin2hex(random_bytes(6)));
        $workspace=$store->create('bad');$service=new AttachmentService($store);
        foreach([
            ['sample','cover','', '<svg></svg>','fake.png'],
            ['sample','caption','fr',"WEBVTT\n\n00:00:04.000 --> 00:00:02.000\nBackwards\n",'fr.vtt'],
            ['sample','caption','en',"WEBVTT\n\n00:00:01.000 --> 00:00:09.000\nToo long\n",'en.vtt'],
            ['sample','caption','xx',"WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello\n",'xx.vtt'],
            ['unknown','caption','en',"WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nHello\n",'en.vtt'],
        ] as [$asset,$kind,$language,$text,$name]) {
            $file=$this->file($text,$name);
            try{$service->upload($workspace,$asset,$kind,$language,$file);self::fail('Invalid attachment accepted');}
            catch(\DomainException $e){self::assertSame(422,$e->getCode());}
            finally{if(is_file($file->getPathname()))unlink($file->getPathname());}
        }
        self::assertCount(0,$service->list($workspace));
    }
}
