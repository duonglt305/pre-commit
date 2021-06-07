<?php


namespace DuoLee\PreCommit;

use DuoLee\PreCommit\Console\Commands\PreCommitCommand;
use Illuminate\Support\ServiceProvider;

class PreCommitServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            implode(DIRECTORY_SEPARATOR, [
                __DIR__, '..', 'config', 'pre-commit.php'
            ]),
            'pre-commit'
        );
        $this->commands([
            PreCommitCommand::class,
        ]);
    }
}
