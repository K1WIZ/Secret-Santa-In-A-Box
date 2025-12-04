# 🎄 Secret Santa E-mail Bot (Docker Version)

Welcome to the **Secret Santa E-mail Bot**, the festive automation gremlin that handles all the chaos of drawing names without the need for paper scraps, family arguments, or “who did I get last year again?” debates.

This bot:

- Draws randomized Secret Santa pairings  
- Prevents same-household assignments  
- Now handles Wish Lists!
- Avoids repeating last year’s pairings  
- Sends beautiful Christmas-themed HTML emails  
- Sends the master list privately to the admin  
- Runs automatically every Thanksgiving Day at noon  
- Can also be triggered manually with `-force`
- Now includes usage analytics! (track opens!)  
- Now is packaged to run as a Docker container

In other words:

> **It’s the holiday elf you always wanted — one that doesn’t eat your cookies or unionize.**

---

## 🎁 Features

- 🐳 Now runs as a docker container
- 🎅 Automatic Thanksgiving Day draw  
- 🌐 Manual run via CLI `-force`  
- 💌 Sends individual HTML emails to participants  
- 👾 Now provides email tracking for "opens"
- 🎄 Colorful Christmas-themed template  
- 🔒 Avoids same-family matchups  
- 🔁 Avoids pairing repeats from last year
- ✨ Secret Santas can view their person's Wish List, and set their own
- 📬 Sends admin a complete pairing list  
- 🧪 Admin dashboard:  
  - Test pairings  
  - Commit pairings to DB  
  - Test SMTP email  
- 🗄 Uses MySQL for persistent year-over-year tracking  
- 📮 Uses PHPMailer + Gmail SMTP  

---

## 🛠 Installation & Setup (Docker)

### 1. Clone the project files from github
```
mkdir /opt/ss
cd /opt/ss
git clone https://github.com/K1WIZ/Secret-Santa-In-A-Box.git
```
### 2. Modify docker-compose.yml and set environment variables to suit your environment
```
Comments in the docker-compose.yml file.  But basically, you want to set these env variables:

      APP_BASE_URL: "http://localhost:8080"
      DB_HOST: "db"
      DB_PORT: "3306"
      DB_NAME: "secret_santa"
      DB_USER: "secretsanta"
      DB_PASSWORD: "changeme"
      # Timezone
      APP_TZ: "America/New_York"
      TZ: "America/New_York"
      # For dev: send mail to MailHog instead of real SMTP
      SMTP_HOST: "mailhog"
      SMTP_PORT: "1025"
      SMTP_USER: ""
      SMTP_PASS: ""  # ⚠️ Use a Gmail App Password, not your actual Gmail password! (if using gmail smtp)
      SMTP_FROM_EMAIL: "santa@example.test"
      SMTP_FROM_NAME: "Secret Santa Bot"
      TZ: "America/New_York"
      MYSQL_ROOT_PASSWORD: "changeme"
      MYSQL_DATABASE: "secret_santa"
      MYSQL_USER: "secretsanta"
      MYSQL_PASSWORD: "changeme"

At a minimum!
```
### 3. run commands to build and run the app using docker-compose:
```
docker-compose up -d --build
```
(Add your recipients to the table!)

### 4. Inject your "people" into the participants database (IMPORTANT: make sure email addresses are accurate!)
```
INSERT INTO participants (first_name, last_name, email, family_unit, wish_key)
VALUES ('Test', 'User', 'test@example.com', 1, '');
```
### 5. Add a cron job to automatically generate and email secret santa recipients
```
0 12 * 11 *   root  /usr/bin/docker exec -it secretsanta_app php /var/www/html/secret_santa.php
```
(NOTE: the script will check if it's Thanksgiving Day when run, and if it is, will generate and email pairings)

### 6. Testing
If you wish to test the script, you can do so by calling the script and using the `-force` flag like so:
```
docker exec -it secretsanta_app php /var/www/html/secret_santa.php -force
```

You can also use the Admin page to test pairings without emailing or writing to the DB, using your favorite web browser:
```
https://yourdomain.com:8080/admin_secret_santa.php?key=changeme

NOTE: you can change the admin secret key in the admin_secret_santa.php file.  Look
for the line:
$ADMIN_KEY = 'changeme';

and set it to what you desire.  For dev purposes, this doesn't need changing.  In
production environments PLEASE CHANGE THIS!
```
Tools available:

- Generate pairings
- Save pairings to the database
- Test email functionality
- Confirm “avoid last year” logic

### ☕ Buy Me a Coffee
If this project saved your holiday sanity or prevented at least one family meltdown…

You can buy me a coffee on Venmo:

👉 @wizworks

Cheers, and happy gifting! 🎄😄
