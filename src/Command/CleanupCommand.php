<?php
namespace App\Command;
use App\Workspace\WorkspaceStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'app:cleanup',description:'Remove expired demo workspaces and their uploads.')]
final class CleanupCommand extends Command
{
    public function __construct(private WorkspaceStore $store) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $output->writeln('Expired workspaces removed: '.$this->store->cleanup()); return Command::SUCCESS;
    }
}
