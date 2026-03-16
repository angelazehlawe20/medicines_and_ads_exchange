<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    use ShefaaTrait;

    public function register(Request $request)
    {
        $rules = [
            'username' => 'required|string|unique:users,username',
            'email' => 'nullable|unique:users,email',
            'password' => 'required|string|min:6|confirmed',
            'phone' => 'nullable|string|max:20|unique:users,phone',
            'role' => 'required|in:admin,citizen,pharmacy,specialist,delivery',
            'governorate' => 'required|in:Damascus,Aleppo,Homs,Hama,Lattakia,Tartous,Daraa,Deir ez-Zor,Hasakah,Raqqa,Suwayda,Quneitra,Rif Dimashq',
        ];

        switch ($request->role) {
            case 'citizen':
                $rules = array_merge($rules, [
                    'address' => 'required|string',
                ]);
                break;

            case 'pharmacy':
                $rules = array_merge($rules, [
                    'pharmacy_name' => 'required|string|max:255',
                ]);
                break;

            case 'specialist':
                $rules = array_merge($rules, [
                    'pharmacy_name' => 'nullable|string|max:255',
                    'pharmacy_address' => 'nullable|string|max:255',
                ]);
                break;
        }

        $validation = Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        try {
            return DB::transaction(function () use ($request) {
                $user = User::create([
                    'username' => $request->username,
                    'password' => bcrypt($request->password),
                    'email' => $request->email,
                    'phone' => $request->phone,
                    'role' => $request->role,
                    'governorate' => $request->governorate
                ]);

                if ($request->role === 'citizen') {
                    $user->citizen()->create([
                        'address' => $request->address,
                    ]);
                } elseif ($request->role === 'pharmacy') {
                    $user->pharmacy()->create([
                        'pharmacy_name' => $request->pharmacy_name,
                        'governorate' => $request->governorate,
                    ]);
                } elseif ($request->role === 'specialist') {
                    $user->specialist()->create([
                        'pharmacy_name' => $request->pharmacy_name,
                        'pharmacy_address' => $request->pharmacy_address,
                        'governorate' => $request->governorate,
                    ]);
                } elseif ($request->role === 'admin') {
                    $user->admin()->create();
                } elseif ($request->role === 'delivery') {
                    $user->delivery()->create([
                        'governorate' => $request->governorate,
                    ]);
                }

                $token = $user->createToken('auth_token')->plainTextToken;
                return $this->SuccessResponse(['token' => $token, 'user' => $user], __('authTranslate.registered_success'), 201);
            });
        } catch (\Exception $e) {
            return $this->ErrorResponse(__('authTranslate.registration_failed') . $e->getMessage(), 500);
        }
    }

    public function login(Request $request)
    {
        $validation = Validator::make($request->all(), [
            'username' => 'required|string',
            'password' => 'required|string|min:6',
            'fcm_token' => 'nullable|string'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->ErrorResponse(__('authTranslate.invalid_data'), 401);
        }

        if ($user->account_status !== 1) {
            return $this->ErrorResponse(__('authTranslate.banned'), 403);
        }

        if ($request->filled('fcm_token')) {
            $user->update(['fcm_token' => $request->fcm_token]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return $this->SuccessResponse(['token' => $token, 'role' => $user->role], __('authTranslate.login_success'), 200);
    }

    public function getMyProfile()
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $relation = match ($user->role) {
            'admin' => 'admin',
            'pharmacy' => 'pharmacy',
            'specialist' => 'specialist',
            'delivery' => 'delivery',
            'citizen' => 'citizen',
            default => null,
        };

        $userData = User::with($relation)->find($user->id);
        return $this->SuccessResponse($userData, __('authTranslate.your_profile'), 200);
    }

    public function updateMyProfile(Request $request)
    {
        $user = auth()->user();

        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $rules = [
            'username' => 'sometimes|string|unique:users,username,' . $user->id,
            'phone' => 'sometimes|string|max:20|unique:users,phone,' . $user->id,
            'email' => 'sometimes|string|email|unique:users,email,' . $user->id,
            'governorate' => 'sometimes|in:Damascus,Aleppo,Homs,Hama,Lattakia,Tartous,Daraa,Deir ez-Zor,Hasakah,Raqqa,Suwayda,Quneitra,Rif Dimashq',
        ];

        switch ($user->role) {
            case 'citizen':
                $rules = array_merge($rules, ['address' => 'nullable|string']);
                break;
            case 'pharmacy':
                $rules = array_merge($rules, ['pharmacy_name' => 'nullable|string|max:255']);
                break;
            case 'specialist':
                $rules = array_merge($rules, [
                    'pharmacy_name' => 'nullable|string|max:255',
                    'pharmacy_address' => 'nullable|string|max:255'
                ]);
                break;
        }

        $validation = Validator::make($request->all(), $rules);
        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        try {
            DB::transaction(function () use ($user, $request) {
                $user->update($request->only(['username', 'email', 'phone', 'governorate']));

                if ($user->role === 'citizen' && $user->citizen) {
                    $user->citizen->update($request->only(['address']));
                } elseif ($user->role === 'pharmacy' && $user->pharmacy) {
                    $user->pharmacy->update($request->only(['pharmacy_name', 'governorate']));
                } elseif ($user->role === 'specialist' && $user->specialist) {
                    $user->specialist->update($request->only(['pharmacy_name', 'pharmacy_address', 'governorate']));
                } elseif ($user->role === 'delivery' && $user->delivery) {
                    $user->delivery->update($request->only(['governorate']));
                }
            });

            return $this->SuccessResponse($user->load($user->role), __('authTranslate.updated_success'), 200);
        } catch (\Exception $e) {
            return $this->ErrorResponse('Update failed: ' . $e->getMessage(), 500);
        }
    }
}
