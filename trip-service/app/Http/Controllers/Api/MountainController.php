<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mountain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MountainController extends Controller
{
    public function index()
    {
        $mountains = Mountain::with('images')->get();
        return response()->json([
            'success' => true,
            'data' => $mountains
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nama_gunung' => 'required|string',
            'lokasi' => 'required|string',
            'link_map' => 'required|string',
            'ketinggian' => 'required|integer',
            'status_gunung' => 'required|in:mudah,menengah,sulit',
            'status_pendakian' => 'required|in:pemula,mahir,ahli',
            'deskripsi' => 'required|string',
            'peraturan' => 'required|array',
            'images' => 'required|array',
            'images.*' => 'required|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()
            ], 422);
        }

        try {
            $mountain = Mountain::create($request->except('images'));

            // Store images
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $image) {
                    $path = $image->store('mountains', 'public');
                    $mountain->images()->create([
                        'image_path' => $path
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Data gunung berhasil ditambahkan',
                'data' => $mountain->load('images')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        $mountain = Mountain::with('images')->find($id);

        if (!$mountain) {
            return response()->json([
                'success' => false,
                'message' => 'Data gunung tidak ditemukan'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $mountain
        ]);
    }

    public function update(Request $request, $id)
    {
        $mountain = Mountain::find($id);

        if (!$mountain) {
            return response()->json([
                'success' => false,
                'message' => 'Data gunung tidak ditemukan'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'nama_gunung' => 'required|string',
            'lokasi' => 'required|string',
            'link_map' => 'required|string',
            'ketinggian' => 'required|integer',
            'status_gunung' => 'required|in:mudah,menengah,sulit',
            'status_pendakian' => 'required|in:pemula,mahir,ahli',
            'deskripsi' => 'required|string',
            'peraturan' => 'required',
            'existing_images' => 'nullable',
            'images.*' => 'nullable|image|mimes:jpeg,png,jpg|max:2048'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()
            ], 422);
        }

        try {
            // Update basic mountain data
            $mountain->update([
                'nama_gunung' => $request->nama_gunung,
                'lokasi' => $request->lokasi,
                'link_map' => $request->link_map,
                'ketinggian' => $request->ketinggian,
                'status_gunung' => $request->status_gunung,
                'status_pendakian' => $request->status_pendakian,
                'deskripsi' => $request->deskripsi,
                'peraturan' => json_decode($request->peraturan),
            ]);

            // Handle images
            if ($request->hasFile('images')) {
                // Upload new images
                foreach ($request->file('images') as $image) {
                    $path = $image->store('mountains', 'public');
                    $mountain->images()->create([
                        'image_path' => $path
                    ]);
                }
            }

            // Handle existing images
            if ($request->has('existing_images')) {
                $existingImages = json_decode($request->existing_images);
                $currentImages = $mountain->images->pluck('image_path')->toArray();

                // Delete images that are not in existing_images
                foreach ($currentImages as $currentImage) {
                    if (!in_array($currentImage, $existingImages)) {
                        Storage::disk('public')->delete($currentImage);
                        $mountain->images()->where('image_path', $currentImage)->delete();
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Data gunung berhasil diperbarui',
                'data' => $mountain->load('images')
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        $mountain = Mountain::with('images')->find($id);

        if (!$mountain) {
            return response()->json([
                'success' => false,
                'message' => 'Data gunung tidak ditemukan'
            ], 404);
        }

        // Delete all images from storage
        foreach ($mountain->images as $image) {
            Storage::disk('public')->delete($image->image_path);
        }

        $mountain->delete();

        return response()->json([
            'success' => true,
            'message' => 'Data gunung berhasil dihapus'
        ]);
    }
}