#!/usr/bin/env node
/*
 * Writes the minified copies of the admin script and styles next to their
 * readable sources. The plugin loads the .min copy unless SCRIPT_DEBUG is on
 * or the copy is missing (Admin::asset()). Both copies are committed, because
 * release ZIPs are a `git archive` of the repository.
 *
 *   node scripts/minify-assets.mjs           rebuild
 *   node scripts/minify-assets.mjs --check   fail if a copy is out of date (CI)
 *
 * Scripts go through SWC (whitespace, syntax and local names only; top-level
 * names stay global and no newer syntax is introduced), styles through
 * lightningcss for the browsers listed below. Every result is parsed once
 * more. Both tools are pinned in package-lock.json.
 */
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { Script } from 'node:vm';
import { minifySync } from '@swc/core';
import { transform } from 'lightningcss';

const assets = [
    ['assets/admin.js', 'assets/admin.min.js'],
    ['assets/admin.css', 'assets/admin.min.css'],
];

// Oldest browsers the styles must keep working in; lightningcss adds the
// vendor prefixes they need and never uses syntax they lack.
const version = (major) => major << 16;
const targets = {
    chrome: version(90),
    edge: version(90),
    firefox: version(88),
    safari: version(14),
    ios_saf: version(14),
    samsung: version(15),
};

const check = process.argv.includes('--check');
let failed = false;
for (const [source, target] of assets) {
    // Same bytes on Windows (CRLF checkouts) and in CI.
    const code = readFileSync(source, 'utf8').replace(/\r\n/g, '\n');
    let output;
    if (source.endsWith('.css')) {
        output = transform({ filename: source, code: Buffer.from(code), minify: true, targets }).code.toString();
        // Parse check: lightningcss throws on invalid CSS.
        transform({ filename: target, code: Buffer.from(output), targets });
    } else {
        output = minifySync(code, {
            module: false,
            compress: { toplevel: false },
            mangle: { toplevel: false },
            format: { comments: 'some' },
        }).code;
        // Parse check only; nothing is executed.
        new Script(output, { filename: target });
    }
    output += '\n';
    const size = `${Buffer.byteLength(output)} bytes from ${Buffer.byteLength(code)}`;

    if (check) {
        const current = existsSync(target) ? readFileSync(target, 'utf8').replace(/\r\n/g, '\n') : '';
        if (current !== output) {
            console.error(`${target} is out of date: run node scripts/minify-assets.mjs`);
            failed = true;
        } else {
            console.log(`PASS: ${target} is up to date (${size}).`);
        }
    } else {
        writeFileSync(target, output);
        console.log(`Wrote ${target}: ${size}.`);
    }
}
process.exit(failed ? 1 : 0);
