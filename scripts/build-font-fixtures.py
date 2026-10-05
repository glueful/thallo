#!/usr/bin/env python3
"""Build tests/fixtures/fonts/ — the WOFF2 reader's proof set (block typeface plan, Task 1).

Dev-only: needs fontTools and brotli (pip install fonttools brotli). Sources: the default
theme's Figtree (OFL). Re-running rewrites every file; commit the result with PROVENANCE.md.
"""
import os
import random
import struct

import brotli
import fontTools
from fontTools.ttLib import TTFont
from fontTools.ttLib.woff2 import WOFF2Reader
from fontTools.varLib import instancer

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC = os.path.join(ROOT, 'packages/thallo-render/themes/default/assets/fonts')
OUT = os.path.join(ROOT, 'tests/fixtures/fonts')
VEC = os.path.join(OUT, 'brotli')
os.makedirs(VEC, exist_ok=True)


def woff2(font, name):
    font.flavor = 'woff2'
    font.save(os.path.join(OUT, name))
    return os.path.join(OUT, name)


def src(name):
    return TTFont(os.path.join(SRC, name))


variable = woff2(src('figtree-roman-latin.woff2'), 'variable.woff2')
woff2(src('figtree-italic-latin.woff2'), 'variable-italic.woff2')
woff2(instancer.instantiateVariableFont(src('figtree-roman-latin.woff2'), {'wght': 700}), 'static-700.woff2')
woff2(instancer.instantiateVariableFont(src('figtree-italic-latin.woff2'), {'wght': 400}), 'static-400-italic.woff2')

data = open(variable, 'rb').read()
open(os.path.join(OUT, 'truncated.woff2'), 'wb').write(data[: len(data) // 2])
open(os.path.join(OUT, 'not-a-font.woff2'), 'wb').write(b'<html>not a font</html>')
woff1 = src('figtree-roman-latin.woff2')
woff1.flavor = 'woff'
woff1.save(os.path.join(OUT, 'wrong-flavour.woff'))

# The decompressed table stream (what the Brotli stream inflates to), from fontTools' reader.
with open(variable, 'rb') as f:
    reader = WOFF2Reader(f)
    tables = reader.transformBuffer.getvalue()  # a BytesIO in fontTools


def directory_end(font):
    """Offset just past the 48-byte header and the table directory: where the Brotli stream starts."""
    def base128(pos):
        value = 0
        for _ in range(5):
            byte = font[pos]
            pos += 1
            value = (value << 7) | (byte & 0x7F)
            if not byte & 0x80:
                return value, pos
        raise ValueError('UIntBase128 longer than 5 bytes')

    pos = 48
    for _ in range(struct.unpack('>H', font[12:14])[0]):  # numTables
        flags = font[pos]
        pos += 1
        tag, version = flags & 0x3F, flags >> 6
        if tag == 63:
            pos += 4  # an explicit tag follows
        _, pos = base128(pos)  # origLength
        if (tag in (10, 11) and version == 0) or (tag not in (10, 11) and version != 0):
            _, pos = base128(pos)  # transformLength (glyf/loca version 0; any other table's non-null)
    return pos


compressed_len = struct.unpack('>I', data[20:24])[0]  # totalCompressedSize
stream_offset = directory_end(data)  # the stream is followed by padding, so it is located from the front
assert brotli.decompress(data[stream_offset:stream_offset + compressed_len]) == tables

# A reader-level bomb: the REAL header and table directory (which advertise the real, small
# sizes), followed by a Brotli stream that inflates to 64 MiB. Nothing before decompression can
# reject it; only a bounded decoder stops it.
bomb_stream = brotli.compress(b'\0' * (64 * 1024 * 1024), quality=5)
header = bytearray(data[:stream_offset])
header[20:24] = struct.pack('>I', len(bomb_stream))  # totalCompressedSize = the bomb's
header[8:12] = struct.pack('>I', len(header) + len(bomb_stream))  # length
open(os.path.join(OUT, 'bomb.woff2'), 'wb').write(bytes(header) + bomb_stream)

# Brotli vectors: (raw, compressed) across qualities.
random.seed(7)
samples = {
    'empty': b'',
    'text': open(os.path.join(ROOT, 'LICENSE'), 'rb').read(),
    'random-4k': bytes(random.getrandbits(8) for _ in range(4096)),
    'repeat-256k': b'thallo ' * 37449,
    'font-tables': tables,  # the real decompressed table stream
    'big-tables': (tables * (1048576 // len(tables) + 1))[:1048576],  # ~1 MiB of real table bytes
}
for name, raw in samples.items():
    for q in (0, 5, 11):
        open(os.path.join(VEC, f'{name}.q{q}.br'), 'wb').write(brotli.compress(raw, quality=q))
    open(os.path.join(VEC, f'{name}.raw'), 'wb').write(raw)
open(os.path.join(VEC, 'expansion-64m.br'), 'wb').write(bomb_stream)  # the raw expansion stream
print('fixtures written to', OUT, '(fontTools', fontTools.version + ', brotli', brotli.__version__ + ')')
