<?php

declare(strict_types=1);

namespace Coleza\Domain\Notifications\Transport;

use Coleza\Domain\Notifications\Delivery\DeliveryResult;
use Coleza\Domain\Notifications\Messages\NotificationMessage;
use Throwable;

final class SmtpTransport implements MailerInterface
{
    public function __construct(
        private SmtpConfiguration $config
    ) {
    }

    public function getTransportName(): string
    {
        return 'smtp';
    }

    public function send(NotificationMessage $message): DeliveryResult
    {
        $messageId = sprintf('<%s.%s@%s>', bin2hex(random_bytes(8)), time(), parse_url($this->config->getHost(), PHP_URL_HOST) ?? 'coleza.internal');

        try {
            $rawMime = $this->buildMimeMessage($message, $messageId);

            // Execute socket dispatch
            $this->dispatchSocket($rawMime, $message->getRecipientEmail());

            return DeliveryResult::success(
                messageId: $messageId,
                transport: $this->getTransportName(),
                metadata: [
                    'recipient' => $message->getRecipientEmail(),
                    'smtp_host' => $this->config->getHost(),
                    'smtp_port' => $this->config->getPort(),
                ]
            );
        } catch (Throwable $e) {
            return DeliveryResult::failure(
                error: $e->getMessage(),
                transport: $this->getTransportName(),
                messageId: $messageId,
                metadata: [
                    'recipient' => $message->getRecipientEmail(),
                    'smtp_host' => $this->config->getHost(),
                ]
            );
        }
    }

    public function buildMimeMessage(NotificationMessage $message, string $messageId): string
    {
        $fromEmail = $message->getFromEmail() ?? $this->config->getFromEmail();
        $fromName = $message->getFromName() ?? $this->config->getFromName();
        $recipientEmail = $message->getRecipientEmail();
        $recipientName = $message->getRecipientName();

        $fromHeader = $fromName !== '' ? "=?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>" : $fromEmail;
        $toHeader = $recipientName !== null && $recipientName !== '' 
            ? "=?UTF-8?B?" . base64_encode($recipientName) . "?= <{$recipientEmail}>" 
            : $recipientEmail;

        $subjectHeader = "=?UTF-8?B?" . base64_encode($message->getSubject()) . "?=";
        $boundary = '=_boundary_' . bin2hex(random_bytes(12));

        $headers = [
            "Date: " . date('r'),
            "From: {$fromHeader}",
            "To: {$toHeader}",
            "Subject: {$subjectHeader}",
            "Message-ID: {$messageId}",
            "MIME-Version: 1.0",
            "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        ];

        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($message->getPlainTextBody())) . "\r\n";

        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($message->getHtmlBody())) . "\r\n";

        $body .= "--{$boundary}--\r\n";

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function dispatchSocket(string $rawMime, string $recipientEmail): void
    {
        $host = $this->config->getHost();
        $port = $this->config->getPort();
        $timeout = $this->config->getTimeoutSeconds();

        $protocol = match ($this->config->getEncryption()) {
            'ssl' => 'ssl://',
            default => '',
        };

        $socket = stream_socket_client(
            "{$protocol}{$host}:{$port}",
            $errNo,
            $errStr,
            (float) $timeout,
            STREAM_CLIENT_CONNECT
        );

        if (!$socket) {
            throw new \RuntimeException("Could not connect to SMTP host {$host}:{$port} ({$errNo}: {$errStr})");
        }

        try {
            stream_set_timeout($socket, $timeout);
            $this->readResponse($socket, 220);

            // EHLO
            $this->sendCommand($socket, "EHLO " . gethostname());
            $this->readResponse($socket, 250);

            // STARTTLS if configured
            if ($this->config->getEncryption() === 'tls') {
                $this->sendCommand($socket, "STARTTLS");
                $this->readResponse($socket, 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException("Failed to establish TLS encryption with SMTP server.");
                }
                $this->sendCommand($socket, "EHLO " . gethostname());
                $this->readResponse($socket, 250);
            }

            // AUTH LOGIN if credentials provided
            if ($this->config->getUsername() !== null && $this->config->getUsername() !== '') {
                $this->sendCommand($socket, "AUTH LOGIN");
                $this->readResponse($socket, 334);
                $this->sendCommand($socket, base64_encode($this->config->getUsername()));
                $this->readResponse($socket, 334);
                $this->sendCommand($socket, base64_encode($this->config->getPassword() ?? ''));
                $this->readResponse($socket, 235);
            }

            // MAIL FROM
            $fromEmail = $this->config->getFromEmail();
            $this->sendCommand($socket, "MAIL FROM:<{$fromEmail}>");
            $this->readResponse($socket, 250);

            // RCPT TO
            $this->sendCommand($socket, "RCPT TO:<{$recipientEmail}>");
            $this->readResponse($socket, 250);

            // DATA
            $this->sendCommand($socket, "DATA");
            $this->readResponse($socket, 354);

            // Send payload ending with dot
            $normalizedPayload = preg_replace('/^\./m', '..', $rawMime) ?? $rawMime;
            fwrite($socket, $normalizedPayload . "\r\n.\r\n");
            $this->readResponse($socket, 250);

            // QUIT
            $this->sendCommand($socket, "QUIT");
            $this->readResponse($socket, 221);
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function sendCommand($socket, string $cmd): void
    {
        fwrite($socket, $cmd . "\r\n");
    }

    /**
     * @param resource $socket
     */
    private function readResponse($socket, int $expectedCode): string
    {
        $response = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) {
                break;
            }
            $response .= $line;
            // Check if end of multiline response (e.g. "250 OK" vs "250-SIZE")
            if (preg_match('/^(\d{3})(?:[ ])(.*)$/', $line, $m)) {
                $code = (int) $m[1];
                if ($code !== $expectedCode) {
                    throw new \RuntimeException("SMTP error response: {$response}");
                }
                return $response;
            }
        }

        throw new \RuntimeException("SMTP connection closed unexpectedly or timed out: {$response}");
    }
}
