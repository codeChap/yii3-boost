<?php

declare(strict_types=1);

namespace codechap\yii3boost\Command;

use codechap\yii3boost\Mcp\Server;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display package info, version, and tool status.
 */
#[AsCommand(
    name: 'boost:info',
    description: 'Show Yii3 AI Boost package information',
)]
final class InfoCommand extends Command
{
    public function __construct(
        private readonly Server $server,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Yii3 AI Boost</info> v' . Server::VERSION);
        $output->writeln('MCP Server for Yii3 Applications');
        $output->writeln('');

        // Trigger tool resolution to get available/unavailable info
        $listResult = $this->server->handleRequest(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        $response = json_decode($listResult, true);
        $tools = $response['result']['tools'] ?? [];
        $unavailable = $this->server->getUnavailableTools();

        $output->writeln('<comment>Available tools (' . count($tools) . '):</comment>');
        foreach ($tools as $tool) {
            $output->writeln('  + ' . $tool['name']);
        }

        if (!empty($unavailable)) {
            $output->writeln('');
            $output->writeln('<comment>Unavailable tools (' . count($unavailable) . '):</comment>');
            foreach ($unavailable as $name => $reason) {
                $output->writeln("  - $name: $reason");
            }
        }

        return Command::SUCCESS;
    }
}
