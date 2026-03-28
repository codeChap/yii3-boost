<?php

declare(strict_types=1);

namespace codechap\yii3boost\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Yiisoft\Aliases\Aliases;

/**
 * Installation wizard — generates MCP config files for IDE integration.
 */
#[AsCommand(
    name: 'boost:install',
    description: 'Install Yii3 AI Boost — generate MCP configuration for your IDE',
)]
final class InstallCommand extends Command
{
    public function __construct(
        private readonly Aliases $aliases,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rootPath = $this->aliases->get('@root');

        $output->writeln('<info>Yii3 AI Boost — Installation Wizard</info>');
        $output->writeln('');

        // Generate .mcp.json for Claude Code / Cursor / VS Code
        $mcpConfig = [
            'mcpServers' => [
                'yii3-ai-boost' => [
                    'command' => 'php',
                    'args' => ['yii', 'boost:mcp'],
                ],
            ],
        ];

        $mcpPath = $rootPath . '/.mcp.json';
        file_put_contents(
            $mcpPath,
            json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
        $output->writeln("  Created <comment>$mcpPath</comment>");

        $output->writeln('');
        $output->writeln('<info>Installation complete.</info>');
        $output->writeln('Run <comment>./yii boost:mcp</comment> to start the MCP server.');

        return Command::SUCCESS;
    }
}
