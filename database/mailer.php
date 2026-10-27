<?php
/*
 * Small mail helper: SMTP (STARTTLS + AUTH LOGIN) with a fallback to PHP mail().
 * Returns ['ok' => bool, 'error' => string].
 */
function mv_mail_config(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/mail_config.php';
    }
    return $cfg;
}

function mv_mail_log(string $line): void {
    @file_put_contents(__DIR__ . '/mail_log.txt', '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
}

function mv_smtp_read($fp): string {
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    return $data;
}

function mv_smtp_cmd($fp, string $cmd, array $okCodes): string {
    fwrite($fp, $cmd . "\r\n");
    $resp = mv_smtp_read($fp);
    if (!in_array((int)substr($resp, 0, 3), $okCodes, true)) {
        throw new RuntimeException(trim($resp) !== '' ? trim($resp) : 'No response from mail server.');
    }
    return $resp;
}

function mv_send_mail(string $to, string $subject, string $body): array {
    $cfg = mv_mail_config();
    $fromEmail = $cfg['from_email'] ?: $cfg['username'];
    $fromName  = $cfg['from_name'] ?: 'MediVault';

    if (!empty($cfg['smtp_enabled']) && $cfg['username'] !== '' && $cfg['password'] !== '') {
        $fp = null;
        try {
            $fp = @stream_socket_client('tcp://' . $cfg['host'] . ':' . (int)$cfg['port'], $errno, $errstr, 15);
            if (!$fp) throw new RuntimeException('Could not connect to mail server: ' . $errstr);
            stream_set_timeout($fp, 15);
            $greet = mv_smtp_read($fp);
            if ((int)substr($greet, 0, 3) !== 220) throw new RuntimeException(trim($greet));
            mv_smtp_cmd($fp, 'EHLO localhost', [250]);
            mv_smtp_cmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('TLS handshake failed (check that the openssl extension is enabled in php.ini).');
            }
            mv_smtp_cmd($fp, 'EHLO localhost', [250]);
            mv_smtp_cmd($fp, 'AUTH LOGIN', [334]);
            mv_smtp_cmd($fp, base64_encode($cfg['username']), [334]);
            mv_smtp_cmd($fp, base64_encode($cfg['password']), [235]);
            mv_smtp_cmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            mv_smtp_cmd($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            mv_smtp_cmd($fp, 'DATA', [354]);

            $headers  = 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n";
            $headers .= 'To: <' . $to . ">\r\n";
            $headers .= 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n";
            $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
            $safeBody = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
            $safeBody = str_replace("\n", "\r\n", $safeBody);
            fwrite($fp, $headers . "\r\n" . $safeBody . "\r\n.\r\n");
            $resp = mv_smtp_read($fp);
            if ((int)substr($resp, 0, 3) !== 250) throw new RuntimeException(trim($resp));
            @fwrite($fp, "QUIT\r\n");
            fclose($fp);
            return ['ok' => true, 'error' => ''];
        } catch (Throwable $e) {
            if (is_resource($fp)) @fclose($fp);
            mv_mail_log('SMTP failed for ' . $to . ': ' . $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // Fallback: PHP mail() (works only when sendmail/SMTP is configured in php.ini).
    $headers = 'From: ' . $fromName . ' <' . ($fromEmail ?: 'no-reply@medivault.local') . ">\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($to, $subject, $body, $headers);
    if (!$ok) mv_mail_log('mail() failed for ' . $to . '. SMTP is not configured.');
    return ['ok' => (bool)$ok, 'error' => $ok ? '' : 'Mail server is not configured.'];
}
