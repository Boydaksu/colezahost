<?php
declare(strict_types=1);
namespace Coleza\Application\Commerce;

use Coleza\Application\Identity\CommandAuthorizer;
use Coleza\Foundation\Exceptions\AuthorizationException;

/** Decisions are reused only inside one already-authorized list command. */
final class CommerceListVisibility
{
    public static function filter(array $rows,CommandAuthorizer $authorization,int $actor,string $systemPermission,string $organizationPermission): array
    {
        $visible=[];$decisions=[];
        foreach($rows as$row){
            $owner=$row->getUserId();$org=$row->getOrganizationId();
            if($org===null&&$owner===$actor){$visible[]=$row;continue;}
            $key=$owner.':'.($org??'personal');
            if(!array_key_exists($key,$decisions)){
                try{$authorization->resource($actor,$owner,$org,$systemPermission,$organizationPermission);$decisions[$key]=true;}
                catch(AuthorizationException){$decisions[$key]=false;}
            }
            if($decisions[$key]){$visible[]=$row;}
        }
        return $visible;
    }
}
