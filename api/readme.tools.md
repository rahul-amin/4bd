# 4BDOR Tools Module Documentation

This document describes the PHP tools in the `/tools/` directory. These tools provide advanced search, browsing, and data extraction features for the Bangladesh Digital Land Records Management System (4BDOR). They are designed for use in web UIs built with PHP, MySQL, Tailwind CSS, and Alpine.js.

---

## Tool List & Purpose

### 1. `src-khatian-no.php`
- Fetches a single khatian (land record) by its ID and survey type (e.g., RS, CS, SA, etc.).
- Validates parameters and survey type.
- Returns all khatian fields and optionally context (district, upazila, mouza names).
- Used for detail views or direct khatian lookup.

### 2. `search-namjari.php`
- UI and logic for searching khatians for land mutation (namjari) by owner/guardian name and location.
- Uses Tailwind CSS and Alpine.js for a responsive, interactive UI.
- Step-by-step selection: division → district → upazila → mouza → khatian.
- Returns paginated results with mobile-friendly tables.

### 3. `search-map-dags.php`
- Searches khatians by dag (plot) number within a mouza and survey type.
- Highlights the searched dag in the results.
- Used for finding all khatians containing a specific dag.

### 4. `search-khatian.php`
- Advanced search UI for khatians by location, survey, khatian number, dag number, etc.
- Uses Alpine.js for dynamic dropdowns and filtering.
- Returns paginated, filterable results with Tailwind-styled tables.

### 5. `name-search.php`
- UI for searching khatians by owner or guardian name, with location filters.
- Uses AJAX to fetch divisions, districts, upazilas, and survey types.
- Returns paginated results with owner/guardian highlights.

### 6. `name-search-full.php`
- Similar to `name-search.php` but with a more focused or full-page UI.
- Designed for direct access or embedding in other pages.

### 7. `holding-browser.php`
- UI for browsing land holdings by district, upazila, mouza, and search terms.
- Uses Tailwind CSS and Alpine.js for a modern, responsive interface.
- Displays holding details, owners, land schedules, and tax info.
- Includes advanced filters and dark mode support.

---

## Common Features
- All tools use MySQL for data access and PHP for backend logic.
- Most tools are designed to be included in a main `tools.php` or accessed directly.
- UIs are built with Tailwind CSS and Alpine.js for interactivity and responsiveness.
- All search/filter forms use GET parameters for easy linking/bookmarking.
- Mobile-friendly and accessible design (with Bengali language support).

---

## Database Structure & Relationships

All tools in this directory interact with the main 4BDOR MySQL database. The core tables used include:

- **dlrms_khatians_{SURVEY}**: Stores khatian (land record) data for each survey type (RS, CS, SA, etc.). Fields include id, jl_number_id, mouza_id, khatian_no, dags (plot numbers), owners, guardians, total_land, etc.
- **dlrms_divisions, dlrms_districts, dlrms_upazilas, dlrms_mouza_jl_numbers**: Administrative hierarchy for filtering/searching.
- **holdings_core, holdings_details_main, holdings_land_schedules, holdings_land_usage_tax**: Used by holding-browser.php for land tax and holding details.
- **namjari_mouzas**: Used for mutation (namjari) khatian search and selection.

### How Each Tool Works with the Database

- **src-khatian-no.php**: Receives a khatian ID and survey type, validates the type, and queries the corresponding `dlrms_khatians_{SURVEY}` table for all fields. Optionally fetches related names from division/district/upazila/mouza tables for context.

- **search-namjari.php**: Guides the user through division → district → upazila → mouza selection using the administrative tables, then queries `namjari_mouzas` and `dlrms_khatians_{SURVEY}` for khatian search by owner/guardian.

- **search-map-dags.php**: Accepts a dag (plot) number and mouza ID, then queries the appropriate `dlrms_khatians_{SURVEY}` table using `FIND_IN_SET` on the `dags` field to find all khatians containing that dag.

- **search-khatian.php**: Provides a multi-filter search UI, querying administrative tables for location filters and `dlrms_khatians_{SURVEY}` for khatian/dag search. Supports pagination and dynamic filtering.

- **name-search.php / name-search-full.php**: Use AJAX to fetch division/district/upazila/survey options, then query `dlrms_khatians_{SURVEY}` for khatians matching owner/guardian names. May use a cache table (`dlrms_src_names`) for repeated queries.

- **holding-browser.php**: Uses `holdings_core` for main holding info, `holdings_details_main` for details, `holdings_land_schedules` for land breakdown, and `holdings_land_usage_tax` for tax calculations. Also queries administrative tables for filtering.

### Relationships and Data Flow

- All search tools start with administrative filters (division, district, upazila, mouza) to narrow down the dataset.
- Khatian-related tools always reference the correct survey table based on user selection.
- Owner/guardian/dag/khatian number searches are performed using SQL `LIKE`, `FIND_IN_SET`, or direct match queries.
- Results are paginated and returned as arrays for display in tables or detail views.
- Tools are designed to be modular and can be combined in a single UI or used separately.

---

## How Tools Relate to the Main System

- These tools are typically included in a main `tools.php` or loaded via AJAX in the main web app.
- They provide advanced search, browsing, and reporting features beyond the basic API endpoints.
- All tools use the same database and share the administrative hierarchy, ensuring consistent filtering and data integrity.
- UI components (dropdowns, tables, modals) are standardized using Tailwind CSS and Alpine.js, making it easy to extend or integrate new tools.

---

## Example Data Flow

1. **User selects division, district, upazila, and mouza** (using dropdowns populated from the database).
2. **User enters search criteria** (khatian number, dag number, owner/guardian name, etc.).
3. **Tool queries the appropriate tables** (e.g., `dlrms_khatians_RS` for RS survey) and returns results.
4. **Results are displayed** in a paginated, filterable table or as a detail view.
5. **User can drill down** to see more details, related holdings, or tax info as needed.

---

## For AI Code Generation
- Use the structure and UI patterns in these tools as templates for new features.
- All forms, tables, and modals use Tailwind CSS classes.
- Use Alpine.js for all client-side interactivity (dropdowns, modals, AJAX, etc.).
- Follow the parameter and data flow patterns for new search or detail tools.
