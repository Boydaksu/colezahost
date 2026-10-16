<?php
declare(strict_types=1);
namespace Coleza\Tests\Unit\Updater;

use Coleza\Domain\Updater\UpdatePackageManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class UpdateVersionTest extends TestCase
{
    public static function precedence(): array
    {
        return [
            ['1.0.0+one','1.0.0+two',0],['1.0.0-alpha','1.0.0',-1],
            ['1.0.0-beta.2','1.0.0-beta.11',-1],['1.0.0-alpha','1.0.0-alpha.1',-1],
            ['1.0.0-9','1.0.0-alpha',-1],['1.10.0','1.9.0',1],
            ['99999999999999999999.0.0','9999999999999999999.0.0',1],['1.0.0-rc.1','1.0.0-beta.11',1],
        ];
    }
    #[DataProvider('precedence')]
    public function testSemVerPrecedence(string $left,string $right,int $expected): void
    {
        self::assertSame($expected,UpdatePackageManifest::compareVersions($left,$right));
        self::assertSame(-$expected,UpdatePackageManifest::compareVersions($right,$left));
    }
    public static function malformed(): array {return [['01.0.0'],['1.0'],['1.0.0-01'],['1.0.0-alpha..1'],['1.0.0+'],['1.0.0+build..1'],['../1.0.0']];}
    #[DataProvider('malformed')]
    public function testMalformedVersionIsRejected(string $version): void
    {
        $this->expectException(RuntimeException::class);UpdatePackageManifest::assertVersion($version);
    }
}
