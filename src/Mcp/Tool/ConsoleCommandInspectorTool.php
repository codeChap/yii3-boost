<?php

declare(strict_types=1);

namespace codechap\yii3boost\Mcp\Tool;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Console Command Inspector Tool
 *
 * Discovers and inspects Symfony Console commands (Yii3 uses Symfony Console)
 * including:
 * - All registered console commands
 * - Command arguments and options
 * - Help text and descriptions
 */
final class ConsoleCommandInspectorTool extends AbstractTool
{
    public function __construct(
        private readonly Application $application,
    ) {
    }

    public function getName(): string
    {
        return 'console_command_inspector';
    }

    public function getDescription(): string
    {
        return 'Discover and inspect console commands, their arguments, options, and help text';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'Command name to inspect (e.g. "migrate/up", "cache/clear"). Omit to list all commands.',
                ],
                'include' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'What to include: arguments, options, help, all. Defaults to [arguments, help].',
                ],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $commandName = $arguments['command'] ?? null;
        $include = $arguments['include'] ?? ['arguments', 'help'];

        $includeAll = $include === [] || in_array('all', $include, true);

        try {
            if ($commandName === null) {
                return $this->listCommands();
            }

            return $this->inspectCommand($commandName, $include, $includeAll);
        } catch (\Throwable $e) {
            return [
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ];
        }
    }

    /**
     * List all non-hidden registered commands.
     *
     * @return array{commands: list<array{name: string, description: string, aliases: list<string>}>, total: int}
     */
    private function listCommands(): array
    {
        $allCommands = $this->application->all();
        $commands = [];

        foreach ($allCommands as $name => $command) {
            if ($command->isHidden()) {
                continue;
            }

            // Avoid duplicates: Symfony registers commands under both name and aliases.
            // Only include the entry where the key matches the canonical name.
            if ($name !== $command->getName()) {
                continue;
            }

            $commands[] = [
                'name' => $command->getName(),
                'description' => $command->getDescription(),
                'aliases' => $command->getAliases(),
            ];
        }

        // Sort by name for consistent output
        usort($commands, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'commands' => $commands,
            'total' => count($commands),
        ];
    }

    /**
     * Inspect a specific command in detail.
     */
    private function inspectCommand(string $commandName, array $include, bool $includeAll): array
    {
        if (!$this->application->has($commandName)) {
            // Suggest similar commands
            $allCommands = $this->application->all();
            $available = [];
            foreach ($allCommands as $cmd) {
                if (!$cmd->isHidden()) {
                    $available[] = $cmd->getName();
                }
            }
            $available = array_unique($available);
            sort($available);

            return [
                'error' => "Command '{$commandName}' not found",
                'available_commands' => $available,
            ];
        }

        $command = $this->application->find($commandName);
        $definition = $command->getDefinition();

        $result = [
            'name' => $command->getName(),
            'description' => $command->getDescription(),
            'aliases' => $command->getAliases(),
            'hidden' => $command->isHidden(),
        ];

        if ($includeAll || in_array('help', $include, true)) {
            $result['help'] = $command->getHelp() ?: '(no help text available)';
        }

        if ($includeAll || in_array('arguments', $include, true)) {
            $result['arguments'] = $this->extractArguments($definition->getArguments());
        }

        if ($includeAll || in_array('options', $include, true)) {
            $result['options'] = $this->extractOptions($definition->getOptions());
        }

        // Always include a usage synopsis
        $result['usage'] = $command->getSynopsis();

        return $result;
    }

    /**
     * Extract argument details from InputArgument array.
     *
     * @param InputArgument[] $arguments
     * @return list<array{name: string, description: string, required: bool, default: mixed}>
     */
    private function extractArguments(array $arguments): array
    {
        $result = [];

        foreach ($arguments as $argument) {
            $result[] = [
                'name' => $argument->getName(),
                'description' => $argument->getDescription(),
                'required' => $argument->isRequired(),
                'default' => $argument->getDefault(),
            ];
        }

        return $result;
    }

    /**
     * Extract option details from InputOption array.
     *
     * @param InputOption[] $options
     * @return list<array{name: string, shortcut: string|null, description: string, value_required: bool, default: mixed}>
     */
    private function extractOptions(array $options): array
    {
        $result = [];

        foreach ($options as $option) {
            $result[] = [
                'name' => '--' . $option->getName(),
                'shortcut' => $option->getShortcut() ? '-' . $option->getShortcut() : null,
                'description' => $option->getDescription(),
                'value_required' => $option->isValueRequired(),
                'default' => $option->getDefault(),
            ];
        }

        return $result;
    }
}
