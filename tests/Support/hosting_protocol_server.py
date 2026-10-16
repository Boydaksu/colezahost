"""Stateful loopback WHM fixture: real TLS, request journal and explicit failure/crash barriers."""
import datetime,ipaddress,json,socket,ssl,sys,threading,time
from pathlib import Path
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from urllib.parse import urlsplit,parse_qs
from cryptography import x509
from cryptography.hazmat.primitives import hashes,serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
root=Path(sys.argv[1]);lock=threading.Lock();accounts={};counts={};stopping=threading.Event()
def save(name,value):
    temp=root/(name+'.tmp');temp.write_text(json.dumps(value),encoding='utf-8');temp.replace(root/name)
def mode():
    return json.loads((root/'mode.json').read_text(encoding='utf-8'))
def ok(data):return {'metadata':{'result':1,'reason':'OK','version':1},'data':data}
class Server(ThreadingHTTPServer):daemon_threads=False
class Whm(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def handle_request(self):
        endpoint=urlsplit(self.path).path.rsplit('/',1)[-1];query=parse_qs(urlsplit(self.path).query)
        body=parse_qs(self.rfile.read(int(self.headers.get('Content-Length','0'))).decode());settings=mode();params=body if self.command=='POST' else query
        authorized=self.headers.get('Authorization')=='whm root:fixture-only-token'
        record={'method':self.command,'endpoint':endpoint,'authorized':authorized,'query':query,'body_keys':sorted(body)}
        with lock:
            counts[endpoint]=counts.get(endpoint,0)+1;occurrence=counts[endpoint]
            with (root/'requests.jsonl').open('a',encoding='utf-8')as out:out.write(json.dumps(record)+'\n')
            if not authorized:status=401;payload={'metadata':{'result':0,'reason':'Denied'}}
            elif endpoint=='listpkgs':status=200;payload=ok({'pkg':[{'name':'starter','QUOTA':'100','BWLIMIT':'1000'}]})
            elif endpoint=='createacct':
                status=200;user=params.get('username',[''])[0]
                if user in accounts:payload={'metadata':{'result':0,'reason':'Exists'}}
                else:
                    accounts[user]={'user':user,'domain':params.get('domain',[''])[0],'plan':params.get('plan',[''])[0],'ip':'127.0.0.2','suspended':0};payload=ok({'ip':'127.0.0.2'})
            elif endpoint in ['suspendacct','unsuspendacct','removeacct']:
                status=200;user=params.get('user',[''])[0]
                if user not in accounts:payload={'metadata':{'result':0,'reason':'Missing'}}
                else:
                    if endpoint=='removeacct':del accounts[user]
                    else:accounts[user]['suspended']=1 if endpoint=='suspendacct' else 0
                    payload=ok({})
            elif endpoint=='accountsummary':
                status=200;user=params.get('user',[''])[0];payload=ok({'acct':[dict(accounts[user])]}) if user in accounts else {'metadata':{'result':0,'reason':'Missing'}}
            elif endpoint=='listaccts':status=200;payload=ok({'acct':[dict(a) for a in accounts.values()]})
            else:status=404;payload={'metadata':{'result':0,'reason':'Unsupported'}}
            save('accounts.json',accounts)
        pause=settings.get('pause',[])
        if (pause==endpoint or isinstance(pause,list) and endpoint in pause) and occurrence==settings.get('pause_nth',1):
            (root/'paused').write_text(endpoint,encoding='utf-8');deadline=time.monotonic()+25
            while not (root/'release').exists() and not stopping.is_set() and time.monotonic()<deadline:time.sleep(.01)
        failure=settings.get('failure') if settings.get('endpoint')==endpoint else None
        if failure=='drop':
            self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
        if failure=='timeout':time.sleep(2)
        if failure=='http503':status=503
        if failure=='negative':payload={'metadata':{'result':0,'reason':'Fixture denied'}}
        if failure=='malformed':payload='not JSON'
        if failure=='mismatch' and endpoint=='accountsummary' and 'data' in payload:payload['data']['acct'][0]['domain']='other.test'
        if failure=='ambiguous' and endpoint=='accountsummary':payload=ok({'acct':[]})
        raw=payload.encode() if isinstance(payload,str) else json.dumps(payload).encode()
        try:
            self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
        except(BrokenPipeError,ConnectionResetError,ConnectionAbortedError,ssl.SSLError):pass
    do_GET=handle_request
    do_POST=handle_request
key=rsa.generate_private_key(public_exponent=65537,key_size=2048);name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'hosting-loopback')]);now=datetime.datetime.now(datetime.timezone.utc)
cert=(x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(minutes=5)).not_valid_after(now+datetime.timedelta(days=1)).add_extension(x509.BasicConstraints(ca=True,path_length=None),critical=True).add_extension(x509.SubjectAlternativeName([x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]),critical=False).sign(key,hashes.SHA256()))
(root/'ca.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM));(root/'key.pem').write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
save('mode.json',{});save('accounts.json',{});(root/'requests.jsonl').write_text('')
server=Server(('127.0.0.1',0),Whm);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(root/'ca.pem',root/'key.pem');server.socket=ctx.wrap_socket(server.socket,server_side=True)
threading.Thread(target=lambda:server.serve_forever(poll_interval=.05),daemon=True).start();save('ready.json',{'port':server.server_port,'ca_bundle':str(root/'ca.pem')})
try:sys.stdin.buffer.read()
finally:stopping.set();server.shutdown();server.server_close()
