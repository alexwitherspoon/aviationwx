#!/usr/bin/env node
/**
 * Build script for Leaflet and Leaflet.markercluster
 *
 * Copies files from node_modules to public directories:
 * - Leaflet: leaflet.js, leaflet.css, images/* → public/js/, public/css/, public/images/leaflet/
 * - MarkerCluster: leaflet.markercluster.js, MarkerCluster.css, MarkerCluster.Default.css → public/js/, public/css/
 *
 * Usage:
 *   node scripts/build-leaflet.js
 */

const fs = require('fs');
const path = require('path');

const NODE_MODULES_LEAFLET = path.join(__dirname, '..', 'node_modules', 'leaflet', 'dist');
const NODE_MODULES_MARKERCLUSTER = path.join(__dirname, '..', 'node_modules', 'leaflet.markercluster', 'dist');
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

// Copy leaflet.js and fix source map path
const leafletJs = path.join(NODE_MODULES_LEAFLET, 'leaflet.js');
const leafletJsDest = path.join(PUBLIC_JS, 'leaflet.js');
if (fs.existsSync(leafletJs)) {
    let jsContent = fs.readFileSync(leafletJs, 'utf8');
    // Fix source map path to use absolute path
    jsContent = jsContent.replace(/\/\/# sourceMappingURL=leaflet\.js\.map/g, '//# sourceMappingURL=/public/js/leaflet.js.map');
    fs.writeFileSync(leafletJsDest, jsContent, 'utf8');
    const stats = fs.statSync(leafletJsDest);
    console.log(`✓ Copied leaflet.js with fixed source map path (${(stats.size / 1024).toFixed(1)} KB)`);
} else {
    console.error(`❌ Error: ${leafletJs} not found`);
    process.exit(1);
}

// Copy leaflet.js.map (source map)
const leafletJsMap = path.join(NODE_MODULES_LEAFLET, 'leaflet.js.map');
const leafletJsMapDest = path.join(PUBLIC_JS, 'leaflet.js.map');
if (fs.existsSync(leafletJsMap)) {
    fs.copyFileSync(leafletJsMap, leafletJsMapDest);
    const stats = fs.statSync(leafletJsMapDest);
    console.log(`✓ Copied leaflet.js.map (${(stats.size / 1024).toFixed(1)} KB)`);
} else {
    console.warn(`⚠️  Warning: ${leafletJsMap} not found (source map will not be available)`);
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

// Copy Leaflet.markercluster
if (!fs.existsSync(NODE_MODULES_MARKERCLUSTER)) {
    console.error('❌ Error: leaflet.markercluster not found in node_modules');
    console.error('   Run: npm install');
    process.exit(1);
}

const markerclusterFiles = [
    { src: 'leaflet.markercluster.js', dest: PUBLIC_JS },
    { src: 'leaflet.markercluster.js.map', dest: PUBLIC_JS },
    { src: 'MarkerCluster.css', dest: PUBLIC_CSS },
    { src: 'MarkerCluster.Default.css', dest: PUBLIC_CSS },
];

markerclusterFiles.forEach(({ src, dest }) => {
    const srcPath = path.join(NODE_MODULES_MARKERCLUSTER, src);
    const destPath = path.join(dest, src);
    if (fs.existsSync(srcPath)) {
        fs.copyFileSync(srcPath, destPath);
        const stats = fs.statSync(destPath);
        console.log(`✓ Copied ${src} (${(stats.size / 1024).toFixed(1)} KB)`);
    } else {
        console.error(`❌ Error: ${srcPath} not found`);
        process.exit(1);
    }
});

console.log('\n✓ Leaflet and Leaflet.markercluster build complete!');
console.log('  Files are ready in public/js/, public/css/, and public/images/leaflet/');

