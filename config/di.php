<?php

declare(strict_types=1);

use codechap\yii3boost\Mcp\Server;
use codechap\yii3boost\Mcp\Transport\StdioTransport;
use codechap\yii3boost\Mcp\Transport\TransportInterface;

/** @var array $params */

return [
    TransportInterface::class => StdioTransport::class,

    // The host's console command map (the `yiisoft/yii-console` `commands` param) is wired onto
    // Yiisoft\Yii\Console\Application via its command loader — NOT onto the base Symfony Application.
    // ConsoleCommandInspectorTool type-hints the base Symfony\Component\Console\Application, which the
    // container would otherwise autowire as a fresh, empty instance (only completion/help/list). Alias
    // the base class to the configured Yii console app so the inspector sees every registered command.
    \Symfony\Component\Console\Application::class => \Yiisoft\Yii\Console\Application::class,

    Server::class => [
        'class' => Server::class,
        '__construct()' => [
            'serverName' => $params['codechap/yii3-boost']['serverName'],
            'serverVersion' => $params['codechap/yii3-boost']['serverVersion'],
            'enabledTools' => $params['codechap/yii3-boost']['tools'],
            'additionalTools' => $params['codechap/yii3-boost']['additionalTools'],
        ],
    ],
];
