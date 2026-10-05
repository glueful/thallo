#!/usr/bin/env python3
"""Build tests/fixtures/brotli/ — the differential corpus for Thallo's Brotli port (typeface plan, Task 1).

Dev-only: needs the brotli module (pip install brotli; the C reference decoder and encoder) and a
checkout of google/brotli at the commit the port is pinned to:

    python3 scripts/build-brotli-vectors.py /path/to/google/brotli

Every entry records the upstream outcome — the output's length and SHA-256, or "error" — so the PHP
test compares the port's outcome with upstream's without shipping the expanded outputs.
"""
import base64
import hashlib
import json
import os
import random
import re
import shutil
import struct
import subprocess
import sys

import brotli

PINNED = '42a2ed4355bc6287da6bb6319f090b499cba4550'

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'tests/fixtures/brotli')


def outcome(data):
    """What the upstream (C) decoder makes of a stream."""
    try:
        out = brotli.decompress(data)
    except brotli.error:
        return 'error'
    return {'length': len(out), 'sha256': hashlib.sha256(out).hexdigest()}


def digest(raw):
    return {'length': len(raw), 'sha256': hashlib.sha256(raw).hexdigest()}


def write_json(name, value):
    with open(os.path.join(OUT, name), 'w') as f:
        json.dump(value, f, indent=1, sort_keys=True)
        f.write('\n')


def upstream_corpus(upstream):
    """tests/testdata at the pinned commit: each *.compressed* with upstream's own expected output."""
    src = os.path.join(upstream, 'tests/testdata')
    dst = os.path.join(OUT, 'upstream')
    shutil.rmtree(dst, ignore_errors=True)
    os.makedirs(dst)
    manifest = {}
    for name in sorted(os.listdir(src)):
        match = re.fullmatch(r'(.+)\.compressed(\.\d+)?', name)
        if not match:
            continue
        compressed = open(os.path.join(src, name), 'rb').read()
        expected = digest(open(os.path.join(src, match.group(1)), 'rb').read())
        if outcome(compressed) != expected:
            sys.exit(f'upstream decoder disagrees with upstream\'s expected output for {name}')
        shutil.copyfile(os.path.join(src, name), os.path.join(dst, name))
        manifest[name] = expected
    shutil.copyfile(os.path.join(upstream, 'LICENSE'), os.path.join(dst, 'LICENSE'))
    write_json('upstream.json', manifest)


def java_string(expr):
    """Evaluates SynthTest's expected-output expression: "literals" + times(n, "literal")."""
    def literal(text):
        return text.encode('latin-1').decode('unicode_escape').encode('latin-1')

    runs = []
    for times, count, text, plain in re.findall(r'(times\((\d+),\s*"((?:[^"\\]|\\.)*)"\))|"((?:[^"\\]|\\.)*)"', expr):
        if times:
            runs.append([int(count), base64.b64encode(literal(text)).decode()])
        else:
            runs.append([1, base64.b64encode(literal(plain)).decode()])
    return runs


def synth_vectors(upstream):
    """SynthTest.java's generated vectors: the bytes, whether decoding succeeds, and the output."""
    src = open(os.path.join(upstream, 'java/org/brotli/dec/SynthTest.java')).read()
    vectors = {}
    for name, body in re.findall(r'@Test\s+public void (test\w+)\(\) \{(.*?)\n  \}\n', src, re.S):
        if 'assumeTrue(false)' in body:
            continue  # disabled upstream
        compressed = bytes(int(b, 16) for b in re.findall(r'\(byte\) 0x([0-9a-f]{2})', body))
        call = re.search(r'checkSynth\(\s*/\*.*?\*/\s*compressed,\s*(true|false),\s*(.*?)\s*\);', body, re.S)
        runs = java_string(call.group(2))
        expected = b''.join(base64.b64decode(text) * count for count, text in runs)
        success = call.group(1) == 'true'
        upstream_says = outcome(compressed)
        if success and upstream_says != digest(expected):
            sys.exit(f'upstream decoder disagrees with SynthTest for {name}')
        if not success and upstream_says != 'error':
            sys.exit(f'upstream decoder accepts {name}, which SynthTest expects to fail')
        vectors[name] = {
            'compressed': base64.b64encode(compressed).decode(),
            'outcome': digest(expected) if success else 'error',
        }
    if len(vectors) < 40:
        sys.exit(f'only {len(vectors)} SynthTest vectors parsed')
    write_json('synth.json', vectors)


def generated_streams():
    """Streams from the C encoder chosen to reach specific decoder paths."""
    dst = os.path.join(OUT, 'generated')
    shutil.rmtree(dst, ignore_errors=True)
    os.makedirs(dst)
    rng = random.Random(20261005)
    manifest = {}

    def add(name, raw, **params):
        compressed = brotli.compress(raw, **params)
        if outcome(compressed) != digest(raw):
            sys.exit(f'round trip failed for {name}')
        open(os.path.join(dst, name + '.br'), 'wb').write(compressed)
        manifest[name + '.br'] = digest(raw)

    # Dictionary words and their transforms: the static dictionary's words in prose, capitalised,
    # upper-cased, with the prefixes and suffixes the RFC transforms add.
    words = open(os.path.join(ROOT, 'LICENSE'), 'rb').read().split()
    prose = []
    for i in range(6000):
        word = rng.choice(words)
        form = i % 7
        if form == 1:
            word = word.capitalize()
        elif form == 2:
            word = word.upper()
        elif form == 3:
            word = b'the ' + word + b','
        elif form == 4:
            word = word + b'ing'
        elif form == 5:
            word = b'"' + word + b'">'
        prose.append(word)
    text = b' '.join(prose)
    for q in (2, 4, 6, 9, 11):
        add(f'dictionary-text.q{q}', text, quality=q, mode=brotli.MODE_TEXT)
    add('dictionary-text.q11.generic', text, quality=11, mode=brotli.MODE_GENERIC)
    add('dictionary-utf8.q11', ('Größe naïve café ÉLAN ' * 300).encode(), quality=11, mode=brotli.MODE_TEXT)

    # Overlapping copies: a copy whose distance is shorter than its length.
    for period in (1, 2, 3, 4, 5, 7, 8, 15, 16, 17, 31, 64, 100):
        unit = bytes(rng.getrandbits(8) for _ in range(period))
        for q in (1, 5, 11):
            add(f'overlap-p{period}.q{q}', unit * (6000 // period + 1), quality=q)

    # Window sizes 10..24, with a copy at exactly the largest distance the window allows
    # (2^lgwin - 16) and one byte past it.
    for lgwin in range(10, 25):
        distance = (1 << lgwin) - 16
        block = bytes(rng.getrandbits(8) for _ in range(256))
        filler_len = distance - len(block)
        if lgwin <= 15:
            filler = bytes(rng.getrandbits(8) for _ in range(filler_len))  # incompressible: literals
        else:
            filler = bytes((i * 7) & 0x3F for i in range(filler_len))  # compressible: stays small
        add(f'window-{lgwin}.edge', block + filler + block, quality=11, lgwin=lgwin)
        add(f'window-{lgwin}.past', block + filler + b'x' + block, quality=11, lgwin=lgwin)
        if lgwin <= 20:  # keeps the expanded outputs (and the test's time) small
            add(f'window-{lgwin}.q5', (block + filler) * 2, quality=5, lgwin=lgwin)

    # Uncompressed meta-blocks (random data), and the fastest qualities.
    noise = bytes(rng.getrandbits(8) for _ in range(20000))
    for q in (0, 1, 11):
        add(f'random-20k.q{q}', noise, quality=q)

    # Several meta-blocks: ~2 MiB of repetitive text, with small and large block sizes.
    lines = b''.join(b'%d: ' % (i % 97) + words[i % 40] * (1 + i % 5) + b'\n' for i in range(120000))
    for lgblock in (16, 24):
        add(f'multi-metablock.lgblock{lgblock}', lines, quality=5, lgblock=lgblock)
    add('multi-metablock.q11', lines[:400000], quality=11)

    # Mixed literals and binary context modes.
    add('font-mode.q11', open(os.path.join(ROOT, 'tests/fixtures/fonts/brotli/font-tables.raw'), 'rb').read(),
        quality=11, mode=brotli.MODE_FONT)
    write_json('generated.json', manifest)


def mutations():
    """Truncated and corrupted streams, each with the upstream decoder's verdict.

    Recorded as operations on a base stream so the expanded mutants are not committed:
    ["truncate", n] keeps the first n bytes; ["flip", byte, bit]; ["set", byte, value]."""
    rng = random.Random(1005)
    bases = [
        'upstream/quickfox.compressed', 'upstream/x.compressed', 'upstream/ukkonooa.compressed',
        'upstream/monkey.compressed', 'upstream/cp852-utf8.compressed', 'upstream/10x10y.compressed',
        'generated/dictionary-utf8.q11.br', 'generated/overlap-p3.q11.br', 'generated/window-10.edge.br',
        'generated/dictionary-text.q11.br', 'generated/random-20k.q0.br',
    ]
    entries = []
    for base in bases:
        data = open(os.path.join(OUT, base), 'rb').read()

        def record(op, mutant):
            entries.append({'base': base, 'op': op, 'outcome': outcome(mutant)})

        cuts = range(len(data)) if len(data) <= 700 else sorted(rng.sample(range(len(data)), 400))
        for n in cuts:
            record(['truncate', n], data[:n])
        for _ in range(250):
            at, bit = rng.randrange(len(data)), rng.randrange(8)
            mutant = bytearray(data)
            mutant[at] ^= 1 << bit
            record(['flip', at, bit], bytes(mutant))
        for _ in range(100):
            at, value = rng.randrange(len(data)), rng.randrange(256)
            mutant = bytearray(data)
            mutant[at] = value
            record(['set', at, value], bytes(mutant))
    write_json('mutated.json', entries)
    accepted = sum(1 for e in entries if e['outcome'] != 'error')
    return len(entries), accepted


def max_trees_stream():
    """A stream whose headers demand the most block types and Huffman trees the format allows,
    then ends: the decoder must bound what these headers make it allocate."""
    bits = []

    def put(value, n):
        for i in range(n):
            bits.append((value >> i) & 1)

    def var_len_uint8(value):  # 1..255 as the format's VarLenUint8 (value - 1 is encoded)
        value -= 1
        if value == 0:
            put(0, 1)
            return
        n = value.bit_length() - 1
        put(1, 1)
        put(n, 3)
        put(value - (1 << n), n)

    def simple_code(alphabet):  # a one-symbol simple prefix code for symbol 0
        put(1, 2)  # simple
        put(0, 2)  # NSYM - 1
        put(0, (alphabet - 1).bit_length())

    put(0, 1)  # WBITS: 16
    put(0, 1)  # ISLAST
    put(0, 2)  # MNIBBLES: 4
    put(0xFFFF, 16)  # MLEN - 1
    put(0, 1)  # ISUNCOMPRESSED
    for _ in range(3):  # literal, command, distance block types: 256 each
        var_len_uint8(256)
        simple_code(258)  # block type code
        simple_code(26)  # block count code
        put(0, 2)  # block count extra bits
    put(0, 2)  # NPOSTFIX
    put(0, 4)  # NDIRECT
    put(0, 2 * 256)  # context modes
    for _ in range(2):  # literal and distance context maps: 256 trees, all entries 0
        var_len_uint8(256)
        put(0, 1)  # no RLE
        simple_code(256)
        put(0, 1)  # no inverse move-to-front
    for _ in range(256):  # 256 literal trees
        simple_code(256)
    for _ in range(256):  # 256 command trees
        simple_code(704)
    # The distance tree group is allocated next; the stream ends before its trees.
    data = bytearray((len(bits) + 7) // 8)
    for i, bit in enumerate(bits):
        data[i >> 3] |= bit << (i & 7)
    open(os.path.join(OUT, 'max-trees.br'), 'wb').write(bytes(data))
    if outcome(bytes(data)) != 'error':
        sys.exit('max-trees.br must be refused upstream')


def main():
    if len(sys.argv) != 2:
        sys.exit(__doc__)
    upstream = sys.argv[1]
    head = subprocess.run(['git', '-C', upstream, 'rev-parse', 'HEAD'], capture_output=True, text=True).stdout.strip()
    if head != PINNED:
        sys.exit(f'google/brotli checkout is at {head}, the port is pinned to {PINNED}')
    os.makedirs(OUT, exist_ok=True)
    upstream_corpus(upstream)
    synth_vectors(upstream)
    generated_streams()
    total, accepted = mutations()
    max_trees_stream()
    print(f'corpus written to {OUT} (brotli {brotli.__version__}, google/brotli {PINNED[:8]};'
          f' {total} mutants, {accepted} accepted upstream)')


if __name__ == '__main__':
    main()
