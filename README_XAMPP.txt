MediVault — Linked Login + Patient + Pharmacist Module
========================================================

Flow:
Sign up -> Login -> select role -> role-specific dashboard

Patient:
Sign up -> Login -> Patient -> patient/browse.php

Pharmacist:
Sign up -> Login -> Pharmacist -> pharmasist/medivaultpharmasist.php

Pharmacist integration:
- Pharmacist registration creates both the users row and the matching pharmacist_profiles row.
- The new pharmacist is linked to the first active pharmacy in the existing medivault database.
- A unique license number is generated from the new user ID.
- The application registration flow marks the new pharmacist APPROVED using an existing active ADMIN account, so the pharmacist can log in immediately.
- The pharmacist dashboard reads the logged-in pharmacist, pharmacist profile, pharmacy and medicine/reservation data from MySQL.
- Hard-coded pharmacist ID 3 / pharmacy ID 1 values were removed from the dashboard actions and queries.
- Inventory, low-stock, expiry, pending reservations, approved reservations and reservation history are scoped to the logged-in pharmacist's assigned pharmacy.
- Medicine creation stores created_by_user_id using the logged-in pharmacist's user_id.
- Logout uses the existing login/logout.php file.

Database:
- Uses the existing medivault database.
- No new SQL file is included.
- The existing 24-table schema and its relationships remain unchanged.

XAMPP:
Place the MediVault folder inside C:\xampp\htdocs\ and open:
http://localhost/MediVault/
