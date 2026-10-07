<?php

declare(strict_types=1);

namespace Coleza\Tests\Unit\Domain\Identity;

use Coleza\Domain\Identity\Organization\OrganizationService;
use Coleza\Domain\Identity\Session\DatabaseSessionHandler;
use Coleza\Foundation\Database\Connection;
use Coleza\Foundation\Exceptions\ValidationException;
use PDO;
use PHPUnit\Framework\TestCase;

final class OrganizationServiceTest extends TestCase
{
    private Connection $connection;
    private DatabaseSessionHandler $sessionHandler;
    private OrganizationService $orgService;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $this->connection = new Connection($pdo, 'sqlite');
        $this->sessionHandler = new DatabaseSessionHandler($this->connection);
        $this->orgService = new OrganizationService($this->connection, $this->sessionHandler);
    }

    public function testCreateOrganizationAndListMemberships(): void
    {
        $ownerId = 10;
        $orgId = $this->orgService->createOrganization($ownerId, 'Acme Hosting Ltd', 'acme-hosting');

        $this->assertGreaterThan(0, $orgId);

        $orgs = $this->orgService->getUserOrganizations($ownerId);
        $this->assertCount(1, $orgs);
        $this->assertSame('acme-hosting', $orgs[0]['slug']);
        $this->assertSame('owner', $orgs[0]['role']);
    }

    public function testDuplicateSlugThrowsValidationException(): void
    {
        $this->orgService->createOrganization(1, 'Alpha Corp', 'alpha');

        $this->expectException(ValidationException::class);
        $this->orgService->createOrganization(2, 'Alpha Second', 'alpha');
    }

    public function testSwitchOrganizationInSession(): void
    {
        $userId = 5;
        $orgId = $this->orgService->createOrganization($userId, 'Cloud Services Inc', 'cloud-inc');

        $sessionId = 'sess_org_switch';
        $this->sessionHandler->write($sessionId, ['logged_in' => true], userId: $userId);

        $this->orgService->switchOrganization($sessionId, $userId, $orgId);

        $sessionData = $this->sessionHandler->read($sessionId);
        $this->assertSame($orgId, $sessionData['active_organization_id']);
        $this->assertSame('owner', $sessionData['active_organization_role']);
    }

    public function testSwitchToUnauthorizedOrganizationFails(): void
    {
        $ownerId = 1;
        $unauthorizedUserId = 99;
        $orgId = $this->orgService->createOrganization($ownerId, 'Private Org', 'private-org');

        $this->expectException(ValidationException::class);
        $this->orgService->switchOrganization('sess_unauth', $unauthorizedUserId, $orgId);
    }

    public function testInviteAndAcceptMembership(): void
    {
        $ownerId = 1;
        $memberId = 2;
        $memberEmail = 'member@acme.com';

        $orgId = $this->orgService->createOrganization($ownerId, 'Network Solutions', 'net-solutions');

        // Owner invites member
        $inviteToken = $this->orgService->inviteMember($ownerId, $orgId, $memberEmail, 'billing');
        $this->assertNotEmpty($inviteToken);

        // Member accepts invite
        $acceptedOrgId = $this->orgService->acceptInvitation($memberId, $memberEmail, $inviteToken);
        $this->assertSame($orgId, $acceptedOrgId);

        // Verify member has access to org with role 'billing'
        $memberOrgs = $this->orgService->getUserOrganizations($memberId);
        $this->assertCount(1, $memberOrgs);
        $this->assertSame('billing', $memberOrgs[0]['role']);
    }

    public function testAcceptInvitationWithWrongEmailFails(): void
    {
        $ownerId = 1;
        $orgId = $this->orgService->createOrganization($ownerId, 'Security Org', 'sec-org');
        $token = $this->orgService->inviteMember($ownerId, $orgId, 'target@org.com');

        $this->expectException(ValidationException::class);
        $this->orgService->acceptInvitation(3, 'wrong-user@org.com', $token);
    }
}
