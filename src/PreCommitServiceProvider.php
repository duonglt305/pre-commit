<?php


namespace DuoLee\PreCommit;

use DuoLee\PreCommit\Console\Commands\PreCommitCommand;
use Illuminate\Support\ServiceProvider;

class PreCommitServiceProvider extends ServiceProvider
{
    public function register()
    {
        $cfDir = implode(DIRECTORY_SEPARATOR, [
            __DIR__, '..', 'config', 'pre-commit.php'
        ]);
        $this->mergeConfigFrom($cfDir,
            'pre-commit'
        );
        $this->commands([
            PreCommitCommand::class,
        ]);
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $cfDir
            ], "pre-commit");
        }
    }
}
