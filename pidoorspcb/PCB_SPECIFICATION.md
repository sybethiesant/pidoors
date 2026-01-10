# PiDoors Access Control PCB Specification

**Document Version:** 2.0
**Date:** January 2026
**Target Software Version:** PiDoors v2.2.0+

---

## Table of Contents

1. [Overview](#1-overview)
2. [Design Goals](#2-design-goals)
3. [Supported Configurations](#3-supported-configurations)
4. [Board Specifications](#4-board-specifications)
5. [GPIO Mapping](#5-gpio-mapping)
6. [Power Supply Design](#6-power-supply-design)
7. [Protection Circuits](#7-protection-circuits)
8. [Interface Circuits](#8-interface-circuits)
9. [Connector Specifications](#9-connector-specifications)
10. [Indicator LEDs](#10-indicator-leds)
11. [PCB Layout Guidelines](#11-pcb-layout-guidelines)
12. [Bill of Materials](#12-bill-of-materials)
13. [Assembly Notes](#13-assembly-notes)
14. [Testing Procedure](#14-testing-procedure)

---

## 1. Overview

This specification defines a professional-grade PCB for the PiDoors Access Control System. The board serves as a HAT (Hardware Attached on Top) for Raspberry Pi, providing:

- Multi-reader interface support (Wiegand, OSDP, NFC)
- Protected relay output for electric locks
- Optically isolated door sensor input
- Protected REX (Request to Exit) button input
- Robust power management with protection
- Status indication LEDs
- Tamper detection input

### 1.1 Compatibility

| Raspberry Pi Model | Compatible | Notes |
|-------------------|------------|-------|
| Pi Zero W | Yes | Primary target |
| Pi Zero 2 W | Yes | Primary target |
| Pi 3B/3B+ | Yes | Full support |
| Pi 4B | Yes | Full support |
| Pi 5 | Yes | May need GPIO config |

---

## 2. Design Goals

### 2.1 Primary Goals

1. **Reliability**: Industrial-grade components, proper protection on all I/O
2. **Safety**: Isolated relay output, overcurrent protection, fail-secure design
3. **Flexibility**: Support all PiDoors reader types on single board
4. **Manufacturability**: 2-layer PCB, standard components, hand-solderable option
5. **Compliance**: CE/FCC-friendly design with proper EMC considerations

### 2.2 Protection Requirements

| Threat | Protection Method | Rating |
|--------|------------------|--------|
| ESD (Human Body Model) | TVS diodes | ±8kV contact, ±15kV air |
| Surge (External lines) | TVS + series resistors | 500V transient |
| Overcurrent | Polyfuses (PPTC) | 500mA per input |
| Reverse polarity | Schottky diode OR-ing | 30V max |
| Short circuit | Current limiting | Protected outputs |
| Overvoltage | Zener clamps | 5.5V max to Pi GPIO |

---

## 3. Supported Configurations

The PCB supports four mutually exclusive reader configurations. Only ONE reader type is active at a time (selected via solder jumpers or software).

### 3.1 Configuration A: Wiegand Reader

```
External Reader ──► Wiegand Interface ──► Pi GPIO 23/24
                    (Level shift + ESD)
```

- Supports 26, 32, 34, 35, 36, 37, 48-bit formats
- 5V tolerant inputs with 3.3V level shifting
- Pull-up resistors included

### 3.2 Configuration B: OSDP RS-485 Reader

```
External Reader ──► RS-485 Transceiver ──► Pi UART (GPIO 14/15)
                    (Isolated + ESD)
```

- Half-duplex RS-485 with auto-direction
- Optional galvanic isolation
- Supports 9600-115200 baud

### 3.3 Configuration C: PN532 NFC Module

```
PN532 Header ──► I2C/SPI Selection ──► Pi I2C (GPIO 2/3) or SPI (GPIO 8-11)
                (On-board or external)
```

- Breakout header for external PN532 module
- Optional on-board PN532 chip footprint
- I2C or SPI selectable via jumper

### 3.4 Configuration D: MFRC522 NFC Module

```
MFRC522 Header ──► SPI Interface ──► Pi SPI (GPIO 8-11)
                   (Level shift)      Reset: GPIO 25
```

- Breakout header for external MFRC522 module
- 3.3V level shifting (MFRC522 is 3.3V native)

---

## 4. Board Specifications

### 4.1 Physical Dimensions

| Parameter | Value | Notes |
|-----------|-------|-------|
| Form Factor | Raspberry Pi HAT | 65mm x 56.5mm |
| Mounting Holes | 4x M2.5 | Pi HAT standard positions |
| Connector Clearance | 15mm | GPIO header standoff height |
| Total Height | 20mm max | Including components |
| PCB Thickness | 1.6mm | Standard FR4 |
| Copper Weight | 1oz (35μm) | Both layers |
| Layers | 2 | Top + Bottom |
| Surface Finish | HASL or ENIG | Lead-free |
| Solder Mask | Green | Both sides |
| Silkscreen | White | Both sides |

### 4.2 Environmental Ratings

| Parameter | Value |
|-----------|-------|
| Operating Temperature | -20°C to +70°C |
| Storage Temperature | -40°C to +85°C |
| Humidity | 10-90% RH (non-condensing) |
| Altitude | Up to 3000m |

### 4.3 Electrical Ratings

| Parameter | Value | Notes |
|-----------|-------|-------|
| Input Voltage | 12V DC nominal | 9-24V range |
| Relay Output | 30V DC / 1A max | Resistive load |
| Total Power | 5W max | Including Pi |
| GPIO Voltage | 3.3V | Pi standard |

---

## 5. GPIO Mapping

### 5.1 Fixed Pin Assignments

These pins have dedicated functions and should not be remapped:

| GPIO | BCM Pin | Physical Pin | Function | Direction |
|------|---------|--------------|----------|-----------|
| GPIO 2 | 2 | 3 | I2C SDA (PN532) | Bidirectional |
| GPIO 3 | 3 | 5 | I2C SCL (PN532) | Output |
| GPIO 8 | 8 | 24 | SPI CE0 (NFC) | Output |
| GPIO 9 | 9 | 21 | SPI MISO | Input |
| GPIO 10 | 10 | 19 | SPI MOSI | Output |
| GPIO 11 | 11 | 23 | SPI SCLK | Output |
| GPIO 14 | 14 | 8 | UART TX (OSDP) | Output |
| GPIO 15 | 15 | 10 | UART RX (OSDP) | Input |

### 5.2 Default Configurable Pins

| GPIO | BCM Pin | Physical Pin | Default Function | Configurable |
|------|---------|--------------|------------------|--------------|
| GPIO 18 | 18 | 12 | Relay/Latch Output | Yes |
| GPIO 23 | 23 | 16 | Wiegand D1 | Yes |
| GPIO 24 | 24 | 18 | Wiegand D0 | Yes |
| GPIO 25 | 25 | 22 | NFC Reset / Status LED | Yes |
| GPIO 27 | 27 | 13 | Door Sensor Input | Yes |
| GPIO 17 | 17 | 11 | REX Button Input | Yes |
| GPIO 22 | 22 | 15 | Status LED (Denied) | Yes |
| GPIO 25 | 25 | 22 | Status LED (Granted) | Yes |
| GPIO 6 | 6 | 31 | Tamper Detect Input | Yes |
| GPIO 12 | 12 | 32 | Buzzer Output | Optional |

### 5.3 GPIO Electrical Characteristics

| Parameter | Min | Typ | Max | Unit |
|-----------|-----|-----|-----|------|
| Input High Voltage | 1.8 | - | 3.3 | V |
| Input Low Voltage | 0 | - | 0.8 | V |
| Output High Current | - | - | 16 | mA |
| Output Low Current | - | - | 16 | mA |
| Internal Pull-up | 50 | - | 65 | kΩ |

---

## 6. Power Supply Design

### 6.1 Block Diagram

```
                    ┌─────────────────────────────────────────────────┐
                    │                                                 │
  12V DC ──►[F1]──►[D1]──►[C1]──┬──►[U1: 5V Reg]──►[C2]──► 5V Rail   │
  Input     PPTC   TVS   100μF │      7805/DC-DC    100μF            │
                               │                                      │
                               ├──► 12V Rail (Relay coil)             │
                               │                                      │
                               └──►[U2: 3.3V Reg]──►[C3]──► 3.3V Rail │
                                     LD1117-3.3     100μF             │
                    └─────────────────────────────────────────────────┘
```

### 6.2 Input Power

| Component | Value | Part Number | Notes |
|-----------|-------|-------------|-------|
| Input Connector | 5.5x2.1mm barrel | CUI PJ-002A | Center positive |
| Polyfuse F1 | 1.1A hold, 2.2A trip | Littelfuse 1812L110 | Resettable |
| TVS D1 | 24V standoff, 38.9V clamp | Littelfuse SMBJ24A | 600W peak |
| Bulk Cap C1 | 100μF/35V | Panasonic EEE-FK1V101P | Low ESR electrolytic |
| Reverse Diode | 3A Schottky | ON Semi SS34 | Optional P-FET instead |

### 6.3 5V Rail (Pi Power)

**Option A: Linear Regulator (Simple, more heat)**

| Component | Value | Part Number | Notes |
|-----------|-------|-------------|-------|
| Regulator U1 | LM7805 | ST L7805CV | TO-220, needs heatsink |
| Input Cap | 0.33μF ceramic | - | Close to regulator |
| Output Cap | 0.1μF ceramic | - | Close to regulator |
| Bulk Cap | 100μF/10V | - | Output filtering |

**Option B: DC-DC Buck Converter (Recommended, efficient)**

| Component | Value | Part Number | Notes |
|-----------|-------|-------------|-------|
| DC-DC Module | 5V/3A | Murata OKI-78SR-5 | Drop-in 7805 replacement |
| Input Cap | 10μF ceramic | - | X7R, 25V |
| Output Cap | 22μF ceramic | - | X5R, 10V |

Power Delivery: 5V supplied to Pi via GPIO header pins 2,4 (5V) and 6,9,14,20,25,30,34,39 (GND).

### 6.4 3.3V Rail (Sensors/NFC)

| Component | Value | Part Number | Notes |
|-----------|-------|-------------|-------|
| Regulator U2 | LD1117-3.3 | ST LD1117S33TR | SOT-223 |
| Input Cap | 10μF ceramic | - | X5R, 10V |
| Output Cap | 10μF ceramic | - | X5R, 6.3V |
| Load | 300mA max | - | NFC module + sensors |

### 6.5 Power Sequencing

No special sequencing required. All rails come up together within 100ms.

---

## 7. Protection Circuits

### 7.1 ESD Protection (All External Connectors)

```
External ──►[R]──┬──► To Circuit
Signal    100Ω  │
                [TVS]
                │
               GND
```

| Line | Series R | TVS Part | Notes |
|------|----------|----------|-------|
| Wiegand D0 | 100Ω | PESD5V0S1BL | Bidirectional, 5V |
| Wiegand D1 | 100Ω | PESD5V0S1BL | Bidirectional, 5V |
| RS-485 A | 10Ω | SM712 | Asymmetric, ±12V |
| RS-485 B | 10Ω | SM712 | Asymmetric, ±12V |
| Door Sensor | 1kΩ | PESD5V0S1BL | Slow signal OK |
| REX Button | 1kΩ | PESD5V0S1BL | Slow signal OK |
| Tamper | 1kΩ | PESD5V0S1BL | Slow signal OK |

### 7.2 Wiegand Input Protection

```
                     3.3V
                      │
                     [10kΩ] Pull-up
                      │
Reader ──[100Ω]──┬───[TVS]───┬──► GPIO
  D0/D1          │           │
                GND    [Level Shifter]
                        BSS138 or
                        TXB0102
```

Full Wiegand input circuit:
1. 100Ω series resistor (limits current during ESD)
2. TVS clamp to GND (PESD5V0S1BL)
3. 10kΩ pull-up to 5V (reader side)
4. Level shifter 5V → 3.3V (BSS138 or TXB0102)
5. 10kΩ pull-up to 3.3V (Pi side)

### 7.3 Door Sensor Input (Optically Isolated)

```
                   +3.3V
                     │
External ─────────[1kΩ]────┐
Sensor                     │
  +  ──[1kΩ]──┬──►[LED│]   │ Optocoupler
  -  ─────────┘     │      │ (PC817)
                  [├─┼─►]──┴──► GPIO 27
                     │
                    GND
```

| Component | Value | Part Number |
|-----------|-------|-------------|
| Optocoupler | 50-600% CTR | Sharp PC817X |
| LED Resistor | 1kΩ 1/4W | - |
| Pull-up | 10kΩ | On Pi side |

Benefits:
- Complete galvanic isolation
- Works with both N.O. and N.C. sensors
- Immune to ground loops
- 2500V isolation rating

### 7.4 REX Button Input (Debounced)

```
                    +3.3V
                      │
                    [10kΩ]
                      │
Button ──[1kΩ]──┬────┴──[100nF]──► GPIO 17
                │
              [TVS]
                │
               GND
```

Hardware debounce with RC filter (τ = 1ms).

### 7.5 Relay Output Protection

```
GPIO 18 ──►[Buffer]──►[R 1kΩ]──►[Q1 NPN]──►[Relay Coil]──► +12V
                                    │            │
                                   GND     [Flyback D1]
                                                 │
                                                GND
```

| Component | Value | Part Number | Notes |
|-----------|-------|-------------|-------|
| NPN Transistor Q1 | 500mA, 40V | 2N2222A or BC337 | SOT-23 or TO-92 |
| Base Resistor | 1kΩ | - | GPIO current limit |
| Flyback Diode D1 | 1A, 100V | 1N4148 or 1N4007 | Across relay coil |
| Relay | 12V coil, 5A/250VAC | Omron G5LE-14 | Or Songle SRD-12VDC |

**Alternative: Solid State Relay (SSR)**

For higher reliability and faster switching:

| Component | Value | Part Number | Notes |
|-----------|-------|-------------|-------|
| SSR | 3-32VDC input, 240VAC/2A | Omron G3MB-202P | PCB mount |
| Control Resistor | 330Ω | - | For 3.3V GPIO drive |

---

## 8. Interface Circuits

### 8.1 Wiegand Interface

```
┌─────────────────────────────────────────────────────────────┐
│                    WIEGAND INTERFACE                        │
│                                                             │
│   +5V ◄──────────────────────────────────────────► VCC     │
│                                                             │
│         ┌──[10kΩ]──┐     ┌──[10kΩ]──┐                      │
│         │          │     │          │                      │
│   D0 ◄──┴──[100Ω]──┼──[TVS]──[BSS138]──┼──► GPIO 24        │
│                    │          ↑       │                    │
│                   GND       3.3V     GND                   │
│                                                             │
│   D1 ◄── (same circuit as D0) ─────────────► GPIO 23       │
│                                                             │
│   GND ◄──────────────────────────────────────────► GND     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 8.2 RS-485 Interface (OSDP)

```
┌─────────────────────────────────────────────────────────────┐
│                    RS-485 INTERFACE                         │
│                                                             │
│   Reader              MAX485/SP485             Pi           │
│                      ┌─────────┐                           │
│     A+ ──[10Ω]──[TVS]─┤A      RO├───────────► GPIO 15 (RX) │
│     B- ──[10Ω]──[TVS]─┤B      DI├◄────────── GPIO 14 (TX)  │
│                      │       DE├◄──┬──────── GPIO 4 (DE)   │
│                      │      /RE├◄──┘                       │
│     GND ─────────────┤GND   VCC├─── +3.3V                  │
│                      └─────────┘                           │
│                                                             │
│   Optional: 120Ω termination resistor across A/B           │
│   Optional: Bias resistors 560Ω to VCC (A), GND (B)        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

| Component | Value | Part Number | Notes |
|-----------|-------|-------------|-------|
| Transceiver | 3.3V, 10Mbps | MAX3485 or SP3485 | SO-8 |
| ESD Protection | ±15kV | SM712 | SOT-23 |
| Termination | 120Ω | Optional | End of line |
| Bias Resistors | 560Ω | Optional | Idle state |

**Auto-Direction Option (Simpler):**

Use MAX13487E with auto-direction (no DE/RE control needed):

| Component | Value | Part Number |
|-----------|-------|-------------|
| Transceiver | Auto-direction | MAX13487EESA+ |

### 8.3 NFC Module Headers

**PN532 I2C Header (J5):**

| Pin | Signal | Notes |
|-----|--------|-------|
| 1 | VCC | 3.3V |
| 2 | GND | Ground |
| 3 | SDA | GPIO 2 |
| 4 | SCL | GPIO 3 |
| 5 | IRQ | Optional, GPIO 16 |
| 6 | RST | Optional, GPIO 25 |

**PN532/MFRC522 SPI Header (J6):**

| Pin | Signal | GPIO | Notes |
|-----|--------|------|-------|
| 1 | VCC | - | 3.3V |
| 2 | GND | - | Ground |
| 3 | SCK | 11 | SPI Clock |
| 4 | MISO | 9 | SPI Data In |
| 5 | MOSI | 10 | SPI Data Out |
| 6 | CS | 8 | Chip Select (CE0) |
| 7 | RST | 25 | Reset |
| 8 | IRQ | 16 | Optional interrupt |

---

## 9. Connector Specifications

### 9.1 Connector Summary

| Designator | Type | Pins | Function |
|------------|------|------|----------|
| J1 | 2x20 Female Header | 40 | Raspberry Pi GPIO |
| J2 | 5.5x2.1mm Barrel | 2 | 12V Power Input |
| J3 | Screw Terminal | 4 | Wiegand Reader (D0, D1, 5V, GND) |
| J4 | Screw Terminal | 4 | RS-485 (A+, B-, GND, Shield) |
| J5 | Pin Header | 6 | PN532 I2C |
| J6 | Pin Header | 8 | NFC SPI |
| J7 | Screw Terminal | 3 | Relay Output (NO, COM, NC) |
| J8 | Screw Terminal | 2 | Door Sensor |
| J9 | Screw Terminal | 2 | REX Button |
| J10 | Pin Header | 2 | Tamper Switch |
| J11 | Screw Terminal | 2 | External Buzzer |

### 9.2 Connector Details

**J1 - Raspberry Pi GPIO (2x20 Female Header)**

Standard Raspberry Pi 40-pin header. Use low-profile socket if height is critical.

| Part Number | Manufacturer | Notes |
|-------------|--------------|-------|
| SSW-120-02-G-D | Samtec | 8.5mm height |
| PPPC202LFBN-RC | Sullins | Standard height |

**J2 - Power Input (Barrel Jack)**

| Specification | Value |
|---------------|-------|
| Size | 5.5mm OD x 2.1mm ID |
| Polarity | Center Positive |
| Part Number | CUI PJ-002A |

**J3, J4, J7, J8, J9, J11 - Screw Terminals**

| Specification | Value |
|---------------|-------|
| Pitch | 5.08mm (0.2") |
| Wire Range | 12-26 AWG |
| Current Rating | 10A |
| Part Number | Phoenix 1935161 or equivalent |

### 9.3 Connector Placement

```
┌────────────────────────────────────────────────────────────────┐
│  [J11]  [J10]        PiDoors HAT v2.0         [J2 Barrel]     │
│  Buzzer Tamper                                   12V DC       │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │                                                      │    │
│  │              Raspberry Pi GPIO Header                │    │
│  │                       J1                             │    │
│  │                    (40 pins)                         │    │
│  │                                                      │    │
│  └──────────────────────────────────────────────────────┘    │
│                                                               │
│  [J5]      [J6]         [LED]  [LED]  [LED]         [J3]     │
│  PN532     NFC SPI      PWR    OK     ERR          Wiegand   │
│  I2C                                              D0 D1 5V G │
│                                                               │
│  [J8]      [J9]           [REL1]                   [J4]      │
│  Door      REX            RELAY                   RS-485     │
│  Sensor    Button                                A+ B- G S   │
│                                                               │
│  ○         ○              [J7]                    ○         ○ │
│                        NO COM NC                             │
│ M2.5     M2.5          Relay Out              M2.5       M2.5│
└────────────────────────────────────────────────────────────────┘
```

---

## 10. Indicator LEDs

### 10.1 LED Specifications

| LED | Color | GPIO | Function |
|-----|-------|------|----------|
| D3 | Green | - | Power Good (5V rail) |
| D4 | Green | GPIO 25 | Access Granted |
| D5 | Red | GPIO 22 | Access Denied / Error |
| D6 | Yellow | GPIO 12 | Activity / Card Read |

### 10.2 LED Circuit

```
+3.3V (or GPIO HIGH)
    │
   [R] 330Ω (Green/Yellow) or 220Ω (Red)
    │
   [LED]
    │
   GPIO (sink mode) or GND (power LED)
```

| LED Color | Vf | Series R | If |
|-----------|-----|----------|-----|
| Green | 2.2V | 330Ω | 3.3mA |
| Red | 1.8V | 220Ω | 6.8mA |
| Yellow | 2.0V | 330Ω | 4mA |

### 10.3 Power LED Circuit

Dedicated power indicator (always on when 5V present):

```
+5V ──[1kΩ]──[Green LED]── GND
```

---

## 11. PCB Layout Guidelines

### 11.1 Layer Stack

| Layer | Usage |
|-------|-------|
| Top | Components, signals, power traces |
| Bottom | Ground plane, some signals |

### 11.2 Design Rules

| Parameter | Value | Notes |
|-----------|-------|-------|
| Minimum Trace Width | 0.25mm (10mil) | Signals |
| Power Trace Width | 0.5mm minimum | 5V, 12V, GND |
| Minimum Clearance | 0.2mm (8mil) | Signal to signal |
| Via Size | 0.8mm pad, 0.4mm hole | Standard |
| Via Clearance | 0.25mm | Annular ring |

### 11.3 Critical Layout Rules

1. **Power Input Section**
   - Place input protection (TVS, polyfuse) within 10mm of barrel jack
   - Bulk capacitor adjacent to regulator input
   - Output capacitors adjacent to regulator outputs
   - Wide traces (1mm+) for power paths

2. **Ground Plane**
   - Solid ground plane on bottom layer
   - Do NOT route signals through ground plane under analog sections
   - Star ground from bulk capacitor to: digital, analog, relay sections

3. **Relay Section**
   - Keep relay traces away from sensitive inputs
   - Flyback diode as close as possible to relay coil
   - Consider slot in ground plane to isolate relay section

4. **ESD Protection**
   - TVS diodes as close as possible to connectors
   - Short, wide traces to ground plane
   - Series resistors between TVS and protected circuit

5. **RS-485 Section**
   - Differential pair routing for A/B lines
   - Keep traces equal length
   - Place common-mode choke if EMC issues

6. **SPI Section**
   - Keep SPI traces short (<50mm)
   - Route CLK away from other signals
   - Add 33Ω series termination on CLK if issues

### 11.4 Thermal Considerations

If using linear 7805 regulator with 12V input:
- Power dissipation: (12V - 5V) × 0.5A = 3.5W
- Requires heatsink or copper pour
- Consider DC-DC converter instead

Recommended: Use OKI-78SR-5 or similar DC-DC module.

---

## 12. Bill of Materials

### 12.1 Core Components

| Ref | Description | Value | Package | Part Number | Qty |
|-----|-------------|-------|---------|-------------|-----|
| U1 | DC-DC Converter | 5V 1.5A | SIP-3 | OKI-78SR-5/1.5-W36 | 1 |
| U2 | LDO Regulator | 3.3V | SOT-223 | LD1117S33TR | 1 |
| U3 | RS-485 Transceiver | Auto-dir | SO-8 | MAX13487EESA+ | 1 |
| U4 | Dual Level Shifter | - | SOT-23-6 | TXB0102DCUR | 1 |
| U5 | Optocoupler | - | DIP-4 | PC817X | 1 |
| Q1 | NPN Transistor | 500mA | SOT-23 | MMBT2222A | 1 |
| REL1 | Relay | 12V SPDT | PCB | G5LE-14 DC12 | 1 |

### 12.2 Protection Components

| Ref | Description | Value | Package | Part Number | Qty |
|-----|-------------|-------|---------|-------------|-----|
| F1 | Polyfuse | 1.1A | 1812 | 1812L110/24DR | 1 |
| D1 | TVS Diode (Power) | 24V | SMB | SMBJ24A | 1 |
| D2-D5 | TVS Diode (Signal) | 5V | SOD-323 | PESD5V0S1BL | 4 |
| D6-D7 | TVS Diode (RS-485) | ±12V | SOT-23 | SM712 | 2 |
| D8 | Flyback Diode | 100V | SOD-123 | 1N4148W | 1 |
| D9 | Schottky (Reverse) | 40V 3A | SMA | SS34 | 1 |

### 12.3 Passive Components

| Ref | Description | Value | Package | Qty |
|-----|-------------|-------|---------|-----|
| C1 | Bulk Cap Input | 100μF/35V | Radial 8x12mm | 1 |
| C2 | Bulk Cap 5V | 100μF/10V | Radial 6x7mm | 1 |
| C3-C6 | Ceramic Cap | 10μF/25V | 0805 | 4 |
| C7-C10 | Ceramic Cap | 100nF | 0603 | 4 |
| R1-R2 | Pull-up Wiegand | 10kΩ | 0603 | 2 |
| R3-R4 | Series ESD | 100Ω | 0603 | 2 |
| R5-R8 | Series ESD | 1kΩ | 0603 | 4 |
| R9 | Transistor Base | 1kΩ | 0603 | 1 |
| R10-R13 | LED Resistors | 330Ω | 0603 | 4 |
| R14 | Optocoupler LED | 1kΩ | 0603 | 1 |

### 12.4 Connectors

| Ref | Description | Pins | Part Number | Qty |
|-----|-------------|------|-------------|-----|
| J1 | Pi GPIO Header | 2x20 | SSW-120-02-G-D | 1 |
| J2 | Barrel Jack | 2 | PJ-002A | 1 |
| J3 | Screw Terminal | 4 | 1935161 (4-pos) | 1 |
| J4 | Screw Terminal | 4 | 1935161 (4-pos) | 1 |
| J5 | Pin Header | 6 | - | 1 |
| J6 | Pin Header | 8 | - | 1 |
| J7 | Screw Terminal | 3 | 1935174 (3-pos) | 1 |
| J8-J9 | Screw Terminal | 2 | 1935147 (2-pos) | 2 |
| J10-J11 | Pin Header | 2 | - | 2 |

### 12.5 LEDs

| Ref | Color | Package | Part Number | Qty |
|-----|-------|---------|-------------|-----|
| D10 | Green (Power) | 0805 | - | 1 |
| D11 | Green (OK) | 0805 | - | 1 |
| D12 | Red (Error) | 0805 | - | 1 |
| D13 | Yellow (Activity) | 0805 | - | 1 |

### 12.6 BOM Cost Estimate

| Category | Estimated Cost (qty 1) | Estimated Cost (qty 100) |
|----------|------------------------|--------------------------|
| ICs | $8.00 | $4.50 |
| Protection | $5.00 | $2.50 |
| Passives | $2.00 | $0.50 |
| Connectors | $6.00 | $3.00 |
| Relay | $3.00 | $1.50 |
| PCB (2-layer) | $5.00 | $1.00 |
| **Total** | **~$29** | **~$13** |

---

## 13. Assembly Notes

### 13.1 Recommended Assembly Order

1. **SMD Components (Bottom to Top by height)**
   - 0603 resistors and capacitors
   - SOT-23 transistors and TVS diodes
   - SO-8 / SOT-223 ICs

2. **Through-Hole Components**
   - Electrolytic capacitors (observe polarity!)
   - Pin headers
   - Relay
   - Screw terminals
   - Barrel jack
   - 40-pin GPIO header (last)

### 13.2 Solder Paste Stencil

For production assembly, use 0.12mm (5mil) stencil thickness for 0603 and larger components.

### 13.3 Reflow Profile (Lead-Free)

| Phase | Temperature | Time |
|-------|-------------|------|
| Preheat | 150-200°C | 60-120s |
| Soak | 200-220°C | 60-90s |
| Reflow | 245-250°C peak | 30-60s |
| Cooling | <3°C/second | - |

### 13.4 Hand Soldering

- Use lead-free solder (SAC305 recommended)
- Tip temperature: 350-380°C
- Use flux for through-hole joints

---

## 14. Testing Procedure

### 14.1 Visual Inspection

- [ ] All components present and correctly oriented
- [ ] No solder bridges
- [ ] Polarity correct on capacitors, diodes, ICs
- [ ] Clean board (no flux residue in production)

### 14.2 Power-On Test (Without Pi)

1. **Apply 12V power**
   - [ ] No smoke or burning smell
   - [ ] Current draw < 50mA (no load)
   - [ ] Power LED illuminates

2. **Measure voltages**
   - [ ] 5V rail: 4.9-5.1V
   - [ ] 3.3V rail: 3.2-3.4V
   - [ ] 12V rail (relay): 11.5-12.5V

### 14.3 GPIO Test (With Pi)

1. **Boot Raspberry Pi**
   - [ ] Pi boots normally
   - [ ] No kernel errors related to GPIO

2. **Test Outputs**
   ```bash
   # Test relay
   gpio -g mode 18 out
   gpio -g write 18 1  # Relay should click
   gpio -g write 18 0  # Relay should release

   # Test LEDs
   gpio -g mode 5 out
   gpio -g write 5 1   # Green LED on
   gpio -g mode 22 out
   gpio -g write 22 1  # Red LED on
   ```

3. **Test Inputs**
   ```bash
   # Door sensor (short terminals to test)
   gpio -g mode 27 in
   gpio -g read 27     # Should read 0 when shorted

   # REX button
   gpio -g mode 17 in
   gpio -g read 17     # Should read 0 when pressed
   ```

### 14.4 Wiegand Test

1. Connect a Wiegand reader to J3
2. Run PiDoors in debug mode
3. Scan a card
4. Verify card number appears in logs

### 14.5 RS-485 Test

1. Connect OSDP reader to J4
2. Check for communication (no errors in log)
3. Verify bidirectional data with logic analyzer if needed

### 14.6 Relay Endurance Test

For production validation:
- Cycle relay 10,000 times
- Verify no failure or contact degradation

---

## Appendix A: Schematic Symbol Reference

```
Resistor:    ─[////]─
Capacitor:   ─┤├─  (ceramic)  ─┤(─  (electrolytic)
Diode:       ─►├─  (anode → cathode)
LED:         ─►◄─
TVS:         ─◄►─  (bidirectional)
Transistor:  ─┤◄   (NPN, base-emitter-collector)
Optocoupler: ─►├───┤◄─
```

---

## Appendix B: Reference Documents

1. Raspberry Pi GPIO Pinout: https://pinout.xyz
2. Wiegand Protocol: HID Application Note AN-001
3. OSDP Specification: SIA OSDP v2.2
4. RS-485 Design Guide: TI SLLA272C

---

## Appendix C: Revision History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2020-12 | Initial release (Wiegand only) |
| 2.0 | 2026-01 | Multi-reader support, protection circuits, professional spec |

---

## Appendix D: License

This PCB design specification is released under the MIT License as part of the PiDoors project.

```
MIT License

Copyright (c) 2026 PiDoors Project

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Documentation"), to deal
in the Documentation without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Documentation, and to permit persons to whom the Documentation is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Documentation.
```

---

*End of PiDoors PCB Specification v2.0*
