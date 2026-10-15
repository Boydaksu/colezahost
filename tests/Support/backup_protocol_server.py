"""Loopback-only S3 HTTPS/SigV4 and SSH/SFTP acceptance fixture; no external accounts."""
import base64
import datetime
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import socket
import ssl
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, quote, unquote, urlsplit
from xml.etree.ElementTree import Element, SubElement, tostring

import paramiko
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import rsa
from cryptography.x509.oid import NameOID

ROOT = Path(sys.argv[1]).resolve()
STORE = ROOT / 'objects'
STORE.mkdir()
USER, PASSWORD, ACCESS, SECRET = 'backup-test', 'loopback-only', 'TESTACCESS', 'loopback-secret'


def mode():
    try:
        return json.loads((ROOT / 'mode.json').read_text())
    except (OSError, ValueError):
        return {}


def safe(path):
    result = (STORE / path.lstrip('/')).resolve()
    if not result.is_relative_to(STORE):
        raise PermissionError('outside fixture root')
    return result


def digest(key, text):
    return hmac.new(key, text.encode(), hashlib.sha256).digest()


class S3(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, *args):
        pass

    def reply(self, code, data=b''):
        self.send_response(code)
        self.send_header('Content-Length', str(len(data)))
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(data)

    def authenticated(self, body):
        try:
            auth = self.headers['Authorization']
            algorithm, values = auth.split(' ', 1)
            fields = dict(x.strip().split('=', 1) for x in values.split(','))
            access, day, region, service, end = fields['Credential'].split('/')
            assert (algorithm, access, region, service, end) == ('AWS4-HMAC-SHA256', ACCESS, 'us-east-1', 's3', 'aws4_request')
            assert abs((datetime.datetime.now(datetime.timezone.utc) - datetime.datetime.strptime(self.headers['X-Amz-Date'], '%Y%m%dT%H%M%SZ').replace(tzinfo=datetime.timezone.utc)).total_seconds()) < 300
            payload = hashlib.sha256(body).hexdigest()
            assert self.headers['X-Amz-Content-Sha256'] == payload
            parsed = urlsplit(self.path)
            pairs = [(quote(k, safe='-_.~'), quote(v, safe='-_.~')) for k, values in parse_qs(parsed.query, keep_blank_values=True).items() for v in values]
            canonical_query = '&'.join(k + '=' + v for k, v in sorted(pairs))
            names = fields['SignedHeaders']
            headers = ''.join(n + ':' + ' '.join(self.headers[n].split()) + '\n' for n in names.split(';'))
            request = '\n'.join([self.command, parsed.path, canonical_query, headers, names, payload])
            scope = '/'.join([day, region, service, end])
            signed = '\n'.join([algorithm, self.headers['X-Amz-Date'], scope, hashlib.sha256(request.encode()).hexdigest()])
            key = digest(digest(digest(digest(('AWS4' + SECRET).encode(), day), region), service), end)
            assert hmac.compare_digest(hmac.new(key, signed.encode(), hashlib.sha256).hexdigest(), fields['Signature'])
            return True
        except (AssertionError, KeyError, ValueError, TypeError, AttributeError):
            return False

    def handle_request(self):
        body = self.rfile.read(int(self.headers.get('Content-Length', '0')))
        if not self.authenticated(body):
            return self.reply(403)
        parsed = urlsplit(self.path)
        path = unquote(parsed.path)
        fault = mode().get('fault', '')
        if self.command == 'GET' and 'list-type' in parse_qs(parsed.query):
            if fault == 'bad-list':
                return self.reply(200, b'<!DOCTYPE x [<!ENTITY e SYSTEM "file:///secret">]><x>&e;</x>')
            query = parse_qs(parsed.query)
            prefix = query.get('prefix', [''])[0]
            keys = sorted(p.relative_to(safe('/bucket')).as_posix() for p in safe('/bucket').rglob('*') if p.is_file()) if safe('/bucket').exists() else []
            keys = [k for k in keys if k.startswith(prefix)]
            offset = int(query.get('continuation-token', ['0'])[0])
            page = keys[offset:offset + 2]
            xml = Element('ListBucketResult', xmlns='http://s3.amazonaws.com/doc/2006-03-01/')
            SubElement(xml, 'IsTruncated').text = str(offset + 2 < len(keys)).lower()
            if offset + 2 < len(keys):
                SubElement(xml, 'NextContinuationToken').text = str(offset + 2)
            for name in page:
                SubElement(SubElement(xml, 'Contents'), 'Key').text = name
            return self.reply(200, tostring(xml))
        target = safe(path)
        if self.command == 'PUT':
            if (fault == 'reject-manifest' and path.endswith('.manifest.json')) or (fault == 'reject-archive' and path.endswith('.zip')):
                return self.reply(503)
            target.parent.mkdir(parents=True, exist_ok=True)
            try:
                with target.open('xb' if self.headers.get('If-None-Match') == '*' else 'wb') as output:
                    output.write(body)
            except FileExistsError:
                return self.reply(412)
            return self.reply(200)
        if self.command == 'DELETE':
            if fault == 'reject-delete' and path.endswith('.manifest.json'):
                return self.reply(403)
            target.unlink(missing_ok=True)
            return self.reply(204)
        if not target.is_file():
            return self.reply(404)
        data = target.read_bytes()
        if fault == 'corrupt-read' and path.endswith('.zip'):
            data = b'corrupted'
        if fault == 'truncate-read' and path.endswith('.zip'):
            self.send_response(200)
            self.send_header('Content-Length', str(len(data) + 50))
            self.end_headers()
            self.wfile.write(data[:1])
            self.wfile.flush()
            self.connection.shutdown(socket.SHUT_RDWR)
            self.close_connection = True
            return
        return self.reply(200, data)

    do_GET = do_PUT = do_DELETE = handle_request


class Auth(paramiko.ServerInterface):
    def check_auth_password(self, username, password):
        return paramiko.AUTH_SUCCESSFUL if (username, password) == (USER, PASSWORD) else paramiko.AUTH_FAILED

    def check_auth_publickey(self, username, key):
        return paramiko.AUTH_SUCCESSFUL if username == USER and key == CLIENT else paramiko.AUTH_FAILED

    def get_allowed_auths(self, username):
        return 'password,publickey'

    def check_channel_request(self, kind, chanid):
        return paramiko.OPEN_SUCCEEDED if kind == 'session' else paramiko.OPEN_FAILED_ADMINISTRATIVELY_PROHIBITED


class Handle(paramiko.SFTPHandle):
    def write(self, offset, data):
        fault = mode().get('fault', '')
        if (fault == 'reject-manifest' and self.filename.endswith('.manifest.json')) or (fault == 'reject-archive' and self.filename.endswith('.zip')):
            return paramiko.SFTP_PERMISSION_DENIED
        return super().write(offset, data)

    def read(self, offset, length):
        fault = mode().get('fault', '')
        if self.filename.endswith('.zip') and fault == 'corrupt-read':
            return b'corrupted' if offset == 0 else b''
        if self.filename.endswith('.zip') and fault == 'truncate-read':
            return paramiko.SFTP_FAILURE
        return super().read(offset, length)


class Sftp(paramiko.SFTPServerInterface):
    def canonicalize(self, path):
        return '/' + path.lstrip('/')

    def stat(self, path):
        try:
            return paramiko.SFTPAttributes.from_stat(safe(path).stat())
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)

    lstat = stat

    def list_folder(self, path):
        try:
            result = []
            for entry in safe(path).iterdir():
                attr = paramiko.SFTPAttributes.from_stat(entry.stat())
                attr.filename = entry.name
                result.append(attr)
            return result
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)

    def open(self, path, flags, attr):
        try:
            target = safe(path)
            fd = os.open(target, flags, 0o600)
            handle = Handle(flags)
            handle.filename = path
            file = os.fdopen(fd, 'r+b' if flags & os.O_RDWR else 'wb' if flags & os.O_WRONLY else 'rb')
            handle.readfile = handle.writefile = file
            return handle
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)

    def mkdir(self, path, attr):
        return self.operation(lambda: safe(path).mkdir(mode=0o700))

    def rmdir(self, path):
        return self.operation(lambda: safe(path).rmdir())

    def remove(self, path):
        if mode().get('fault') == 'reject-delete' and path.endswith('.manifest.json'):
            return paramiko.SFTP_PERMISSION_DENIED
        return self.operation(lambda: safe(path).unlink())

    def rename(self, oldpath, newpath):
        if safe(newpath).exists():
            return paramiko.SFTP_FAILURE
        return self.operation(lambda: safe(oldpath).rename(safe(newpath)))

    def chattr(self, path, attr):
        return self.operation(lambda: paramiko.SFTPServer.set_file_attr(str(safe(path)), attr))

    @staticmethod
    def operation(action):
        try:
            action()
            return paramiko.SFTP_OK
        except OSError as error:
            return paramiko.SFTPServer.convert_errno(error.errno)


HOST = paramiko.RSAKey.generate(2048)
CLIENT = paramiko.RSAKey.generate(2048)
CLIENT.write_private_key_file(str(ROOT / 'client-key'))
(ROOT / 'client-key.pub').write_text(CLIENT.get_name() + ' ' + CLIENT.get_base64())
listener = socket.socket()
listener.bind(('127.0.0.1', 0))
listener.listen(64)


def ssh_connection(client):
    transport = paramiko.Transport(client)
    transport.add_server_key(HOST)
    transport.set_subsystem_handler('sftp', paramiko.SFTPServer, Sftp)
    try:
        transport.start_server(server=Auth())
        while transport.is_active():
            time.sleep(0.02)
    except (EOFError, OSError, paramiko.SSHException):
        pass
    finally:
        transport.close()


def ssh_server():
    while True:
        client, _ = listener.accept()
        threading.Thread(target=ssh_connection, args=(client,), daemon=True).start()


key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
name = x509.Name([x509.NameAttribute(NameOID.COMMON_NAME, 'backup-loopback')])
now = datetime.datetime.now(datetime.timezone.utc)
cert = (x509.CertificateBuilder().subject_name(name).issuer_name(name).public_key(key.public_key())
        .serial_number(x509.random_serial_number()).not_valid_before(now - datetime.timedelta(minutes=5))
        .not_valid_after(now + datetime.timedelta(days=1)).add_extension(x509.BasicConstraints(ca=True, path_length=None), critical=True)
        .add_extension(x509.SubjectAlternativeName([x509.IPAddress(ipaddress.ip_address('127.0.0.1'))]), critical=False)
        .sign(key, hashes.SHA256()))
(ROOT / 'ca.pem').write_bytes(cert.public_bytes(serialization.Encoding.PEM))
(ROOT / 'tls-key').write_bytes(key.private_bytes(serialization.Encoding.PEM, serialization.PrivateFormat.PKCS8, serialization.NoEncryption()))
http = ThreadingHTTPServer(('127.0.0.1', 0), S3)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(ROOT / 'ca.pem', ROOT / 'tls-key')
http.socket = context.wrap_socket(http.socket, server_side=True)
threading.Thread(target=ssh_server, daemon=True).start()
threading.Thread(target=http.serve_forever, daemon=True).start()
ready = {'endpoint': 'https://127.0.0.1:' + str(http.server_port), 'ca_bundle': str(ROOT / 'ca.pem'),
         'port': listener.getsockname()[1], 'host': '127.0.0.1', 'username': USER, 'password': PASSWORD,
         'host_key_sha256': base64.b64encode(hashlib.sha256(HOST.asbytes()).digest()).decode(),
         'private_key': str(ROOT / 'client-key'), 'public_key': str(ROOT / 'client-key.pub'),
         'access_key': ACCESS, 'secret_key': SECRET, 'region': 'us-east-1', 'bucket': 'bucket'}
(ROOT / 'ready.tmp').write_text(json.dumps(ready))
(ROOT / 'ready.json').write_bytes((ROOT / 'ready.tmp').read_bytes())
while sys.stdin.buffer.read(1):
    pass
