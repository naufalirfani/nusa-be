<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $directory = $request->get('directory', 'media');
        $files = Storage::disk('public')->files($directory);

        $mediaList = collect($files)->map(function ($file) {
            return [
                'path' => $file,
                'url' => url('storage/' . $file),
                'name' => basename($file),
                'size' => Storage::disk('public')->size($file),
                'mime_type' => Storage::disk('public')->mimeType($file),
                'last_modified' => Storage::disk('public')->lastModified($file),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $mediaList
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:10240', // Max 10MB
            'directory' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan'
            ], 400);
        }

        $file = $request->file('file');
        $directory = $request->get('directory', 'media');
        
        // Generate unique filename
        $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
        
        // Store file in public disk
        $path = $file->storeAs($directory, $filename, 'public');

        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupload file'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'File berhasil diupload',
            'data' => [
                'path' => $path,
                'url' => url('storage/' . $path),
                'name' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request)
    {
        $path = $request->get('path');

        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'Path file tidak ditemukan'
            ], 400);
        }

        if (!Storage::disk('public')->exists($path)) {
            Log::info("File not found: " . $path);
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'path' => $path,
                'url' => url('storage/' . $path),
                'name' => basename($path),
                'size' => Storage::disk('public')->size($path),
                'mime_type' => Storage::disk('public')->mimeType($path),
                'last_modified' => Storage::disk('public')->lastModified($path),
            ]
        ]);
    }

    /**
     * Download a file from public storage.
     * Accepts a path (supports nested folders) and returns a download response.
     */
    public function download($path)
    {
        $path = urldecode($path);

        if (!Storage::disk('public')->exists($path)) {
            Log::info("File not found for download: " . $path);
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan'
            ], 404);
        }

        return Storage::disk('public')->download($path);
    }
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'old_path' => 'required|string',
            'file' => 'required|file|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $oldPath = $request->get('old_path');

        if (!Storage::disk('public')->exists($oldPath)) {
            return response()->json([
                'success' => false,
                'message' => 'File lama tidak ditemukan'
            ], 404);
        }

        // Delete old file
        Storage::disk('public')->delete($oldPath);

        // Upload new file
        $file = $request->file('file');
        $directory = dirname($oldPath);
        
        // Generate unique filename
        $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
        
        // Store file in public disk
        $path = $file->storeAs($directory, $filename, 'public');

        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupload file baru'
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'File berhasil diupdate',
            'data' => [
                'path' => $path,
                'url' => url('storage/' . $path),
                'name' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request)
    {
        $path = $request->get('path');

        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'Path file tidak ditemukan'
            ], 400);
        }

        if (!Storage::disk('public')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => 'File tidak ditemukan'
            ], 404);
        }

        Storage::disk('public')->delete($path);

        return response()->json([
            'success' => true,
            'message' => 'File berhasil dihapus'
        ]);
    }

    /**
     * Upload multiple files at once
     */
    public function storeMultiple(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'files' => 'required|array',
            'files.*' => 'required|file|max:10240',
            'directory' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $directory = $request->get('directory', 'media');
        $uploadedFiles = [];

        foreach ($request->file('files') as $file) {
            // Generate unique filename
            $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
            
            // Store file in public disk
            $path = $file->storeAs($directory, $filename, 'public');

            if ($path) {
                $uploadedFiles[] = [
                    'path' => $path,
                    'url' => url('storage/' . $path),
                    'name' => $filename,
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => count($uploadedFiles) . ' file berhasil diupload',
            'data' => $uploadedFiles
        ], 201);
    }
}
