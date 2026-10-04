Course CRM 0.1.0

Initial foundation:
- Dedicated CRM database tables.
- Clients.
- Orders.
- Payments table.
- Communication history table.
- Google Calendar table.
- CF7 capture via wpcf7_before_send_mail.
- Basic WordPress admin CRM screens.
- Google OAuth settings and event creation API foundation.

CF7 expected field names:
first_name
last_name
email
phone
course_id

Important:
The plugin creates its own database tables and does not store CRM clients as a WordPress CPT.
The course_id refers to the existing WordPress course CPT post ID.

Google Calendar:
1. Create a Google Cloud project.
2. Enable Google Calendar API.
3. Create OAuth credentials.
4. Add the callback URL shown in Course CRM -> Google Calendar.
5. Enter Client ID, Client Secret and Calendar ID in the plugin settings.
