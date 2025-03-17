<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected $code; // The reset code

    public function __construct($code)
    {
        $this->code = $code;
    }

    /**
     * Get the notification delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Your Password Reset Code')
            ->line('We received a request to reset your password.')
            ->line('Use the following code to reset your password:')
            ->line("**{$this->code}**") // Highlight the code for emphasis
            ->line('This code is valid for 15 minutes.')
            ->line('If you did not request a password reset, please ignore this email.');
    }
}
