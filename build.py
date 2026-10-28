"""Build and verify the complete WordPress plugin update ZIP."""
from pathlib import Path
import hashlib
import zipfile

root = Path(__file__).resolve().parent
out = root / 'dist'
out.mkdir(exist_ok=True)
archive = out / 'keha-mcp-0.3.0.zip'
files = sorted((root / 'keha-mcp').rglob('*'))
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as z:
    for source in files:
        if not source.is_file():
            continue
        assert not source.is_symlink()
        name = source.relative_to(root).as_posix()
        info = zipfile.ZipInfo(name, (2026, 10, 28, 0, 0, 0))
        info.create_system = 3
        info.external_attr = 0o100644 << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, source.read_bytes().replace(b'\r\n', b'\n'))
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    assert len(z.namelist()) == 9
    assert 'keha-mcp/keha-mcp.php' in z.namelist()
    for name in z.namelist():
        assert z.read(name) == (root / name).read_bytes().replace(b'\r\n', b'\n')
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(out / (archive.name + '.sha256')).write_text(digest + '  ' + archive.name + '\n', encoding='ascii')
print(f'PASS: {archive.name}; 9 complete plugin files; ZIP integrity and byte comparison; SHA256 {digest}')
