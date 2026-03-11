<?php
/**
 * Minimal SMTP Sender
 * PiDoors Access Control System
 *
 * Supports AUTH LOGIN with STARTTLS (587), implicit SSL (465), and plain (25).
 */

/**
 * Send an email via SMTP
 *
 * @param string $to Recipient email address
 * @param string $subject Email subject line
 * @param string $html_body HTML email body
 * @param array  $smtp SMTP settings: host, port, user, pass, from
 * @return true|string True on success, error string on failure
 */
function smtp_send($to, $subject, $html_body, $smtp) {
    $host = $smtp['host'] ?? '';
    $port = (int)($smtp['port'] ?? 587);
    $user = $smtp['user'] ?? '';
    $pass = $smtp['pass'] ?? '';
    $from = $smtp['from'] ?? $user;

    // Strip CRLF to prevent header injection
    $from = str_replace(["\r", "\n"], '', $from);
    $to = str_replace(["\r", "\n"], '', $to);
    $subject = str_replace(["\r", "\n"], '', $subject);

    if (empty($host) || empty($to)) {
        return 'SMTP host and recipient are required';
    }
    if (empty($from)) {
        return 'From address is required';
    }

    $timeout = 30;
    $errno = 0;
    $errstr = '';

    // Port 465 = implicit TLS, connect with ssl:// prefix
    if ($port === 465) {
        $conn = @stream_socket_client(
            "ssl://{$host}:{$port}", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create(['ssl' => ['verify_peer' => ($config['smtp_verify_tls'] ?? true), 'verify_peer_name' => ($config['smtp_verify_tls'] ?? true)]])
        );
    } else {
        $conn = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    }

    if (!$conn) {
        return "Connection failed: {$errstr} ({$errno})";
    }

    stream_set_timeout($conn, $timeout);

    // Read greeting
    $resp = smtp_read($conn);
    if (!smtp_ok($resp)) return "Greeting failed: {$resp}";

    // EHLO
    $resp = smtp_cmd($conn, "EHLO pidoors");
    if (!smtp_ok($resp)) return "EHLO failed: {$resp}";

    // STARTTLS for port 587 (or 25 if server supports it)
    if ($port === 587 || ($port === 25 && stripos($resp, 'STARTTLS') !== false)) {
        if (stripos($resp, 'STARTTLS') !== false) {
            $resp = smtp_cmd($conn, "STARTTLS");
            if (!smtp_ok($resp)) return "STARTTLS failed: {$resp}";

            $crypto = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
            if (!$crypto) {
                // Try broader method set for older servers
                $crypto = stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            }
            if (!$crypto) return "TLS negotiation failed";

            // Re-EHLO after STARTTLS
            $resp = smtp_cmd($conn, "EHLO pidoors");
            if (!smtp_ok($resp)) return "EHLO after STARTTLS failed: {$resp}";
        }
    }

    // AUTH LOGIN (skip if no credentials provided - allows relay on port 25)
    if (!empty($user) && !empty($pass)) {
        $resp = smtp_cmd($conn, "AUTH LOGIN");
        if (substr($resp, 0, 3) !== '334') return "AUTH LOGIN failed: {$resp}";

        $resp = smtp_cmd($conn, base64_encode($user));
        if (substr($resp, 0, 3) !== '334') return "AUTH username failed: {$resp}";

        $resp = smtp_cmd($conn, base64_encode($pass));
        if (!smtp_ok($resp)) return "AUTH password failed: {$resp}";
    }

    // MAIL FROM
    $resp = smtp_cmd($conn, "MAIL FROM:<{$from}>");
    if (!smtp_ok($resp)) return "MAIL FROM failed: {$resp}";

    // RCPT TO
    $resp = smtp_cmd($conn, "RCPT TO:<{$to}>");
    if (!smtp_ok($resp)) return "RCPT TO failed: {$resp}";

    // DATA
    $resp = smtp_cmd($conn, "DATA");
    if (substr($resp, 0, 3) !== '354') return "DATA failed: {$resp}";

    // Build message with proper headers
    $boundary = md5(uniqid(time()));
    $message = "From: PiDoors <{$from}>\r\n";
    $message .= "To: {$to}\r\n";
    $message .= "Subject: {$subject}\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n";
    $message .= "X-Mailer: PiDoors Notification System\r\n";
    $message .= "Date: " . date('r') . "\r\n";
    $message .= "Message-ID: <" . md5(uniqid()) . "@pidoors>\r\n";
    $message .= "\r\n";
    $message .= $html_body;

    // Dot-stuffing: lines starting with a period get an extra period
    $message = str_replace("\r\n.", "\r\n..", $message);

    // Send message body + terminating dot
    fwrite($conn, $message . "\r\n.\r\n");
    $resp = smtp_read($conn);
    if (!smtp_ok($resp)) return "Message send failed: {$resp}";

    // QUIT
    smtp_cmd($conn, "QUIT");
    fclose($conn);

    return true;
}

/**
 * Send an SMTP command and read the response
 */
function smtp_cmd($conn, $cmd) {
    fwrite($conn, $cmd . "\r\n");
    return smtp_read($conn);
}

/**
 * Read SMTP response (handles multi-line responses)
 */
function smtp_read($conn) {
    $response = '';
    while (true) {
        $line = fgets($conn, 512);
        if ($line === false) break;
        $response .= $line;
        // Last line of response has a space after the code (e.g., "250 OK")
        if (isset($line[3]) && $line[3] === ' ') break;
        // Single-line responses without continuation
        if (strlen($line) < 4) break;
    }
    return trim($response);
}

/**
 * Check if SMTP response indicates success (2xx)
 */
function smtp_ok($response) {
    return in_array(substr($response, 0, 1), ['2', '3']);
}
