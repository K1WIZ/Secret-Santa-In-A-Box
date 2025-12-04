<?php
// src/config.php
// Docker-friendly configuration: reads from environment variables.

return [
    'db' => [
        'dsn' => sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            getenv('DB_HOST') ?: 'db',
            getenv('DB_PORT') ?: '3306',
            getenv('DB_NAME') ?: 'secret_santa'
        ),
        'user'     => getenv('DB_USER') ?: 'secretsanta',
        'password' => getenv('DB_PASSWORD') ?: 'secretsanta_pass',
    ],
    'smtp' => [
        // For dev with MailHog, these are overridden via docker-compose env.
        'host'       => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
        'port'       => getenv('SMTP_PORT') ?: 587,
        'username'   => getenv('SMTP_USER') ?: '', // yourgmail@gmail.com
        'password'   => getenv('SMTP_PASS') ?: '', // your_app_password_here
        'from_email' => getenv('SMTP_FROM_EMAIL') ?: 'yourgmail@gmail.com',
        'from_name'  => getenv('SMTP_FROM_NAME') ?: 'Secret Santa Bot',
    ],
    'app' => [
        // Public base URL (used for links in emails)
	'base_url' => rtrim(getenv('APP_BASE_URL') ?: 'http://localhost:8080', '/'),
	'timezone' => getenv('APP_TZ') ?: 'America/New_York',
    ],
];

