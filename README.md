# Fleet and Transportation Management System

## Project Description

The Fleet and Transportation Management System is a web-based application developed for Priority Handling Logistics Inc. It centralizes fleet operations, vehicle management, driver monitoring, reservations, dispatching, fuel management, and transportation cost analysis.

## Technologies Used

- PHP
- PostgreSQL
- Tailwind CSS
- JavaScript
- HTML5
- pgAdmin 4
- XAMPP

---

## System Requirements

- PHP 8.x
- PostgreSQL 15 or later
- pgAdmin 4
- XAMPP
- Node.js (for Tailwind CSS)

---

## Installation

### 1. Copy the project

Copy the `FTMS-fixed` folder to:

```
C:\xampp\htdocs\
```

### 2. Create the database

Open PostgreSQL and create a database named:

```
ftms_db
```

### 3. Import the database

Open pgAdmin.

Open **Query Tool**.

Run:

```
database/FTMS.sql
```

### 4. Configure the database connection

Edit:

```
config/ftms_db.php
```

Update the following values if necessary:

```php
$host = "localhost";
$port = "5432";
$dbname = "ftms_db";
$user = "postgres";
$password = "your_password";
```

### 5. Install Tailwind dependencies

Open Terminal.

```
npm install
```

### 6. Build Tailwind CSS

```
npx @tailwindcss/cli -i ./src/input.css -o ./src/output.css --watch
```

### 7. Start Apache

Open XAMPP Control Panel.

Start **Apache**.

### 8. Open the system

```
http://localhost/FTMS-fixed
```

---

## System Modules

- Authentication
- Dashboard
- Fleet & Vehicle Management
- Reservation & Dispatch
- Driver & Trip Performance Monitoring
- Fuel Management
- Route Planning & Optimization
- Transport Cost Analysis
- Mobile Fleet Command

---

## Developed By

BS Information Technology

Capstone Project

Bestlink College of the Philippines# LOG2
FMTS
