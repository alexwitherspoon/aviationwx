#!/bin/bash
# Create a simple placeholder image
# Using ImageMagick if available, otherwise create a simple text file

if command -v convert &> /dev/null; then
    convert -size 800x600 xc:gray -font Arial -pointsize 24 -fill white -gravity center -annotate +0+0 "Webcam Placeholder\nKSPB" placeholder.jpg
    echo "Placeholder image created using ImageMagick"
elif command -v ffmpeg &> /dev/null; then
    ffmpeg -f lavfi -i color=c=gray:s=800x600 -vf "drawtext=text='Webcam Placeholder':fontcolor=white:fontsize=24:x=(w-tw)/2:y=(h-th)/2" -frames:v 1 placeholder.jpg -y
    echo "Placeholder image created using ffmpeg"
else
    echo "Creating simple placeholder text file"
    echo "Webcam Placeholder for KSPB" > ../public/images/placeholder.txt
    echo "Note: Install ImageMagick or create a placeholder.jpg image manually"
fi

