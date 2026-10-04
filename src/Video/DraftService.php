<?php
namespace App\Video;
use App\Workspace\WorkspaceStore;

final class DraftService
{
    public function __construct(private WorkspaceStore $store) {}
    public function validate(string $workspace,array $payload): array
    {
        $this->store->assertActive($workspace);
        $errors=VideoInput::errors($payload);
        if (($payload['asset_id']??null)!=='sample' && !$this->store->db()->fetchOne('SELECT id FROM assets WHERE id=? AND workspace_id=?', [is_string($payload['asset_id']??null)?$payload['asset_id']:'',$workspace])) $errors['asset_id'][]='Select a video owned by this workspace.';
        foreach(VideoInput::ATTACHMENTS as $field) {
            $id=$payload[$field]??'';if(!$id)continue;
            $row=is_string($id)?$this->store->db()->fetchAssociative('SELECT * FROM attachments WHERE id=? AND workspace_id=?',[$id,$workspace]):false;
            $kind=$field==='cover_id'?'cover':'caption';$language=$kind==='cover'?'':substr($field,-2);
            if(!$row || $row['asset_id']!==($payload['asset_id']??'') || $row['kind']!==$kind || $row['language']!==$language)$errors[$field][]='Select an owned attachment matching this video, type and language.';
        }
        return $errors;
    }
    public function save(string $workspace,array $payload,string $saveKey): array
    {
        if (!preg_match('/^[a-f0-9]{32}$/D',$saveKey)) throw new \DomainException('Invalid save key.',422);
        if ($errors=$this->validate($workspace,$payload)) throw new \DomainException(json_encode($errors,JSON_THROW_ON_ERROR),422);
        $ordered=[]; foreach (VideoInput::FIELDS as $field) $ordered[$field]=$payload[$field]??'';
        $json=json_encode($ordered,JSON_THROW_ON_ERROR); $hash=hash('sha256',$json);
        return $this->store->atomic(function () use ($workspace,$saveKey,$hash,$json,$ordered): array {
            $this->store->assertActive($workspace);
            $existing=$this->store->db()->fetchAssociative('SELECT * FROM drafts WHERE workspace_id=? AND save_key=?',[$workspace,$saveKey]);
            if ($existing) {
                if (!hash_equals($existing['payload_hash'],$hash)) throw new \DomainException('This save key already belongs to different metadata.',409);
                return $this->record($existing);
            }
            if ((int)$this->store->db()->fetchOne('SELECT COUNT(*) FROM drafts WHERE workspace_id=?',[$workspace])>=20) throw new \DomainException('This workspace has reached its twenty-draft limit.',429);
            $duration=$ordered['asset_id']==='sample' ? 8.0 : (float)$this->store->db()->fetchOne('SELECT duration FROM assets WHERE id=? AND workspace_id=?',[$ordered['asset_id'],$workspace]);
            $stored=json_encode([...$ordered,'duration'=>$duration],JSON_THROW_ON_ERROR);
            $id=bin2hex(random_bytes(12));
            $row=['id'=>$id,'workspace_id'=>$workspace,'save_key'=>$saveKey,'payload_hash'=>$hash,'payload'=>$stored,'created_at'=>time()];
            $this->store->db()->insert('drafts',$row); return $this->record($row);
        });
    }
    public function find(string $workspace,?string $id,?string $key): ?array
    {
        $this->store->assertActive($workspace);
        $row=$id ? $this->store->db()->fetchAssociative('SELECT * FROM drafts WHERE workspace_id=? AND id=?',[$workspace,$id]) : $this->store->db()->fetchAssociative('SELECT * FROM drafts WHERE workspace_id=? AND save_key=?',[$workspace,$key]);
        return $row ? $this->record($row) : null;
    }
    public function list(string $workspace): array
    {
        $this->store->assertActive($workspace);
        return array_map($this->record(...),$this->store->db()->fetchAllAssociative('SELECT * FROM drafts WHERE workspace_id=? ORDER BY created_at DESC,id DESC',[$workspace]));
    }
    private function record(array $row): array
    {
        return [...json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR),'id'=>$row['id'],'save_key'=>$row['save_key'],'created_at'=>(int)$row['created_at'],'status'=>'draft','published'=>false,'url'=>'/content/'.$row['id']];
    }
}
