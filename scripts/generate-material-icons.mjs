/**
 * Generate the Material Icons sprite + catalog for the theme.
 *
 * Reads the filled Material Icons from the @material-design-icons/svg
 * package (Apache 2.0, Google) and writes:
 * - assets/icons/material-icons.svg  — one <symbol> per icon, filled
 *   with currentColor so icons follow the text colour.
 * - assets/icons/material-icons.json — the icon names, in order.
 * - assets/icons/MATERIAL-ICONS-LICENSE.txt — the package LICENSE.
 *
 * Usage: bun run icons:generate
 */
import { copyFile, mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const sourceDirectory = join(
    process.cwd(),
    'node_modules',
    '@material-design-icons',
    'svg',
    'filled',
);
const packageDirectory = join(
    process.cwd(),
    'node_modules',
    '@material-design-icons',
    'svg',
);
const outputDirectory = join(process.cwd(), 'assets', 'icons');
const spritePath = join(outputDirectory, 'material-icons.svg');
const catalogPath = join(outputDirectory, 'material-icons.json');
const licensePath = join(outputDirectory, 'MATERIAL-ICONS-LICENSE.txt');

const iconFiles = (await readdir(sourceDirectory))
    .filter((file) => file.endsWith('.svg'))
    .sort();

const symbols = [];
const names = [];

for (const file of iconFiles) {
    const name = file.slice(0, -4);
    const source = await readFile(join(sourceDirectory, file), 'utf8');
    const viewBox = source.match(/<svg\b[^>]*\bviewBox="([^"]+)"/i)?.[1];
    const content = source.match(/<svg\b[^>]*>([\s\S]*?)<\/svg>/i)?.[1];

    if (!viewBox || !content) {
        throw new Error(`Material icon "${file}" has no SVG viewBox or content.`);
    }

    // Drop comments and any hardcoded fill/color so the symbol paints
    // with currentColor (the surrounding text colour).
    const paths = content
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\s(?:fill|color)="[^"]*"/g, '')
        .replace(/\s+/g, ' ')
        .trim();

    symbols.push(`<symbol id="material-${name}" viewBox="${viewBox}">${paths}</symbol>`);
    names.push(name);
}

await mkdir(outputDirectory, { recursive: true });

const sprite = [
    '<svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true">',
    '<!-- Generated from Google Material Design Icons and modified as a sprite with currentColor fills. -->',
    ...symbols,
    '</svg>',
    '',
].join('\n');

await writeFile(spritePath, sprite);
await writeFile(catalogPath, `${JSON.stringify(names)}\n`);
await copyFile(join(packageDirectory, 'LICENSE'), licensePath);

console.log(`Generated ${names.length} local Material Icons.`);
