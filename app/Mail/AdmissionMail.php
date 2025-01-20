<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public $student,$pdfPath;

    public function __construct($student, $pdfPath)
    {
        $this->student = $student;
        $this->pdfPath = $pdfPath;
    }

    public function build()
    {
        return $this->view('mails.admission')
                    ->subject('Admission Mail')
                    ->from('no-reply@unizik.com', 'UNIZIK')
                    ->with([
                        'student' => $this->student,
                    ])
                    ->attach($this->pdfPath);
    }
}
