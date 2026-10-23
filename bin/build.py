#!/usr/bin/env python3
"""
Build script for the Convomail module.
Usage: python3 bin/build.py <version>
  e.g. python3 bin/build.py 0.1.1

1. Updates version strings in source files (before zipping, never after).
2. Installs the vendored PHP libraries (Horde IMAP) with Composer, production only.
   Uses a local `composer` if present, otherwise the composer:2 Docker image.
3. Builds bin/module_convomail-<version>.zip with `convomail/` as the top-level folder,
   vendor/ included, and checks the version inside the zip.
"""

import re
import shutil
import subprocess
import sys
import zipfile
import pathlib

ROOT = pathlib.Path(__file__).parent.parent
MODULE = ROOT / 'module'
BIN = ROOT / 'bin'

VERSION_PATTERN = (r"(\$this->version\s*=\s*')[^']+(')", r'\g<1>{version}\g<2>')
VERSION_FILES = [
    MODULE / 'core' / 'modules' / 'modConvomail.class.php',
    MODULE / 'core' / 'triggers' / 'interface_99_modConvomail_ConvomailTrigger.class.php',
]

EXCLUDE_NAMES = {'.DS_Store', 'composer.lock'}


def set_versions(version):
    pattern, replacement = VERSION_PATTERN
    for path in VERSION_FILES:
        text = path.read_text()
        new_text = re.sub(pattern, replacement.format(version=version), text)
        if new_text == text:
            print(f'  WARNING: no version string changed in {path.name}')
        else:
            path.write_text(new_text)
            print(f'  updated {path.name}')


def verify_versions(version):
    ok = True
    for path in VERSION_FILES:
        if f"'{version}'" not in path.read_text():
            print(f'  ERROR: {version} not found in {path.name}')
            ok = False
        else:
            print(f'  verified {path.name} = {version}')
    return ok


def composer_install():
    args = ['install', '--no-dev', '--no-interaction', '--optimize-autoloader']
    if shutil.which('composer'):
        cmd = ['composer'] + args
    elif shutil.which('docker'):
        cmd = ['docker', 'run', '--rm', '-v', f'{MODULE}:/app', '-w', '/app', 'composer:2'] + args
    else:
        print('  ERROR: neither composer nor docker found')
        return False
    print('  ' + ' '.join(cmd))
    return subprocess.run(cmd, cwd=MODULE).returncode == 0


def build_zip(version):
    out = BIN / f'module_convomail-{version}.zip'
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as zf:
        for f in sorted(MODULE.rglob('*')):
            if f.is_file() and f.name not in EXCLUDE_NAMES:
                zf.write(f, 'convomail/' + str(f.relative_to(MODULE)))
    with zipfile.ZipFile(out) as zf:
        names = set(zf.namelist())
        if 'convomail/vendor/autoload.php' not in names:
            print('  ERROR: vendor/autoload.php missing from zip')
            return None
        for path in VERSION_FILES:
            name = 'convomail/' + str(path.relative_to(MODULE))
            if f"'{version}'" not in zf.read(name).decode():
                print(f'  ERROR: {version} not in zip entry {name}')
                return None
    print(f'  created {out.name} ({out.stat().st_size // 1024} KB)')
    return out


def main():
    if len(sys.argv) != 2:
        print('Usage: python3 bin/build.py <version>')
        sys.exit(1)

    version = sys.argv[1]
    print(f'\n=== Building v{version} ===\n')

    print('1. Updating version strings in source files...')
    set_versions(version)

    print('\n2. Verifying source files...')
    if not verify_versions(version):
        print('\nABORTED: version strings not set correctly.')
        sys.exit(1)

    print('\n3. Installing PHP libraries...')
    if not composer_install():
        print('\nABORTED: composer install failed.')
        sys.exit(1)

    print('\n4. Building zip...')
    out = build_zip(version)
    if not out:
        print('\nABORTED: zip check failed.')
        sys.exit(1)

    print('\nDone. Next steps:')
    print(f'  git add -A && git commit -m "v{version}: ..."')
    print(f'  gh release create v{version} {out} --title "v{version}" --notes "..."')


if __name__ == '__main__':
    main()
