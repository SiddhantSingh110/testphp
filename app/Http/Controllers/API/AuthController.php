<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use App\Models\Patient; // use Doctor or Hospital when needed
use Illuminate\Validation\ValidationException;


class AuthController extends Controller
{
    // Previous patient-related methods have been moved to PatientAuthController
    // This controller can now be used for other general auth purposes if needed
}