"""Executable negative tests for the narrow, content-pinned OS process exception."""
from pathlib import Path
from tempfile import TemporaryDirectory
import hashlib
import json
from scan_security_and_secrets import scan_security_functions


def verify():
    real = Path(__file__).resolve().parent.parent
    source = (real / 'src/Domain/Vault/PrivateKeyFile.php').read_bytes()
    with TemporaryDirectory(prefix='coleza-process-policy-') as temporary:
        root = Path(temporary)
        path = root / 'src/Domain/Vault/PrivateKeyFile.php'
        path.parent.mkdir(parents=True)
        (root / 'tools').mkdir()
        policy = root / 'tools/reviewed-processes.json'

        def pin(value):
            path.write_bytes(value)
            policy.write_text(json.dumps({'files': {'src/Domain/Vault/PrivateKeyFile.php': {
                'sha256': hashlib.sha256(value).hexdigest()}}}), encoding='utf-8')

        pin(source)
        assert not scan_security_functions(root), 'Approved process rejected'
        path.write_bytes(source + b'\n// source changed')
        assert scan_security_functions(root), 'Changed source accepted'
        pin(source + b'\nproc_open($untrusted, [], $pipes);')
        assert scan_security_functions(root), 'Two calls accepted even with updated pin'
        pin(source.replace(b'proc_open(', b'shell_exec('))
        assert scan_security_functions(root), 'Other execution function accepted'
        pin(source)
        other = path.with_name('Unapproved.php')
        other.write_bytes(source)
        assert scan_security_functions(root), 'Other path accepted'
        other.unlink()
        policy.unlink()
        assert scan_security_functions(root), 'Absent policy accepted'
        policy.write_text('{malformed', encoding='utf-8')
        assert scan_security_functions(root), 'Malformed policy accepted'
    print('PASS: approved call and six fail-closed process-policy scenarios')


if __name__ == '__main__':
    verify()
