<?php

/* SPDX-License-Identifier: GPL-3.0-or-later */

use PHPUnit\Framework\TestCase;

final class PmToolsValidatorTest extends TestCase
{
    public function testDictionaryRejectsNamesOutsideTheWhitelist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PmToolsValidator::dictionary('c_ticket_type');
    }

    public function testDictionaryEntryNormalizesCodeAndValues(): void
    {
        $entry = PmToolsValidator::dictionaryEntry('ticket_types', [
            'code' => ' recurring ',
            'label' => ' Dauerauftrag ',
            'pos' => '50',
            'active' => 1,
        ], true);

        $this->assertSame(['code' => 'RECURRING', 'label' => 'Dauerauftrag', 'pos' => 50, 'active' => 1], $entry);
    }

    public function testNewEntryNeedsALabel(): void
    {
        $this->expectExceptionMessage('A new entry needs a label.');
        PmToolsValidator::dictionaryEntry('ticket_types', ['code' => 'X'], true);
    }

    public function testExistingEntryMayOmitTheLabel(): void
    {
        $this->assertSame(['code' => 'X', 'pos' => 3], PmToolsValidator::dictionaryEntry('ticket_types', ['code' => 'X', 'pos' => 3], false));
    }

    /**
     * @dataProvider invalidEntries
     *
     * @param array<string, mixed> $entry
     */
    public function testInvalidEntriesAreRejected(string $dictionary, array $entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        PmToolsValidator::dictionaryEntry($dictionary, $entry, true);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public function invalidEntries(): iterable
    {
        yield 'code with a dash' => ['ticket_types', ['code' => 'A-B', 'label' => 'x']];
        yield 'code too long' => ['ticket_types', ['code' => str_repeat('A', 33), 'label' => 'x']];
        yield 'unknown field' => ['ticket_types', ['code' => 'A', 'label' => 'x', 'rowid' => 1]];
        yield 'color on types' => ['ticket_types', ['code' => 'A', 'label' => 'x', 'color' => 'ff0000']];
        yield 'public on severities' => ['ticket_severities', ['code' => 'A', 'label' => 'x', 'public' => 1]];
        yield 'flag above one' => ['ticket_types', ['code' => 'A', 'label' => 'x', 'active' => 2]];
        yield 'negative pos' => ['ticket_types', ['code' => 'A', 'label' => 'x', 'pos' => -1]];
        yield 'fractional pos' => ['ticket_types', ['code' => 'A', 'label' => 'x', 'pos' => '1.5']];
        yield 'bad color' => ['ticket_severities', ['code' => 'A', 'label' => 'x', 'color' => 'red']];
        yield 'label too long' => ['ticket_types', ['code' => 'A', 'label' => str_repeat('x', 129)]];
    }

    public function testColorLosesItsHash(): void
    {
        $entry = PmToolsValidator::dictionaryEntry('ticket_severities', ['code' => 'A', 'label' => 'x', 'color' => '#00ff00'], true);
        $this->assertSame('00ff00', $entry['color']);
    }

    public function testChangesListsOnlyDifferingColumns(): void
    {
        $stored = ['rowid' => '7', 'code' => 'A', 'label' => 'Old', 'pos' => '10', 'active' => '1'];
        $this->assertSame(['label' => 'New'], PmToolsValidator::changes($stored, ['code' => 'A', 'label' => 'New', 'pos' => 10, 'active' => 1]));
        $this->assertSame([], PmToolsValidator::changes($stored, ['code' => 'A', 'label' => 'Old']));
    }

    public function testCloseModes(): void
    {
        $this->assertSame(0, PmToolsValidator::closeMode('closed'));
        $this->assertSame(1, PmToolsValidator::closeMode('canceled'));
        $this->expectException(InvalidArgumentException::class);
        PmToolsValidator::closeMode('done');
    }

    public function testCloseDecision(): void
    {
        $this->assertSame('close', PmToolsValidator::closeDecision(0, 0));
        $this->assertSame('close', PmToolsValidator::closeDecision(3, 1));
        $this->assertSame('unchanged', PmToolsValidator::closeDecision(8, 0));
        $this->assertSame('unchanged', PmToolsValidator::closeDecision(9, 1));
    }

    public function testCloseDecisionRefusesSwitchingTheEndStatus(): void
    {
        $this->expectException(DomainException::class);
        PmToolsValidator::closeDecision(8, 1);
    }

    public function testReopenStatus(): void
    {
        $this->assertNull(PmToolsValidator::reopenStatus(3, 1));
        $this->assertSame(2, PmToolsValidator::reopenStatus(8, 4));
        $this->assertSame(0, PmToolsValidator::reopenStatus(9, 0));
    }

    public function testTicketRef(): void
    {
        $this->assertSame(['id' => 12, 'ref' => ''], PmToolsValidator::ticketRef('12'));
        $this->assertSame(['id' => 12, 'ref' => ''], PmToolsValidator::ticketRef(12));
        $this->assertSame(['id' => 0, 'ref' => 'TS2610-0050'], PmToolsValidator::ticketRef(' TS2610-0050 '));
        $this->expectException(InvalidArgumentException::class);
        PmToolsValidator::ticketRef("TS' OR 1=1");
    }

    public function testLimit(): void
    {
        $this->assertSame(50, PmToolsValidator::limit(null));
        $this->assertSame(500, PmToolsValidator::limit(9999));
        $this->assertSame(5, PmToolsValidator::limit('5'));
        $this->expectException(InvalidArgumentException::class);
        PmToolsValidator::limit(0);
    }
}
