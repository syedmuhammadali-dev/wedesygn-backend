<?php
declare(strict_types=1);

/**
 * Contact-form notification mail.
 *
 * Sends through SMTP when SMTP_HOST is configured (recommended: the Google Workspace / hosting
 * mailbox that owns MAIL_FROM), and falls back to PHP's mail() otherwise or if SMTP fails.
 */

function notificationRecipients(): array
{
    $configured = getenv('MAIL_TO') ?: 'hello@wedesygn.com,wedesygnofficial@gmail.com';
    $recipients = [];
    foreach (explode(',', $configured) as $recipient) {
        $recipient = trim($recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipients[] = $recipient;
        }
    }

    return array_values(array_unique($recipients));
}

/**
 * Minimal SMTP client: implicit TLS ("ssl", usually port 465), STARTTLS ("tls", usually 587)
 * or plain ("none"), with AUTH LOGIN. Returns true when at least one recipient was accepted and
 * the server accepted the message. Never logs credentials.
 */
function smtpSend(
    string $host,
    int $port,
    string $secure,
    string $user,
    string $password,
    string $from,
    array $to,
    string $replyTo,
    string $subject,
    string $body
): bool {
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $socket = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if ($socket === false) {
        error_log("SMTP connect to {$host}:{$port} failed: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($socket, 20);

    $read = static function () use ($socket): array {
        $text = '';
        while (($line = fgets($socket, 2048)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return [(int) substr($text, 0, 3), trim($text)];
    };
    $command = static function (string $line, array $accept, string $label) use ($socket, $read): bool {
        fwrite($socket, $line . "\r\n");
        [$code, $text] = $read();
        if (!in_array($code, $accept, true)) {
            error_log("SMTP {$label} rejected: {$code} {$text}");
            return false;
        }

        return true;
    };

    try {
        [$code, $text] = $read();
        if ($code !== 220) {
            error_log("SMTP greeting rejected: {$code} {$text}");
            return false;
        }

        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        if (!$command('EHLO ' . $domain, [250], 'EHLO')) {
            return false;
        }
        if ($secure === 'tls') {
            if (!$command('STARTTLS', [220], 'STARTTLS')
                || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)
                || !$command('EHLO ' . $domain, [250], 'EHLO (after STARTTLS)')) {
                return false;
            }
        }
        if ($user !== '') {
            if (!$command('AUTH LOGIN', [334], 'AUTH')
                || !$command(base64_encode($user), [334], 'AUTH user')
                || !$command(base64_encode($password), [235], 'AUTH password')) {
                return false;
            }
        }
        if (!$command('MAIL FROM:<' . $from . '>', [250], 'MAIL FROM')) {
            return false;
        }

        $accepted = [];
        foreach ($to as $recipient) {
            if ($command('RCPT TO:<' . $recipient . '>', [250, 251], 'RCPT TO ' . $recipient)) {
                $accepted[] = $recipient;
            }
        }
        if ($accepted === [] || !$command('DATA', [354], 'DATA')) {
            return false;
        }

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: wedesygn <' . $from . '>',
            'To: ' . implode(', ', $to),
            'Reply-To: ' . $replyTo,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: wedesygn PHP backend',
        ];
        // base64 output contains no leading dots, so no dot-stuffing is needed.
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n") . '.';
        if (!$command($data, [250], 'message')) {
            return false;
        }

        $command('QUIT', [221, 250], 'QUIT');
        return true;
    } finally {
        fclose($socket);
    }
}

function sendUserNotification(string $name, string $email, ?string $interestedIn, ?string $budgetInUsd, ?string $projectDetails): bool
{
    $recipients = notificationRecipients();
    if ($recipients === []) {
        error_log('User notification skipped: no valid MAIL_TO recipients configured');
        return false;
    }

    $from = getenv('MAIL_FROM') ?: 'hello@wedesygn.com';
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        $from = 'hello@wedesygn.com';
    }

    $subject = 'New project enquiry — wedesygn';
    $message = implode("\n", [
        'A new project enquiry was submitted on wedesygn.com.',
        '',
        'Name: ' . $name,
        'Email: ' . $email,
        'Interested in: ' . ($interestedIn ?: 'Not provided'),
        'Budget: ' . ($budgetInUsd ?: 'Not provided'),
        '',
        'Project details:',
        $projectDetails ?: 'Not provided',
    ]);

    $smtpHost = trim((string) (getenv('SMTP_HOST') ?: ''));
    if ($smtpHost !== '') {
        $port = (int) (getenv('SMTP_PORT') ?: 465);
        $secure = strtolower((string) (getenv('SMTP_SECURE') ?: ($port === 465 ? 'ssl' : 'tls')));
        try {
            if (smtpSend($smtpHost, $port, $secure, (string) (getenv('SMTP_USER') ?: ''), (string) (getenv('SMTP_PASSWORD') ?: ''), $from, $recipients, $email, $subject, $message)) {
                return true;
            }
        } catch (Throwable $error) {
            error_log('SMTP send failed: ' . $error->getMessage());
        }
        error_log('SMTP delivery failed, falling back to mail()');
    }

    $headers = implode("\r\n", [
        'From: wedesygn <' . $from . '>',
        'Reply-To: ' . $email,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: wedesygn PHP backend',
    ]);

    return mail(implode(',', $recipients), $subject, $message, $headers);
}
