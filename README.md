# Team Hifsa CCNA Tracker

A polished CCNA lab tracker built for Packet Tracer practice, spaced review, personal notes, and student progress tracking.

This project turns a large CCNA lab collection into a guided study dashboard with scheduling, progress tracking, filters, note-taking, image attachments, and a live multi-user mode for classrooms or training groups.

## What This Project Does

The tracker helps you manage the included CCNA labs in a much more practical way than opening `.pkt` files one by one from a folder.

It gives you:

- A clean dashboard for all included labs
- Per-lab progress states like `Pending`, `Review`, and `Completed`
- Review scheduling with due and overdue tracking
- A random planner that spreads unscheduled work across upcoming days
- Per-lab rich notes
- Image attachments inside notes
- A `Screen Recorded` checkbox on every task
- Filters including `Recorded` and `Not Recorded`
- Multi-user private accounts on a live server so students do not overwrite each other

## Main Features

### 1. Lab Progress Tracking

Each lab can be tracked individually with:

- Completion state
- Review state
- Review interval
- Next review date
- Last score
- Screen recording status

### 2. Smart Review Workflow

The tracker is built around repeated practice, not just one-time completion.

You can:

- Mark labs completed
- Put labs into review mode
- Rate a review from `1` to `10`
- Automatically set the next review date based on the selected score
- See which labs are due now or overdue

### 3. Random Planner

The `Random Planner` can spread eligible labs across the next number of days you choose.

It:

- Keeps future schedules already set
- Only fills labs that are not scheduled yet or already due
- Distributes labs across the selected period
- Assigns random time slots within each day

### 4. Rich Notes and Attachments

Every lab includes its own note area.

You can store:

- Commands
- Troubleshooting steps
- Theory reminders
- Review notes
- Pasted or uploaded images

### 5. Multi-User Live Mode

When hosted on a PHP server, the tracker supports separate student accounts.

That means:

- Every student has a private login
- Each account keeps its own progress
- Notes stay isolated per user
- Review dates do not mix between users
- Screen-recorded flags stay private to that account

### 6. Local Folder Mode

When opened directly as a local HTML file, the tracker can still work without a server.

In this mode, it saves progress into the project JSON file after you click `Link Project Folder`.

## Included Lab Set

The project includes `76` CCNA labs covering topics such as:

- Router and switch basics
- VLANs and inter-VLAN routing
- SSH and Telnet
- Static routing
- RIP, OSPF, EIGRP, and BGP
- ACLs
- NAT and PAT
- STP, EtherChannel, HSRP
- IPv6
- GRE
- PPP and PPPoE
- Troubleshooting and review labs

## Project Structure

```text
.
|-- api/
|   |-- auth.php
|   |-- bootstrap.php
|   |-- config.php
|   |-- config.local.example.php
|   |-- lib.php
|   |-- save.php
|   `-- upload.php
|-- attachments/
|-- scripts/
|   `-- import-json-to-db.php
|-- storage/
|   `-- ccna-tracker.sqlite
|-- ccna-progress-vault.html
|-- ccna-progress-data.json
|-- index.html
|-- packettracer-protocol-launcher.ps1
|-- enable-ccna-pkt-protocol.reg
|-- disable-ccna-pkt-protocol.reg
`-- *.pkt / *.zip lab files
```

## How It Works

### Local Desktop Mode

Use this when you want a single-user tracker on your own machine.

1. Open `ccna-progress-vault.html`
2. Click `Link Project Folder`
3. Grant folder access
4. The tracker saves into `ccna-progress-data.json`

This mode is useful if you want everything stored locally with no server dependency.

### Live Server Mode

Use this when multiple students need separate accounts.

1. Upload the repository to a PHP-enabled hosting server
2. Make sure `storage/` is writable
3. Make sure `attachments/` is writable
4. Open the site in a browser
5. Create an account from the `Private Student Accounts` box
6. Each student signs in with their own username and password

In live mode, the tracker uses the backend API inside `api/`.

## Account Creation and Login

On the live site:

1. Open the tracker page
2. In `Private Student Accounts`, enter:
   - Display name
   - Username
   - Password
3. Click `Create Account`

To log in later:

1. Enter the same username
2. Enter the same password
3. Click `Sign In`

After login, that user's own tracker data is loaded automatically.

## Database Setup

### Default Setup

By default, the project uses `SQLite`.

Database file:

```text
storage/ccna-tracker.sqlite
```

This is the easiest setup for most PHP hosts.

### Optional MySQL Setup

If you want to use MySQL instead of SQLite, create:

```text
api/config.local.php
```

Example:

```php
<?php
return [
    'db_driver' => 'mysql',
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'track_ccna',
    'db_user' => 'your_database_user',
    'db_pass' => 'your_database_password',
    'db_charset' => 'utf8mb4',
];
```

## Important Hosting Notes

- `GitHub Pages` cannot run the PHP backend
- Multi-user mode needs a real PHP-capable server
- `storage/` must be writable for the database
- `attachments/` must be writable for note image uploads
- Packet Tracer files open normally from the hosted site

## Packet Tracer Launcher Helpers

The repository also includes helper files for local desktop use:

- `packettracer-protocol-launcher.ps1`
- `enable-ccna-pkt-protocol.reg`
- `disable-ccna-pkt-protocol.reg`

These help local Windows setups launch `.pkt` files through the custom `ccna-pkt:` helper when the tracker is opened from local files.

## Why This Repo Is Useful

This project is especially helpful if you are:

- Studying CCNA with repetition instead of one-time viewing
- Managing a large Packet Tracer lab library
- Teaching students and needing isolated progress per user
- Building your own review rhythm with notes and ratings
- Tracking which labs were screen recorded

## Tech Stack

- HTML
- CSS
- Vanilla JavaScript
- PHP
- SQLite by default
- Optional MySQL

## Repository Entry Point

The repo root redirects through:

```text
index.html
```

Which sends users to:

```text
ccna-progress-vault.html
```

## Final Note

This tracker was designed to make CCNA practice feel organized, visual, and repeatable instead of messy and folder-based. If you are working through Packet Tracer labs seriously, this gives you a much stronger system for staying consistent.
