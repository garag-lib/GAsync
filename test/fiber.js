// Optional Node.js comparison: the same bounded copy as examples/fibers.php.
const fs = require('node:fs/promises');
const os = require('node:os');
const path = require('node:path');
const { createHash } = require('node:crypto');

(async () => {
    const dir = await fs.mkdtemp(path.join(os.tmpdir(), 'gasync-node-'));
    let source;
    let destination;
    try {
        const payload = Buffer.alloc(1024 * 1024, 'x');
        await fs.writeFile(path.join(dir, 'original.bin'), payload);
        source = await fs.open(path.join(dir, 'original.bin'), 'r');
        destination = await fs.open(path.join(dir, 'copy.bin'), 'w');
        const buffer = Buffer.alloc(65536);
        const timer = new Promise(resolve => setTimeout(resolve, 10));
        while (true) {
            const { bytesRead } = await source.read(buffer, 0, buffer.length, null);
            if (bytesRead === 0) break;
            let offset = 0;
            while (offset < bytesRead) {
                const { bytesWritten } = await destination.write(buffer, offset, bytesRead - offset, null);
                if (bytesWritten === 0) throw new Error('Write made no progress');
                offset += bytesWritten;
            }
        }
        await timer;
        const copied = await fs.readFile(path.join(dir, 'copy.bin'));
        const hash = data => createHash('sha256').update(data).digest('hex');
        if (hash(copied) !== hash(payload)) throw new Error('Copy failed');
        console.log('Copied 1 MiB; SHA-256 verified');
    } finally {
        await source?.close();
        await destination?.close();
        await fs.rm(dir, { recursive: true, force: true });
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
