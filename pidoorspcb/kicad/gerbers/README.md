# PiDoors HAT v2.0 - Gerber Files

## File Contents

| File | Description | Layer |
|------|-------------|-------|
| `pidoors-hat-F_Cu.gtl` | Top Copper | Traces, pads, vias |
| `pidoors-hat-B_Cu.gbl` | Bottom Copper | GND plane, traces |
| `pidoors-hat-F_Mask.gts` | Top Solder Mask | Pad openings |
| `pidoors-hat-B_Mask.gbs` | Bottom Solder Mask | Pad openings |
| `pidoors-hat-F_SilkS.gto` | Top Silkscreen | Labels, designators |
| `pidoors-hat-B_SilkS.gbo` | Bottom Silkscreen | Project info |
| `pidoors-hat-Edge_Cuts.gm1` | Board Outline | 65mm x 56.5mm |
| `pidoors-hat-PTH.drl` | Plated Holes | Component holes, vias |
| `pidoors-hat-NPTH.drl` | Non-Plated Holes | Mounting holes |

## Board Specifications

- **Dimensions**: 65mm x 56.5mm (Raspberry Pi HAT standard)
- **Layers**: 2 (Top + Bottom)
- **Thickness**: 1.6mm
- **Copper Weight**: 1oz
- **Surface Finish**: HASL Lead-Free or ENIG
- **Solder Mask**: Green
- **Silkscreen**: White
- **Min Trace Width**: 0.25mm (signals), 0.5mm (power)
- **Min Clearance**: 0.2mm
- **Min Via**: 0.8mm pad, 0.4mm drill

## Ordering from JLCPCB

1. **Create ZIP file**:
   ```bash
   cd gerbers/
   zip pidoors-hat-gerbers.zip *.gtl *.gbl *.gts *.gbs *.gto *.gbo *.gm1 *.drl
   ```

2. **Upload to JLCPCB**:
   - Go to https://jlcpcb.com
   - Click "Order Now" or "Instant Quote"
   - Upload `pidoors-hat-gerbers.zip`

3. **Verify Gerber Preview**:
   - Board dimensions: 65 x 56.5 mm
   - Mounting holes: 4 at corners
   - All layers present

4. **Select Options**:
   - Layers: 2
   - PCB Thickness: 1.6mm
   - PCB Color: Green (or preference)
   - Surface Finish: HASL Lead-Free
   - Remove Order Number: Yes (recommended)

5. **SMT Assembly** (Optional):
   - Upload BOM: `../pidoors-hat-bom.csv`
   - Upload CPL: `../pidoors-hat-cpl.csv`
   - Select "Assemble top side"

## Regenerating Gerbers (KiCad 8.x)

If you have KiCad 8.x installed:

```bash
chmod +x generate_gerbers.sh
./generate_gerbers.sh
```

Or manually in KiCad:
1. Open `pidoors-hat.kicad_pcb`
2. File > Fabrication Outputs > Gerbers
3. File > Fabrication Outputs > Drill Files

## Component Layers

### Top Side (SMD Components)
- F1 - PTC Fuse (1812)
- D1 - TVS Diode (SMB)
- D7 - Schottky Diode (SMA)
- U2 - 3.3V LDO (SOT-223)
- U3 - RS-485 Transceiver (SOIC-8)
- U4 - Level Shifter (SOT-23-6)
- Q1 - NPN Transistor (SOT-23)
- D6 - Flyback Diode (SOD-123)
- R3-R12 - Resistors (0603)
- D2-D5 - TVS Diodes (SOD-323)
- D8-D11 - LEDs (0805)

### Top Side (Through-Hole)
- J1 - 2x20 Pin Socket (Pi Header)
- J2 - Barrel Jack (5.5x2.1mm)
- J3 - 4-pos Terminal Block (Wiegand)
- J4 - 4-pos Terminal Block (RS-485)
- J7 - 3-pos Terminal Block (Relay Out)
- C1 - 100uF/35V Electrolytic
- C2 - 100uF/10V Electrolytic
- U1 - DC-DC Converter (OKI-78SR-5)
- K1 - SPDT Relay (G5LE-14)
- U5 - Optocoupler (DIP-4)

### Bottom Side
- GND Ground Plane (copper pour)
- Signal routing for long traces
- Project URL silkscreen

## Quality Check

Before ordering, verify:
- [ ] Board outline is 65 x 56.5 mm
- [ ] All 4 mounting holes visible (2.7mm)
- [ ] GPIO header footprint correct (2x20)
- [ ] Power traces are wide (0.5mm+)
- [ ] No silkscreen overlapping pads
- [ ] Drill files included (PTH + NPTH)

---

*Generated for PiDoors Project v2.2*
