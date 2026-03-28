<?php

declare(strict_types=1);

use codechap\yii3boost\Command\McpCommand;
use codechap\yii3boost\Command\InstallCommand;
use codechap\yii3boost\Command\InfoCommand;
use codechap\yii3boost\Command\UpdateCommand;

return [
    'yiisoft/yii-console' => [
        'commands' => [
            'boost:mcp' => McpCommand::class,
            'boost:install' => InstallCommand::class,
            'boost:info' => InfoCommand::class,
            'boost:update' => UpdateCommand::class,
        ],
    ],
];
