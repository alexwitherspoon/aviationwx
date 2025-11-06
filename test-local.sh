#!/bin/bash
# AviationWX Local Testing Script
# Run various testing commands for local development

echo "✈️ AviationWX Local Testing"
echo "================================"
echo ""

# Check if server is running
if lsof -Pi :8080 -sTCP:LISTEN -t >/dev/null ; then
    echo "✓ PHP Server is running on localhost:8080"
else
    echo "⚠ PHP Server is NOT running"
    echo "  Start it with: php -S localhost:8080"
    echo ""
fi

echo ""
echo "Available commands:"
echo ""
echo "1. Test webcam fetching"
echo "   php fetch-webcam-safe.php"
echo ""
echo "2. Check weather API"
echo "   curl -s 'http://localhost:8080/weather.php?airport=kspb' | python3 -m json.tool"
echo ""
echo "3. View KSPB page"
echo "   open http://localhost:8080/?airport=kspb"
echo ""
echo "4. Check cached webcam images"
echo "   ls -lh cache/webcams/"
echo ""
echo "5. Start PHP server"
echo "   php -S localhost:8080"
echo ""

read -p "Enter a command number (or 'q' to quit): " choice

case $choice in
    1)
        echo "Fetching webcam images..."
        php fetch-webcam-safe.php
        ;;
    2)
        echo "Testing weather API..."
        curl -s 'http://localhost:8080/weather.php?airport=kspb' | python3 -m json.tool
        ;;
    3)
        echo "Opening KSPB page..."
        open http://localhost:8080/?airport=kspb 2>/dev/null || xdg-open http://localhost:8080/?airport=kspb 2>/dev/null || echo "Visit: http://localhost:8080/?airport=kspb"
        ;;
    4)
        echo "Cached webcam images:"
        ls -lh cache/webcams/ 2>/dev/null || echo "No cached images yet"
        ;;
    5)
        echo "Starting PHP server..."
        php -S localhost:8080
        ;;
    q)
        exit 0
        ;;
    *)
        echo "Invalid choice"
        ;;
esac

