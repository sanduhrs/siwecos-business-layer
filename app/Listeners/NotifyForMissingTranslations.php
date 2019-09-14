<?php

namespace App\Listeners;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use LostInTranslation\Events\MissingTranslationFound;
use App\Notifications\MissingTranslationFoundNotification;

class NotifyForMissingTranslations
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle(MissingTranslationFound $event)
    {
        // Do not send missing translation mails for validation errors
        if (substr($event->key, 0, 11) !== 'validation.') {
            if (\Cache::add($event->key, 'notification_sent', 3600)) {
                \Notification::route('mail', config('siwecos.technicalSupportMail'))
                    ->notify(new MissingTranslationFoundNotification($event));
            }
        }
    }
}
