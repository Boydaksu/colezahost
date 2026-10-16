<?php
declare(strict_types=1);
namespace Coleza\Tests\Integration\Providers;
use Coleza\Domain\Providers\Cpanel\{CpanelApiClient,CpanelConfiguration,CpanelCurlTransport,CpanelMemoryTransport};
use Coleza\Domain\Providers\Exceptions\ProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class CpanelTransportTest extends TestCase
{
    private static string $root;
    private static array $ready;
    private static mixed $server;
    private static array $pipes=[];
    public static function setUpBeforeClass():void
    {
        self::$root=sys_get_temp_dir().'/coleza_cpanel_'.bin2hex(random_bytes(12));mkdir(self::$root,0700);
        self::$server=proc_open([getenv('COLEZA_BACKUP_TEST_PYTHON')?:'python','-u',dirname(__DIR__,2).'/Support/cpanel_protocol_server.py',self::$root],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],self::$pipes);
        if(!is_resource(self::$server)){throw new RuntimeException('Cannot start WHM fixture.');}
        stream_set_blocking(self::$pipes[1],false);stream_set_blocking(self::$pipes[2],false);
        $deadline=microtime(true)+20;
        do{$ready=is_file(self::$root.'/ready.json')?json_decode(file_get_contents(self::$root.'/ready.json'),true):null;if(is_array($ready)){self::$ready=$ready;return;}if(!proc_get_status(self::$server)['running']){break;}usleep(50000);}while(microtime(true)<$deadline);
        throw new RuntimeException('WHM fixture unavailable: '.stream_get_contents(self::$pipes[2]));
    }
    public static function tearDownAfterClass():void
    {
        if(isset(self::$pipes[0])&&is_resource(self::$pipes[0])){fclose(self::$pipes[0]);}
        if(isset(self::$server)&&is_resource(self::$server)){
            $deadline=microtime(true)+8;
            do{$status=proc_get_status(self::$server);if(!$status['running']){break;}usleep(50000);}while(microtime(true)<$deadline);
            if($status['running']){proc_terminate(self::$server);throw new RuntimeException('WHM fixture failed to stop gracefully.');}
            $error=stream_get_contents(self::$pipes[2]);
            foreach([1,2] as $index){if(is_resource(self::$pipes[$index])){fclose(self::$pipes[$index]);}}
            proc_close(self::$server);
            if($status['exitcode']!==0||$error!==''){throw new RuntimeException('WHM fixture shutdown failed: '.$error);}
        }
        if(isset(self::$root)){self::remove(self::$root);}
    }
    private static function remove(string $path):void {if(is_link($path)||is_file($path)){unlink($path);return;}if(!is_dir($path)){return;}foreach(scandir($path) as $name){if($name!=='.'&&$name!=='..'){self::remove($path.'/'.$name);}}rmdir($path);}
    protected function setUp():void {file_put_contents(self::$root.'/mode.json','{}');file_put_contents(self::$root.'/requests.jsonl','');}
    private function mode(array $mode):void {file_put_contents(self::$root.'/mode.json',json_encode($mode,JSON_THROW_ON_ERROR));}
    private function requests():array {return array_map(static fn($line)=>json_decode($line,true,flags:JSON_THROW_ON_ERROR),file(self::$root.'/requests.jsonl',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES));}
    private function transport():CpanelCurlTransport {return new CpanelCurlTransport(caBundle:self::$ready['ca_bundle']);}
    private function url():string {return 'https://127.0.0.1:'.self::$ready['port'].'/json-api/version';}
    private function client(?CpanelMemoryTransport $memory=null):CpanelApiClient {return new CpanelApiClient(new CpanelConfiguration('127.0.0.1',apiToken:'fixture-only-token',port:self::$ready['port']),$memory??$this->transport());}
    public function testRealHttpsAuthenticationReturnsVersionAndLatency():void
    {
        $result=$this->client()->testAuthentication();self::assertTrue($result['authenticated']);self::assertSame('11.138.0.1',$result['version']);self::assertGreaterThanOrEqual(1,$result['latency_ms']);
        $requests=$this->requests();self::assertCount(1,$requests);self::assertTrue($requests[0]['authorized']);self::assertSame(['api.version'=>['1']],$requests[0]['query']);
    }
    public function testRealPostSucceedsOnceAndPreservesFormValues():void
    {
        $result=$this->transport()->request('POST',$this->url(),['Authorization'=>'whm root:fixture-only-token'],['api.version'=>1],['domain'=>'örnek.test','password'=>'a&b+c=1']);
        self::assertSame(200,$result['status_code']);self::assertSame('real-https',$result['headers']['x-whm-fixture']);self::assertGreaterThanOrEqual(1,$result['latency_ms']);self::assertCount(1,$this->requests());self::assertSame(['a&b+c=1'],$this->requests()[0]['body']['password']);
    }
    public function testRedirectNeverReplaysCredentials():void
    {
        $this->mode(['status'=>302,'redirect'=>'http://127.0.0.1:'.self::$ready['plain_port'].'/stolen']);
        $result=$this->transport()->request('GET',$this->url(),['Authorization'=>'whm root:fixture-only-token']);self::assertSame(302,$result['status_code']);self::assertCount(1,$this->requests());
    }
    public function testUntrustedCertificateNeverReachesHttp():void
    {
        try{(new CpanelCurlTransport())->request('GET',$this->url());self::fail('Untrusted certificate accepted.');}catch(RuntimeException){self::assertSame([],$this->requests());}
    }
    public function testWrongTlsHostnameNeverReachesHttp():void
    {
        try{$this->transport()->request('GET',str_replace('127.0.0.1','localhost',$this->url()));self::fail('TLS hostname mismatch accepted.');}catch(RuntimeException){self::assertSame([],$this->requests());}
    }
    public function testTlsVerificationCannotBeDisabled():void {$this->expectException(RuntimeException::class);new CpanelCurlTransport(false);}
    #[DataProvider('unsafeRequests')]
    public function testUnsafeRequestsAreRejectedBeforeNetwork(string $kind):void
    {
        $url=$this->url();$headers=['Authorization'=>'whm root:fixture-only-token'];$method='GET';$timeout=3;
        if($kind==='http'){$url='http://127.0.0.1:'.self::$ready['plain_port'].'/';}
        if($kind==='file'){$url='file://'.str_replace('\\','/',self::$root).'/ready.json';}
        if($kind==='userinfo'){$url=str_replace('https://','https://user:pass@',$url);}
        if($kind==='fragment'){$url.='#hidden';}
        if($kind==='header'){$headers['X-Fixture']="value\r\nInjected: yes";}
        if($kind==='method'){$method='TRACE';}
        if($kind==='timeout'){$timeout=0;}
        try{$this->transport()->request($method,$url,$headers,timeout:$timeout);self::fail('Unsafe request accepted.');}catch(RuntimeException){self::assertSame([],$this->requests());}
    }
    public static function unsafeRequests():array {return array_map(static fn($x)=>[$x],['http','file','userinfo','fragment','header','method','timeout']);}
    #[DataProvider('bodyLimits')]
    public function testOversizedResponseRejectedWithAndWithoutContentLength(bool $length):void
    {
        $this->mode(['large'=>2097153,'no_length'=>!$length]);$this->expectException(RuntimeException::class);$this->expectExceptionMessage('response');$this->transport()->request('GET',$this->url());
    }
    public static function bodyLimits():array {return [[true],[false]];}
    public function testTimeoutIsBoundedAndNotRetried():void
    {
        $this->mode(['delay'=>2]);$start=microtime(true);
        try{$this->transport()->request('POST',$this->url(),timeout:1);self::fail('Timeout accepted.');}catch(RuntimeException){self::assertLessThan(2.5,microtime(true)-$start);self::assertCount(1,$this->requests());}
    }
    public function testDisconnectAfterRequestIsNotRetriedOrReportedAsSuccess():void
    {
        $this->mode(['drop'=>true]);try{$this->client()->createAccount(['username'=>'fixture']);self::fail('Disconnected mutation accepted.');}catch(ProviderException $e){self::assertSame('PROVIDER_CONNECTION_FAILED',$e->getErrorCode());self::assertCount(1,$this->requests());}
    }
    #[DataProvider('badResponses')]
    public function testInvalidWhmResponsesNeverAuthenticate(int $status,array|string $body):void
    {
        $memory=new CpanelMemoryTransport();$memory->stageResponse('version',$status,$body);$this->expectException(ProviderException::class);$this->client($memory)->testAuthentication();
    }
    public static function badResponses():array
    {
        return ['redirect'=>[302,['metadata'=>['result'=>1],'data'=>['version'=>'11.1']]],'rate-limit'=>[429,['metadata'=>['result'=>1],'data'=>['version'=>'11.1']]],'empty'=>[200,[]],
            'missing-result'=>[200,['metadata'=>[],'data'=>['version'=>'11.1']]],'boolean-result'=>[200,['metadata'=>['result'=>true],'data'=>['version'=>'11.1']]],
            'missing-version'=>[200,['metadata'=>['result'=>1],'data'=>[]]],'invalid-version'=>[200,['metadata'=>['result'=>1],'data'=>['version'=>[]]]],
            'invalid-reason'=>[200,['metadata'=>['result'=>0,'reason'=>[]]]],'malformed'=>[200,'not JSON'],'auth'=>[401,['metadata'=>['result'=>1]]],'server'=>[503,['metadata'=>['result'=>1]]]];
    }
    public function testHttpFailureWithSuccessBodyCannotCompleteMutationEvenWhenThrowOnErrorFalse():void
    {
        $memory=new CpanelMemoryTransport();$memory->stageResponse('createacct',409,['metadata'=>['result'=>1],'data'=>[]]);$this->expectException(ProviderException::class);$this->client($memory)->createAccount(['username'=>'fixture']);
    }
    #[DataProvider('badLoads')]
    public function testMissingOrInvalidLoadCannotBecomeHealthy(array $data):void
    {
        $memory=new CpanelMemoryTransport();$memory->stageResponse('systemloadavg',200,['metadata'=>['result'=>1],'data'=>$data]);$this->expectException(ProviderException::class);$this->client($memory)->getSystemLoad();
    }
    public static function badLoads():array {return [[[]],[['one'=>0,'five'=>0]],[['one'=>'garbage','five'=>0,'fifteen'=>0]],[['one'=>-1,'five'=>0,'fifteen'=>0]],[['one'=>true,'five'=>0,'fifteen'=>0]]];}
    #[DataProvider('badConfigs')]
    public function testInvalidConfigurationCannotCreateCredentialBearingUrl(array $values):void
    {
        $this->expectException(\InvalidArgumentException::class);new CpanelConfiguration(...array_replace(['hostname'=>'cpanel.example','apiToken'=>'fixture'], $values));
    }
    public static function badConfigs():array {return [[['hostname'=>'host.test/path']],[['hostname'=>'good.test@evil.test']],[['hostname'=>'https://host.test']],[['username'=>"root\r\nInjected: yes"]],[['apiToken'=>'']],[['useSsl'=>false]],[['timeoutSeconds'=>0]],[['apiToken'=>"secret\r\nX: yes"]]];}
    #[DataProvider('badResponses')]
    public function testRealHttpsInvalidWhmResponsesNeverAuthenticate(int $status,array|string $body):void
    {
        $this->mode(['status'=>$status,'body'=>$body]);$this->expectException(ProviderException::class);$this->client()->testAuthentication();
    }
    public function testNegativeMutationResponseRemainsFailureEvidence():void
    {
        $this->mode(['body'=>['metadata'=>['result'=>0,'reason'=>'Permission denied']]]);
        $result=$this->client()->createAccount(['username'=>'fixture']);self::assertSame(0,$result['metadata']['result']);self::assertCount(1,$this->requests());
    }
    public function testRealHealthLoadRequiresAllThreeReadings():void
    {
        $this->mode(['body'=>['metadata'=>['result'=>1],'data'=>['one'=>'0.25','five'=>'1.50','fifteen'=>0]]]);
        $result=$this->client()->getSystemLoad();self::assertSame(0.25,$result['1m']);self::assertSame(1.5,$result['5m']);self::assertSame(0.0,$result['15m']);
    }
    public function testFunctionCannotEscapeConfiguredWhmEndpoint():void
    {
        try{$this->client()->call('../stolen');self::fail('Function traversal accepted.');}catch(ProviderException){self::assertSame([],$this->requests());}
    }
    public function testApiVersionCannotBeOverriddenByOperationParameters():void
    {
        $this->client()->call('version',['api.version'=>999]);self::assertSame(['1'],$this->requests()[0]['query']['api.version']);
    }
    public function testIpv6ConfigurationUsesBracketedAuthorityAndKeepsTls():void
    {
        $config=new CpanelConfiguration('::1',apiToken:'fixture');self::assertSame('https://[::1]:2087',$config->getBaseUrl());
    }
    public function testConnectionOptionsCannotHideInvalidTimeout():void
    {
        $this->expectException(\InvalidArgumentException::class);
        CpanelConfiguration::fromServerConnection(new \Coleza\Domain\Providers\DTO\ServerConnectionDto(1,'host.test',authSecret:'fixture',options:['timeout_seconds'=>'not-a-timeout']));
    }
    public function testUnknownAuthenticationTypeIsNotGuessedAsToken():void
    {
        $this->expectException(\InvalidArgumentException::class);
        CpanelConfiguration::fromServerConnection(new \Coleza\Domain\Providers\DTO\ServerConnectionDto(1,'host.test',authType:'unknown',authSecret:'fixture'));
    }

    public function testExplicitLegacySuccessRemainsSupported():void
    {
        $this->mode(['body'=>['result'=>[['status'=>1,'statusmsg'=>'OK']]]]);$result=$this->client()->suspendAccount('fixture');self::assertSame(1,$result['result'][0]['status']);
    }
    public function testExplicitLegacyFailureIsNotConvertedIntoSuccess():void
    {
        $this->mode(['body'=>['result'=>[['status'=>0,'statusmsg'=>'Denied']]]]);$result=$this->client()->suspendAccount('fixture');self::assertSame(0,$result['result'][0]['status']);
    }

}
