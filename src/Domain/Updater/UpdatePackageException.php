<?php
declare(strict_types=1);
namespace Coleza\Domain\Updater;
final class UpdatePackageException extends \RuntimeException
{
    public function __construct(string $message,public readonly bool $signatureValid=false,public readonly array $tampered=[],public readonly array $missing=[])
    {parent::__construct($message);}
}
