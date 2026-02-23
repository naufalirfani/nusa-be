<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Signing Keys Paths
    |--------------------------------------------------------------------------
    |
    | Paths to the OpenSSL private key and self-signed certificate used to
    | digitally sign generated PDF certificates.
    |
    | Generate with:
    |   php artisan certificate:generate-keys
    |
    */
    'private_key_path'     => env('CERT_PRIVATE_KEY_PATH', storage_path('certs/private.pem')),
    'certificate_path'     => env('CERT_CERTIFICATE_PATH', storage_path('certs/cert.pem')),
    'private_key_password' => env('CERT_KEY_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Signature Metadata
    |--------------------------------------------------------------------------
    |
    | Metadata embedded inside the PDF digital signature field. These values
    | appear in PDF viewers (e.g. Adobe Acrobat) when users click on the
    | visible signature block.
    |
    */
    'signer_name'  => env('CERT_SIGNER_NAME', env('APP_NAME', 'Corporate University')),
    'location'     => env('CERT_LOCATION', 'Indonesia'),
    'reason'       => env('CERT_REASON', 'Sertifikat resmi Corporate University'),
    'contact_info' => env('CERT_CONTACT', env('APP_URL', 'http://localhost')),

    /*
    |--------------------------------------------------------------------------
    | Visible Signature Appearance
    |--------------------------------------------------------------------------
    |
    | Coordinates (in design pixels) of the visible signature box that is
    | placed on the PDF. Set 'visible' to false to use an invisible signature.
    |
    */
    'signature_visible' => env('CERT_SIGNATURE_VISIBLE', true),
    'signature_x'       => env('CERT_SIGNATURE_X', 20),   // px from left
    'signature_y'       => env('CERT_SIGNATURE_Y', 20),   // px from top
    'signature_width'   => env('CERT_SIGNATURE_W', 200),  // px
    'signature_height'  => env('CERT_SIGNATURE_H', 60),   // px

    /*
    |--------------------------------------------------------------------------
    | Certificate Key Settings (for key generation)
    |--------------------------------------------------------------------------
    */
    'key_bits'       => 2048,
    'digest_alg'     => 'sha256',
    'valid_days'     => 3650, // 10 years
    'distinguished_name' => [
        'countryName'            => env('CERT_DN_COUNTRY', 'ID'),
        'stateOrProvinceName'    => env('CERT_DN_STATE', 'DKI Jakarta'),
        'localityName'           => env('CERT_DN_LOCALITY', 'Jakarta'),
        'organizationName'       => env('CERT_DN_ORG', env('APP_NAME', 'Corporate University')),
        'organizationalUnitName' => env('CERT_DN_OU', 'IT Department'),
        'commonName'             => env('CERT_DN_CN', env('APP_NAME', 'Corporate University')),
        'emailAddress'           => env('CERT_DN_EMAIL', env('MAIL_FROM_ADDRESS', 'admin@example.com')),
    ],

];
