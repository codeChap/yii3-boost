<?php

declare(strict_types=1);

return [
    'codechap/yii3-boost' => [
        'serverName' => 'Yii3 AI Boost',
        'serverVersion' => '1.0.0',
        'tools' => [
            'application_info' => true,
            'database_schema' => true,
            'database_query' => false,
            'config_inspector' => true,
            'route_inspector' => true,
            'service_inspector' => true,
            'log_inspector' => true,
            'semantic_search' => true,
            'model_inspector' => true,
            'console_command_inspector' => true,
            'migration_inspector' => true,
            'middleware_inspector' => true,
            'performance_profiler' => true,
            'psalm' => true,
            'tinker' => false,
            'env_inspector' => true,
            'dev_server' => true,
        ],
        'modelPaths' => ['src/Model', 'src/Entity'],
        'modelParentClass' => 'Yiisoft\\ActiveRecord\\ActiveRecord',
        'additionalTools' => [],
    ],
];
