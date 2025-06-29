# Bangladesh Digital Land Records Management System (4BDOR) API
API url:  https://4bd.org/api/{php_file}?
## Project Overview
This is the backend API for the Bangladesh Digital Land Records Management System (4BDOR), a comprehensive solution for managing land records, surveys, and land tax information in Bangladesh. The system handles various types of land surveys, administrative divisions, and land holdings data.

## Features

### 1. Administrative Hierarchy Management
- Divisions
- Districts
- Upazilas/Thanas
- Mauzas (Land Revenue Villages)

### 2. Survey Management
Supports multiple survey types:
- CS (Cadastral Survey)
- RS (Revisional Survey)
- SA (State Acquisition)
- BS (Bangladesh Survey)
- DIARA (River Area Survey)
- PETY (Petty Survey)
- BRS (Bangladesh Revisional Survey)
- BDS (Bangladesh Digital Survey)

### 3. Land Records Management
- Khatian (Record of Rights) Management
- Plot/Dag Numbers
- Land Ownership Records
- Land Usage Information
- Historical Record Tracking

### 4. Digital Map Management (E-Mouza)
- Survey Map Storage and Retrieval
- Google Drive Integration for Map Files
- Map Sheet Management
- Plot/Dag Geometry Storage

### 5. Land Tax Management
- Holdings Management
- Land Usage Tax Calculation
- Tax Payment Records
- Due Amount Tracking
- Interest Calculation

## Database Structure

### Core Tables
1. **Administrative Division Tables**
   - `dlrms_divisions`
   - `dlrms_districts`
   - `dlrms_upazilas`
   - `dlrms_mouza_jl_numbers`

2. **Survey Records Tables**
   - `dlrms_all_surveys`
   - `dlrms_khatians_*` (CS, RS, SA, BS, etc.)
   - Multiple survey-specific tables for different types

3. **E-Mouza Map Tables**
   - `emouza_map_files`
   - `emouza_survey_types`
   - `land_plots`
   - `land_sheets`

4. **Land Tax Tables**
   - `holdings_core`
   - `holdings_details_main`
   - `holdings_land_schedules`
   - `holdings_land_usage_tax`

## API Endpoints

### Administrative Endpoints
1. **Division Management**
   - GET `/ajax.php?action=get_divisions` - Fetch all divisions
   - Includes name in Bengali and English

2. **District Management**
   - GET `/ajax.php?action=get_districts&division_id={id}` - Fetch districts by division

3. **Upazila Management**
   - GET `/ajax.php?action=get_upazilas&district_id={id}` - Fetch upazilas by district

### Map Management Endpoints
1. **E-Mouza Map Access**
   - GET `/emouza-map-ajax.php?action=get_divisions` - Fetch divisions for maps
   - GET `/emouza-map-ajax.php?action=get_districts&division_id={id}` - Fetch districts for maps
   - GET `/emouza-map-ajax.php?action=get_upazilas&district_id={id}` - Fetch upazilas for maps

## Detailed API Endpoints

### 1. Core Land Records API (`ajax.php`)
- `action=get_divisions` — List all divisions
- `action=get_districts&division_id={id}` — List districts in a division
- `action=get_upazilas&district_id={id}` — List upazilas/circles in a district
- `action=get_surveys&district_bbs_code={code}&upazila_bbs_code={code}` — List available survey types for a location
- `action=get_mouzas&district_bbs_code={code}&upazila_bbs_code={code}&survey_id={id}` — List mouzas/JL numbers for a survey
- `action=get_khatians&mouza_jl_id={id}&survey_id={id}&page={n}&pageSize={n}&khatian_search={str}&dag_search={str}` — Paginated khatian search (by number or dag)

#### Example Response (get_khatians):
```json
{
  "success": true,
  "message": "Khatians fetched successfully",
  "data": {
    "khatians": [
      {"id": 1, "khatian_no": "123", "owners": "...", ...}
    ],
    "totalItems": 100,
    "totalPages": 5,
    "currentPage": 1,
    "pageSize": 20
  }
}
```

### 2. E-Mouza Map API (`emouza-map-ajax.php`)
- `action=get_divisions` — List e-mouza divisions
- `action=get_districts&division_id={id}` — List e-mouza districts
- `action=get_upazilas&district_id={id}` — List e-mouza upazilas
- `action=get_mouzas&upazila_id={id}` — List mouzas and available survey types for an upazila
- `action=get_map_files&mouza_id={id}&survey_type_id={id}` — List map files for a mouza and survey type

### 3. Holdings Browser API (`holding-browser-ajax.php`)
- `action=get_upazilas&district_id={id}` — List upazilas for holdings
- `action=get_moujas&upazila_id={id}` — List moujas for holdings
- `holding_id={id}` — Get holding details, owners, and land schedules
- `page={n}&limit={n}&search={str}&owner_name={str}&father_name={str}` — Paginated holdings search

### 4. Map Files API (`maps-files-ajax.php`)
- `action=search_files&term={str}` — Search map files by name (min 3 chars)
- Returns file info, Google Drive IDs, and breadcrumb navigation

### 5. Name Search API (`name-search-ajax.php`)
- `action=search_by_name&survey_id={id}&owner_search={str}&guardian_search={str}&page={n}&pageSize={n}` — Search khatians by owner/guardian name
- Uses caching for repeated queries

### 6. Namjari Khatian API (`namjari-khatian-ajax.php`)
- `action=get_divisions` — List divisions for mutation (namjari)
- `action=get_districts&division_bbs={code}` — List districts for a division
- `action=get_upazilas&division_bbs={code}&district_bbs={code}` — List upazilas for a district
- `action=get_mouzas&district_bbs={code}&upazila_bbs={code}&search={str}` — List mouzas for upazila
- `action=get_khatians&mouza_jl_id={id}` — List khatians for a mouza

### 7. Land Office Tree API (`vhumi-office-tree-ajax.php`)
- `type=root` — List all divisions
- `type=division&id={id}` — List districts in a division
- `type=district&id={id}` — List upazilas in a district
- `type=upazila&id={id}` — List land offices in an upazila
- `type=office&id={id}` — List moujas for an office
- `type=office_detail&id={id}` — Get details for a specific office

## Example Data Structures
- All endpoints return JSON with at least `success`, `message`, and `data` fields.
- List endpoints return arrays of objects with IDs, names, and codes.
- Search endpoints support pagination and filtering.

## Use Cases
- Build land record search and display UIs
- Integrate digital map viewers for land plots
- Provide land tax and holding information to citizens
- Support government land mutation (namjari) workflows
- Enable hierarchical navigation of land offices and mouzas

## Technical Details

### Database
- MySQL/MariaDB with UTF-8 (utf8mb4) encoding
- Spatial data support for map geometries
- Full-text search capabilities for owner/guardian names

### Security
- CORS configuration for API access control
- Input validation and parameterized queries
- Role-based access control

### Data Standards
- BBS (Bangladesh Bureau of Statistics) codes for administrative areas
- Standard survey codes
- Geographical coordinate system for maps

## Setup Requirements

### Prerequisites
- PHP 7.4+ with MySQL support
- MySQL/MariaDB database server
- Apache/Nginx web server
- Required PHP extensions:
  - mysqli
  - json
  - mbstring

### Configuration
1. Database configuration in a secure location
2. CORS settings for allowed origins
3. File permissions for map storage
4. Google Drive API configuration (for map storage)

## Security Notes
1. Move database credentials to environment variables or secure config
2. Implement proper authentication/authorization
3. Validate all input parameters
4. Use prepared statements for all queries
5. Implement rate limiting
6. Secure file uploads and access

## Development Guidelines
1. Follow PSR standards for PHP code
2. Use prepared statements for database queries
3. Implement proper error handling and logging
4. Document all API endpoints and parameters
5. Validate input data and sanitize output

## Contributing
1. Follow the coding standards
2. Document all changes
3. Test thoroughly before submitting changes
4. Update readme with any new features or changes
