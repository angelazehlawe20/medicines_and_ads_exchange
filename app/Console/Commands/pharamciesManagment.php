<?php

namespace App\Console\Commands;

use App\Models\Medicine;
use App\Models\Notification;
use App\Models\Pharmacy;
use App\Services\FcmService;
use App\Traits\ShefaaTrait;
use Carbon\Carbon;
use Illuminate\Console\Command;

class pharamciesManagment extends Command
{
    use ShefaaTrait;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pharmacy:check-inventory';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(FcmService $fcmService)
    {
        $oneMonthFromNow = Carbon::today()->addMonth();
        $lowStockThreshold = 10;
        $waitDays = 7; // الفترة الزمنية قبل إعادة التنبيه (أسبوع)

        $medsForCheck = Medicine::with('pharmacy.user')
            ->where(function ($query) use ($oneMonthFromNow, $lowStockThreshold) {
                $query->where('expiration_date', '<=', $oneMonthFromNow)
                    ->orWhere('quantity_available', '<=', $lowStockThreshold);
            })
            // لم يتم إرسال تنبيه أو مر أكثر من أسبوع على آخر تنبيه
            ->where(function ($query) use ($waitDays) {
                $query->whereNull('last_notified_at')
                    ->orWhere('last_notified_at', '<=', Carbon::now()->subDays($waitDays));
            })
            ->get();

        foreach ($medsForCheck as $medicine) {
            $pharmacy = $medicine->pharmacy;
            if (!$pharmacy || !$pharmacy->user_id) continue; // ينتقل الى الدواء الاخر 

            $title = __('pharmacy.commandTitle');
            $message = __('pharmacy.commandMessage', [
                'medicine_name' => $medicine->name,
                'reason' => $medicine->quantity_available <= $lowStockThreshold ? __('pharmacy.low_stock') : __('pharmacy.expiring_soon')
            ]);

            $fcmService->sendFcmNotification($pharmacy->user_id, $title, $message, [
                'related_id' => (string)$medicine->id,
                'related_type' => 'check_medicine'
            ]);

            // تحديث تاريخ آخر تنبيه لمنع التكرار غداً
            $medicine->update([
                'last_notified_at' => Carbon::now()
            ]);

            $this->info("تم تنبيه صيدلية {$pharmacy->pharmacy_name} بخصوص: {$medicine->name}");
        }
    }
}
