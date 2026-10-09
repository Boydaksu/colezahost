<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Whmcs\Support;

use Coleza\Domain\Migration\Whmcs\WhmcsReadOnlyConnector;

/**
 * Extracts support departments, tickets, replies, attachments, and custom fields
 * from a source WHMCS database with read-only integrity.
 */
final class WhmcsSupportExtractor
{
    public function __construct(
        private WhmcsReadOnlyConnector $connector
    ) {
    }

    /**
     * Extracts ticket departments from tblticketdepartments.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractDepartments(): array
    {
        if (!$this->connector->tableExists('tblticketdepartments')) {
            return [];
        }

        return $this->connector->select('SELECT * FROM tblticketdepartments ORDER BY id ASC');
    }

    /**
     * Extracts support tickets from tbltickets, enriched with replies, attachments, and custom fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractTickets(?int $limit = null, ?int $offset = null): array
    {
        if (!$this->connector->tableExists('tbltickets')) {
            return [];
        }

        $sql = 'SELECT * FROM tbltickets ORDER BY id ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
            if ($offset !== null) {
                $sql .= ' OFFSET ' . (int) $offset;
            }
        }

        $tickets = $this->connector->select($sql);

        foreach ($tickets as &$ticket) {
            $ticketId = (int) ($ticket['id'] ?? 0);

            // Replies
            $ticket['replies'] = $this->extractReplies($ticketId);

            // Attachments
            $ticket['attachments'] = $this->extractAttachments($ticketId);

            // Custom fields
            $ticket['customfields'] = $this->extractCustomFieldValues('support', $ticketId);
        }
        unset($ticket);

        return $tickets;
    }

    /**
     * Extracts replies for a ticket from tblticketreplies.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractReplies(int $ticketId): array
    {
        if (!$this->connector->tableExists('tblticketreplies')) {
            return [];
        }

        return $this->connector->select(
            'SELECT * FROM tblticketreplies WHERE tid = ? ORDER BY id ASC',
            [$ticketId]
        );
    }

    /**
     * Extracts attachments for a ticket from tblticketattachments if exists.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractAttachments(int $ticketId): array
    {
        if ($this->connector->tableExists('tblticketattachments')) {
            return $this->connector->select(
                'SELECT * FROM tblticketattachments WHERE ticketid = ? ORDER BY id ASC',
                [$ticketId]
            );
        }

        return [];
    }

    /**
     * Extracts custom field definitions from tblcustomfields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractCustomFieldDefinitions(): array
    {
        if (!$this->connector->tableExists('tblcustomfields')) {
            return [];
        }

        return $this->connector->select('SELECT * FROM tblcustomfields ORDER BY id ASC');
    }

    /**
     * Extracts custom field values for a specific entity type and ID.
     *
     * @return array<string, mixed>
     */
    public function extractCustomFieldValues(string $type, int $relId): array
    {
        if (!$this->connector->tableExists('tblcustomfields') || !$this->connector->tableExists('tblcustomfieldsvalues')) {
            return [];
        }

        $sql = 'SELECT f.fieldname, v.value
                FROM tblcustomfields f
                JOIN tblcustomfieldsvalues v ON v.fieldid = f.id
                WHERE f.type = ? AND v.relid = ?';

        $rows = $this->connector->select($sql, [$type, $relId]);
        $result = [];

        foreach ($rows as $row) {
            if (isset($row['fieldname'])) {
                $result[(string) $row['fieldname']] = $row['value'] ?? '';
            }
        }

        return $result;
    }
}
