<?php


namespace DuoLee\PreCommit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionException;
use RuntimeException;

class PreCommitCommand extends Command
{
    protected Filesystem $files;

    public function __construct(Filesystem $files)
    {
        $this->files = $files;
        parent::__construct();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pre-commit
                            {--install : Install GIT Pre Commit hook.}
                            {--path= : The location where the pre commit check.}
                            {--all-files : Pre commit check all files.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Hook before commit GIT';

    /**
     * @return int
     * @throws ReflectionException
     */
    public function handle(): int
    {
        if ($this->option('install')) {
            return $this->install();
        }else if($this->option('path')){
            $changed = $this->getPHPFilesOfPath($this->option('path'));
        } else if($this->option('all-files')){
            $changed = $this->getPHPFilesOfPath();
        } else {
            $changed = $this->getPHPChangedFiles();
        }
        if (!$changed) {
            $this->info('Nothing to check!');
            return 0;
        }
        $this->output->writeln('Running PHP lint...');
        if (!$this->lint($changed)) {
            exit($this->fails());
        }
        $this->output->writeln("\nChecking PSR-2 Coding Standard...");
        $status = $this->psr2($changed);
        if (!$status) {
            $this->output->writeln("\nRunning Fixer Coding Standard...");
            if (!$this->fixer($changed)) {
                $this->error('Unable to fix violations.');
                exit(1);
            }
        }
        $this->info('🎉  All done!');
        return 0;
    }

    /**
     * @return array
     */
    protected function getPHPFilesOfPath(string $path = ''): array
    {
        $files = [];
        $path = base_path($path);
        if (!$this->files->exists($path)) {
            $this->error("$path does not exists.");
        }
        if ($this->files->exists($path)) {
            $allFiles = $this->files->allFiles($path);
            foreach ($allFiles as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    /**
     * Install git pre-commit hook
     * @throws ReflectionException
     */
    public function install(): int
    {
        $signature = $this->getCommandSignature(PreCommitCommand::class);
        $script = $this->getHookScript($signature);
        $path = base_path('.git/hooks/pre-commit');
        if (file_exists($path) && md5_file($path) != md5($script)) {
            if (!$this->confirm($path . ' already exists, do you want to overwrite it?', true)) {
                return 0;
            }
        }
        if (!$this->writeHookScript($path, $script)) {
            $this->error('Unable to install pre-commit hook.');
            return 0;
        }
        $this->info('Hook pre-commit successfully installed');
        return 0;
    }

    /**
     * @param string $path
     * @param string $script
     * @return bool
     */
    protected function writeHookScript(string $path, string $script): bool
    {
        if (!file_put_contents($path, $script)) {
            return false;
        }
        if (!chmod($path, 0755)) {
            return false;
        }
        return true;
    }

    /**
     * @param string $signature
     * @return string
     */
    protected function getHookScript(string $signature): string
    {
        $artisan = str_replace('\\', '/', base_path('artisan'));
        return "#!/bin/sh\n/usr/bin/env php " . $artisan . ' ' . $signature . "\n";
    }

    /**
     * @param string $class
     * @return string
     * @throws ReflectionException
     */
    protected function getCommandSignature(string $class): string
    {
        $reflect = new ReflectionClass($class);
        $properties = $reflect->getDefaultProperties();
        if (!preg_match('/^(\S+)/', $properties['signature'], $matches)) {
            throw new RuntimeException('Cannot read signature of ' . $class);
        }
        [, $signature] = $matches;
        return $signature;
    }

    /**
     * @return int
     */
    protected function fails(): int
    {
        $message = 'Commit aborted: you have errors in your code!';

        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN' && $this->exec('which cowsay')) {
            $this->exec('cowsay -f unipony-smaller "{$message}"', $output);
            $message = implode("\n", $output);
        }

        $this->output->writeln('<fg=red>' . $message . '</fg=red>');

        return 1;
    }

    /**
     * @return array
     */
    protected function getPHPChangedFiles(): array
    {
        $changed = [];
        foreach ($this->getChangedFiles() as $path) {
            if (Str::endsWith($path, '.php') && !Str::endsWith($path, '.blade.php')) {
                $changed[] = $path;
            }
        }
        return $changed;
    }

    /**
     * Check psr2 code standard
     * @param array $changed
     * @return bool
     */
    protected function psr2(array $changed): bool
    {
        $ignored = config('pre-commit.psr2.ignored');

        $options = [
            '--standard=' . config('pre-commit.psr2.standard'),
            '--ignore=' . implode(',', $ignored),
            '--report=' . config('pre-commit.psr2.report'),
        ];

        if ($this->option('ansi')) {
            $options[] = '--colors';
        }

        $cmd = implode(' ', [
            base_path('vendor/bin/phpcs'),
            implode(' ', $options),
            implode(' ', $changed),
        ]);
        $status = $this->exec($cmd, $outputs);

        if (!$this->option('quiet') && $outputs) {
            $this->output->writeln($outputs);
        }

        return $status;
    }


    /**
     * @param array $changed
     * @return bool
     */
    protected function fixer(array $changed): bool
    {
        $options = [
            '--standard=' . config('pre-commit.psr2.standard'),
        ];
        $cmd = implode(' ', [
            base_path('vendor/bin/phpcbf'),
            implode(' ', $changed),
            implode(' ', $options),
        ]);

        $status = $this->exec($cmd, $outputs, $code);
        if (!$this->option('quiet') && $outputs) {
            $this->output->writeln($outputs);
        }
        if ($code === 1 || $code === 0) {
            $this->exec(implode(' ', [
                'git add ',
                implode(' ', $changed)
            ]));
            $status = true;
        }
        return $status;
    }

    /**
     * @param array $changed
     * @return bool
     */
    protected function lint(array $changed): bool
    {
        $process = $this->openParallelLintProcess($pipes);

        foreach ($changed as $path) {
            fwrite($pipes[0], $path . "\n");
        }

        fclose($pipes[0]);

        if (false === $output = stream_get_contents($pipes[1])) {
            throw new RuntimeException('Unable to get the lint result');
        }

        if (!$this->option('quiet') && trim($output)) {
            $this->output->writeln(trim($output));
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    /**
     * @param null $pipes
     * @return false|resource
     */
    protected function openParallelLintProcess(&$pipes = null)
    {
        $options = [
            '--stdin',
            '--no-progress',
        ];

        if ($this->option('ansi')) {
            $options[] = '--colors';
        }

        $cmd = base_path('vendor/bin/parallel-lint') . ' ' . implode(' ', $options);

        return $this->openProcess($cmd, $pipes);
    }

    /**
     * @param string $cmd
     * @param null $pipes
     * @return false|resource
     */
    protected function openProcess(string $cmd, &$pipes = null)
    {
        $descriptionOrSpec = [
            0 => ['pipe', 'r'],  // stdin is a pipe that the child will read from
            1 => ['pipe', 'w'],  // stdout is a pipe that the child will write to
            2 => ['pipe', 'w'],  // stderr is a pipe that the child will write to
        ];

        return proc_open($cmd, $descriptionOrSpec, $pipes);
    }

    /**
     * @return array
     */
    public function getChangedFiles(): array
    {
        if (!$this->exec($cmd = 'git status --short', $outputs, $code)) {
            throw new RuntimeException('Unable to run command: ' . $cmd);
        }
        $changed = [];
        foreach ($outputs as $line) {
            if ($path = $this->parseGitStatus($line)) {
                $changed[] = $path;
            }
        }
        return $changed;
    }

    /**
     * Execute command
     * @param $command
     * @param null $outputs
     * @param int|null $code
     * @return bool
     */
    protected function exec($command, &$outputs = null, ?int &$code = null): bool
    {
        exec($command, $outputs, $code);
        return $code == 0;
    }

    /**
     * @param string $line
     * @return string|null
     */
    protected function parseGitStatus(string $line): ?string
    {
        if (!preg_match('/^(M|A|AM)\s(.*)$/', trim($line), $matches)) {
            return null;
        }

        [, $first, $path] = $matches;

        if (!in_array($first, ['M', 'A'])) {
            return null;
        }

        return trim($path);
    }
}
