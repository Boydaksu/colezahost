<?php
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';

use Coleza\Domain\Backup\Storage\SftpCurlBackupTransport;

$expect=$argv[1]??'supported';
if(!in_array($expect,['supported','unsupported'],true)){throw new RuntimeException('Expected supported or unsupported.');}
$info=curl_version();
$probe=curl_init();
$native=defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')&&curl_setopt($probe,CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256,base64_encode(str_repeat("\0",32)));
$failure=null;
try{
    new SftpCurlBackupTransport(['host'=>'127.0.0.1','username'=>'probe','password'=>'probe','host_key_sha256'=>base64_encode(str_repeat("\0",32)),'remote_path'=>'/backups']);
}catch(RuntimeException $error){$failure=$error->getMessage();}
$actual=$failure===null;
if($actual!==$native||$actual!==($expect==='supported')||(!$actual&&!str_contains($failure,'SHA256 SSH host-key pinning'))){throw new RuntimeException('SFTP runtime admission contract failed.');}
echo json_encode(['php'=>PHP_VERSION,'curl'=>$info['version'],'ssh'=>$info['libssh_version']??'unavailable','sha256_host_pin'=>$native,'admitted'=>$actual,'network_requests'=>0,'diagnostic'=>$failure],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
