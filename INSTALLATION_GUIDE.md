# PiDoors - Complete Installation Guide for Beginners

This guide will walk you through setting up the PiDoors Access Control System step-by-step, even if you've never used a Raspberry Pi before.

---

## Table of Contents

1. [What You'll Need](#what-youll-need)
2. [Part 1: Setting Up Your Raspberry Pi](#part-1-setting-up-your-raspberry-pi)
3. [Part 2: Installing the Server](#part-2-installing-the-server)
4. [Part 3: Installing Door Controllers](#part-3-installing-door-controllers)
5. [Part 4: First Login and Configuration](#part-4-first-login-and-configuration)
6. [Part 5: Adding Your First Door and Card](#part-5-adding-your-first-door-and-card)
7. [Troubleshooting](#troubleshooting)

---

## What You'll Need

### For the Server (Required - One Per System)

**Hardware:**
- 1× Raspberry Pi 3B+ or newer (4GB RAM recommended)
- 1× MicroSD card (16GB minimum, 32GB recommended)
- 1× Power supply for Raspberry Pi (official recommended)
- 1× Ethernet cable (or WiFi setup)
- 1× Monitor, keyboard, mouse (for initial setup only)

**Optional:**
- Case for Raspberry Pi
- Cooling fan

### For Each Door Controller (One Per Door)

**Hardware:**
- 1× Raspberry Pi Zero W or newer per door
- 1× MicroSD card (8GB minimum)
- 1× Wiegand card reader (26-bit, 34-bit, or 37-bit compatible)
- 1× 12V electric strike or magnetic lock
- 1× Relay module (to control the lock)
- 1× Door sensor (magnetic switch - optional but recommended)
- 1× Exit button / REX button (optional)
- Wiring supplies (jumper wires, terminal blocks)

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
   - Click the gear icon ⚙️ in the bottom right
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
git clone https://github.com/sybethiesant/pidoors.git
```

If you get an error that git isn't installed:

```bash
sudo apt-get install git -y
git clone https://github.com/sybethiesant/pidoors.git
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

1. **Copy the example configuration**:

```bash
cd ~/pidoors
cp pidoorserv/includes/config.php.example pidoorserv/includes/config.php
```

2. **Edit the configuration file**:

```bash
sudo nano pidoorserv/includes/config.php
```

3. **Update the following values**:
   - Find the line with `'sqlpass' => ''`
   - Replace with `'sqlpass' => 'your_database_password'` (the one you created earlier)
   - Find `'url' => 'http://localhost'`
   - Replace `localhost` with your Pi's IP address

4. **Find your Pi's IP address** (open a new terminal):

```bash
hostname -I
```

Example output: `192.168.1.100` (your IP will be different)

5. **Update the URL in config.php**:
   - Change to: `'url' => 'http://192.168.1.100'`

6. **Save and exit**:
   - Press `Ctrl + X`
   - Press `Y` to confirm
   - Press `Enter` to save

7. **Copy the config to the web directory**:

```bash
sudo cp ~/pidoors/pidoorserv/includes/config.php /var/www/pidoors/includes/config.php
```

### Step 2.4: Import the Database Schema

1. **Import the database structure**:

```bash
mysql -u pidoors -p access < ~/pidoors/database_migration.sql
```

2. **Enter the PiDoors database password** when prompted

3. **Verify it worked** (should show tables):

```bash
mysql -u pidoors -p -e "USE access; SHOW TABLES;"
```

You should see a list of tables like `cards`, `doors`, `logs`, etc.

### Step 2.5: Set Permissions

```bash
sudo chown -R www-data:www-data /var/www/pidoors
sudo chmod -R 755 /var/www/pidoors
```

### Step 2.6: Access the Web Interface

1. **Open a web browser** on any computer on your network

2. **Navigate to**: `http://192.168.1.100` (use your Pi's IP address)

3. **You should see the PiDoors login page!**

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
git clone https://github.com/sybethiesant/pidoors.git
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

### Step 3.3: Wire the Wiegand Reader

**IMPORTANT: Power off the Raspberry Pi before wiring!**

```bash
sudo shutdown -h now
```

**Standard Wiegand Wiring:**

| Wiegand Reader | Raspberry Pi GPIO |
|----------------|-------------------|
| DATA0 (Green)  | GPIO 23           |
| DATA1 (White)  | GPIO 24           |
| GND (Black)    | GND (Pin 6)       |
| 5V+ (Red)      | 5V (Pin 2)        |

**Lock Control:**

| Component      | Raspberry Pi GPIO |
|----------------|-------------------|
| Relay IN       | GPIO 17           |
| Relay VCC      | 5V (Pin 4)        |
| Relay GND      | GND (Pin 14)      |

**Optional - Door Sensor:**

| Component      | Raspberry Pi GPIO |
|----------------|-------------------|
| Sensor Signal  | GPIO 27           |
| Sensor GND     | GND               |

**Optional - Exit Button (REX):**

| Component      | Raspberry Pi GPIO |
|----------------|-------------------|
| Button Signal  | GPIO 22           |
| Button GND     | GND               |

**GPIO Pin Reference:**
```
    3V3  (1) (2)  5V
  GPIO2  (3) (4)  5V
  GPIO3  (5) (6)  GND
  GPIO4  (7) (8)  GPIO14
    GND  (9) (10) GPIO15
 GPIO17 (11) (12) GPIO18
 GPIO27 (13) (14) GND
 GPIO22 (15) (16) GPIO23
    3V3 (17) (18) GPIO24
 GPIO10 (19) (20) GND
```

### Step 3.4: Start the Door Controller

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

## Part 4: First Login and Configuration

### Step 4.1: Change Default Password

1. **Log into the web interface** (http://your-server-ip)

2. **Click your email** in the top right corner

3. **Click "Profile"**

4. **Change your password** to something secure

5. **Click "Update Profile"**

### Step 4.2: Configure System Settings

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

### Step 4.3: Configure Your Doors

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

## Part 5: Adding Your First Door and Card

### Step 5.1: Scan a Card to Get Its ID

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

### Step 5.2: Add the Card to the System

1. **In the web interface**, go to **"Cards"**

2. **Click "Add Card"**

3. **Fill in the form**:
   - Card ID: The number you wrote down (e.g., 12345678)
   - User ID: A unique identifier (e.g., EMP001, JOHN001)
   - First Name: Card holder's first name
   - Last Name: Card holder's last name
   - Active: Check this box (✓)
   - Access Group: Leave blank for now (full access)
   - Schedule: Leave blank for 24/7 access
   - Valid From: Leave blank or set start date
   - Valid Until: Leave blank for no expiration
   - PIN Code: Optional 4-6 digit code

4. **Click "Add Card"**

### Step 5.3: Test the Card

1. **Go to the door** and scan the card again

2. **The door should unlock!**

3. **Verify in the web interface**:
   - Go to "Access Logs"
   - You should see a new entry with "Granted" status in green

---

## Part 6: Advanced Features (Optional)

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

**Solution 1: Check if Apache is running**
```bash
sudo systemctl status apache2
```

If it's not running:
```bash
sudo systemctl start apache2
```

**Solution 2: Verify the IP address**
```bash
hostname -I
```

Use the first IP address shown.

**Solution 3: Check firewall**
```bash
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
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

### Problem: Door won't unlock even with valid card

**Solution 1: Check relay wiring**
- Verify GPIO 17 connection to relay
- Test relay manually:
```bash
# Turn on (unlock)
gpio -g mode 17 out
gpio -g write 17 1

# Wait 5 seconds, then turn off (lock)
gpio -g write 17 0
```

**Solution 2: Check lock power**
- Ensure 12V power supply is connected
- Check lock current draw (should be < 1A)

**Solution 3: Check unlock duration**
- Go to "Doors" → Edit your door
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

3. Update config.php with new password

### Getting More Help

1. **Check the logs**:
   - Door controller: `sudo journalctl -u pidoors -f`
   - Apache errors: `sudo tail -f /var/log/apache2/pidoors_error.log`
   - MySQL errors: `sudo tail -f /var/log/mysql/error.log`

2. **Check the audit log** in the web interface (Admin Tools → Audit Log)

3. **Restart services**:
   ```bash
   sudo systemctl restart apache2
   sudo systemctl restart pidoors
   sudo systemctl restart mysql
   ```

4. **Check GitHub Issues**: https://github.com/sybethiesant/pidoors/issues

---

## Maintenance

### Daily Backups (Automatic)

Backups run automatically at 2 AM daily to `/var/backups/pidoors/`

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
sudo systemctl restart pidoors
sudo systemctl restart apache2
```

---

## Next Steps

Now that your system is running:

1. ✅ Add all your doors
2. ✅ Import or add all your cards
3. ✅ Create access schedules if needed
4. ✅ Create access groups for different user types
5. ✅ Set up email notifications
6. ✅ Test the backup system
7. ✅ Monitor the system via the dashboard

**Congratulations! Your PiDoors Access Control System is now operational!** 🎉
