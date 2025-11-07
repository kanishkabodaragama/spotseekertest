<?php

namespace App\Console\Commands;

use App\Jobs\NotificationJob;
use App\Models\Notification;
use Illuminate\Console\Command;

class SendNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-notifications';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cron job for send notifications';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dueSms = Notification::where('is_sent', false)
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($dueSms as $sms) {
            NotificationJob::dispatch($sms);
        }
    }
}
