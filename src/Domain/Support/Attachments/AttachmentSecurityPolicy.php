<?php

declare(strict_types=1);

namespace Coleza\Domain\Support\Attachments;

use Coleza\Foundation\Exceptions\ValidationException;

final class AttachmentSecurityPolicy
{
    public const DEFAULT_MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public const ALLOWED_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'pdf', 'txt', 'log', 'csv',
        'zip', 'tar', 'gz',
    ];

    public const FORBIDDEN_EXTENSIONS = [
        'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7', 'pht',
        'exe', 'dll', 'so', 'sh', 'bash', 'bat', 'cmd', 'com', 'scr', 'vbs',
        'js', 'jsp', 'asp', 'aspx', 'cgi', 'pl', 'py', 'jar', 'war',
        'html', 'htm', 'svg', 'htaccess', 'htpasswd',
    ];

    public const MIME_TYPE_MAP = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'txt' => 'text/plain',
        'log' => 'text/plain',
        'csv' => 'text/csv',
        'zip' => 'application/zip',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
    ];

    public function __construct(
        private readonly int $maxSizeBytes = self::DEFAULT_MAX_SIZE_BYTES,
        /** @var array<int, string> */
        private readonly array $allowedExtensions = self::ALLOWED_EXTENSIONS
    ) {
    }

    /**
     * Validate an attachment candidate against all security rules.
     *
     * @throws ValidationException
     */
    public function validate(string $filename, string $content, ?string $clientMimeType = null): void
    {
        // 1. Check for null byte injection
        if (str_contains($filename, "\0") || str_contains($filename, '%00')) {
            throw new ValidationException(
                ['filename' => 'Filename contains illegal null byte characters.'],
                'Null byte security violation'
            );
        }

        // 2. Path traversal check
        if (str_contains($filename, '../') || str_contains($filename, '..\\')) {
            throw new ValidationException(
                ['filename' => 'Directory traversal patterns are forbidden in attachment names.'],
                'Directory traversal security violation'
            );
        }

        // 3. Size check
        $actualSize = strlen($content);
        if ($actualSize === 0) {
            throw new ValidationException(
                ['file' => 'Attachment file cannot be empty (0 bytes).'],
                'Empty attachment'
            );
        }

        if ($actualSize > $this->maxSizeBytes) {
            throw new ValidationException(
                ['file' => sprintf('Attachment exceeds maximum size of %d bytes (provided %d bytes).', $this->maxSizeBytes, $actualSize)],
                'Attachment size limit exceeded'
            );
        }

        // 4. Extension inspection (including multiple extensions check)
        $cleanBasename = basename(str_replace('\\', '/', $filename));
        $parts = explode('.', strtolower($cleanBasename));

        if (count($parts) < 2) {
            throw new ValidationException(
                ['filename' => 'Attachment file must have a valid extension.'],
                'Missing file extension'
            );
        }

        $finalExtension = end($parts);

        // Check if ANY part matches forbidden extensions (blocks file.php.png or invoice.pdf.exe)
        foreach ($parts as $idx => $part) {
            if ($idx === 0) {
                continue; // The base name
            }
            if (in_array($part, self::FORBIDDEN_EXTENSIONS, true)) {
                throw new ValidationException(
                    ['filename' => sprintf("Forbidden executable extension '%s' detected in filename.", $part)],
                    'Forbidden extension'
                );
            }
        }

        // Check against allowed extensions
        if (!in_array($finalExtension, $this->allowedExtensions, true)) {
            throw new ValidationException(
                ['extension' => sprintf("Extension '%s' is not allowed for support attachments.", $finalExtension)],
                'Disallowed file extension'
            );
        }

        // 5. Executable payload content sniffing
        $contentSample = substr($content, 0, 1024);
        $dangerousSignatures = ['<?php', '<?=', '<script', '<html', '<!doctype html', '<svg'];
        foreach ($dangerousSignatures as $sig) {
            if (stripos($contentSample, $sig) !== false) {
                // If it's not a legitimate image format, reject
                if (!in_array($finalExtension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
                    throw new ValidationException(
                        ['content' => 'Attachment content contains disallowed script or markup signatures.'],
                        'Dangerous script content detected'
                    );
                }
            }
        }
    }

    public function sanitizeFilename(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        // Remove control characters and non-alphanumeric/dot/hyphen/underscore
        $sanitized = preg_replace('/[^\w.\-_]/u', '_', $base);

        return $sanitized ?: 'attachment_' . bin2hex(random_bytes(4));
    }

    public function detectMimeType(string $filename, ?string $providedMime = null): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (isset(self::MIME_TYPE_MAP[$ext])) {
            return self::MIME_TYPE_MAP[$ext];
        }

        return $providedMime ?: 'application/octet-stream';
    }

    public function getMaxSizeBytes(): int
    {
        return $this->maxSizeBytes;
    }
}
