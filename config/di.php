<?php

declare(strict_types=1);

use codechap\yii3boost\Mcp\Server;
use codechap\yii3boost\Mcp\Transport\StdioTransport;
use codechap\yii3boost\Mcp\Transport\TransportInterface;

/** @var array $params */

return [
    TransportInterface::class => StdioTransport::class,

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
