# Yii3 Console Commands

## Command Structure

Yii3 console commands extend Symfony Console's `Command` class and use the `#[AsCommand]` attribute for metadata. The console runner is provided by `yiisoft/yii-console`.

```php
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Yii\Console\ExitCode;

#[AsCommand(
    name: 'hello',
    description: 'An example command',
)]
final class HelloCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Hello!');
        return ExitCode::OK;
    }
}
```

## Dependency Injection in Commands

Inject services via the constructor. Always call `parent::__construct()` after setting dependencies.

```php
#[AsCommand(
    name: 'crawl:start',
    description: 'Push sites to the crawl queue',
)]
final class CrawlCommand extends Command
{
    public function __construct(private readonly ConnectionInterface $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sites = (new Query($this->db))
            ->select(['id', 'url'])
            ->from('websites')
            ->where(['viable' => 1])
            ->limit(50)
            ->all();

        // Process sites...

        return ExitCode::OK;
    }
}
```

## Input Options and Arguments

Define options in `configure()` and read them in `execute()`.

```php
use Symfony\Component\Console\Input\InputOption;

protected function configure(): void
{
    $this->addOption('wid', 'w', InputOption::VALUE_REQUIRED, 'Specific website ID');
    $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max sites', '50');
}

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $wid   = $input->getOption('wid');
    $limit = (int) $input->getOption('limit');
    // ...
}
```

## Styled Output

Use `SymfonyStyle` for formatted console output.

```php
use Symfony\Component\Console\Style\SymfonyStyle;

protected function execute(InputInterface $input, OutputInterface $output): int
{
    $io = new SymfonyStyle($input, $output);
    $io->title('Queuing Crawl Jobs');
    $io->success('Queued 42 sites');
    $io->warning('No viable websites found');
    return ExitCode::OK;
}
```

## Command Registration

Commands are registered in `config/console/params.php` under the `yiisoft/yii-console` params key.

```php
// config/console/commands.php
return [
    'hello'        => Console\HelloCommand::class,
    'crawl:start'  => Console\CrawlCommand::class,
    'sync:pull'    => Console\SyncPullCommand::class,
];

// config/console/params.php
return [
    'yiisoft/yii-console' => [
        'commands' => require __DIR__ . '/commands.php',
    ],
];
```

The array key is the command name and must match the `name` in the `#[AsCommand]` attribute.

## Console Entry Point

The `yii` script in the project root bootstraps the console runner:

```php
#!/usr/bin/env php
<?php
use Yiisoft\Yii\Runner\Console\ConsoleApplicationRunner;

require_once __DIR__ . '/vendor/autoload.php';

$runner = new ConsoleApplicationRunner(
    rootPath: __DIR__,
    debug: (bool)($_ENV['APP_DEBUG'] ?? false),
    environment: $_ENV['APP_ENV'] ?? 'prod',
);
$runner->run();
```

Run commands with: `./yii crawl:start --limit=100`

## Exit Codes

Use `Yiisoft\Yii\Console\ExitCode` constants for consistent exit codes:
- `ExitCode::OK` (0) -- success
- `ExitCode::UNSPECIFIED_ERROR` (1) -- general failure
