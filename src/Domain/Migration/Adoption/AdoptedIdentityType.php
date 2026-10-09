<?php

declare(strict_types=1);

namespace Coleza\Domain\Migration\Adoption;

enum AdoptedIdentityType: string
{
    case HOSTING_SERVICE = 'hosting_service';
    case DOMAIN_REGISTRATION = 'domain_registration';
}
