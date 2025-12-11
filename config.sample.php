<?php
return [
    'data_path' => __DIR__ . '/data',
    'teams_path' => __DIR__ . '/data/teams',
    'lobbies_path' => __DIR__ . '/data/lobbies',
    'questions_path' => __DIR__ . '/questions/questions.json',
    'custom_questions_path' => __DIR__ . '/questions/custom',
    'default_depth' => 3,
    'default_timer_seconds' => 0,
    'round_timer_options' => [0, 60, 120, 180],
    'admin_password' => 'change-me-admin',
    'question_pools' => [
        'basic' => [
            'label' => 'Standard',
            'path' => __DIR__ . '/questions/questions.json',
            'default' => true,
        ],
        'spicy' => [
            'label' => 'Spicy',
            'path' => __DIR__ . '/questions/spicy.json',
            'default' => false,
        ],
        'university' => [
            'label' => 'Universität',
            'path' => __DIR__ . '/questions/university.json',
            'default' => false,
        ],
        'couples' => [
            'label' => 'Couples',
            'path' => __DIR__ . '/questions/couples.json',
            'default' => false,
        ],
    ],
    'openai' => [
        'api_key' => '',
        'model' => 'gpt-4o-mini',
        'rate_limit_seconds' => 30,
    ],
];
