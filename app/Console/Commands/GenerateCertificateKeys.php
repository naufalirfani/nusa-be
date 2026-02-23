<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateCertificateKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'certificate:generate-keys
                            {--force : Overwrite existing keys without asking}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate OpenSSL private key and self-signed certificate for PDF digital signing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // (Windows) Locate openssl.cnf so we can pass it explicitly in $config.

        $privateKeyPath  = config('certificate.private_key_path');
        $certificatePath = config('certificate.certificate_path');
        $password        = config('certificate.private_key_password', '');
        $keyBits         = (int) config('certificate.key_bits', 2048);
        $digestAlg       = config('certificate.digest_alg', 'sha256');
        $validDays       = (int) config('certificate.valid_days', 3650);
        $dn              = config('certificate.distinguished_name', []);

        // --- Guard: existing files ---
        if ((file_exists($privateKeyPath) || file_exists($certificatePath)) && ! $this->option('force')) {
            $this->warn('Keys already exist at:');
            $this->line("  Private key : {$privateKeyPath}");
            $this->line("  Certificate : {$certificatePath}");

            if (! $this->confirm('Overwrite?', false)) {
                $this->info('Aborted. No files were changed.');
                return self::SUCCESS;
            }
        }

        // --- Create directories ---
        foreach ([dirname($privateKeyPath), dirname($certificatePath)] as $dir) {
            if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
                $this->error("Could not create directory: {$dir}");
                return self::FAILURE;
            }
        }

        // --- Generate private key ---
        $this->info("Generating {$keyBits}-bit RSA private key…");

        $config = [
            'digest_alg'       => $digestAlg,
            'private_key_bits' => $keyBits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        // Resolve the openssl.cnf config file path and inject it into every
        // call.  This is required on Windows where the default OPENSSL_CONF
        // path (C:\Program Files\Common Files\SSL\openssl.cnf) often does not
        // exist, causing a cryptic "No such process" error.
        $opensslCnf = $this->resolveOpensslCnf();
        if ($opensslCnf) {
            $config['config'] = $opensslCnf;
        }

        $privateKey = openssl_pkey_new($config);
        if (! $privateKey) {
            $this->error('openssl_pkey_new() failed: ' . openssl_error_string());
            return self::FAILURE;
        }

        // --- Export private key ---
        $passphrase = $password !== '' ? $password : null;
        openssl_pkey_export($privateKey, $privateKeyPem, $passphrase, $config);
        file_put_contents($privateKeyPath, $privateKeyPem);
        chmod($privateKeyPath, 0600);
        $this->line("  ✔ Private key saved  → {$privateKeyPath}");
        if ($passphrase) {
            $this->line('  ✔ Private key is passphrase-protected (CERT_KEY_PASSWORD is set)');
        }

        // --- Build CSR ---
        $csr = openssl_csr_new($dn, $privateKey, $config);
        if (! $csr) {
            $this->error('openssl_csr_new() failed: ' . openssl_error_string());
            return self::FAILURE;
        }

        // --- Self-sign the certificate ---
        $this->info("Self-signing certificate (valid for {$validDays} days)…");
        $cert = openssl_csr_sign($csr, null, $privateKey, $validDays, $config);
        if (! $cert) {
            $this->error('openssl_csr_sign() failed: ' . openssl_error_string());
            return self::FAILURE;
        }

        openssl_x509_export($cert, $certPem);
        file_put_contents($certificatePath, $certPem);
        chmod($certificatePath, 0644);
        $this->line("  ✔ Certificate saved  → {$certificatePath}");

        // --- Summary ---
        $this->newLine();
        $this->info('Done! Add these to your .env if you use non-default paths:');
        $this->line("  CERT_PRIVATE_KEY_PATH={$privateKeyPath}");
        $this->line("  CERT_CERTIFICATE_PATH={$certificatePath}");
        if ($password !== '') {
            $this->line("  CERT_KEY_PASSWORD=<your-password>");
        }
        $this->newLine();
        $this->warn('⚠  Keep private.pem SECRET. Never commit it to version control.');
        $this->warn('⚠  Add storage/certs/ to your .gitignore.');

        return self::SUCCESS;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Ensure the OPENSSL_CONF environment variable points to a valid config
     * file.  On Windows this file is often missing from the default path
     * (C:\Program Files\Common Files\SSL\openssl.cnf).
     *
     * We look for the openssl.cnf that ships alongside PHP itself at
     * {PHP_BINARY_DIR}/extras/ssl/openssl.cnf.
     */
    /**
     * Locate the openssl.cnf file so it can be passed explicitly to PHP OpenSSL
     * functions via the 'config' array key.  On Windows the environment-variable
     * default often points to a non-existent path.
     *
     * @return string|null Absolute path, or null if not found.
     */
    private function resolveOpensslCnf(): ?string
    {
        // 1. Respect an explicitly set OPENSSL_CONF.
        $env = getenv('OPENSSL_CONF');
        if ($env && file_exists($env)) {
            return $env;
        }

        // 2. Look next to the PHP binary (php-x.y.z/extras/ssl/openssl.cnf).
        $phpDir  = dirname(PHP_BINARY);
        $guesses = [
            $phpDir . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            $phpDir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/local/etc/openssl/openssl.cnf',
            '/usr/local/etc/openssl@3/openssl.cnf',
        ];

        foreach ($guesses as $path) {
            $real = realpath($path);
            if ($real && file_exists($real)) {
                $this->line("  ℹ  Using openssl.cnf: {$real}");
                return $real;
            }
        }

        $this->warn('openssl.cnf not found automatically. Key generation may fail on Windows.');
        return null;
    }
}
