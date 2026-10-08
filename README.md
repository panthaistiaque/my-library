# My Library

A lightweight, personal book-management app — rebranded as **My Library** (LuCreative).
Single-file PHP app with a JSON database: **no MySQL, no phpMyAdmin, no Composer** required.

---

## Features

- **Dashboard** — KPI cards (Books, Tags, Shelves, On Loan), overdue alert, recently added books, active loans at a glance
- **Books** — full CRUD with search, status filter, tag filter (with book counts), cover thumbnails and empty states
- **Tags** — create / rename / delete tags, shows how many books use each tag
- **Shelves** — organize books by shelf and location; deleting a shelf safely unassigns its books
- **Lending** — lend a book, track borrower / loan / due dates, mark as returned, overdue rows highlighted in red
- **Automatic status sync** — a book's status is derived from its loan records on every page load, so it can never drift (borrowed books always show *Lent*)
- **Smart inputs** — Author, Language, Publisher, Year and Edition suggest previous entries (dropdown) while still accepting new values
- **Tags A–Z** — tag checkboxes and tag filters are sorted alphabetically
- **Reorganized book form** — Main information → Publication details → Purchase & cover → Organization
- **Feedback toasts** — success message after every save/delete (auto-dismiss)
- **Responsive UI** — Bootstrap 5, dark sidebar, mobile offcanvas menu, Plus Jakarta Sans font

## Requirements

- PHP 8.0+ (tested on PHP 8.2 / XAMPP)
- Any modern browser
- No database server needed

## Installation (XAMPP)

1. Copy this folder to `E:\xampp\htdocs\personal_library`
2. Start **Apache** in the XAMPP control panel
3. Open <http://localhost/personal_library/>
4. Done — no configuration, no database import

## File structure

```
personal_library/
├── index.php            # the whole app (UI + logic)
├── data/
│   └── library.json     # the database (books, tags, shelves, loans)
├── README.md
└── README.txt
```

## Data & backup

- All data lives in `data/library.json` (UTF-8, pretty-printed — safe to edit by hand).
- To back up: copy the whole folder, or just `data/library.json`.
- The file is created automatically on first run if it is missing.

## Sections

| Section  | What it does                                              |
| -------- | --------------------------------------------------------- |
| Dashboard| Overview: stats, overdue loans, recent books, active loans |
| Books    | Search / filter / add / edit / delete books                |
| Tags     | Manage tags used to categorize books                       |
| Shelves  | Manage physical shelves and locations                      |
| Lending  | Lend books, track due dates, mark returns                  |

## Tech stack

- Vanilla PHP (no framework, no dependencies)
- JSON file storage with `LOCK_EX` writes
- Bootstrap 5.3 + Bootstrap Icons (CDN)
- Plus Jakarta Sans (Google Fonts)

## License

Free to use and modify for personal projects.
