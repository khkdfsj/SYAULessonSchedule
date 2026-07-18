#!/usr/bin/env python3
"""Decrypt a LessonSchedule production backup created with AES-256-GCM."""

from pathlib import Path
import sys

from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.scrypt import Scrypt


MAGIC = b"LSBKP1"
ASSOCIATED_DATA = b"LessonSchedule-production-backup-v1"


def main() -> int:
    if len(sys.argv) != 4:
        print("usage: decrypt_backup.py <backup.aesgcm> <key-file> <output.tar.gz>")
        return 2

    source = Path(sys.argv[1]).read_bytes()
    password = Path(sys.argv[2]).read_bytes().strip()

    if not source.startswith(MAGIC) or len(source) < len(MAGIC) + 16 + 12 + 16:
        raise ValueError("invalid or truncated LessonSchedule backup")

    offset = len(MAGIC)
    salt = source[offset : offset + 16]
    nonce = source[offset + 16 : offset + 28]
    ciphertext = source[offset + 28 :]

    key = Scrypt(salt=salt, length=32, n=2**14, r=8, p=1).derive(password)
    plaintext = AESGCM(key).decrypt(nonce, ciphertext, ASSOCIATED_DATA)
    Path(sys.argv[3]).write_bytes(plaintext)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
