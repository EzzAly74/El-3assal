<?php
return [
    'cache' => [
        'graphql' => [
            'id_salt' => 'SvziYeSYGeRO36chjxNpfkbO9erEpT1l'
        ],
        'frontend' => [
            'default' => [
                'id_prefix' => 'c7e_'
            ],
            'page_cache' => [
                'id_prefix' => 'c7e_'
            ]
        ],
        'allow_parallel_generation' => false
    ],
    'queue' => [
        'consumers_wait_for_messages' => 1
    ],
    'remote_storage' => [
        'driver' => 'file'
    ],
    'config' => [
        'async' => 0
    ],
    'backend' => [
        'frontName' => 'admin_qq40zjs'
    ],
    'crypt' => [
        'key' => 'base64zZn631N22mql8YT0VJoZnglIQ3IYXLGPjqJP/YHlaCY='
    ],
    'db' => [
        'table_prefix' => '',
        'connection' => [
            'default' => [
                'host' => 'localhost',
                'dbname' => 'a41aed59_elassal',
                'username' => 'a41aed59_elassal',
                'password' => 'ThroveItalicLeavenGraph',
                'model' => 'mysql4',
                'engine' => 'innodb',
                'initStatements' => 'SET NAMES utf8;',
                'active' => '1',
                'driver_options' => [
                    1014 => false
                ]
            ]
        ]
    ],
    'resource' => [
        'default_setup' => [
            'connection' => 'default'
        ]
    ],
    'x-frame-options' => 'SAMEORIGIN',
    'MAGE_MODE' => 'production',
    'session' => [
        'save' => 'files'
    ],
    'lock' => [
        'provider' => 'db'
    ],
    'directories' => [
        'document_root_is_pub' => true
    ],
    'cache_types' => [
        'config' => 1,
        'layout' => 1,
        'block_html' => 1,
        'collections' => 1,
        'reflection' => 1,
        'db_ddl' => 1,
        'compiled_config' => 1,
        'eav' => 1,
        'customer_notification' => 1,
        'graphql_query_resolver_result' => 1,
        'config_integration' => 1,
        'config_integration_api' => 1,
        'full_page' => 1,
        'config_webservice' => 1,
        'translate' => 1
    ],
    'downloadable_domains' => [
        '051258281d.nxcli.io'
    ],
    'install' => [
        'date' => 'Fri, 21 Aug 2026 15:45:11 +0000'
    ]
];
