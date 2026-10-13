<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Onboarding;

class OnboardingController extends Controller
{
    public function index(Request $request)
    {
        return response()->json([
            'data' => Onboarding::first()
        ]);
    }

    public function show($id)
    {
        return response()->json([
            'data' => Onboarding::findOrFail($id)
        ]);
    }

    public function store(Request $request)
    {
        $existingImages = $request->existing_images ?? [];

        $uploadedImages = [];

        if($request->hasFile('images')) {

            foreach ($request->file('images') as $image) {

                $fileName = time() . '_' . uniqid() . '.' . $image->extension();

                $image->move(
                    base_path('uploads'),
                    $fileName
                );

                $uploadedImages[] = $fileName;
            }
        }

        // Merge old + new images
        $allImages = array_merge(
            $existingImages,
            $uploadedImages
        );

        $onboarding = Onboarding::first();

        if($onboarding) {

            $onboarding->update([
                'images' => $allImages
            ]);

        } else {

            $onboarding = Onboarding::create([
                'images' => $allImages
            ]);
        }

        return response()->json([
            'message' => 'Onboarding saved successfully',
            'data' => $onboarding
        ]);
    }
}
