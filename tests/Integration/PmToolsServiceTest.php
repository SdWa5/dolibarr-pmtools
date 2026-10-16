<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

/**
 * Runs against a real Dolibarr database. Every test works inside a transaction that is rolled
 * back, so the mirror keeps its data. See the README for the command.
 */
final class PmToolsServiceTest extends TestCase
{
    private PmToolsService $service;

    protected function setUp(): void
    {
        global $db;
        if (!getenv('DOLIBARR_MAIN_INC')) {
            $this->markTestSkipped('DOLIBARR_MAIN_INC is not set.');
        }
        $db->begin();
        $this->service = new PmToolsService($db, $this->admin());
    }

    protected function tearDown(): void
    {
        global $db;
        if (getenv('DOLIBARR_MAIN_INC')) {
            $db->rollback();
        }
    }

    public function testDictionaryUpsertIsIdempotent(): void
    {
        $entry = ['code' => 'PMTOOLSTEST', 'label' => 'Test type', 'pos' => 50];
        $this->assertSame('created', $this->service->upsertDictionaryEntry('ticket_types', $entry)['action']);
        $this->assertSame('unchanged', $this->service->upsertDictionaryEntry('ticket_types', $entry)['action']);

        $updated = $this->service->upsertDictionaryEntry('ticket_types', ['code' => 'pmtoolstest', 'label' => 'Renamed']);
        $this->assertSame('updated', $updated['action']);
        $this->assertSame('Renamed', $updated['entry']['label']);
        $this->assertSame('50', (string) $updated['entry']['pos']);

        $codes = array_column($this->service->listDictionary('ticket_types'), 'code');
        $this->assertContains('PMTOOLSTEST', $codes);
    }

    public function testTicketLifecycle(): void
    {
        $this->service->upsertDictionaryEntry('ticket_types', ['code' => 'PMTOOLSTEST', 'label' => 'Test type']);
        $created = $this->service->createTicket(['subject' => 'PmTools test', 'message' => 'Body', 'type_code' => 'PMTOOLSTEST']);
        $this->assertSame('PMTOOLSTEST', $created['type_code']);
        $this->assertSame('new', $created['status']);

        $updated = $this->service->updateTicket($created['ref'], ['subject' => 'PmTools test renamed']);
        $this->assertSame('updated', $updated['action']);
        $this->assertSame('PmTools test renamed', $updated['ticket']['subject']);
        $this->assertSame('unchanged', $this->service->updateTicket($created['ref'], ['subject' => 'PmTools test renamed'])['action']);

        $withMessage = $this->service->addMessage($created['id'], 'A private note');
        $this->assertContains('A private note', array_column($withMessage['messages'], 'message'));

        $closed = $this->service->closeTicket($created['ref'], 'closed');
        $this->assertSame('closed', $closed['action']);
        $this->assertSame('closed', $closed['ticket']['status']);
        $this->assertNotSame('', $closed['ticket']['date_closed']);
        $this->assertSame('unchanged', $this->service->closeTicket($created['ref'], 'closed')['action']);

        try {
            $this->service->closeTicket($created['ref'], 'canceled');
            $this->fail('Switching closed to canceled must be refused.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Reopen it first', $e->getMessage());
        }

        $reopened = $this->service->reopenTicket($created['ref']);
        $this->assertSame('reopened', $reopened['action']);
        $this->assertSame('unchanged', $this->service->reopenTicket($created['ref'])['action']);
        $this->assertSame('canceled', $this->service->closeTicket($created['ref'], 'canceled')['ticket']['status']);
    }

    public function testUnknownCodesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->createTicket(['subject' => 'x', 'message' => 'y', 'type_code' => 'NO_SUCH_TYPE']);
    }

    public function testUserWithoutRightsIsDenied(): void
    {
        global $db;
        $nobody = new User($db);
        $nobody->id = 0;
        $nobody->rights = new stdClass();
        $service = new PmToolsService($db, $nobody);

        $this->expectException(PmToolsDeniedException::class);
        $service->upsertDictionaryEntry('ticket_types', ['code' => 'X', 'label' => 'x']);
    }

    public function testMissingTicketIsNotFound(): void
    {
        $this->expectException(PmToolsNotFoundException::class);
        $this->service->getTicket('NOPE-0000');
    }

    private function admin(): User
    {
        global $db;
        $admin = new User($db);
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'user WHERE admin = 1 AND statut = 1 ORDER BY rowid LIMIT 1';
        $row = $db->fetch_object($db->query($sql));
        $admin->fetch((int) $row->rowid);
        $admin->loadRights();

        return $admin;
    }
}
