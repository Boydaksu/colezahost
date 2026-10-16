<?php

declare(strict_types=1);

namespace Coleza\Domain\Module;

use Coleza\Foundation\Exceptions\ValidationException;

final class ModuleManifest
{
    public const VALID_TYPES = ['payment_gateway', 'server_provisioner', 'domain_registrar', 'notification_channel', 'integration'];

    /**
     * @param array<int, string> $capabilities
     * @param array<int, string> $permissions
     * @param array<string, string> $dependencies
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $version,
        public string $type,
        public string $minCoreVersion,
        public array $capabilities = [],
        public array $permissions = [],
        public array $dependencies = [],
        public ?string $description = null,
        public ?string $author = null,
        public ?string $maxCoreVersion = null,
        public string $extensionApiVersion = '1.0.0'
    ) {
    }

    /**
     * Validate and create manifest from raw array.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $errors=[];
        foreach(['id','name','version','type'] as $key){if(!is_string($data[$key]??null)||trim($data[$key])===''||strlen($data[$key])>150){$errors[$key][]='A bounded string is required.';}}
        foreach(['min_core_version','max_core_version','extension_api_version','description','author'] as $key){if(array_key_exists($key,$data)&&!is_string($data[$key])&& !($data[$key]===null&&in_array($key,['max_core_version','description','author'],true))){$errors[$key][]='Invalid field type.';}}
        if($errors!==[]){throw new ValidationException($errors,'Invalid module manifest.');}
        $id=$data['id'];$name=$data['name'];$version=$data['version'];$type=$data['type'];$minCoreVersion=$data['min_core_version']??'1.0.0';
        if(!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$id)){$errors['id'][]='Invalid bounded module ID.';}
        try{\Coleza\Domain\Backup\BackupFileTree::path($id);}catch(\RuntimeException){$errors['id'][]='Non-portable module ID.';}
        if(!in_array($type,self::VALID_TYPES,true)){$errors['type'][]='Invalid module type.';}
        try{
            foreach([$version,$minCoreVersion,$data['extension_api_version']??'1.0.0'] as $value){\Coleza\Domain\Updater\UpdatePackageManifest::assertVersion($value);}
            if(isset($data['max_core_version'])&&$data['max_core_version']!==''){\Coleza\Domain\Updater\UpdatePackageManifest::assertVersion($data['max_core_version']);if(\Coleza\Domain\Updater\UpdatePackageManifest::compareVersions($minCoreVersion,$data['max_core_version'])>0){throw new \RuntimeException('Inverted bounds.');}}
        }catch(\RuntimeException){$errors['version'][]='Invalid version or core bounds.';}
        foreach(['capabilities','permissions'] as $key){
            $values=array_key_exists($key,$data)?$data[$key]:[];
            if(!is_array($values)||!array_is_list($values)||count($values)>100){$errors[$key][]='A bounded list is required.';continue;}
            $seen=[];foreach($values as $value){if(!is_string($value)||$value===''||strlen($value)>255||isset($seen[$value])){$errors[$key][]='Invalid or duplicate list entry.';break;}$seen[$value]=true;}
        }
        $dependencies=array_key_exists('dependencies',$data)?$data['dependencies']:[];
        if(!is_array($dependencies)||count($dependencies)>100){$errors['dependencies'][]='Invalid dependency map.';}
        else{foreach($dependencies as $dependency=>$required){try{if(!is_string($dependency)||!preg_match('/^[a-z][a-z0-9_-]{0,39}$/D',$dependency)||$dependency===$id||!is_string($required)){throw new \RuntimeException();}\Coleza\Domain\Updater\UpdatePackageManifest::assertVersion($required);}catch(\RuntimeException){$errors['dependencies'][]='Invalid dependency.';}}}
        if($errors!==[]){throw new ValidationException($errors,'Invalid module manifest.');}

        return new self(
            id: $id,
            name: $name,
            version: $version,
            type: $type,
            minCoreVersion: $minCoreVersion,
            capabilities: is_array($data['capabilities'] ?? null) ? $data['capabilities'] : [],
            permissions: is_array($data['permissions'] ?? null) ? $data['permissions'] : [],
            dependencies: is_array($data['dependencies'] ?? null) ? $data['dependencies'] : [],
            description: isset($data['description']) ? (string) $data['description'] : null,
            author: $data['author']??null,
            maxCoreVersion: ($data['max_core_version']??'')!==''?$data['max_core_version']:null,
            extensionApiVersion: $data['extension_api_version']??'1.0.0'
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'type' => $this->type,
            'min_core_version' => $this->minCoreVersion,
            'capabilities' => $this->capabilities,
            'permissions' => $this->permissions,
            'dependencies' => $this->dependencies,
            'description' => $this->description,
            'author' => $this->author,
            'max_core_version' => $this->maxCoreVersion??'',
            'extension_api_version' => $this->extensionApiVersion,
        ];
    }
}
