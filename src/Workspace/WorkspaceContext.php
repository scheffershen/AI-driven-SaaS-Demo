<?php
namespace App\Workspace;
use Symfony\Component\HttpFoundation\Request;

final class WorkspaceContext
{
    public function __construct(private WorkspaceStore $store) {}
    public function requireWorkspace(Request $request): string
    {
        $id=$request->getSession()->get('workspace_id');
        if (!is_string($id)) throw new \DomainException('Start a demo workspace first.',403);
        $this->store->assertActive($id); return $id;
    }
}
