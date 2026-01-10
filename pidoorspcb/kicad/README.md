# PiDoors HAT KiCad Project

KiCad 7/8 project files for the PiDoors Access Control HAT.

## Project Files

| File | Description |
|------|-------------|
| `pidoors-hat.kicad_pro` | KiCad project file |
| `pidoors-hat.kicad_sch` | Schematic (placeholder - needs symbols) |
| `pidoors-hat.kicad_pcb` | PCB layout with board outline and mounting holes |
| `pidoors-hat.net` | Complete netlist with all components and connections |

## Current Status

This project provides:
- Complete netlist with 50+ components and all connections
- Pi HAT board outline (65mm x 56.5mm)
- Mounting hole positions (M2.5)
- Net definitions for all signals

**To complete the design, you need to:**
1. Import the netlist into KiCad or use it as reference
2. Add component footprints
3. Place components
4. Route traces (or use auto-router)
5. Generate Gerber files

## Quick Start - Complete the Design

### Option 1: Use KiCad (Recommended for Customization)

1. **Open the project in KiCad 7 or 8**
   ```bash
   kicad pidoors-hat.kicad_pro
   ```

2. **Create schematic from netlist**
   - Open Eeschema (schematic editor)
   - Add symbols from KiCad standard libraries
   - Connect according to netlist

3. **Import netlist to PCB**
   - Open PCBnew
   - Tools → Update PCB from Schematic
   - Place components within board outline
   - Route traces

4. **Generate manufacturing files**
   - File → Fabrication Outputs → Gerbers
   - File → Fabrication Outputs → Drill Files

### Option 2: Use EasyEDA (Easiest for JLCPCB Orders)

1. Go to [EasyEDA](https://easyeda.com)
2. Create new project
3. Use the netlist as reference to create schematic
4. Convert to PCB, place components
5. Order directly from JLCPCB with one click

### Option 3: Hire a Designer

Give the following files to a PCB designer on Fiverr/Upwork:
- `pidoors-hat.net` - Complete netlist
- `../PCB_SPECIFICATION.md` - Full design specification
- This README

Estimated cost: $50-150 for complete design with Gerbers.

## Component Placement Guide

```
┌─────────────────────────────────────────────────────────────────┐
│ H1                                                          H2 │
│ (3.5,3.5)                                            (61.5,3.5)│
│                                                                 │
│    J2          U1        U2                                    │
│  Barrel     DC-DC      LDO         C1   C2                     │
│   Jack      5V        3.3V                                     │
│  (5,12)   (15,12)   (25,12)     (35,10) (40,10)               │
│                                                                 │
│  ┌──────────────────────────────────────────────────────┐      │
│  │                                                      │      │
│  │              J1 - Raspberry Pi GPIO                  │      │
│  │                   2x20 Header                        │      │
│  │                  (7.5 to 57.5, 25)                   │      │
│  │                                                      │      │
│  └──────────────────────────────────────────────────────┘      │
│                                                                 │
│  J3         J4          J5        J6         D8-D11            │
│ Wiegand   RS-485      PN532     NFC SPI      LEDs              │
│ (8,42)   (20,42)     (32,42)   (44,42)     (55,42)            │
│                                                                 │
│  J7         J8        J9        J10       J11                  │
│ Relay    DoorSns     REX      Tamper    Buzzer                 │
│ (8,50)   (18,50)   (28,50)   (38,50)   (48,50)                │
│                                                                 │
│ H3                                                          H4 │
│ (3.5,53)                                              (61.5,53)│
└─────────────────────────────────────────────────────────────────┘
```

## Footprint Assignments

| Ref | Footprint | Notes |
|-----|-----------|-------|
| J1 | `Connector_PinSocket_2.54mm:PinSocket_2x20_P2.54mm_Vertical` | Pi GPIO |
| J2 | `Connector_BarrelJack:BarrelJack_CUI_PJ-002A_Horizontal` | 5.5x2.1mm |
| J3,J4 | `TerminalBlock_Phoenix:TerminalBlock_Phoenix_MKDS-1,5-4-5.08_1x04_P5.08mm_Horizontal` | 4-pos screw |
| J5 | `Connector_PinHeader_2.54mm:PinHeader_1x06_P2.54mm_Vertical` | 6-pin |
| J6 | `Connector_PinHeader_2.54mm:PinHeader_1x08_P2.54mm_Vertical` | 8-pin |
| J7 | `TerminalBlock_Phoenix:TerminalBlock_Phoenix_MKDS-1,5-3-5.08_1x03_P5.08mm_Horizontal` | 3-pos |
| J8,J9,J11 | `TerminalBlock_Phoenix:TerminalBlock_Phoenix_MKDS-1,5-2-5.08_1x02_P5.08mm_Horizontal` | 2-pos |
| J10 | `Connector_PinHeader_2.54mm:PinHeader_1x02_P2.54mm_Vertical` | 2-pin |
| U1 | `Converter_DCDC:Converter_DCDC_muRata_OKI-78SRx_Vertical` | DC-DC |
| U2 | `Package_TO_SOT_SMD:SOT-223-3_TabPin2` | LDO |
| U3 | `Package_SO:SOIC-8_3.9x4.9mm_P1.27mm` | RS-485 |
| U4 | `Package_TO_SOT_SMD:SOT-23-6` | Level shifter |
| U5 | `Package_DIP:DIP-4_W7.62mm` | Optocoupler |
| K1 | `Relay_THT:Relay_SPDT_Omron-G5LE-14` | Relay |
| Q1 | `Package_TO_SOT_SMD:SOT-23` | NPN |
| D1 | `Diode_SMD:D_SMB` | TVS |
| D2-D5 | `Diode_SMD:D_SOD-323` or `Package_TO_SOT_SMD:SOT-23` | TVS |
| D6 | `Diode_SMD:D_SOD-123` | Flyback |
| D7 | `Diode_SMD:D_SMA` | Schottky |
| D8-D11 | `LED_SMD:LED_0805_2012Metric` | LEDs |
| C1 | `Capacitor_THT:CP_Radial_D8.0mm_P3.50mm` | 100uF/35V |
| C2 | `Capacitor_THT:CP_Radial_D6.3mm_P2.50mm` | 100uF/10V |
| C3-C6 | `Capacitor_SMD:C_0805_2012Metric` | 10uF |
| C7-C8 | `Capacitor_SMD:C_0603_1608Metric` | 100nF |
| R1-R13 | `Resistor_SMD:R_0603_1608Metric` | Various |
| F1 | `Fuse:Fuse_1812_4532Metric` | Polyfuse |
| H1-H4 | `MountingHole:MountingHole_2.7mm_M2.5` | M2.5 holes |

## Design Rules for Manufacturing

| Parameter | Value | JLCPCB Compatible |
|-----------|-------|-------------------|
| Min Track Width | 0.2mm (8mil) | Yes (min 0.127mm) |
| Min Clearance | 0.2mm | Yes (min 0.127mm) |
| Min Via Diameter | 0.5mm | Yes (min 0.45mm) |
| Min Via Drill | 0.3mm | Yes (min 0.2mm) |
| Min Hole Size | 0.3mm | Yes |
| Board Thickness | 1.6mm | Standard |
| Copper Weight | 1oz | Standard |

## Ordering from JLCPCB

1. Generate Gerber files from KiCad
2. Go to [jlcpcb.com](https://jlcpcb.com)
3. Upload Gerber ZIP file
4. Select options:
   - Layers: 2
   - Dimensions: 65 x 56.5 mm
   - PCB Thickness: 1.6mm
   - Solder Mask: Green (or your choice)
   - Silkscreen: White
   - Surface Finish: HASL (cheapest) or ENIG (better)

**Estimated cost:** $2-5 for 5 boards + shipping

## Assembly Options

### Self-Assembly
Order bare PCBs and solder components yourself.
Total BOM cost: ~$25-30 per board

### JLCPCB SMT Assembly
1. Generate BOM and CPL (Component Placement List) files
2. Upload with Gerbers
3. JLCPCB will solder SMD components
4. You solder through-hole parts (connectors, relay)

**Estimated cost:** ~$15-20 per board (qty 5) + component cost

## Files for Manufacturing

When ordering, you'll need:
- `gerbers/` - Gerber files (generate from KiCad)
- `pidoors-hat-bom.csv` - Bill of Materials
- `pidoors-hat-cpl.csv` - Component Placement List

## License

MIT License - See main project LICENSE file.
