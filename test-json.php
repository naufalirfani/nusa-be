<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== Test desain_sertifikat JSON handling ===\n\n";

$kegiatan = \App\Models\Kegiatan::first();

if (!$kegiatan) {
    echo "Belum ada data kegiatan di database.\n";
    exit;
}

echo "1. Raw dari database (string):\n";
echo substr($kegiatan->getAttributes()['desain_sertifikat'] ?? 'null', 0, 200) . "...\n\n";

echo "2. Setelah cast (array/object):\n";
echo "Type: " . gettype($kegiatan->desain_sertifikat) . "\n";
if (is_array($kegiatan->desain_sertifikat)) {
    echo "Keys: " . implode(', ', array_keys($kegiatan->desain_sertifikat)) . "\n";
    echo "Width: " . ($kegiatan->desain_sertifikat['width'] ?? 'N/A') . "\n";
    echo "Height: " . ($kegiatan->desain_sertifikat['height'] ?? 'N/A') . "\n";
    echo "Total elements: " . count($kegiatan->desain_sertifikat['elements'] ?? []) . "\n\n";
}

echo "3. JSON Response (seperti yang diterima client):\n";
echo json_encode([
    'success' => true,
    'data' => [
        'id' => $kegiatan->id,
        'nama_kegiatan' => $kegiatan->nama_kegiatan,
        'desain_sertifikat' => $kegiatan->desain_sertifikat
    ]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
