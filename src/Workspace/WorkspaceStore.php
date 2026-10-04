<?php
namespace App\Workspace;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

final class WorkspaceStore
{
    private Connection $connection;
    public function __construct(public readonly string $dataDir)
    {
        if (!is_dir($dataDir)) mkdir($dataDir, 0700, true);
        if (!is_dir($dataDir.'/uploads')) mkdir($dataDir.'/uploads', 0700, true);
        $this->connection = DriverManager::getConnection(['driver'=>'pdo_sqlite', 'path'=>$dataDir.'/workspace.sqlite']);
        $this->connection->executeStatement('PRAGMA foreign_keys=ON');
        $this->connection->executeStatement('PRAGMA journal_mode=WAL');
        $this->connection->executeStatement('PRAGMA busy_timeout=5000');
        foreach ([
            'CREATE TABLE IF NOT EXISTS workspaces (id TEXT PRIMARY KEY, expires_at INTEGER NOT NULL)',
            'CREATE TABLE IF NOT EXISTS assets (id TEXT PRIMARY KEY, workspace_id TEXT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE, filename TEXT NOT NULL, label TEXT NOT NULL, bytes INTEGER NOT NULL, duration REAL NOT NULL)',
            'CREATE TABLE IF NOT EXISTS attachments (id TEXT PRIMARY KEY, workspace_id TEXT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE, asset_id TEXT NOT NULL, kind TEXT NOT NULL, language TEXT NOT NULL, filename TEXT NOT NULL, label TEXT NOT NULL, bytes INTEGER NOT NULL, mime TEXT NOT NULL)',
            'CREATE TABLE IF NOT EXISTS drafts (id TEXT PRIMARY KEY, workspace_id TEXT NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE, save_key TEXT NOT NULL, payload_hash TEXT NOT NULL, payload TEXT NOT NULL, created_at INTEGER NOT NULL, UNIQUE(workspace_id,save_key))',
            'CREATE TABLE IF NOT EXISTS throttles (bucket TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires_at INTEGER NOT NULL)',
        ] as $sql) $this->connection->executeStatement($sql);
    }
    public function db(): Connection { return $this->connection; }
    public function uploadedBytes(): int
    {
        return (int)$this->db()->fetchOne('SELECT COALESCE(SUM(bytes),0) FROM assets')+(int)$this->db()->fetchOne('SELECT COALESCE(SUM(bytes),0) FROM attachments');
    }
    public function atomic(callable $operation): mixed
    {
        $this->connection->executeStatement('BEGIN IMMEDIATE');
        try { $result=$operation(); $this->connection->executeStatement('COMMIT'); return $result; }
        catch (\Throwable $error) { $this->connection->executeStatement('ROLLBACK'); throw $error; }
    }
    public function create(string $client): string
    {
        $this->throttle('start:'.hash('sha256', $client), 15, 3600);
        return $this->atomic(function (): string {
            if ((int)$this->db()->fetchOne('SELECT COUNT(*) FROM workspaces WHERE expires_at>?', [time()]) >= 1000) throw new \DomainException('The demo is at capacity. Try later.', 429);
            $id=bin2hex(random_bytes(16));
            $this->db()->insert('workspaces', ['id'=>$id, 'expires_at'=>time()+86400]);
            return $id;
        });
    }
    public function assertActive(string $id): void
    {
        if (!$this->db()->fetchOne('SELECT id FROM workspaces WHERE id=? AND expires_at>?', [$id,time()])) throw new \DomainException('Your demo workspace expired. Start a new workspace.', 403);
    }
    public function throttle(string $name, int $limit, int $seconds=60): void
    {
        $bucket=$name.':'.intdiv(time(),$seconds);
        $this->atomic(function () use ($bucket,$limit,$seconds): void {
            $this->db()->executeStatement('INSERT INTO throttles(bucket,hits,expires_at) VALUES(?,1,?) ON CONFLICT(bucket) DO UPDATE SET hits=hits+1', [$bucket,time()+$seconds]);
            if ((int)$this->db()->fetchOne('SELECT hits FROM throttles WHERE bucket=?', [$bucket]) > $limit) throw new \DomainException('Rate limit reached. Wait before trying again.', 429);
        });
    }
    public function cleanup(): int
    {
        return $this->atomic(function (): int {
            $expired=$this->db()->fetchFirstColumn('SELECT id FROM workspaces WHERE expires_at<=?', [time()]);
            foreach ($expired as $id) {
                foreach ([...$this->db()->fetchFirstColumn('SELECT filename FROM assets WHERE workspace_id=?', [$id]),...$this->db()->fetchFirstColumn('SELECT filename FROM attachments WHERE workspace_id=?', [$id])] as $file) {
                    if (preg_match('/^[a-f0-9]{32}\.(mp4|png|jpg|webp|vtt)$/D', $file) && is_file($this->dataDir.'/uploads/'.$file)) unlink($this->dataDir.'/uploads/'.$file);
                }
                $this->db()->delete('workspaces', ['id'=>$id]);
            }
            $this->db()->executeStatement('DELETE FROM throttles WHERE expires_at<=?', [time()]);
            return count($expired);
        });
    }
}
