<?php

declare(strict_types=1);

namespace Kochbuch\Service;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Invite/password-reset emails, mirroring YTAN's src/Service/MailService.php
 * (see CLAUDE.md's planned user management section). Plain-text via SMTP;
 * CHARSET_UTF8 + ENCODING_QUOTED_PRINTABLE are set explicitly since
 * PHPMailer's default (iso-8859-1) mangles German umlauts otherwise.
 */
final class MailService
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $from,
        private readonly string $fromName,
        private readonly string $encryption,
    ) {
    }

    public function sendInvite(string $toEmail, string $toUsername, string $setPasswordLink): void
    {
        $this->send(
            $toEmail,
            "Willkommen bei {$this->fromName}",
            "Hallo {$toUsername},\n\n" .
            "es wurde ein Konto für dich bei {$this->fromName} angelegt. " .
            "Vergib bitte über den folgenden Link ein Passwort, um dich anzumelden:\n\n" .
            "{$setPasswordLink}\n\n" .
            "Der Link ist 7 Tage gültig."
        );
    }

    public function sendPasswordReset(string $toEmail, string $setPasswordLink): void
    {
        $this->send(
            $toEmail,
            "Passwort zurücksetzen bei {$this->fromName}",
            "Hallo,\n\n" .
            "über den folgenden Link kannst du ein neues Passwort vergeben:\n\n" .
            "{$setPasswordLink}\n\n" .
            "Der Link ist 1 Stunde gültig. Falls du das nicht angefordert hast, kannst du diese E-Mail ignorieren."
        );
    }

    private function send(string $toEmail, string $subject, string $body): void
    {
        $mailer = new PHPMailer(true);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
        $mailer->isSMTP();
        $mailer->Host = $this->host;
        $mailer->Port = $this->port;
        $mailer->SMTPAuth = $this->username !== '';
        $mailer->Username = $this->username;
        $mailer->Password = $this->password;
        if ($this->encryption !== '') {
            $mailer->SMTPSecure = $this->encryption;
        }
        $mailer->setFrom($this->from, $this->fromName);
        $mailer->addAddress($toEmail);
        $mailer->Subject = $subject;
        $mailer->Body = $body;
        $mailer->send();
    }
}
