#!/usr/bin/env node

import { copyFileSync, mkdirSync, readdirSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const brandSource = 'public/brand';
const brandDest = 'public/build/brand';

mkdirSync(brandDest, { recursive: true });

if (!existsSync(brandSource)) {
    console.error(`Missing ${brandSource}`);
    process.exit(1);
}

for (const name of readdirSync(brandSource)) {
    if (!name.endsWith('.png')) {
        continue;
    }
    copyFileSync(join(brandSource, name), join(brandDest, name));
}

if (existsSync('public/favicon.png')) {
    copyFileSync('public/favicon.png', join(brandDest, 'favicon.png'));
}

console.log(`Copied brand assets into ${brandDest}`);
