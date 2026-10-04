/**
 * Village Courtyard – brand export script.
 *
 * Regenerates every raster asset from the SVG sources in this folder:
 *
 *   exports/logo-full.png    2000 px wide, transparent   (from logo.svg)
 *   exports/logo-white.png   2000 px wide, transparent   (from logo-white.svg, for dark backgrounds)
 *   exports/logo-icon.png    512 x 512, transparent      (from logo-icon.svg)
 *   exports/favicon.png      64 x 64                     (from favicon.svg)
 *   exports/logo.psd         layered Photoshop file: Background (hidden), Icon, Wordmark, Tagline
 *
 * …then copies the PNGs into the places the site reads them from:
 *   ../backend/uploads/logo/   (the defaults in the settings table point here)
 *   ../frontend/public/favicon.png
 *
 * Usage:  npm install && npm run export          (from this folder)
 *         npm run export -- --no-install         (only write ./exports)
 *
 * Editing the logo: change the SVGs (any vector editor, e.g. Illustrator,
 * Figma or Inkscape; keep the group ids icon / wordmark / tagline), then run this
 * script again. Uploading a logo in Admin → Settings → General also works and
 * needs no script.
 */
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';
import { writePsdBuffer } from 'ag-psd';

const DIR = path.dirname(fileURLToPath(import.meta.url));
const OUT = path.join(DIR, 'exports');
const INSTALL = !process.argv.includes('--no-install');
const CREAM = { r: 0xf6, g: 0xf0, b: 0xe4 };

/** Render an SVG string to a PNG buffer of the given width (height follows the aspect ratio). */
async function renderSvg(svg, { width, height }) {
  // Render at a high density first so curves and small text stay crisp, then downscale.
  return sharp(Buffer.from(svg), { density: 600 })
    .resize({ width, height, fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png({ compressionLevel: 9 })
    .toBuffer();
}

/** The same SVG with every top-level layer except `keep` hidden. */
function onlyLayer(svg, keep, layers) {
  return layers.reduce(
    (s, id) => (id === keep ? s : s.replace(`<g id="${id}"`, `<g id="${id}" display="none"`)),
    svg,
  );
}

async function rawRgba(pngBuffer) {
  const { data, info } = await sharp(pngBuffer).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
  return { width: info.width, height: info.height, data: new Uint8ClampedArray(data.buffer, data.byteOffset, data.length) };
}

/** Layered PSD built from the named groups in logo.svg. */
async function buildPsd(svg, width) {
  const layers = ['icon', 'wordmark', 'tagline'];
  const full = await rawRgba(await renderSvg(svg, { width }));
  const { height } = full;
  const bg = Buffer.alloc(width * height * 4);
  for (let i = 0; i < bg.length; i += 4) { bg[i] = CREAM.r; bg[i + 1] = CREAM.g; bg[i + 2] = CREAM.b; bg[i + 3] = 255; }

  const children = [{
    name: 'Background (cream)', hidden: true, top: 0, left: 0, right: width, bottom: height,
    imageData: { width, height, data: new Uint8ClampedArray(bg) },
  }];
  for (const id of layers) {
    const img = await rawRgba(await renderSvg(onlyLayer(svg, id, layers), { width, height }));
    children.push({ name: id[0].toUpperCase() + id.slice(1), top: 0, left: 0, right: width, bottom: height, imageData: img });
  }
  return writePsdBuffer({ width, height, imageData: full, children }, { generateThumbnail: false });
}

async function main() {
  await fs.mkdir(OUT, { recursive: true });
  const read = (f) => fs.readFile(path.join(DIR, f), 'utf8');
  const [logo, white, iconSvg, favicon] = await Promise.all(
    ['logo.svg', 'logo-white.svg', 'logo-icon.svg', 'favicon.svg'].map(read),
  );

  const files = {
    'logo-full.png': await renderSvg(logo, { width: 2000 }),
    'logo-white.png': await renderSvg(white, { width: 2000 }),
    'logo-icon.png': await renderSvg(iconSvg, { width: 512, height: 512 }),
    'favicon.png': await renderSvg(favicon, { width: 64, height: 64 }),
    'logo.psd': await buildPsd(logo, 2000),
  };
  for (const [name, buf] of Object.entries(files)) {
    await fs.writeFile(path.join(OUT, name), buf);
    console.log(`  exports/${name}  ${(buf.length / 1024).toFixed(1)} KB`);
  }

  if (INSTALL) {
    const uploads = path.join(DIR, '..', 'backend', 'uploads', 'logo');
    await fs.mkdir(uploads, { recursive: true });
    for (const name of ['logo-full.png', 'logo-white.png', 'logo-icon.png', 'favicon.png']) {
      await fs.copyFile(path.join(OUT, name), path.join(uploads, name));
    }
    await fs.copyFile(path.join(OUT, 'favicon.png'), path.join(DIR, '..', 'frontend', 'public', 'favicon.png'));
    console.log('  copied PNGs to backend/uploads/logo/ and favicon.png to frontend/public/');
  }
}

main().catch((err) => { console.error(err); process.exit(1); });
