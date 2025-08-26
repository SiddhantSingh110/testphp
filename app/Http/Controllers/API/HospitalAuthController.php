<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Hospital;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class HospitalAuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:hospitals',
            'phone'    => 'nullable|string',
            'password' => 'required|string|min:6'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $hospital = Hospital::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'phone'    => $request->phone,
            'password' => Hash::make($request->password),
            'is_parent' => true, // default to parent
        ]);

        $token = $hospital->createToken('hospital_token')->plainTextToken;

        return response()->json([
            'message' => 'Hospital registered successfully',
            'token' => $token,
            'user' => $hospital
        ]);
    }

    public function login(Request $request)
    {
        $hospital = Hospital::where('email', $request->email)->first();

        if (!$hospital || !Hash::check($request->password, $hospital->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $token = $hospital->createToken('hospital_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'token' => $token,
            'user' => $hospital
        ]);
    }
}
