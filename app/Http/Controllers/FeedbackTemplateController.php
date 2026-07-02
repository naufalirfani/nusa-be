<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FeedbackTemplateController extends Controller
{
    private string $file = 'feedback-template.json';

    public function show()
    {
        if (!Storage::exists($this->file)) {
            return response()->json($this->defaultTemplate());
        }

        $json = Storage::get($this->file);

        return response()->json(json_decode($json, true));
    }

    public function store(Request $request)
    {
        Storage::put(
            $this->file,
            json_encode(
                $request->all(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            )
        );

        return response()->json([
            'success' => true,
            'message' => 'Template berhasil disimpan.'
        ]);
    }

    private function defaultTemplate(): array
    {
        return [
            "title" => "Form Penilaian Umpan Balik 360",
            "description" => "Mohon isi dengan jujur dan sebenar-benarnya karena bersifat anonim.",
            "showQuestionNumbers" => "off",
            "completedHtml" => '<h3 class="sv-title">Terima kasih, penilaian Anda sudah tersimpan.</h3>',
            "pages" => [
                [
                    "name" => "page1",
                    "title" => "Data Pegawai yang Dinilai",
                    "elements" => [
                        [
                            "type" => "text",
                            "name" => "nama_pegawai",
                            "title" => "Nama Pegawai yang Dinilai",
                            "isRequired" => true,
                            "readOnly" => true,
                        ],
                        [
                            "type" => "text",
                            "name" => "nip",
                            "title" => "NIP",
                            "isRequired" => true,
                            "readOnly" => true,
                        ],
                        [
                            "type" => "text",
                            "name" => "jabatan",
                            "title" => "Jabatan",
                            "isRequired" => true,
                            "readOnly" => true,
                        ],
                        [
                            "type" => "text",
                            "name" => "unit_kerja",
                            "title" => "Unit Kerja",
                            "isRequired" => true,
                            "readOnly" => true,
                        ],
                    ],
                ],
            ],
        ];
    }
}
