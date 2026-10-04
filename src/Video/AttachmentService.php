<?php
namespace App\Video;

use App\Workspace\WorkspaceStore;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AttachmentService
{
    public const LANGUAGES=['en','fr','de','it','es'];
    public function __construct(private WorkspaceStore $store) {}
    public function list(string $workspace): array
    {
        $this->store->assertActive($workspace);
        return array_map(fn($row)=>[...$row,'url'=>'/attachments/'.$row['id']],$this->store->db()->fetchAllAssociative('SELECT * FROM attachments WHERE workspace_id=? ORDER BY rowid',[$workspace]));
    }
    public function record(string $workspace,string $id,bool $checkActive=true): ?array
    {
        if($checkActive)$this->store->assertActive($workspace);
        return $this->store->db()->fetchAssociative('SELECT * FROM attachments WHERE workspace_id=? AND id=?',[$workspace,$id])?:null;
    }
    public function upload(string $workspace,string $asset,string $kind,string $language,UploadedFile $file): array
    {
        $this->store->assertActive($workspace);
        $duration=$asset==='sample'?8:$this->store->db()->fetchOne('SELECT duration FROM assets WHERE workspace_id=? AND id=?',[$workspace,$asset]);
        if(!$duration)throw new \DomainException('Select a video owned by this workspace.',422);
        if(!in_array($kind,['cover','caption'],true) || !$file->isValid() || $file->getSize()>($kind==='cover'?3:1)*1024*1024)throw new \DomainException('Upload a cover up to 3 MiB or a caption file up to 1 MiB.',422);
        if($kind==='cover') {
            $image=@getimagesize($file->getPathname());
            $extensions=['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
            $mime=$image['mime']??'';
            if(!$image || !isset($extensions[$mime]) || $file->getMimeType()!==$mime || $image[0]*$image[1]>20000000)throw new \DomainException('Select a valid PNG, JPEG or WebP cover below 20 megapixels.',422);
            $extension=$extensions[$mime];$language='';
        } else {
            if(!in_array($language,self::LANGUAGES,true))throw new \DomainException('Caption language must be en, fr, de, it or es.',422);
            $this->validateVtt(file_get_contents($file->getPathname()),(float)$duration);
            $extension='vtt';$mime='text/vtt';
        }
        return $this->store->atomic(function()use($workspace,$asset,$kind,$language,$file,$extension,$mime):array {
            $this->store->assertActive($workspace);
            if((int)$this->store->db()->fetchOne('SELECT COUNT(*) FROM attachments WHERE workspace_id=?',[$workspace])>=30)throw new \DomainException('This workspace has reached its thirty-attachment limit.',429);
            $total=$this->store->uploadedBytes();
            if($total+$file->getSize()>256*1024*1024)throw new \DomainException('Demo storage is full.',429);
            $id=bin2hex(random_bytes(16));$filename=$id.'.'.$extension;
            $row=['id'=>$id,'workspace_id'=>$workspace,'asset_id'=>$asset,'kind'=>$kind,'language'=>$language,'filename'=>$filename,'label'=>mb_substr(basename($file->getClientOriginalName()),0,120),'bytes'=>$file->getSize(),'mime'=>$mime];
            $file->move($this->store->dataDir.'/uploads',$filename);
            try{$this->store->db()->insert('attachments',$row);}catch(\Throwable $e){unlink($this->store->dataDir.'/uploads/'.$filename);throw $e;}
            return [...$row,'url'=>'/attachments/'.$id];
        });
    }
    private function validateVtt(string $text,float $duration): void
    {
        $text=str_replace(["\r\n","\r"],"\n",ltrim($text,"\xEF\xBB\xBF"));
        if(!mb_check_encoding($text,'UTF-8') || str_contains($text,"\0") || !preg_match('/\AWEBVTT(?:[^\n]*)\n\n/',$text))throw new \DomainException('Captions require a UTF-8 WEBVTT header and timed cues.',422);
        $blocks=preg_split('/\n{2,}/',trim($text));array_shift($blocks);$count=0;$previous=-1;
        $timestamp='(?:(\d{2,}):)?([0-5]\d):([0-5]\d)\.(\d{3})';
        $seconds=static fn($m)=>(float)($m[1]?:0)*3600+(int)$m[2]*60+(int)$m[3]+(int)$m[4]/1000;
        foreach($blocks as $block) {
            if(preg_match('/\ANOTE(?:\s|$)/',$block))continue;
            $lines=explode("\n",$block);if(!str_contains($lines[0],'-->'))array_shift($lines);
            $time=array_shift($lines);$parts=explode(' --> ',$time??'');
            if(count($parts)!==2 || !preg_match('/\A'.$timestamp.'\z/',$parts[0],$start) || !preg_match('/\A'.$timestamp.'\z/',$parts[1],$end) || !trim(implode("\n",$lines)))throw new \DomainException('Invalid VTT cue. Use plain timed cues without STYLE, REGION or cue settings.',422);
            $from=$seconds($start);$to=$seconds($end);
            if($from<$previous || $to<=$from || $to>$duration+0.25)throw new \DomainException('VTT cues must be ordered and fit the selected video duration.',422);
            $previous=$from;$count++;
        }
        if(!$count)throw new \DomainException('The VTT file contains no timed captions.',422);
    }
}
