<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/PHPMailer/Exception.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/PHPMailer.php';
require_once dirname(__DIR__) . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

class Mailer
{
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
    }

    /**
     * @param array $attachments  [['path' => '/abs/path', 'name' => 'CV.pdf'], ...]
     * @param array $headers      Additional headers ['In-Reply-To' => '<msgid>', ...]
     */
    public function send(
        string $to,
        string $subject,
        string $htmlBody,
        array $attachments = [],
        array $headers = []
    ): string
    {
        $smtp = $this->cfg['smtp'];
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['user'];
        $mail->Password   = $smtp['pass'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = (int)$smtp['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($smtp['user'], $smtp['from_name'] ?? '');
        $mail->addAddress($to);
        $mail->Subject = $subject;

        $mail->isHTML(true);
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

        foreach ($attachments as $att) {
            if (is_file($att['path'])) {
                $mail->addAttachment($att['path'], $att['name'] ?? basename($att['path']));
            }
        }

        foreach ($headers as $key => $value) {
            $mail->addCustomHeader($key, $value);
        }

        $mail->send();

        // Return the Message-ID generated
        return $mail->getLastMessageID();
    }
}
