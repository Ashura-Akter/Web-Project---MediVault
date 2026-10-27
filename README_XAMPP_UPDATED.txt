MediVault - Updated XAMPP Notes

1. Copy the MediVault folder to C:\xampp\htdocs\
2. Start Apache and MySQL in XAMPP.
3. Use the existing medivault database and the supplied MediVault SQL schema as the database source of truth.
4. Open http://localhost/MediVault/

LOGIN
- Patient: use a patient account.
- Pharmacist: use a pharmacist account; approved pharmacist profiles open the pharmacist dashboard.
- Admin: the requested default admin is:
  Email: admin.medivult@gmail.com
  Password: 12344321

The application bootstraps this default admin from the legacy demo-admin seed the first time an admin login is attempted. Once the requested default account exists, it is not overwritten by the bootstrap routine.

REGISTRATION
- Public Sign Up contains Patient and Pharmacist only.
- Admin cannot be created through public registration.
- A logged-in Admin can create another Admin from Admin Dashboard -> Add New Admin.

ROLE NAVIGATION
- Pharmacist and Admin dashboards have fixed left navigation and fixed top header/search areas.
- The MediVault logo/header links to patient/browse.php.
- Profile opens account/profile.php.
- Help & Support opens admin/help_support.php.
- Support submissions are stored as GENERAL notifications for the active administrator, using the existing notifications table; no new database table was added.

FILES
- admin/ : integrated Admin dashboard
- account/profile.php : Profile view/update for logged-in users
- admin/help_support.php : Help & Support form and admin support inbox
- database/bootstrap_admin.php : requested default-admin bootstrap

No new database tables were introduced by this merge. Existing SQL schema remains the source of truth.

MediVault v10 update
--------------------
1. This version makes My Shelf branch-specific. The same medicine can now be saved separately for different pharmacy branches.
2. If you are using the existing database, run database/migration_v10_requests_and_branch_favorites.sql once before using the updated code.
3. If you import the included database/medivault_updated.sql as a fresh database, the new favorite_medicines.pharmacy_id field is already included.
4. Patient restricted-medicine requests appear in the pharmacist Pending tab, with View Prescription, Approve and Reject actions. They do not appear in the Reservation tab.
5. Restricted requests move to the pharmacist Approved tab after approval or rejection. Reservation History excludes restricted requests.
6. Patients have a Request page for restricted and reservation requests, including pending/accepted/rejected status and rejection details.
7. Request-related notification clicks mark that notification as read and open the Request page. Mark all as read is also available.
8. Prescription upload success redirects to the Request page after 30 seconds.

MediVault v11 update
--------------------
1. View more popup (Order History + Request History) now shares one style in common/common.css:
   justified content, big round teal X button, zoom + running gradient ring on hover.
2. Admin now has the same notification bell as Patient/Pharmacist (common/topbar.php).
   Low stock, expiring stock, new patient signup, new pharmacist signup and support requests notify the admin;
   clicking a notification opens the matching left-menu page. No new database table.
3. Admin Top Selling Chart has a new "By Branch" pie chart (sales value per pharmacy).
4. Forgot Password: OTP mail now goes through database/mailer.php.
   - Real emails: fill database/mail_config.php (Gmail App Password) and set smtp_enabled to true.
   - Without mail setup (local XAMPP) the OTP is shown on the page and saved in database/mail_log.txt.

MediVault v12 update
--------------------
1. Pharmacist left menu has a new "In Stock" page: medicines currently available at that pharmacy (with quantity and nearest expiry).
2. Pharmacist sign-up and Help & Support requests now reach every active admin: they appear in Pharmacist Approvals / Support Requests
   and in the admin notification bell (click opens the matching page).
3. Admin left menu is wider so "Pharmacist Approvals (n)" stays on one line.

MediVault v13 update
--------------------
1. DATABASE: drop the old `medivault` database (or just import over it) and import database/medivault_updated.sql.
   It contains 150 Bangladeshi medicines, 13 categories, stock for 5 pharmacies (62-121 medicines each),
   and fresh orders / reservations / prescriptions / carts / shelf / notifications (dates are relative to the import day).
2. A pharmacist who signs up with "Others" + a new pharmacy name: when the admin approves, the pharmacy is created
   automatically, the dashboard opens, and the branch appears in the patient's Branch menu.
3. Pharmacist cards now show the Restricted label, the medicine type and the image (same as Admin).
4. Admin Daily Top Sales / Sales History / Top Selling Chart are never cached and refresh every 20 seconds.
5. Pharmacist top bar shows the pharmacy name in the centre (gradient text, typed one character at a time,
   full name for 3 seconds, then it starts again).
6. Opening a page from the left menu (Pending, Reservations, Low Stock, Expiring Soon, Orders, admin pages ...)
   marks that page's notifications as read, so the bell count goes down.
7. Admin Add Medicine: Image upload is required (JPG/PNG/WEBP/GIF, max 2 MB, saved in uploads/medicines/).
   Type, Category and Company Name each have an "Others" option that opens a text box.
   - Type "Spray" is shown on the card as Type: Spray.
   - A new Category is added to the patient category bar. After 8 categories the rest go into a "More" dropdown.
   - A new Company name is shown on the card.
   Admin can also change a medicine image with the camera button on the card.
8. Patient cards show the medicine image; hovering zooms the image and moving the mouse away restores it.
   Medicines without an image show a neutral pill icon until the admin uploads one.
9. Folder uploads/medicines/ must be writable (normal on XAMPP).
