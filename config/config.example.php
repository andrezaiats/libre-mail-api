<?php

/**
 * Example configuration file for LibreMailApi with SMTP support
 * Copy this file to config.php and modify the settings according to your needs
 */

return [
    'api' => [
        'base_url' => 'http://localhost:8080',
        'version' => 'v3',
        'default_domain' => 'sandbox.libremailapi.org',
        'auth' => [
            'username' => 'api',
            'password' => 'key-test123456789'
        ]
    ],
    'storage' => [
        'path' => __DIR__ . '/../storage',
        'retention_days' => 30
    ],
    'limits' => [
        'max_recipients' => 1000,
        'max_attachment_size' => 25 * 1024 * 1024, // 25MB
        'max_message_size' => 25 * 1024 * 1024
    ],
    'features' => [
        'tracking' => true,
        'dkim' => true,
        'testmode' => true,
        'templates' => true
    ],
    'tracking' => [
        'pixel_base_url' => getenv('TRACKING_PIXEL_BASE_URL') ?: 'https://yourdomain.com/t/o',
        'hmac_secret' => getenv('TRACKING_HMAC_SECRET') ?: 'CHANGE_ME_TO_A_RANDOM_SECRET',
    ],
    'logging' => [
        'enabled' => true,
        'level' => 'info',
        'file' => __DIR__ . '/../logs/libre-mail-api.log'
    ],

    // SMTP Configuration for actual email sending
    'smtp' => [
        // Set to true to actually send emails via SMTP
        'enabled' => false,

        // SMTP server settings
        'host' => 'smtp.gmail.com', // SMTP server hostname
        'port' => 587, // SMTP port (587 for TLS, 465 for SSL, 25 for plain)
        'encryption' => 'tls', // 'tls', 'ssl', or null for no encryption

        // Authentication
        'auth' => true, // Enable SMTP authentication
        'username' => 'your-email@gmail.com', // SMTP username (email address)
        'password' => 'your-app-password', // SMTP password or app password

        // Default sender information (used when not specified in message)
        'from_name' => 'LibreMailApi',
        'from_email' => 'your-email@gmail.com',
        
        // Connection settings
        'timeout' => 30, // Connection timeout in seconds
        'debug' => false, // Enable SMTP debug output (for troubleshooting)
        
        // SSL/TLS settings
        'verify_peer' => true, // Verify SSL certificate
        'allow_self_signed' => false // Allow self-signed certificates
    ]
];

/*
SMTP Configuration Examples:

1. Gmail with App Password:
'smtp' => [
    'enabled' => true,
    'host' => 'smtp.gmail.com',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'your-email@gmail.com',
    'password' => 'your-16-char-app-password',
    'from_name' => 'Your Name',
    'from_email' => 'your-email@gmail.com',
]

2. Outlook/Hotmail:
'smtp' => [
    'enabled' => true,
    'host' => 'smtp-mail.outlook.com',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'your-email@outlook.com',
    'password' => 'your-password',
    'from_name' => 'Your Name',
    'from_email' => 'your-email@outlook.com',
]

3. Custom SMTP Server:
'smtp' => [
    'enabled' => true,
    'host' => 'mail.yourdomain.com',
    'port' => 587,
    'encryption' => 'tls',
    'auth' => true,
    'username' => 'noreply@yourdomain.com',
    'password' => 'your-password',
    'from_name' => 'Your Company',
    'from_email' => 'noreply@yourdomain.com',
]

4. Local SMTP Server (no authentication):
'smtp' => [
    'enabled' => true,
    'host' => 'localhost',
    'port' => 25,
    'encryption' => null,
    'auth' => false,
    'username' => '',
    'password' => '',
    'from_name' => 'Local Server',
    'from_email' => 'noreply@localhost',
]

Note: For Gmail, you need to:
1. Enable 2-factor authentication
2. Generate an App Password (not your regular password)
3. Use the App Password in the configuration
*/
