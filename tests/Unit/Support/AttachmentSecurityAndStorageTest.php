<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Support;

use Coleza\Domain\Support\Attachments\AttachmentSecurityPolicy;
use Coleza\Domain\Support\Attachments\AttachmentService;
use Coleza\Domain\Support\Attachments\InMemoryAttachmentStorage;
use Coleza\Domain\Support\Attachments\PrivateAttachmentStorage;
use Coleza\Domain\Support\Attachments\TicketAttachment;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttachmentSecurityAndStorageTest extends TestCase
{
    private Connection $db;
    private PDO $pdo;
    private InMemoryAttachmentStorage $storage;
    private AttachmentSecurityPolicy $policy;
    private AttachmentService $service;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->db = new Connection($this->pdo, 'sqlite');

        $this->storage = new InMemoryAttachmentStorage();
        $this->policy = new AttachmentSecurityPolicy(maxSizeBytes: 2 * 1024 * 1024); // 2 MB limit for test
        $this->service = new AttachmentService($this->db, $this->storage, $this->policy);
        $this->service->ensureTables();
    }

    public function testSecurityPolicyAcceptsValidFiles(): void
    {
        $this->policy->validate('server_error.log', '2026-10-09 10:00:00 [ERROR] Connection reset by peer');
        $this->policy->validate('screenshot.png', "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR");
        $this->policy->validate('terms.pdf', "%PDF-1.4 sample document bytes");
        $this->policy->validate('export.csv', "id,name,email\n1,Alice,alice@example.com");

        $this->assertTrue(true); // Reached without exception
    }

    public function testSecurityPolicyRejectsDangerousExtensions(): void
    {
        $dangerousFiles = [
            'backdoor.php',
            'script.phtml',
            'exploit.phar',
            'malware.exe',
            'shell.sh',
            'batch.bat',
            'cmd.cmd',
            'vector.svg',
            '.htaccess',
            'worm.vbs',
            'payload.js',
        ];

        foreach ($dangerousFiles as $filename) {
            try {
                $this->policy->validate($filename, 'sample payload');
                $this->fail("Expected ValidationException for dangerous file: {$filename}");
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->getErrors());
            }
        }
    }

    public function testSecurityPolicyBlocksMultipleExtensionSpoofing(): void
    {
        // Malicious double extensions attempting to bypass naive extension filters
        $trickyFiles = [
            'invoice.php.png',
            'contract.exe.pdf',
            'report.sh.log',
            'backup.phar.zip',
        ];

        foreach ($trickyFiles as $filename) {
            try {
                $this->policy->validate($filename, 'dummy content');
                $this->fail("Expected ValidationException for double extension: {$filename}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('filename', $e->getErrors());
            }
        }
    }

    public function testSecurityPolicyBlocksNullByteAndPathTraversal(): void
    {
        // Null byte injection
        try {
            $this->policy->validate("shell.php\0.png", 'data');
            $this->fail('Expected ValidationException for null byte');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('filename', $e->getErrors());
        }

        try {
            $this->policy->validate("shell.php%00.jpg", 'data');
            $this->fail('Expected ValidationException for encoded null byte');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('filename', $e->getErrors());
        }

        // Path traversal
        try {
            $this->policy->validate("../../etc/passwd.txt", 'root:x:0:0');
            $this->fail('Expected ValidationException for path traversal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('filename', $e->getErrors());
        }
    }

    public function testSecurityPolicyBlocksEmbeddedScriptSignaturesInText(): void
    {
        // Text file concealing PHP payload
        try {
            $this->policy->validate('debug.txt', "<?php system(\$_GET['cmd']); ?>");
            $this->fail('Expected ValidationException for embedded php code in txt');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->getErrors());
        }

        // Text file concealing script tags
        try {
            $this->policy->validate('notes.log', "<script>alert(document.cookie)</script>");
            $this->fail('Expected ValidationException for script tag');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('content', $e->getErrors());
        }
    }

    public function testSecurityPolicyEnforcesSizeLimits(): void
    {
        // Empty file
        try {
            $this->policy->validate('empty.txt', '');
            $this->fail('Expected ValidationException for 0 byte file');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->getErrors());
        }

        // Oversized file (> 2 MB)
        $largeData = str_repeat('A', 2 * 1024 * 1024 + 10);
        try {
            $this->policy->validate('huge.txt', $largeData);
            $this->fail('Expected ValidationException for oversized file');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->getErrors());
        }
    }

    public function testFilenameSanitizationAndMimeDetection(): void
    {
        $sanitized = $this->policy->sanitizeFilename('My Invoice #1 [2026] (Final).pdf');
        $this->assertStringNotContainsString('#', $sanitized);
        $this->assertStringNotContainsString('[', $sanitized);
        $this->assertStringNotContainsString(' ', $sanitized);
        $this->assertStringEndsWith('.pdf', $sanitized);

        $this->assertSame('image/png', $this->policy->detectMimeType('screenshot.png'));
        $this->assertSame('application/pdf', $this->policy->detectMimeType('document.pdf'));
        $this->assertSame('text/plain', $this->policy->detectMimeType('app.log'));
    }

    public function testAttachFileIntegrityAndReadContent(): void
    {
        $content = "Error log trace:\nStack trace line 1\nStack trace line 2";
        $attachment = $this->service->attachFile(
            ticketId: 100,
            userId: 5,
            filename: 'stacktrace.log',
            content: $content,
            messageId: 42,
            isPrivate: false,
            metadata: ['browser' => 'Chrome']
        );

        $this->assertGreaterThan(0, $attachment->getId());
        $this->assertSame(100, $attachment->getTicketId());
        $this->assertSame(5, $attachment->getUserId());
        $this->assertSame(42, $attachment->getMessageId());
        $this->assertFalse($attachment->isPrivate());
        $this->assertSame(strlen($content), $attachment->getFileSizeBytes());
        $this->assertSame('text/plain', $attachment->getMimeType());
        $this->assertSame(hash('sha256', $content), $attachment->getSha256Hash());
        $this->assertSame(['browser' => 'Chrome'], $attachment->getMetadata());

        // Verify content read back matches exactly
        $retrievedContent = $this->service->readContent($attachment->getId());
        $this->assertSame($content, $retrievedContent);

        // Serialization roundtrip
        $arr = $attachment->toArray();
        $reconstructed = TicketAttachment::fromArray($arr);
        $this->assertSame($attachment->getId(), $reconstructed->getId());
        $this->assertSame($attachment->getStorageKey(), $reconstructed->getStorageKey());
    }

    public function testTamperDetectionCausesIntegrityException(): void
    {
        $content = "Original authentic diagnostic report";
        $attachment = $this->service->attachFile(
            ticketId: 200,
            userId: 7,
            filename: 'diag.txt',
            content: $content
        );

        // Corrupt storage payload directly
        $this->storage->put($attachment->getStorageKey(), 'Corrupted tampered content');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA256 checksum mismatch');
        $this->service->readContent($attachment->getId());
    }

    public function testPrivateAttachmentSeparationAndFiltering(): void
    {
        // 1. Customer uploads public attachment
        $publicAtt = $this->service->attachFile(
            ticketId: 300,
            userId: 10,
            filename: 'public_doc.pdf',
            content: '%PDF-1.4 public file',
            isPrivate: false
        );

        // 2. Staff uploads internal private attachment (e.g. server audit dump)
        $privateAtt = $this->service->attachFile(
            ticketId: 300,
            userId: 99,
            filename: 'internal_audit.log',
            content: 'INTERNAL AUDIT LOG: confidential data',
            isPrivate: true
        );

        // Customer query (includePrivate: false)
        $customerList = $this->service->getTicketAttachments(300, includePrivate: false);
        $this->assertCount(1, $customerList);
        $this->assertSame($publicAtt->getId(), $customerList[0]->getId());

        // Staff query (includePrivate: true)
        $staffList = $this->service->getTicketAttachments(300, includePrivate: true);
        $this->assertCount(2, $staffList);

        // Message-level attachments
        $msgAtt = $this->service->attachFile(
            ticketId: 300,
            userId: 10,
            filename: 'msg_att.txt',
            content: 'Attached to message 50',
            messageId: 50,
            isPrivate: false
        );
        $msgAttachments = $this->service->getMessageAttachments(50);
        $this->assertCount(1, $msgAttachments);
        $this->assertSame($msgAtt->getId(), $msgAttachments[0]->getId());
    }

    public function testAttachmentAccessControl(): void
    {
        $publicAtt = $this->service->attachFile(
            ticketId: 400,
            userId: 10, // customer 10 uploaded
            filename: 'order_receipt.pdf',
            content: '%PDF receipt',
            isPrivate: false
        );

        $privateAtt = $this->service->attachFile(
            ticketId: 400,
            userId: 99, // staff 99 uploaded
            filename: 'internal_investigation.log',
            content: 'staff only notes',
            isPrivate: true
        );

        // Ticket owner is customer 10
        $ticketOwnerId = 10;

        // 1. Customer 10 accessing public attachment -> ALLOWED
        $this->assertTrue($this->service->canAccess($publicAtt, userId: 10, isStaff: false, ticketOwnerUserId: $ticketOwnerId));

        // 2. Customer 10 accessing private attachment -> FORBIDDEN
        $this->assertFalse($this->service->canAccess($privateAtt, userId: 10, isStaff: false, ticketOwnerUserId: $ticketOwnerId));

        // 3. Another customer 20 accessing customer 10's public attachment -> FORBIDDEN
        $this->assertFalse($this->service->canAccess($publicAtt, userId: 20, isStaff: false, ticketOwnerUserId: $ticketOwnerId));

        // 4. Staff agent accessing public attachment -> ALLOWED
        $this->assertTrue($this->service->canAccess($publicAtt, userId: 99, isStaff: true, ticketOwnerUserId: $ticketOwnerId));

        // 5. Staff agent accessing private attachment -> ALLOWED
        $this->assertTrue($this->service->canAccess($privateAtt, userId: 99, isStaff: true, ticketOwnerUserId: $ticketOwnerId));
    }

    public function testDeleteAttachmentAuthorization(): void
    {
        $attachment = $this->service->attachFile(
            ticketId: 500,
            userId: 10,
            filename: 'wrong_file.txt',
            content: 'mistake file'
        );

        // Unauthorized user attempts deletion
        try {
            $this->service->deleteAttachment($attachment->getId(), actorUserId: 999, isStaff: false);
            $this->fail('Expected ValidationException for unauthorized deletion');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('authorization', $e->getErrors());
        }

        // Author deletes their own file
        $deleted = $this->service->deleteAttachment($attachment->getId(), actorUserId: 10, isStaff: false);
        $this->assertTrue($deleted);
        $this->assertNull($this->service->getAttachment($attachment->getId()));
        $this->assertFalse($this->storage->exists($attachment->getStorageKey()));
    }

    public function testPrivateAttachmentStorageOnFilesystem(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'colezahost_test_att_' . bin2hex(random_bytes(4));
        $diskStorage = new PrivateAttachmentStorage($tempDir);

        $storageKey = 'tickets/123/sample_doc.txt';
        $content = 'Testing filesystem private attachment storage';

        $this->assertTrue($diskStorage->put($storageKey, $content));
        $this->assertTrue($diskStorage->exists($storageKey));
        $this->assertSame($content, $diskStorage->get($storageKey));
        $this->assertTrue($diskStorage->delete($storageKey));
        $this->assertFalse($diskStorage->exists($storageKey));

        // Traversal rejection
        $this->expectException(\InvalidArgumentException::class);
        $diskStorage->put('../secret.txt', 'evil');
    }
}
