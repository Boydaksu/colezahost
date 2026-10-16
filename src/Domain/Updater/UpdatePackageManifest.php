<?php

declare(strict_types=1);

namespace Coleza\Domain\Updater;

/**
 * Manifest definition for an update package.
 */
final class UpdatePackageManifest
{
    /**
     * @param string $version Target application version (e.g. '1.1.0')
     * @param string $minCurrentVersion Minimum installed version required to apply this update
     * @param string $releaseNotes Markdown or text summary of release changes
     * @param array<string, string> $fileChecksums Relative file path => SHA256 checksum
     * @param array<string, string> $requiredModules Module id => minimum compatible version
     * @param array<int, string> $migrations Ordered list of migration class/file names to run
     * @param string $releasedAt ISO 8601 release timestamp
     */
    public function __construct(
        private string $version,
        private string $minCurrentVersion,
        private string $releaseNotes,
        private array $fileChecksums,
        private array $requiredModules = [],
        private array $migrations = [],
        private ?string $releasedAt = null
    ) {
        $this->releasedAt = $releasedAt ?? date('c');
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getMinCurrentVersion(): string
    {
        return $this->minCurrentVersion;
    }

    public function getReleaseNotes(): string
    {
        return $this->releaseNotes;
    }

    /**
     * @return array<string, string>
     */
    public function getFileChecksums(): array
    {
        return $this->fileChecksums;
    }

    /**
     * @return array<string, string>
     */
    public function getRequiredModules(): array
    {
        return $this->requiredModules;
    }

    /**
     * @return array<int, string>
     */
    public function getMigrations(): array
    {
        return $this->migrations;
    }

    public function getReleasedAt(): string
    {
        return (string) $this->releasedAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'min_current_version' => $this->minCurrentVersion,
            'release_notes' => $this->releaseNotes,
            'file_checksums' => $this->fileChecksums,
            'required_modules' => $this->requiredModules,
            'migrations' => $this->migrations,
            'released_at' => $this->releasedAt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function assertVersion(string $version): void
    {
        if(strlen($version)>100||!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*))?(?:\+[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*)?$/D',$version,$matches)){
            throw new \RuntimeException('Invalid update semantic version.');
        }
        foreach(explode('.',$matches[4]??'') as $part){if(ctype_digit($part)&&strlen($part)>1&&$part[0]==='0'){throw new \RuntimeException('Invalid numeric prerelease identifier.');}}
    }

    /** SemVer precedence: build metadata is ignored; prerelease identifiers use numeric/ASCII order. */
    public static function compareVersions(string $left,string $right): int
    {
        self::assertVersion($left);self::assertVersion($right);
        $parse=static function(string $version): array {$pieces=explode('-',explode('+',$version,2)[0],2);return [explode('.',$pieces[0]),isset($pieces[1])?explode('.',$pieces[1]):[]];};
        [$a,$preA]=$parse($left);[$b,$preB]=$parse($right);
        $number=static fn(string $x,string $y):int=>(strlen($x)<=>strlen($y))?:(strcmp($x,$y)<=>0);
        for($i=0;$i<3;$i++){if(($comparison=$number($a[$i],$b[$i]))!==0){return $comparison;}}
        if($preA===[]||$preB===[]){return ($preA===[]?1:0)<=>($preB===[]?1:0);}
        for($i=0;$i<min(count($preA),count($preB));$i++){
            $x=$preA[$i];$y=$preB[$i];$nx=ctype_digit($x);$ny=ctype_digit($y);
            $comparison=$nx&&$ny?$number($x,$y):($nx!==$ny?($nx?-1:1):(strcmp($x,$y)<=>0));
            if($comparison!==0){return $comparison;}
        }
        return count($preA)<=>count($preB);
    }

    public static function fromArray(array $data): self
    {
        foreach(['version','min_current_version'] as $key){if(!is_string($data[$key]??null)){throw new \RuntimeException('Missing or invalid update '.$key);}self::assertVersion($data[$key]);}
        foreach(['release_notes','released_at'] as $key){if(array_key_exists($key,$data)&&!is_string($data[$key])){throw new \RuntimeException('Invalid update '.$key);}}
        if(!is_array($data['file_checksums']??null)||$data['file_checksums']===[]||count($data['file_checksums'])>100000){throw new \RuntimeException('Update requires a nonempty bounded checksum inventory.');}
        $seen=[];$components=[];
        foreach($data['file_checksums'] as $name=>$hash){
            if(!is_string($name)||str_ends_with($name,'/')){throw new \RuntimeException('Invalid update file path.');}
            \Coleza\Domain\Backup\BackupFileTree::path($name);
            $prefix='';foreach(explode('/',$name) as $component){$prefix=$prefix===''?$component:$prefix.'/'.$component;$fold=mb_strtolower($prefix,'UTF-8');if(isset($components[$fold])&&$components[$fold]!==$prefix){throw new \RuntimeException('Case-colliding update path components.');}$components[$fold]=$prefix;}
            $key=mb_strtolower($name,'UTF-8');if(isset($seen[$key])){throw new \RuntimeException('Case-colliding update paths.');}$seen[$key]=true;
            if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash)){throw new \RuntimeException('Invalid update checksum for '.$name);}
        }
        foreach($seen as $name=>$_){$parent=dirname($name);while($parent!=='.'){if(isset($seen[$parent])){throw new \RuntimeException('Update file/directory collision.');}$parent=dirname($parent);}}
        foreach(['required_modules','migrations'] as $field){if(array_key_exists($field,$data)&&!is_array($data[$field])){throw new \RuntimeException('Invalid update '.$field);}}
        $modules=$data['required_modules']??[];if(!is_array($modules)){throw new \RuntimeException('Invalid required module inventory.');}
        foreach($modules as $name=>$version){if(!is_string($name)||!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D',$name)||!is_string($version)){throw new \RuntimeException('Invalid required module identity/version.');}self::assertVersion($version);}
        $migrations=$data['migrations']??[];if(!is_array($migrations)||!array_is_list($migrations)||count(array_unique($migrations,SORT_REGULAR))!==count($migrations)){throw new \RuntimeException('Invalid ordered update migration list.');}
        foreach($migrations as $migration){if(!is_string($migration)||!isset($data['file_checksums'][$migration])||!str_ends_with($migration,'.php')){throw new \RuntimeException('Every migration must name a checksummed PHP payload file.');}}
        return new self($data['version'],$data['min_current_version'],$data['release_notes']??'',$data['file_checksums'],$modules,$migrations,$data['released_at']??null);
    }
}
