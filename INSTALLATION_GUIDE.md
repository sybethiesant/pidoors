# PiDoors Installation Guide

Complete step-by-step instructions for setting up the PiDoors Access Control System. This guide is designed for beginners with no prior Raspberry Pi experience.

---

## Table of Contents

1. [What You'll Need](#what-youll-need)
2. [Part 1: Setting Up Your Raspberry Pi](#part-1-setting-up-your-raspberry-pi)
3. [Part 2: Installing the Server](#part-2-installing-the-server)
4. [Part 3: Installing Door Controllers](#part-3-installing-door-controllers)
5. [Part 4: Reader Wiring Guides](#part-4-reader-wiring-guides)
   - [Wiegand Readers](#wiegand-readers)
   - [OSDP RS-485 Readers](#osdp-rs-485-readers)
   - [NFC PN532 Readers](#nfc-pn532-readers)
   - [NFC MFRC522 Readers](#nfc-mfrc522-readers)
6. [Part 5: First Login and Configuration](#part-5-first-login-and-configuration)
7. [Part 6: Adding Your First Door and Card](#part-6-adding-your-first-door-and-card)
8. [Part 7: Advanced Features](#part-7-advanced-features)
9. [Troubleshooting](#troubleshooting)
10. [Maintenance](#maintenance)

---

## What You'll Need

### For the Server (Required - One Per System)

**Hardware:**
- 1x Raspberry Pi 3B+ or newer (4GB RAM recommended)
- 1x MicroSD card (16GB minimum, 32GB recommended)
- 1x Power supply for Raspberry Pi (official recommended)
- 1x Ethernet cable (or WiFi setup)
- 1x Monitor, keyboard, mouse (for initial setup only)

**Optional:**
- Case for Raspberry Pi
- Cooling fan

### For Each Door Controller (One Per Door)

**Hardware:**
- 1x Raspberry Pi Zero W or newer per door
- 1x MicroSD card (8GB minimum)
- 1x Card reader (see options below)
- 1x 12V electric strike or magnetic lock
- 1x Relay module (to control the lock)
- 1x Door sensor (magnetic switch - optional but recommended)
- 1x Exit button / REX button (optional)
- Wiring supplies (jumper wires, terminal blocks)

**Supported Card Readers (choose one):**

| Reader Type | Interface | Formats | Cost | Notes |
|-------------|-----------|---------|------|-------|
| **Wiegand** | GPIO | 26/32/34/35/36/37/48-bit | $15-50 | Most common, easy setup |
| **OSDP** | RS-485 | Any | $50-150 | Encrypted, commercial-grade |
| **PN532 NFC** | I2C or SPI | Mifare, NTAG | $10-20 | Reads NFC cards/tags |
| **MFRC522 NFC** | SPI | Mifare | $5-10 | Low-cost NFC option |

**Additional Hardware for Specific Readers:**

- **OSDP**: USB to RS-485 adapter or RS-485 HAT
- **PN532 I2C**: No additional hardware (uses GPIO 2/3)
- **PN532 SPI**: No additional hardware (uses SPI0)
- **MFRC522**: No additional hardware (uses SPI0)

**Optional:**
- Custom PCB (files included in `pidoorspcb/` folder)
- Weatherproof enclosure for outdoor installations

### Software You'll Download

- Raspberry Pi Imager (free from raspberrypi.com)
- Raspberry Pi OS (downloaded via Imager)

---

## Part 1: Setting Up Your Raspberry Pi

### Step 1.1: Install Raspberry Pi OS

1. **Download Raspberry Pi Imager**
   - Go to https://www.raspberrypi.com/software/
   - Download and install Raspberry Pi Imager for your computer (Windows, Mac, or Linux)

2. **Prepare Your SD Card**
   - Insert your microSD card into your computer
   - Open Raspberry Pi Imager

3. **Choose Your Operating System**
   - Click "Choose OS"
   - Select "Raspberry Pi OS (32-bit)" - the first option with desktop
   - **For Door Controllers**: You can use "Raspberry Pi OS Lite" (no desktop) to save space

4. **Choose Your SD Card**
   - Click "Choose Storage"
   - Select your SD card (be careful to select the correct one!)

5. **Configure Settings** (Important!)
   - Click the gear icon in the bottom right
   - Enable SSH: Check "Enable SSH" and select "Use password authentication"
   - Set username: `pi` and create a strong password
   - Configure WiFi if you're not using Ethernet (optional)
   - Set your timezone and keyboard layout
   - Click "Save"

6. **Write the Image**
   - Click "Write"
   - Wait for the process to complete (5-10 minutes)
   - When done, eject the SD card

### Step 1.2: First Boot

1. **Insert the SD card** into your Raspberry Pi
2. **Connect** monitor, keyboard, mouse, and Ethernet cable
3. **Power on** the Raspberry Pi by plugging in the power supply
4. **Wait** for the desktop to appear (first boot takes 1-2 minutes)
5. **Complete the welcome wizard** if it appears

### Step 1.3: Update Your Raspberry Pi

1. **Open Terminal** (black icon on the top toolbar)

2. **Update the system** by typing these commands (press Enter after each):

```bash
sudo apt-get update
sudo apt-get upgrade -y
```

This will take 10-20 minutes. Wait for it to complete.

3. **Reboot** when done:

```bash
sudo reboot
```

---

## Part 2: Installing the Server

The server hosts the web interface and database. You only need one server for your entire system.

### Step 2.1: Download PiDoors

1. **Open Terminal**

2. **Navigate to home directory**:

```bash
cd ~
```

3. **Download PiDoors** (requires internet connection):

```bash
git clone https://github.com/yourusername/pidoors.git
```

If you get an error that git isn't installed:

```bash
sudo apt-get install git -y
git clone https://github.com/yourusername/pidoors.git
```

4. **Go into the PiDoors folder**:

```bash
cd pidoors
```

### Step 2.2: Run the Automatic Installer

1. **Make the installer executable**:

```bash
chmod +x install.sh
```

2. **Run the installer as administrator**:

```bash
sudo ./install.sh
```

3. **Follow the prompts**:
   - When asked "Select installation type", choose **1** for Server
   - Wait while packages are installed (10-20 minutes)
   - When prompted for MySQL root password, create a strong password and **write it down**
   - When prompted for PiDoors database password, create another strong password and **write it down**
   - When asked to create a default admin user, choose **y** (yes)
   - Enter your email address (this will be your login username)
   - Create a strong password for the admin account and **write it down**

4. **Wait for completion**. You'll see a success message with the web interface URL.

### Step 2.3: Configure the Application

1. **The installer creates the config automatically**, but if you need to modify it:

```bash
sudo nano /var/www/pidoors/includes/config.php
```

2. **Key settings to verify**:
   - Database password matches what you set during installation
   - URL matches your Pi's IP address

3. **Find your Pi's IP address**:

```bash
hostname -I
```

Example output: `192.168.1.100` (your IP will be different)

4. **Save and exit** (if editing):
   - Press `Ctrl + X`
   - Press `Y` to confirm
   - Press `Enter` to save

### Step 2.4: Verify Installation

1. **Check Nginx is running**:

```bash
sudo systemctl status nginx
```

You should see "active (running)" in green.

2. **Check PHP-FPM is running**:

```bash
sudo systemctl status php*-fpm
```

You should see "active (running)" in green.

3. **Access the web interface**:
   - Open a web browser on any computer on your network
   - Navigate to: `http://192.168.1.100` (use your Pi's IP address)
   - You should see the PiDoors login page

4. **Login with**:
   - Email: The email you created during installation
   - Password: The admin password you created

**If you can't access the page**, see [Troubleshooting](#troubleshooting) section.

---

## Part 3: Installing Door Controllers

Install one door controller at each door location.

### Step 3.1: Prepare the Door Pi

1. **Set up Raspberry Pi OS** on a new SD card (follow Part 1 steps)
   - You can use Raspberry Pi OS Lite (no desktop needed)

2. **Boot and connect** to the network

3. **Update the system**:

```bash
sudo apt-get update
sudo apt-get upgrade -y
sudo reboot
```

### Step 3.2: Install PiDoors on Door Controller

1. **Download PiDoors**:

```bash
cd ~
git clone https://github.com/yourusername/pidoors.git
cd pidoors
```

2. **Run the installer**:

```bash
chmod +x install.sh
sudo ./install.sh
```

3. **Select installation type**: Choose **2** for Door Controller

4. **Follow the prompts**:
   - Database server IP: Enter your server Pi's IP (e.g., `192.168.1.100`)
   - Database password: Enter the PiDoors database password
   - Door name: Give this door a name (e.g., "Front Entrance", "Back Door")
   - **Reader type**: Select your card reader type:
     - **1) Wiegand** - Standard GPIO card readers (most common)
     - **2) OSDP** - RS-485 encrypted readers
     - **3) NFC PN532** - PN532 NFC reader via I2C
     - **4) NFC MFRC522** - MFRC522 NFC reader via SPI

### Step 3.3: Enable Required Interfaces

Depending on your reader type, you may need to enable additional interfaces:

**For SPI readers (PN532 SPI, MFRC522):**
```bash
sudo raspi-config
# Navigate to: Interface Options > SPI > Enable
sudo reboot
```

**For I2C readers (PN532 I2C):**
```bash
sudo raspi-config
# Navigate to: Interface Options > I2C > Enable
sudo reboot
```

**For OSDP readers (Serial/UART):**
```bash
sudo raspi-config
# Navigate to: Interface Options > Serial Port
# Login shell over serial: No
# Serial port hardware enabled: Yes
sudo reboot
```

### Step 3.4: Wire Your Reader

See [Part 4: Reader Wiring Guides](#part-4-reader-wiring-guides) for detailed wiring instructions for your specific reader type.

---

## Part 4: Reader Wiring Guides

**IMPORTANT: Power off the Raspberry Pi before wiring!**

```bash
sudo shutdown -h now
```

### Wiegand Readers

Wiegand is the most common card reader interface. Supports 26, 32, 34, 35, 36, 37, and 48-bit formats with automatic detection.

**Wiegand Reader Wiring:**

| Wiegand Reader | Raspberry Pi | Pin # |
|----------------|--------------|-------|
| DATA0 (Green)  | GPIO 24      | 18    |
| DATA1 (White)  | GPIO 23      | 16    |
| GND (Black)    | GND          | 6     |
| 5V+ (Red)      | 5V           | 2     |

**Lock Control (Relay) - All Reader Types:**

| Relay Module   | Raspberry Pi | Pin # |
|----------------|--------------|-------|
| IN (Signal)    | GPIO 18      | 12    |
| VCC            | 5V           | 4     |
| GND            | GND          | 14    |

Connect your electric lock to the relay's NO (Normally Open) and COM terminals with a 12V power supply.

**Configuration file:** `/opt/pidoors/conf/config.json`
```json
{
    "front_door": {
        "reader_type": "wiegand",
        "d0": 24,
        "d1": 23,
        "wiegand_format": "auto",
        "latch_gpio": 18,
        ...
    }
}
```

---

### OSDP RS-485 Readers

OSDP (Open Supervised Device Protocol) provides encrypted communication with commercial-grade readers. Requires a USB-to-RS-485 adapter or RS-485 HAT.

**USB-RS485 Adapter Wiring:**

| RS-485 Adapter | OSDP Reader |
|----------------|-------------|
| A+ (Data+)     | A+ (Data+)  |
| B- (Data-)     | B- (Data-)  |
| GND            | GND         |

**Note:** The reader needs its own 12V power supply. Do not power from the Pi.

**Relay wiring is the same as Wiegand above.**

**Configuration file:** `/opt/pidoors/conf/config.json`
```json
{
    "secure_door": {
        "reader_type": "osdp",
        "serial_port": "/dev/ttyUSB0",
        "baud_rate": 115200,
        "address": 0,
        "latch_gpio": 18,
        ...
    }
}
```

**For encrypted OSDP (Secure Channel)**, add the encryption key:
```json
"encryption_key": "base64_encoded_16_byte_key"
```

---

### NFC PN532 Readers

The PN532 is a versatile NFC reader supporting Mifare Classic, Ultralight, and NTAG cards. Can use I2C or SPI interface.

**PN532 I2C Wiring (Recommended):**

| PN532 Module | Raspberry Pi | Pin # |
|--------------|--------------|-------|
| VCC          | 3.3V         | 1     |
| GND          | GND          | 6     |
| SDA          | GPIO 2 (SDA) | 3     |
| SCL          | GPIO 3 (SCL) | 5     |

**Important:** Set the PN532 DIP switches to I2C mode (usually both switches OFF or check your module's documentation).

**Relay wiring is the same as Wiegand above.**

**Configuration file:** `/opt/pidoors/conf/config.json`
```json
{
    "nfc_door": {
        "reader_type": "nfc_pn532",
        "interface": "i2c",
        "i2c_bus": 1,
        "i2c_address": 36,
        "latch_gpio": 18,
        ...
    }
}
```

**PN532 SPI Wiring (Alternative):**

| PN532 Module | Raspberry Pi | Pin # |
|--------------|--------------|-------|
| VCC          | 3.3V         | 1     |
| GND          | GND          | 6     |
| SCK          | GPIO 11 (SCLK) | 23  |
| MISO         | GPIO 9 (MISO)  | 21  |
| MOSI         | GPIO 10 (MOSI) | 19  |
| SS           | GPIO 8 (CE0)   | 24  |

---

### NFC MFRC522 Readers

The MFRC522 is an inexpensive NFC reader for Mifare cards. Uses SPI interface only.

**MFRC522 SPI Wiring:**

| MFRC522 Module | Raspberry Pi | Pin # |
|----------------|--------------|-------|
| VCC (3.3V)     | 3.3V         | 1     |
| GND            | GND          | 6     |
| RST            | GPIO 25      | 22    |
| SCK            | GPIO 11 (SCLK) | 23  |
| MISO           | GPIO 9 (MISO)  | 21  |
| MOSI           | GPIO 10 (MOSI) | 19  |
| SDA (SS)       | GPIO 8 (CE0)   | 24  |
| IRQ            | Not connected | -    |

**Relay wiring is the same as Wiegand above.**

**Configuration file:** `/opt/pidoors/conf/config.json`
```json
{
    "rfid_door": {
        "reader_type": "nfc_mfrc522",
        "spi_bus": 0,
        "spi_device": 0,
        "reset_pin": 25,
        "latch_gpio": 18,
        ...
    }
}
```

---

### GPIO Pin Reference (All Readers)

```
    3V3  (1) (2)  5V      <- Power for readers
  GPIO2  (3) (4)  5V      <- I2C SDA
  GPIO3  (5) (6)  GND     <- I2C SCL
  GPIO4  (7) (8)  GPIO14  <- UART TX (OSDP)
    GND  (9) (10) GPIO15  <- UART RX (OSDP)
 GPIO17 (11) (12) GPIO18  <- Lock relay
 GPIO27 (13) (14) GND     <- Door sensor (optional)
 GPIO22 (15) (16) GPIO23  <- Wiegand D1
    3V3 (17) (18) GPIO24  <- Wiegand D0
 GPIO10 (19) (20) GND     <- SPI MOSI
  GPIO9 (21) (22) GPIO25  <- MFRC522 Reset
 GPIO11 (23) (24) GPIO8   <- SPI SCLK / SPI CE0
    GND (25) (26) GPIO7
```

---

### Step 3.5: Start the Door Controller

1. **Power on the Raspberry Pi**

2. **Check if the service is running**:

```bash
sudo systemctl status pidoors
```

You should see "active (running)" in green.

3. **View real-time logs**:

```bash
sudo journalctl -u pidoors -f
```

Press `Ctrl + C` to stop viewing logs.

4. **Test by scanning a card** - you should see the card number appear in the logs

---

## Part 5: First Login and Configuration

### Step 5.1: Change Default Password

1. **Log into the web interface** (http://your-server-ip)
2. **Click your email** in the top right corner
3. **Click "Profile"**
4. **Change your password** to something secure
5. **Click "Update Profile"**

### Step 5.2: Configure System Settings

1. **Go to Settings** (in the sidebar, under Admin Tools)

2. **Configure basic settings**:
   - System Name: Give your system a name
   - Timezone: Set your timezone
   - Session Timeout: 3600 seconds (1 hour) is recommended

3. **Configure Email Notifications** (optional but recommended):
   - SMTP Host: Your email server (e.g., smtp.gmail.com)
   - SMTP Port: Usually 587
   - SMTP Username: Your email address
   - SMTP Password: Your email password or app-specific password
   - From Email: Your email address
   - Notification Recipients: Email addresses to receive alerts (comma-separated)

4. **Click "Save Settings"**

### Step 5.3: Configure Your Doors

1. **Go to "Doors"** in the sidebar
2. **Click "Add Door"**
3. **Fill in the form**:
   - Name: The name you gave this door (e.g., "Front Entrance")
   - Location: Description or building location
   - IP Address: The IP address of the door controller Pi
   - Unlock Duration: How long to unlock (5 seconds is typical)
   - Schedule: Leave blank for 24/7 access (you can create schedules later)

4. **Click "Add Door"**

5. **Verify the door status**:
   - Go back to "Doors"
   - You should see your door listed
   - Status should turn green ("Online") within a minute

---

## Part 6: Adding Your First Door and Card

### Step 6.1: Scan a Card to Get Its ID

1. **At the door controller**, scan a card on the reader

2. **View the logs** to get the card number:

```bash
sudo journalctl -u pidoors -n 20
```

3. **Look for a line like**:
```
Card read: 12345678
Access denied: Card not in database
```

4. **Write down the card number** (e.g., 12345678)

### Step 6.2: Add the Card to the System

1. **In the web interface**, go to **"Cards"**
2. **Click "Add Card"**
3. **Fill in the form**:
   - Card ID: The number you wrote down (e.g., 12345678)
   - User ID: A unique identifier (e.g., EMP001, JOHN001)
   - First Name: Card holder's first name
   - Last Name: Card holder's last name
   - Active: Check this box
   - Access Group: Leave blank for now (full access)
   - Schedule: Leave blank for 24/7 access
   - Valid From: Leave blank or set start date
   - Valid Until: Leave blank for no expiration
   - PIN Code: Optional 4-6 digit code

4. **Click "Add Card"**

### Step 6.3: Test the Card

1. **Go to the door** and scan the card again
2. **The door should unlock!**
3. **Verify in the web interface**:
   - Go to "Access Logs"
   - You should see a new entry with "Granted" status in green

---

## Part 7: Advanced Features

### Creating Access Schedules

1. **Go to "Schedules"**
2. **Click "Add Schedule"**
3. **Give it a name** (e.g., "Business Hours")
4. **Set time windows** for each day:
   - Monday-Friday: 8:00 AM - 5:00 PM
   - Saturday-Sunday: Closed (leave blank)

5. **Assign to cards**:
   - Edit a card
   - Select the schedule
   - Save

Now that card only works during business hours!

### Creating Access Groups

1. **Go to "Access Groups"**
2. **Click "Add Group"**
3. **Give it a name** (e.g., "Employees", "Management")
4. **Select which doors** this group can access
5. **Assign cards to the group**:
   - Edit a card
   - Select the group
   - Save

### Setting Up Holidays

1. **Go to "Holidays"**
2. **Click "Add Holiday"**
3. **Enter**:
   - Name: Holiday name
   - Date: The date
   - Access Type: "Deny All" or "Allow All"
4. **Save**

Cards will respect holiday settings automatically!

### Importing Multiple Cards from CSV

1. **Create a CSV file** with this format:

```csv
card_id,user_id,firstname,lastname
12345678,EMP001,John,Smith
23456789,EMP002,Jane,Doe
34567890,EMP003,Bob,Wilson
```

2. **Go to "Import Cards"**
3. **Upload your CSV file**
4. **Click "Import Cards"**

All cards will be added at once!

---

## Troubleshooting

### Problem: Can't access the web interface

**Solution 1: Check if Nginx is running**
```bash
sudo systemctl status nginx
```

If it's not running:
```bash
sudo systemctl start nginx
```

**Solution 2: Check if PHP-FPM is running**
```bash
sudo systemctl status php*-fpm
```

If it's not running:
```bash
sudo systemctl start php*-fpm
```

**Solution 3: Verify the IP address**
```bash
hostname -I
```

Use the first IP address shown.

**Solution 4: Check firewall**
```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
```

**Solution 5: Check Nginx error log**
```bash
sudo tail -50 /var/log/nginx/pidoors_error.log
```

### Problem: Door controller shows "offline" in web interface

**Solution 1: Check if service is running**
```bash
sudo systemctl status pidoors
```

If not running:
```bash
sudo systemctl start pidoors
```

**Solution 2: Check database connection**
```bash
sudo journalctl -u pidoors -n 50
```

Look for connection errors.

**Solution 3: Verify network connectivity**
```bash
ping -c 4 192.168.1.100
```
(Replace with your server IP)

**Solution 4: Check configuration file**
```bash
cat /opt/pidoors/conf/config.json
```

Verify the server IP and database password are correct.

### Problem: Card not working

**Solution 1: Check if card is in the database**
- Go to "Cards" in web interface
- Search for the card ID

**Solution 2: Check if card is active**
- Edit the card
- Make sure "Active" is checked

**Solution 3: Check access logs**
- Go to "Access Logs"
- Look for the card attempt
- Check the denial reason

**Solution 4: Verify Wiegand wiring**
```bash
sudo journalctl -u pidoors -f
```
Scan a card - you should see the card number appear.

### Problem: NFC reader (PN532/MFRC522) not detecting cards

**Solution 1: Verify SPI/I2C is enabled**
```bash
# For SPI readers:
ls /dev/spidev*
# Should show /dev/spidev0.0

# For I2C readers:
ls /dev/i2c*
# Should show /dev/i2c-1
```

If not present, enable via raspi-config:
```bash
sudo raspi-config
# Interface Options > SPI or I2C > Enable
sudo reboot
```

**Solution 2: Check I2C device detection (PN532 I2C)**
```bash
sudo apt install -y i2c-tools
sudo i2cdetect -y 1
```
You should see address `24` (0x24) in the grid.

**Solution 3: Check wiring connections**
- Verify 3.3V (not 5V!) for NFC modules
- Check all SPI/I2C connections are secure
- For MFRC522, verify RST pin is connected to GPIO 25

**Solution 4: Check PN532 DIP switches**
- For I2C mode: Usually both switches OFF
- For SPI mode: Usually SW1=ON, SW2=OFF
- Consult your module's documentation

**Solution 5: Test SPI communication**
```bash
# Check if SPI driver is loaded
lsmod | grep spi
```

### Problem: OSDP reader not responding

**Solution 1: Verify serial port**
```bash
# For USB adapter:
ls /dev/ttyUSB*
# Should show /dev/ttyUSB0

# For GPIO UART:
ls /dev/serial0
```

**Solution 2: Check RS-485 wiring**
- Verify A+ to A+ and B- to B- connections
- Check for proper termination (120 ohm resistor may be needed)
- Ensure GND is connected

**Solution 3: Verify UART is enabled (for GPIO serial)**
```bash
sudo raspi-config
# Interface Options > Serial Port
# Login shell: No
# Serial hardware: Yes
```

**Solution 4: Check baud rate**
- Most OSDP readers use 9600 or 115200 baud
- Verify setting matches your reader

**Solution 5: Test serial communication**
```bash
# Install screen
sudo apt install screen

# Connect (press Ctrl+A then K to exit)
sudo screen /dev/ttyUSB0 9600
```

**Solution 6: Check OSDP address**
- Default address is usually 0
- Some readers use address 1 or allow configuration

### Problem: Door won't unlock even with valid card

**Solution 1: Check relay wiring**
- Verify GPIO 17 connection to relay
- Test relay manually:
```bash
# Test using Python
python3 -c "import RPi.GPIO as GPIO; GPIO.setmode(GPIO.BCM); GPIO.setup(17, GPIO.OUT); GPIO.output(17, GPIO.HIGH); import time; time.sleep(2); GPIO.output(17, GPIO.LOW); GPIO.cleanup()"
```

**Solution 2: Check lock power**
- Ensure 12V power supply is connected
- Check lock current draw (should be < 1A)

**Solution 3: Check unlock duration**
- Go to "Doors" > Edit your door
- Increase "Unlock Duration" to 10 seconds
- Test again

### Problem: Forgot admin password

**Solution: Reset password via database**
```bash
# Generate new password hash in PHP
php -r "echo password_hash('newpassword', PASSWORD_BCRYPT);"

# Copy the output, then update database:
mysql -u pidoors -p
```

In MySQL:
```sql
USE users;
UPDATE users SET user_pass = 'paste_hash_here' WHERE user_email = 'your@email.com';
EXIT;
```

### Problem: Database connection errors

**Solution: Verify database credentials**

1. Test connection:
```bash
mysql -u pidoors -p
```

2. If it fails, reset the password:
```bash
sudo mysql -u root -p
```

In MySQL:
```sql
ALTER USER 'pidoors'@'localhost' IDENTIFIED BY 'new_password';
FLUSH PRIVILEGES;
EXIT;
```

3. Update config.php with new password:
```bash
sudo nano /var/www/pidoors/includes/config.php
```

### Getting More Help

1. **Check the logs**:
   - Door controller: `sudo journalctl -u pidoors -f`
   - Nginx errors: `sudo tail -f /var/log/nginx/pidoors_error.log`
   - PHP errors: `sudo tail -f /var/log/nginx/pidoors_error.log`
   - MySQL errors: `sudo tail -f /var/log/mysql/error.log`

2. **Check the audit log** in the web interface (Admin Tools > Audit Log)

3. **Restart services**:
   ```bash
   sudo systemctl restart nginx
   sudo systemctl restart php*-fpm
   sudo systemctl restart pidoors
   sudo systemctl restart mysql
   ```

4. **Test Nginx configuration**:
   ```bash
   sudo nginx -t
   ```

---

## Maintenance

### Daily Backups (Automatic)

Backups run automatically (if configured) to `/var/backups/pidoors/`

### Manual Backup

```bash
sudo /usr/local/bin/pidoors-backup.sh
```

### Restore from Backup

```bash
mysql -u pidoors -p users < /var/backups/pidoors/users_YYYYMMDD_HHMMSS.sql
mysql -u pidoors -p access < /var/backups/pidoors/access_YYYYMMDD_HHMMSS.sql
```

### Update PiDoors

```bash
cd ~/pidoors
git pull
sudo cp -r pidoorserv/* /var/www/pidoors/
sudo chown -R www-data:www-data /var/www/pidoors
sudo systemctl restart nginx
sudo systemctl restart php*-fpm
```

On door controllers:
```bash
cd ~/pidoors
git pull
sudo cp pidoors/pidoors.py /opt/pidoors/
sudo systemctl restart pidoors
```

### Enable HTTPS (Recommended for Production)

1. **Generate a self-signed certificate** (or use Let's Encrypt):
```bash
sudo openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
    -keyout /etc/ssl/private/pidoors.key \
    -out /etc/ssl/certs/pidoors.crt
```

2. **Edit the Nginx configuration**:
```bash
sudo nano /etc/nginx/sites-available/pidoors
```

3. **Uncomment the HTTPS server block** at the bottom of the file

4. **Test and reload Nginx**:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

---

## Next Steps

Now that your system is running:

1. Add all your doors
2. Import or add all your cards
3. Create access schedules if needed
4. Create access groups for different user types
5. Set up email notifications
6. Test the backup system
7. Enable HTTPS for production
8. Monitor the system via the dashboard

**Your PiDoors Access Control System is now operational!**
