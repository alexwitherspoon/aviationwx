#!/usr/bin/env node
/**
 * Build script for Leaflet library
 * 
 * Copies Leaflet files from node_modules to public directories:
 * - leaflet.js → public/js/leaflet.js
 * - leaflet.css → public/css/leaflet.css
 * - images/* → public/images/leaflet/
 * 
 * Usage:
 *   node scripts/build-leaflet.js
 */

const fs = require('fs');
const path = require('path');

const NODE_MODULES_LEAFLET = path.join(__dirname, '..', 'node_modules', 'leaflet', 'dist');
const PUBLIC_JS = path.join(__dirname, '..', 'public', 'js');
const PUBLIC_CSS = path.join(__dirname, '..', 'public', 'css');
const PUBLIC_IMAGES = path.join(__dirname, '..', 'public', 'images', 'leaflet');

// Check if node_modules/leaflet exists
if (!fs.existsSync(NODE_MODULES_LEAFLET)) {
    console.error('❌ Error: Leaflet not found in node_modules');
    console.error('   Run: npm install');
    process.exit(1);
}

// Create public directories if they don't exist
[PUBLIC_JS, PUBLIC_CSS, PUBLIC_IMAGES].forEach(dir => {
    if (!fs.existsSync(dir)) {
        fs.mkdirSync(dir, { recursive: true });
        console.log(`✓ Created directory: ${path.relative(process.cwd(), dir)}`);
    }
});

// Copy leaflet.js
const leafletJs = path.join(NODE_MODULES_LEAFLET, 'leaflet.js');
const leafletJsDest = path.join(PUBLIC_JS, 'leaflet.js');
if (fs.existsSync(leafletJs)) {
    fs.copyFileSync(leafletJs, leafletJsDest);
    const stats = fs.statSync(leafletJsDest);
    console.log(`✓ Copied leaflet.js (${(stats.size / 1024).toFixed(1)} KB)`);
} else {
    console.error(`❌ Error: ${leafletJs} not found`);
    process.exit(1);
}

// Copy leaflet.css and fix image paths
const leafletCss = path.join(NODE_MODULES_LEAFLET, 'leaflet.css');
const leafletCssDest = path.join(PUBLIC_CSS, 'leaflet.css');
if (fs.existsSync(leafletCss)) {
    let cssContent = fs.readFileSync(leafletCss, 'utf8');
    // Fix image paths: url(images/...) -> url(../images/leaflet/...)
    // This is needed because CSS is in public/css/ but images are in public/images/leaflet/
    cssContent = cssContent.replace(/url\(images\//g, 'url(../images/leaflet/');
    fs.writeFileSync(leafletCssDest, cssContent, 'utf8');
    const stats = fs.statSync(leafletCssDest);
    console.log(`✓ Copied leaflet.css with fixed image paths (${(stats.size / 1024).toFixed(1)} KB)`);
} else {
    console.error(`❌ Error: ${leafletCss} not found`);
    process.exit(1);
}

// Copy images directory
const leafletImages = path.join(NODE_MODULES_LEAFLET, 'images');
if (fs.existsSync(leafletImages)) {
    const imageFiles = fs.readdirSync(leafletImages);
    let copiedCount = 0;
    imageFiles.forEach(file => {
        const src = path.join(leafletImages, file);
        const dest = path.join(PUBLIC_IMAGES, file);
        if (fs.statSync(src).isFile()) {
            fs.copyFileSync(src, dest);
            copiedCount++;
        }
    });
    console.log(`✓ Copied ${copiedCount} image file(s) to public/images/leaflet/`);
} else {
    console.error(`❌ Error: ${leafletImages} not found`);
    process.exit(1);
}

console.log('\n✓ Leaflet build complete!');
console.log('  Files are ready in public/js/, public/css/, and public/images/leaflet/');

