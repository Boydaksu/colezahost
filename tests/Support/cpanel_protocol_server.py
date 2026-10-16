"""Loopback HTTPS WHM contract fixture, never a live cPanel acceptance claim."""
import datetime,ipaddress,json,socket,ssl,sys,threading,time
from pathlib import Path
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
from urllib.parse import urlsplit,parse_qs
from cryptography import x509
from cryptography.hazmat.primitives import hashes,serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID
root=Path(sys.argv[1]); lock=threading.Lock()
class Server(ThreadingHTTPServer):
    daemon_threads=False

class Whm(BaseHTTPRequestHandler):
    def log_message(self,*args):pass
    def handle_request(self):
        mode=json.loads((root/'mode.json').read_text()); body=self.rfile.read(int(self.headers.get('Content-Length','0')))
        record={'method':self.command,'path':urlsplit(self.path).path,'query':parse_qs(urlsplit(self.path).query),'body':parse_qs(body.decode()),'authorized':self.headers.get('Authorization')=='whm root:fixture-only-token'}
        with lock:
            with (root/'requests.jsonl').open('a',encoding='utf-8') as out:out.write(json.dumps(record)+'\n')
        if mode.get('drop'):
            self.connection.shutdown(socket.SHUT_RDWR);self.connection.close();return
        if mode.get('delay'):time.sleep(mode['delay'])
        status=mode.get('status',200);payload=mode.get('body',{'metadata':{'result':1,'reason':'OK','version':1},'data':{'version':'11.138.0.1'}})
        raw=payload.encode() if isinstance(payload,str) else json.dumps(payload).encode()
        if mode.get('large'):raw=b'x'*mode['large']
        try:
            self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('X-WHM-Fixture','real-https')
            if mode.get('redirect'):self.send_header('Location',mode['redirect'])
            if not mode.get('no_length'):self.send_header('Content-Length',str(len(raw)))
            self.end_headers();self.wfile.write(raw)
        except (BrokenPipeError,ConnectionResetError,ConnectionAbortedError,ssl.SSLError):pass
    do_GET=handle_request
    do_POST=handle_request
key=rsa.generate_private_key(public_exponent=65537,key_size=2048);name=x509.Name([x509.NameAttribute(NameOID.COMMON_NAME,'cpanel-loopback')]);now=datetime.datetime.now(datetime.timezone.utc)
cert=(x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key()).serial_number(x509.random_serial_number()).not_valid_before(now-datetime.timedelta(minutes=5)).not_valid_after(now+datetime.timedelta(days=1)).add_extension(x509.BasicConstraints(ca=True,path_length=None),critical=True).add_extension(x509.SubjectAlternativeName([x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]),critical=False).sign(key,hashes.SHA256()))
(root/'ca.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM));(root/'key.pem').write_bytes(key.private_bytes(serialization.Encoding.PEM,serialization.PrivateFormat.PKCS8,serialization.NoEncryption()))
(root/'mode.json').write_text('{}')
http=Server(('127.0.0.1',0),Whm);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(root/'ca.pem',root/'key.pem');http.socket=ctx.wrap_socket(http.socket,server_side=True)
plain=Server(('127.0.0.1',0),Whm)
threading.Thread(target=lambda:http.serve_forever(poll_interval=.05),daemon=True).start();threading.Thread(target=lambda:plain.serve_forever(poll_interval=.05),daemon=True).start()
(root/'ready.json').write_text(json.dumps({'port':http.server_port,'plain_port':plain.server_port,'ca_bundle':str(root/'ca.pem')}))
try:
    sys.stdin.buffer.read()
finally:
    http.shutdown();plain.shutdown();http.server_close();plain.server_close()
