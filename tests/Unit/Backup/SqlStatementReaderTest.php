<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Backup;
use Coleza\Domain\Backup\SqlStatementReader;
use PHPUnit\Framework\TestCase;
final class SqlStatementReaderTest extends TestCase
{
    public function testQuotedSemicolonsCommentsAndMultipleStatementsPerLine(): void
    {
        $sql="-- start\nINSERT INTO `odd;table` VALUES ('first;\n-- retained','DPO''s'); /* comment; */ SELECT '/*text*/';SELECT 3;";
        self::assertSame(["INSERT INTO `odd;table` VALUES ('first;\n-- retained','DPO''s')","SELECT '/*text*/'",'SELECT 3'],iterator_to_array(SqlStatementReader::read($sql)));
    }
    public function testBackslashEscapesAreExplicitAndDoNotChangeSqliteStrings(): void
    {
        self::assertSame(["SELECT 'a\\\\'"],iterator_to_array(SqlStatementReader::read("SELECT 'a\\\\';",false)));
        self::assertSame(["SELECT 'DPO\\'s; x'"],iterator_to_array(SqlStatementReader::read("SELECT 'DPO\\'s; x';",true)));
    }
    public function testLexicalTokensCanCrossStreamChunkBoundaries(): void
    {
        $sql='SELECT '.str_repeat(' ',65527)."'a'';b';SELECT 2;";self::assertCount(2,iterator_to_array(SqlStatementReader::read($sql)));
    }
    public static function malformed(): array {return [["SELECT 'unfinished;"],['SELECT 1'],['SELECT 1; /* unterminated'],['/*! SELECT 1 */;']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function testMalformedSqlCannotBeSilentlyTruncated(string $sql): void {$this->expectException(\RuntimeException::class);iterator_to_array(SqlStatementReader::read($sql));}
}
