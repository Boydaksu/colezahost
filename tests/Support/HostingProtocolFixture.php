<?php
declare(strict_types=1);
namespace Coleza\Tests\Support;
use RuntimeException;
final class HostingProtocolFixture
{
    public string $root;
    public array $ready;
    private mixed $server;
    private array $pipes=[];
    private array $workers=[];
    public function __construct()
    {
        $this->root=sys_get_temp_dir().'/coleza_host_'.bin2hex(random_bytes(12));mkdir($this->root,0700);
        $this->server=proc_open([getenv('COLEZA_BACKUP_TEST_PYTHON')?:'python','-u',__DIR__.'/hosting_protocol_server.py',$this->root],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$this->pipes);
        if(!is_resource($this->server)){throw new RuntimeException('Cannot start hosting HTTPS fixture.');}
        stream_set_blocking($this->pipes[1],false);stream_set_blocking($this->pipes[2],false);
        $this->wait(fn()=>is_file($this->root.'/ready.json'),20);$this->ready=json_decode(file_get_contents($this->root.'/ready.json'),true,flags:JSON_THROW_ON_ERROR);
    }
    public function wait(callable $condition,int $seconds=15):void
    {
        $deadline=microtime(true)+$seconds;do{if($condition()){return;}usleep(10000);}while(microtime(true)<$deadline);
        throw new RuntimeException('Hosting process fixture barrier timed out.');
    }
    public function mode(array $mode):void
    {
        $temp=$this->root.'/mode.next';file_put_contents($temp,json_encode((object)$mode,JSON_THROW_ON_ERROR));rename($temp,$this->root.'/mode.json');
    }
    public function requests(?string $endpoint=null):array
    {
        $rows=array_map(static fn($s)=>json_decode($s,true,flags:JSON_THROW_ON_ERROR),file($this->root.'/requests.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));
        return $endpoint===null?$rows:array_values(array_filter($rows,static fn($r)=>$r['endpoint']===$endpoint));
    }
    public function startWorker(array $config,string $name,bool $barrier=false):void
    {
        $config+=['root'=>$this->root,'ca'=>$this->ready['ca_bundle'],'barrier'=>$barrier,'name'=>$name];
        $file=$this->root.'/'.$name.'.json';file_put_contents($file,json_encode($config,JSON_THROW_ON_ERROR));
        $command=[PHP_BINARY];if(php_ini_loaded_file()!==false){$command[]='-c';$command[]=php_ini_loaded_file();}$command[]=__DIR__.'/hosting-worker.php';$command[]=$file;
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process)){throw new RuntimeException('Cannot start hosting worker.');}
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$this->workers[$name]=[$process,$pipes];
        $this->wait(fn()=>is_file($this->root.'/'.$name.'.ready'));
    }
    public function result(string $name):array
    {
        [$process,$pipes]=$this->workers[$name];$this->wait(fn()=>!proc_get_status($process)['running']);
        $output=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);
        if($error!==''){throw new RuntimeException('Hosting worker stderr: '.$error);}
        foreach([1,2]as$i){fclose($pipes[$i]);}proc_close($process);unset($this->workers[$name]);
        return json_decode($output,true,flags:JSON_THROW_ON_ERROR);
    }
    public function kill(string $name):void
    {
        [$process,$pipes]=$this->workers[$name];proc_terminate($process);$this->wait(fn()=>!proc_get_status($process)['running']);
        foreach([1,2]as$i){fclose($pipes[$i]);}proc_close($process);unset($this->workers[$name]);
    }
    public function release():void{file_put_contents($this->root.'/release','go');}
    public function close():void
    {
        foreach(array_keys($this->workers)as$name){$this->kill($name);}$this->release();
        if(is_resource($this->server)){
            fclose($this->pipes[0]);$this->wait(fn()=>!proc_get_status($this->server)['running'],10);
            $error=stream_get_contents($this->pipes[2]);foreach([1,2]as$i){fclose($this->pipes[$i]);}proc_close($this->server);$this->server=null;
            if($error!==''){throw new RuntimeException('Hosting HTTPS fixture stderr: '.$error);}
        }
        $root=realpath($this->root);$temp=realpath(sys_get_temp_dir());
        if($root===false||$temp===false||dirname($root)!==$temp||!preg_match('/^coleza_host_[a-f0-9]{24}$/D',basename($root))){throw new RuntimeException('Unsafe fixture cleanup path.');}
        self::remove($root);
    }
    private static function remove(string $path):void
    {
        if(is_link($path)||is_file($path)){unlink($path);return;}foreach(scandir($path)as$name){if($name!=='.'&&$name!=='..'){self::remove($path.'/'.$name);}}rmdir($path);
    }
}
