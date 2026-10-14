#!/usr/bin/env python3
"""Build only this repository's WHMCS installation ZIP. Python standard library only."""
import hashlib
import json
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def build(destination=None):
    manifest = json.loads((ROOT / 'module.json').read_text())
    module = Path(manifest['modulePath'])
    if module.parts != ('modules', manifest['type'], manifest['module']):
        raise ValueError('Invalid module path')
    output = Path(destination) if destination else ROOT / 'dist'
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'{manifest["repository"]}-{manifest["version"]}.zip'
    files = [ROOT / name for name in ['README.md', 'LICENSE', 'module.json']]
    files += sorted((ROOT / 'docs').rglob('*'))
    files += sorted((ROOT / module).rglob('*'))
    with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as target:
        for source in files:
            if source.is_symlink():
                raise ValueError(f'Symlinks are not distributable: {source}')
            if not source.is_file() or source.name == '.DS_Store':
                continue
            target.write(source, source.relative_to(ROOT).as_posix())
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix('.zip.sha256').write_text(f'{checksum}  {archive.name}\n')
    return archive


if __name__ == '__main__':
    print(build())
