#!/bin/bash
# Gerber Generation Script for PiDoors HAT v2.0
# Run this script on a system with KiCad 8.x installed

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PCB_FILE="$SCRIPT_DIR/../pidoors-hat.kicad_pcb"
OUTPUT_DIR="$SCRIPT_DIR"

# Check for KiCad CLI
if ! command -v kicad-cli &> /dev/null; then
    echo "Error: kicad-cli not found. Please install KiCad 8.x"
    echo ""
    echo "On Ubuntu/Debian:"
    echo "  sudo add-apt-repository ppa:kicad/kicad-8.0-releases"
    echo "  sudo apt update && sudo apt install kicad"
    echo ""
    echo "On macOS:"
    echo "  brew install kicad"
    echo ""
    exit 1
fi

echo "Generating Gerber files for PiDoors HAT v2.0..."
echo "PCB File: $PCB_FILE"
echo "Output: $OUTPUT_DIR"
echo ""

# Generate Gerbers
kicad-cli pcb export gerbers \
    --output "$OUTPUT_DIR" \
    --layers "F.Cu,B.Cu,F.Paste,B.Paste,F.SilkS,B.SilkS,F.Mask,B.Mask,Edge.Cuts" \
    --use-drill-file-origin \
    --subtract-soldermask \
    "$PCB_FILE"

if [ $? -ne 0 ]; then
    echo "Error generating Gerbers"
    exit 1
fi

echo "Gerbers generated successfully!"
echo ""

# Generate Drill files
echo "Generating drill files..."
kicad-cli pcb export drill \
    --output "$OUTPUT_DIR" \
    --format excellon \
    --drill-origin plot \
    --excellon-separate-th \
    --generate-map \
    --map-format gerberx2 \
    "$PCB_FILE"

if [ $? -ne 0 ]; then
    echo "Error generating drill files"
    exit 1
fi

echo "Drill files generated successfully!"
echo ""

# Create ZIP for JLCPCB upload
echo "Creating JLCPCB upload package..."
cd "$OUTPUT_DIR"
zip -j pidoors-hat-gerbers.zip *.g* *.drl 2>/dev/null || zip -j pidoors-hat-gerbers.zip *.gbr *.xln 2>/dev/null

echo ""
echo "=== Generation Complete ==="
echo "Files created in: $OUTPUT_DIR"
echo ""
echo "Upload pidoors-hat-gerbers.zip to JLCPCB for manufacturing"
echo ""
ls -la "$OUTPUT_DIR"
