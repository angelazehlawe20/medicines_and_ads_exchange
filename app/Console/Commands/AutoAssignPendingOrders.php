<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\FcmService;
use Illuminate\Console\Command;

class AutoAssignPendingOrders extends Command
{
    protected $signature = 'order:auto-assign';

    public function handle(FcmService $fcmService)
    {
        $orders = Order::where('ph_approval_status', 'approved')
                       ->whereNull('delivery_id')
                       ->get();

        foreach ($orders as $order) {
            // استدعاء الدالة من الـ Trait مباشرة
            $this->autoAssignDelivery($fcmService, $order);
        }
    }
}
