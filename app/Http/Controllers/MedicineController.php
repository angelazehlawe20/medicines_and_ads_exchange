<?php

namespace App\Http\Controllers;

use App\Models\Medicine;
use App\Models\Pharmacy;
use App\Traits\ShefaaTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class MedicineController extends Controller
{
    use ShefaaTrait;

    public function addMedicine(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'quantity_available' => 'required|integer|min:0',
            'expiration_date' => 'required|date|after:today',
            'category' => 'required|in:medicine,cosmetic',
            'description' => 'nullable|string',
            'category' => 'required|in:medicine,cosmetic',
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        // جلب بروفايل الصيدلية المرتبط بالمستخدم الحالي
        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (!$pharmacy) {
            return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);
        }

        $imagePath = null;
        if ($request->hasFile('image')) {
            $folderName = ($request->category === 'cosmetic') ? 'cosmetics' : 'medicines';
            $path = $request->file('image')->store($folderName, 'public');
            $imagePath = asset('storage/' . $path);
        }

        $medicine = Medicine::create([
            'pharmacy_id' => $pharmacy->id,
            'name' => $request->name,
            'price' => $request->price,
            'image' => $imagePath,
            'quantity_available' => $request->quantity_available,
            'expiration_date' => $request->expiration_date,
            'category' => $request->category,
            'description' => $request->description,
            'category' => $request->category,
        ]);

        return $this->SuccessResponse($medicine, __('medicine.added_success'), 201);
    }

    public function updateMedicine(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'medicine_id' => 'required|integer|exists:medicines,id',
            'name' => 'sometimes|string|max:255',
            'price' => 'sometimes|numeric|min:0',
            'image' => 'sometimes|image|mimes:png,jpg,jpeg|max:2048',
            'quantity_available' => 'sometimes|integer|min:0',
            'expiration_date' => 'sometimes|date|after:today',
            'category' => 'sometimes|in:medicine,cosmetic',
            'description' => 'sometimes|string'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (!$pharmacy) {
            return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);
        }

        $medicine = Medicine::where('id', $request->medicine_id)
            ->where('pharmacy_id', $pharmacy->id)
            ->first();

        if (!$medicine) {
            return $this->ErrorResponse(__('medicine.not_found_in_inv'), 404);
        }

        $updateData = $request->only(['name', 'price', 'category', 'expiration_date', 'quantity_available', 'description']);

        if ($request->hasFile('image')) {
            // حذف الصورة القديمة من التخزين لتوفير المساحة
            if ($medicine->image) {
                $oldPath = str_replace(asset('storage/'), '', $medicine->image);
                Storage::disk('public')->delete($oldPath);
            }

            $category = $request->category ?? $medicine->category;
            $folderName = ($category === 'cosmetic') ? 'cosmetics' : 'medicines';
            $path = $request->file('image')->store($folderName, 'public');
            $updateData['image'] = asset('storage/' . $path);
        }

        $medicine->update($updateData);

        return $this->SuccessResponse($medicine->fresh(), __('medicine.updated_success'), 200);
    }

    public function deleteMedicine(Request $request)
    {
        $user = auth()->user();
        if (!$user) {
            return $this->ErrorResponse(__('admin.unauthorized'), 401);
        }

        $validation = Validator::make($request->all(), [
            'medicine_id' => 'required|integer|exists:medicines,id'
        ]);

        if ($validation->fails()) {
            return $this->ErrorResponse($validation->errors(), 422);
        }

        $pharmacy = Pharmacy::where('user_id', $user->id)->first();

        if (!$pharmacy) {
            return $this->ErrorResponse(__('medicine.no_pharmcy_found'), 404);
        }

        $medicine = Medicine::where('id', $request->medicine_id)
            ->where('pharmacy_id', $pharmacy->id)
            ->first();

        if (!$medicine) {
            return $this->ErrorResponse(__('medicine.not_found_in_inv'), 404);
        }

        if ($medicine->image) {
            $path = str_replace(asset('storage/'), '', $medicine->image);
            Storage::disk('public')->delete($path);
        }

        $medicine->delete();

        return $this->SuccessResponse(null, __('medicine.removed_success'), 200);
    }
}
