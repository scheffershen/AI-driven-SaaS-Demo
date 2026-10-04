<?php
namespace App\Video;

use App\Workspace\WorkspaceStore;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\Process;

final class AssetService
{
    public function __construct(private WorkspaceStore $store,private string $samplePath) {}
    public function list(string $workspace): array
    {
        $this->store->assertActive($workspace);
        $rows=$this->store->db()->fetchAllAssociative('SELECT id,label,duration,bytes FROM assets WHERE workspace_id=? ORDER BY label',[$workspace]);
        return [['id'=>'sample','label'=>'Welcome sample · test pattern','duration'=>8.0,'url'=>'/media/sample'],...array_map(fn($row)=>[...$row,'duration'=>(float)$row['duration'],'url'=>'/media/'.$row['id']],$rows)];
    }
    public function upload(string $workspace,UploadedFile $file): array
    {
        $this->store->assertActive($workspace);
        if (!$file->isValid() || $file->getSize()>20*1024*1024) throw new \DomainException('Upload a valid MP4 no larger than 20 MiB.',422);
        if (!in_array($file->getMimeType(),['video/mp4','application/mp4'],true)) throw new \DomainException('The file is not an MP4 video.',422);
        $probe=new Process(['ffprobe','-v','error','-show_format','-show_streams','-of','json',$file->getPathname()]); $probe->setTimeout(20);
        try { $probe->mustRun(); $info=json_decode($probe->getOutput(),true,512,JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new \DomainException('The MP4 container could not be inspected.',422); }
        $duration=(float)($info['format']['duration']??0);
        $videos=array_filter($info['streams']??[],fn($stream)=>($stream['codec_type']??'')==='video' && ($stream['width']??0)>0);
        if (!$videos || !str_contains($info['format']['format_name']??'','mp4') || $duration<=0 || $duration>7200) throw new \DomainException('Upload an MP4 with a video stream and a duration below two hours.',422);
        return $this->store->atomic(function () use ($workspace,$file,$duration): array {
            $this->store->assertActive($workspace);
            if ((int)$this->store->db()->fetchOne('SELECT COUNT(*) FROM assets WHERE workspace_id=?',[$workspace])>=5) throw new \DomainException('This workspace has reached its five-upload limit.',429);
            if ($this->store->uploadedBytes()+$file->getSize()>256*1024*1024) throw new \DomainException('Demo storage is full. Try after expired workspaces are cleaned.',429);
            $id=bin2hex(random_bytes(16)); $filename=$id.'.mp4';
            $label=mb_substr(basename($file->getClientOriginalName()),0,120);
            $row=['id'=>$id,'workspace_id'=>$workspace,'filename'=>$filename,'label'=>$label,'bytes'=>$file->getSize(),'duration'=>$duration];
            $file->move($this->store->dataDir.'/uploads',$filename);
            try { $this->store->db()->insert('assets',$row); }
            catch (\Throwable $e) { unlink($this->store->dataDir.'/uploads/'.$filename); throw $e; }
            return ['id'=>$id,'label'=>$label,'duration'=>$duration,'url'=>'/media/'.$id];
        });
    }
    public function path(string $workspace,string $id): ?string
    {
        $this->store->assertActive($workspace);
        if ($id==='sample') return is_file($this->samplePath)?$this->samplePath:null;
        $filename=$this->store->db()->fetchOne('SELECT filename FROM assets WHERE id=? AND workspace_id=?',[$id,$workspace]);
        return $filename ? $this->store->dataDir.'/uploads/'.$filename : null;
    }
}
