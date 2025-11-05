# Lead Mailer - Comprehensive Testing Plan

## Test Environment Setup

### Prerequisites
- Application running at: http://127.0.0.1:8000/admin/
- Admin credentials: admin@example.com / password123
- MailHog SMTP: localhost:1025 (no authentication required)
- Test domain: ploi.cloud
- Language: Dutch (Nederlands)
- Testing tool: Playwright browser automation

### Docker Services Status Check
```bash
docker compose ps
docker compose logs -f mailhog
```

---

## Phase 1: Authentication & Navigation (5 minutes)

### Test 1.1: Login Flow
**Objective:** Verify admin panel access and basic navigation

**Steps:**
1. Navigate to http://127.0.0.1:8000/admin/login
2. Enter credentials: admin@example.com / password123
3. Click "Sign in" button
4. Verify dashboard loads successfully

**Expected Results:**
- Successful login redirect to dashboard
- Navigation menu visible with all resource groups
- No console errors
- User menu shows logged-in user

**Playwright Test:**
```typescript
await page.goto('http://127.0.0.1:8000/admin/login');
await page.fill('input[type="email"]', 'admin@example.com');
await page.fill('input[type="password"]', 'password123');
await page.click('button[type="submit"]');
await expect(page).toHaveURL(/.*admin$/);
```

### Test 1.2: Navigation Menu Verification
**Objective:** Ensure all 8 resources are accessible

**Steps:**
1. Verify "Domains & Websites" group:
   - Domains
   - Websites
   - Website Requirements
2. Verify "Contacts & Leads" group:
   - Contacts
3. Verify "Email Management" group:
   - Review Queue
   - Email Templates
   - SMTP Credentials
   - Email Sent Logs
4. Verify "Configuration" group:
   - Blacklist Entries

**Expected Results:**
- All navigation items visible
- Click each item to verify page loads
- Correct icons and labels displayed

---

## Phase 2: Domain Resource Testing (10 minutes)

### Test 2.1: Create Domain (ploi.cloud)
**Objective:** Add primary test domain with auto-TLD extraction

**Steps:**
1. Navigate to Domains → Create
2. Fill form:
   - Domain: `ploi.cloud`
   - Status: Active (1)
   - Notes: "Test domain for Playwright automation"
3. Submit form

**Expected Results:**
- Domain created successfully
- TLD automatically extracted as "cloud"
- Status set to Active
- Domain appears in list view
- No validation errors

**Database Verification:**
```sql
SELECT id, domain, tld, status, created_at FROM domains WHERE domain = 'ploi.cloud';
```

**Business Logic Checks:**
- TLD extraction: ploi.cloud → "cloud"
- Status integer mapping: Active = 1
- Timestamps populated correctly

### Test 2.2: Domain CRUD Operations
**Objective:** Test Create, Read, Update, Delete

**Create Additional Domain:**
- Domain: test-duplicate.com
- Status: Pending (0)

**Update Domain:**
- Change status from Pending to Active
- Update notes field
- Verify `last_checked_at` updates correctly

**View Domain:**
- Click "View" action
- Verify all fields display correctly
- Check relationships tab shows websites count

**Delete Domain:**
- Delete test-duplicate.com
- Verify soft delete (deleted_at populated)
- Domain not visible in default list
- Can be restored via trashed filter

### Test 2.3: Domain Status Workflow
**Objective:** Verify status transitions and badges

**Test Status Values:**
- 0 = Pending (gray badge)
- 1 = Active (success badge)
- 2 = Processed (info badge)
- 3 = Failed (danger badge)
- 4 = Blocked (warning badge)

**Steps:**
1. Create domains with each status
2. Verify badge colors in table view
3. Test status filters
4. Verify `markAsChecked()`, `markAsActive()`, `markAsProcessed()` methods

---

## Phase 3: Website Resource Testing (15 minutes)

### Test 3.1: Create Website for ploi.cloud
**Objective:** Add website with proper domain relationship

**Steps:**
1. Navigate to Websites → Create
2. Fill form:
   - Domain: Select "ploi.cloud" (dropdown search)
   - Full URL: `https://ploi.cloud`
   - Status: Pending (0)
   - Title: Leave blank (will be populated by crawler)
3. Submit form

**Expected Results:**
- Website created with domain_id reference
- Status set to Pending
- URL validated (must include protocol)
- Website visible in list view

**Database Verification:**
```sql
SELECT id, domain_id, url, status, title, meets_requirements 
FROM websites WHERE url = 'https://ploi.cloud';
```

### Test 3.2: Website Crawling Workflow
**Objective:** Test crawl job dispatch and status tracking

**Steps:**
1. Click "Crawl" action button on ploi.cloud website
2. Confirm crawl modal
3. Verify notification: "Crawl job queued"
4. Run queue worker:
   ```bash
   docker compose exec app php artisan queue:work --once
   ```
5. Refresh page and check status changes

**Expected Results:**
- Status changes: Pending → Crawling → Completed
- `crawl_started_at` timestamp recorded
- `crawled_at` timestamp after completion
- `crawl_attempts` incremented
- Content fields populated:
  - `title`
  - `description`
  - `detected_platform`
  - `page_count`
  - `word_count`
  - `content_snapshot`

**Error Handling:**
- Test with invalid URL
- Verify status changes to Failed (3)
- Check `crawl_error` field populated
- Verify can retry crawl

### Test 3.3: Website Requirements Evaluation
**Objective:** Test evaluation job and qualification logic

**Prerequisites:**
- Create Website Requirement first (see Phase 4)
- Website must be in Completed status

**Steps:**
1. Click "Evaluate" action button
2. Confirm evaluation modal
3. Run queue worker
4. Check `meets_requirements` boolean
5. Review `requirement_match_details` JSON

**Expected Results:**
- Evaluation job processes successfully
- `meets_requirements` set based on criteria
- Match details stored as JSON array
- Qualified websites show green icon in table

### Test 3.4: Website Status Badges
**Objective:** Verify all status transitions display correctly

**Test Status Values:**
- 0 = Pending (gray)
- 1 = Crawling (warning/yellow)
- 2 = Completed (success/green)
- 3 = Failed (danger/red)
- 4 = Per Review (info/blue)

### Test 3.5: Website Filters
**Objective:** Test table filtering functionality

**Test Filters:**
1. Status filter (multi-select)
2. Platform filter (dynamic from data)
3. Meets Requirements filter (ternary: All/Qualified/Not Qualified)
4. Trashed filter (with soft deletes)

### Test 3.6: Bulk Actions
**Objective:** Test bulk crawl and evaluation

**Steps:**
1. Select multiple websites (2-3)
2. Click "Crawl Selected" bulk action
3. Verify all queued
4. Wait for completion
5. Select completed websites
6. Click "Evaluate Selected"
7. Verify evaluations queued

**Expected Results:**
- Notification shows count: "Queued X website(s)"
- All selected items processed
- Skips already crawling websites

---

## Phase 4: Website Requirements Testing (10 minutes)

### Test 4.1: Create Qualification Criteria
**Objective:** Define requirements for lead qualification

**Steps:**
1. Navigate to Website Requirements → Create
2. Fill form:
   - Name: "Dutch Cloud Hosting Prospects"
   - Description: "SaaS/PaaS platforms targeting Netherlands"
   - Criteria (JSON):
     ```json
     {
       "min_word_count": 500,
       "required_keywords": ["cloud", "hosting", "server"],
       "detected_platforms": ["custom", "wordpress", "laravel"],
       "exclude_keywords": ["casino", "gambling"]
     }
     ```
   - Is Active: true
   - Priority: 80
3. Submit form

**Expected Results:**
- Criteria stored as valid JSON
- Validation ensures proper JSON format
- Can be associated with evaluation logic

### Test 4.2: Requirements CRUD
**Objective:** Test all operations

**Create Multiple Requirements:**
- High priority (90): Enterprise targets
- Medium priority (50): SMB targets
- Low priority (20): Exploratory

**Update:**
- Modify criteria JSON
- Change priority levels
- Toggle active status

**View:**
- Verify criteria displays formatted
- Check associated websites count

**Delete:**
- Soft delete requirement
- Verify can be restored

---

## Phase 5: Contact Resource Testing (15 minutes)

### Test 5.1: Manual Contact Creation
**Objective:** Create contact and test validation

**Steps:**
1. Navigate to Contacts → Create
2. Fill form:
   - Website: Select "ploi.cloud"
   - Email: `test@ploi.cloud`
   - Name: "Dennis Smink" (ploi.cloud founder)
   - Phone: "+31612345678"
   - Position: "Founder & CEO"
   - Source Type: Contact Page
   - Priority: Leave for auto-calculation
3. Submit form

**Expected Results:**
- Contact created successfully
- `is_validated` = false initially
- `priority` auto-calculated based on:
  - Base: 50
  - Contact page source: +30
  - Has name: +10
  - Has position: +5
  - Total: 95
- Contact visible in table

### Test 5.2: Contact Extraction from Website
**Objective:** Test automated contact extraction service

**Prerequisites:**
- Website "ploi.cloud" must be crawled (completed status)

**Steps:**
1. Run contact extraction job manually:
   ```bash
   docker compose exec app php artisan tinker
   ```
   ```php
   $website = App\Models\Website::where('url', 'https://ploi.cloud')->first();
   $service = app(App\Services\ContactExtractionService::class);
   $urls = $service->getPriorityUrls($website);
   foreach ($urls as $url) {
       // Simulate extraction
   }
   ```

**OR create Filament action:**
2. Add "Extract Contacts" action to Website resource
3. Click action
4. Wait for job completion

**Expected Results:**
- Contacts extracted from:
  - Contact page (/contact, /kontakt)
  - About page (/about, /over-ons)
  - Footer section
  - Mailto links
- Emails parsed correctly
- Source type detected from URL patterns
- Context captured (surrounding text)
- Duplicate prevention works
- Priority scores calculated

**Verification:**
```sql
SELECT email, name, source_type, priority, source_url 
FROM contacts 
WHERE website_id = (SELECT id FROM websites WHERE url = 'https://ploi.cloud');
```

### Test 5.3: Contact Validation
**Objective:** Test email validation service

**Create Test Contacts:**
1. Valid email: test@ploi.cloud
2. Invalid syntax: invalid@email
3. Disposable email: test@tempmail.com
4. No MX record: test@fake-domain-xyz.com

**Steps:**
1. Select contacts
2. Click "Validate Email" action (if available)
3. OR run validation job:
   ```bash
   docker compose exec app php artisan app:validate-contacts
   ```

**Expected Results:**
- Valid emails: `is_validated` = true, `is_valid` = true
- Invalid syntax: `validation_error` = "Invalid email format"
- Disposable: `validation_error` = "Disposable email detected"
- No MX: `validation_error` = "MX records not found"

**Service Methods to Verify:**
- `EmailValidationService::validateEmail()`
- Syntax check (FILTER_VALIDATE_EMAIL)
- MX record lookup (dns_get_record)
- Disposable email detection
- Blacklist checking

### Test 5.4: Contact Priority Scoring
**Objective:** Verify priority calculation logic

**Test Cases:**
| Source Type  | Name | Position | Expected Priority |
|--------------|------|----------|-------------------|
| contact_page | Yes  | Yes      | 95 (50+30+10+5)   |
| about_page   | Yes  | No       | 80 (50+20+10)     |
| footer       | No   | No       | 60 (50+10)        |
| body         | No   | No       | 55 (50+5)         |

**Verification:**
```php
$contact = Contact::find($id);
$calculatedPriority = $contact->calculatePriority();
assertEquals($expectedPriority, $calculatedPriority);
```

### Test 5.5: Contact Filters & Scopes
**Objective:** Test query scopes and filters

**Test Filters:**
1. Validation Status:
   - Not Validated
   - Validated & Valid
   - Validated & Invalid
2. Contact Status:
   - Not Contacted
   - Contacted
3. Priority Levels:
   - High (≥80)
   - Medium (50-79)
   - Low (<50)
4. Source Type (multi-select)

**Test Scopes:**
```php
Contact::validated()->get();
Contact::notContacted()->get();
Contact::highPriority()->get();
```

### Test 5.6: Duplicate Prevention
**Objective:** Ensure no duplicate contacts per website

**Steps:**
1. Try to create contact with existing email for same website
2. Verify validation error
3. Test `DuplicatePreventionService::isDuplicate()`

**Expected Results:**
- Duplicate blocked with error message
- Can have same email across different websites
- Database unique constraint enforced

---

## Phase 6: Email Template Testing (10 minutes)

### Test 6.1: Create Dutch Outreach Template
**Objective:** Create simple, interesting Dutch email template

**Steps:**
1. Navigate to Email Templates → Create
2. Fill form:
   - Name: "Dutch Cloud Hosting Outreach"
   - Language: "nl" (Dutch)
   - Subject: "Hoi {{contact_name}}, interessante kans voor {{website_title}}"
   - Preheader: "Een korte vraag over jullie cloudoplossing"
   - Body:
     ```html
     <p>Hoi {{contact_name}},</p>
     
     <p>Ik kwam {{website_title}} tegen en vond jullie cloudoplossing interessant.</p>
     
     <p>Ik werk aan een vergelijkbaar project en zou graag even kort willen sparren over jullie aanpak.</p>
     
     <p>Heb je 15 minuten deze week?</p>
     
     <p>Groeten,<br>
     {{sender_name}}</p>
     ```
   - Is Active: true
   - Category: "outreach"
3. Submit form

**Expected Results:**
- Template saved with placeholders intact
- Language set to "nl"
- Can preview with sample data
- Appears in template selector

### Test 6.2: Template Placeholders
**Objective:** Verify all supported placeholders

**Available Placeholders:**
- `{{contact_name}}` - Contact's name
- `{{contact_email}}` - Contact's email
- `{{website_title}}` - Website title
- `{{website_url}}` - Website URL
- `{{sender_name}}` - Sender name
- `{{sender_email}}` - Sender email
- `{{company_name}}` - Your company name
- `{{unsubscribe_link}}` - Unsubscribe URL

**Steps:**
1. Create template using all placeholders
2. Test replacement logic:
   ```php
   $service = app(App\Services\EmailTemplateService::class);
   $rendered = $service->renderTemplate($template, $contact, $website);
   ```
3. Verify all placeholders replaced correctly

### Test 6.3: Template CRUD Operations
**Objective:** Full lifecycle testing

**Create:**
- Multiple templates (short/long, different languages)
- Different categories (outreach, follow-up, reminder)

**Update:**
- Modify subject and body
- Change active status
- Update language

**View:**
- Preview template with sample data
- See usage statistics (emails sent count)

**Delete:**
- Soft delete template
- Verify cannot be deleted if in use
- Can be restored

---

## Phase 7: SMTP Credentials Testing (10 minutes)

### Test 7.1: Configure MailHog SMTP
**Objective:** Set up local email testing

**Steps:**
1. Navigate to SMTP Credentials → Create
2. Fill form:
   - Name: "MailHog Local"
   - SMTP Host: `mailhog`
   - SMTP Port: `1025`
   - SMTP Encryption: None
   - SMTP Username: (leave blank)
   - SMTP Password: (leave blank)
   - From Email: `noreply@leadmailer.test`
   - From Name: "Lead Mailer Test"
   - Daily Limit: 100
   - Hourly Limit: 20
   - Is Active: true
   - Priority: 1 (highest)
3. Submit form

**Expected Results:**
- Credentials saved successfully
- Password encrypted in database
- Active status checked

**Database Verification:**
```sql
SELECT id, name, smtp_host, smtp_port, from_email, is_active, daily_limit 
FROM smtp_credentials WHERE name = 'MailHog Local';
```

### Test 7.2: SMTP Connection Test
**Objective:** Verify SMTP connectivity

**Steps:**
1. Click "Test Connection" action
2. Wait for test result
3. Check notification message

**Expected Results:**
- Connection successful to MailHog
- Test email sent
- Verify in MailHog UI: http://127.0.0.1:8025

**Service Verification:**
```php
$smtp = SmtpCredential::find($id);
$service = app(App\Services\EmailSendingService::class);
$result = $service->testSmtpConnection($smtp);
assertTrue($result);
```

### Test 7.3: SMTP Rotation Logic
**Objective:** Test round-robin rotation with rate limits

**Setup:**
1. Create 3 SMTP credentials
2. Set different priorities and limits

**Test Rotation:**
```php
$service = app(App\Services\SmtpRotationService::class);
$smtp1 = $service->getNextAvailable();
$smtp2 = $service->getNextAvailable();
$smtp3 = $service->getNextAvailable();
```

**Expected Results:**
- Higher priority SMTP used first
- Respects hourly/daily limits
- Skips inactive credentials
- Falls back when primary exhausted

### Test 7.4: Rate Limiting
**Objective:** Verify sending limits enforced

**Steps:**
1. Set SMTP daily_limit to 5
2. Send 6 emails using this SMTP
3. Verify 6th email uses different SMTP or fails gracefully

**Service Methods:**
- `SmtpRotationService::hasCapacityRemaining()`
- `SmtpRotationService::incrementUsage()`
- `RateLimiterService::checkLimit()`

---

## Phase 8: Email Review Queue Testing (15 minutes)

### Test 8.1: Queue Email for Review
**Objective:** Create review queue entry

**Steps:**
1. Navigate to Email Review Queue → Create
2. Fill form:
   - Website: ploi.cloud
   - Contact: test@ploi.cloud
   - Email Template: Dutch Cloud Hosting Outreach
   - Generated Subject: (auto-filled from template)
   - Generated Body: (auto-filled with placeholders replaced)
   - Priority: 95
   - Status: Pending
3. Submit form

**Expected Results:**
- Review entry created
- Template rendered with contact data
- All placeholders replaced
- Status = Pending
- Priority inherited from contact

**Automated Creation (Preferred):**
```php
$service = app(App\Services\ReviewQueueService::class);
$review = $service->createReviewEntry($contact, $template, $website);
```

### Test 8.2: Review Workflow - Approve
**Objective:** Test approval action

**Steps:**
1. View pending email in queue
2. Review generated content
3. Click "Approve" action
4. Optionally add review notes
5. Submit approval

**Expected Results:**
- Status changes to Approved
- `reviewed_at` timestamp recorded
- `reviewed_by` set to current user ID
- Badge color changes to green
- Review notes saved

**Database Verification:**
```sql
SELECT status, reviewed_by, reviewed_at, review_notes 
FROM email_review_queues 
WHERE id = ?;
```

### Test 8.3: Review Workflow - Reject
**Objective:** Test rejection with required notes

**Steps:**
1. Select pending email
2. Click "Reject" action
3. Enter rejection reason (required)
4. Submit rejection

**Expected Results:**
- Status changes to Rejected
- Review notes required (validation error if empty)
- Red badge displayed
- Email will not be sent

### Test 8.4: Bulk Approval/Rejection
**Objective:** Test bulk operations

**Steps:**
1. Create 5-10 review queue entries
2. Select 3 pending entries
3. Click "Approve Selected" bulk action
4. Verify all approved with notification count

**Then:**
1. Select 2 other pending entries
2. Click "Reject Selected"
3. Enter rejection reason
4. Verify all rejected

**Expected Results:**
- Bulk operations only affect Pending status
- Notification shows count: "Approved X email(s)"
- All selected items updated
- Reviewed timestamp and user set

### Test 8.5: Send Approved Emails
**Objective:** Dispatch emails to queue

**Steps:**
1. Ensure emails are in Approved status
2. Click "Send Now" action on individual email
3. OR use "Send Approved" bulk action
4. Verify notification: "Email queued"
5. Run queue worker:
   ```bash
   docker compose exec app php artisan queue:work --once
   ```
6. Check MailHog UI (http://127.0.0.1:8025)

**Expected Results:**
- Email sent via SMTP
- Visible in MailHog inbox
- EmailSentLog created
- Contact marked as contacted
- Review queue entry processed

### Test 8.6: Queue Filters
**Objective:** Test filtering and sorting

**Test Filters:**
1. Status: Pending/Approved/Rejected
2. Priority Level: High(70+)/Medium(50-69)/Low(<50)

**Test Sorting:**
- Sort by created_at (newest first - default)
- Sort by priority (highest first)
- Sort by reviewed_at

---

## Phase 9: Email Sent Logs Testing (10 minutes)

### Test 9.1: Log Creation After Send
**Objective:** Verify automatic log creation

**Prerequisites:**
- Approved email sent from Review Queue

**Steps:**
1. Navigate to Email Sent Logs
2. Find log entry for sent email
3. Verify fields populated

**Expected Results:**
- Log created with:
  - `contact_id`
  - `website_id`
  - `email_template_id`
  - `smtp_credential_id`
  - `subject`
  - `body`
  - `sent_at` timestamp
  - `status` = sent/failed
  - `error_message` (if failed)

**Database Verification:**
```sql
SELECT * FROM email_sent_logs 
WHERE contact_id = ? 
ORDER BY sent_at DESC LIMIT 1;
```

### Test 9.2: Email Status Tracking
**Objective:** Test status values

**Status Constants:**
- `sent` - Successfully sent
- `failed` - SMTP error
- `bounced` - Email bounced
- `opened` - Tracking pixel (if enabled)
- `clicked` - Link clicked (if enabled)

**Test Failed Email:**
1. Configure invalid SMTP
2. Try to send email
3. Verify status = failed
4. Check error_message field populated

### Test 9.3: Logs Table Filtering
**Objective:** Test search and filters

**Test Filters:**
1. Status (multi-select)
2. Date range (sent_at)
3. SMTP Credential (which server used)
4. Contact search
5. Website search

**Test Search:**
- Search by subject
- Search by contact email
- Search by error message

### Test 9.4: Resend Failed Emails
**Objective:** Retry failed sends

**Steps:**
1. Find failed email log
2. Click "Resend" action
3. Confirm resend
4. Verify new log created

**Expected Results:**
- New queue job dispatched
- Original log preserved
- New log created on send attempt

---

## Phase 10: Blacklist Entries Testing (10 minutes)

### Test 10.1: Add Domain to Blacklist
**Objective:** Block emails to specific domains

**Steps:**
1. Navigate to Blacklist Entries → Create
2. Fill form:
   - Type: Domain
   - Value: `spam-domain.com`
   - Reason: "Known spam trap"
   - Is Active: true
3. Submit form

**Expected Results:**
- Entry created
- Type validated (domain/email)
- Value normalized (lowercase)

### Test 10.2: Add Email to Blacklist
**Objective:** Block specific email addresses

**Steps:**
1. Create entry:
   - Type: Email
   - Value: `bounced@example.com`
   - Reason: "Hard bounce"
   - Is Active: true

**Expected Results:**
- Email blacklisted
- Cannot send to this address

### Test 10.3: Blacklist Checking Service
**Objective:** Verify emails checked before sending

**Steps:**
1. Create blacklist entry for `test@blocked.com`
2. Create contact with this email
3. Try to create review queue entry
4. Verify blocked by blacklist check

**Service Verification:**
```php
$service = app(App\Services\BlacklistService::class);
$isBlocked = $service->isBlocked('test@blocked.com');
assertTrue($isBlocked);
```

### Test 10.4: Blacklist Filters
**Objective:** Test filtering by type

**Test Filters:**
1. Type: Domain/Email
2. Active status: Active/Inactive

### Test 10.5: Deactivate/Delete Entries
**Objective:** Manage blacklist

**Steps:**
1. Deactivate entry (toggle is_active)
2. Verify emails can be sent to deactivated entries
3. Delete entry (soft delete)
4. Verify can be restored

---

## Phase 11: Advanced Features Testing (20 minutes)

### Test 11.1: Contact Extraction Job
**Objective:** Full contact extraction pipeline

**Steps:**
1. Create website: https://ploi.cloud
2. Run crawl job (should complete)
3. Trigger contact extraction:
   ```bash
   docker compose exec app php artisan app:extract-contacts {website_id}
   ```
4. Wait for completion
5. Check contacts created

**Expected Results:**
- Multiple contacts extracted
- Source types correctly identified:
  - Contact page contacts have high priority
  - Footer contacts have lower priority
- Email formats validated
- Names and positions extracted where available
- Duplicate emails not created

**Priority URL Detection:**
- /contact, /kontakt, /kontact (Dutch)
- /about, /over-ons, /about-us (Dutch)
- /team, /ons-team (Dutch)
- Homepage (/)

### Test 11.2: Email Validation Pipeline
**Objective:** Test full validation service

**Steps:**
1. Create contacts with various email types
2. Run validation command:
   ```bash
   docker compose exec app php artisan app:validate-contacts
   ```
3. Check validation results

**Test Cases:**
- Valid corporate email: Pass
- Syntax invalid: Fail with error
- No MX records: Fail with error
- Disposable email (tempmail.com): Fail with error
- Blacklisted email: Fail with error

**Service Methods:**
- Syntax validation (FILTER_VALIDATE_EMAIL)
- MX record lookup (dns_get_record)
- Disposable email detection (hardcoded list)
- Blacklist cross-check

### Test 11.3: Requirements Matching
**Objective:** Test website qualification logic

**Setup:**
1. Create requirement with criteria:
   ```json
   {
     "min_word_count": 500,
     "required_keywords": ["cloud", "hosting"],
     "exclude_keywords": ["casino"]
   }
   ```
2. Crawl website
3. Run evaluation job

**Expected Results:**
- Word count checked: Pass/Fail
- Keywords searched in content: Pass/Fail
- Exclusion keywords checked: Pass/Fail
- `meets_requirements` set accordingly
- `requirement_match_details` JSON populated with:
  ```json
  {
    "word_count": 1234,
    "required_keywords_found": ["cloud", "hosting"],
    "excluded_keywords_found": [],
    "passed": true
  }
  ```

### Test 11.4: Email Personalization Service
**Objective:** Test placeholder replacement

**Steps:**
1. Create template with all placeholders
2. Test rendering:
   ```php
   $service = app(App\Services\EmailPersonalizationService::class);
   $personalized = $service->personalize($template, $contact, $website);
   ```
3. Verify all replacements correct

**Test Cases:**
- Contact without name: Fallback to "Hello" instead of "Hi {{name}}"
- Missing website title: Use domain name
- All placeholders present: Full replacement
- Nested placeholders: Should not double-process

### Test 11.5: Duplicate Prevention
**Objective:** Ensure no duplicate emails sent

**Steps:**
1. Create contact and send email
2. Try to send same template again to same contact
3. Verify blocked by duplicate check

**Service Verification:**
```php
$service = app(App\Services\DuplicatePreventionService::class);
$isDuplicate = $service->isDuplicate($contact, $template, $timeWindow);
assertTrue($isDuplicate);
```

**Time Window Testing:**
- Within 24 hours: Block
- Within 7 days: Block
- After 30 days: Allow

### Test 11.6: Rate Limiting
**Objective:** Test throttling logic

**Steps:**
1. Set SMTP hourly_limit to 5
2. Send 10 emails in quick succession
3. Verify rate limiter throttles after 5

**Service Methods:**
- `RateLimiterService::checkLimit()`
- `RateLimiterService::incrementCounter()`
- `RateLimiterService::resetCounters()`

---

## Phase 12: Business Logic Validation (15 minutes)

### Test 12.1: Domain TLD Extraction
**Objective:** Verify auto-extraction on create

**Test Cases:**
- ploi.cloud → "cloud"
- example.com → "com"
- test.co.uk → "uk"
- subdomain.example.org → "org"

**Code Location:**
- `Domain::boot()` creating event
- `Domain::extractTld()` static method

### Test 12.2: Contact Priority Calculation
**Objective:** Verify scoring algorithm

**Formula:**
```
Base: 50
+ Source Type: 5-30
+ Has Name: 10
+ Has Position: 5
Max: 100
```

**Test Matrix:**
| Source       | Name | Position | Expected |
|--------------|------|----------|----------|
| contact_page | ✓    | ✓        | 95       |
| about_page   | ✓    | ✗        | 80       |
| footer       | ✗    | ✗        | 60       |
| body         | ✗    | ✗        | 55       |

**Verification:**
```php
$contact = Contact::create([...]);
$priority = $contact->calculatePriority();
assertEquals($expected, $priority);
```

### Test 12.3: Website Status Transitions
**Objective:** Verify valid state machine

**Valid Transitions:**
- Pending → Crawling (startCrawl)
- Crawling → Completed (completeCrawl)
- Crawling → Failed (failCrawl)
- Failed → Pending (retry)
- Completed → Per Review (manual flag)

**Invalid Transitions:**
- Completed → Crawling (must reset first)
- Failed → Completed (must re-crawl)

### Test 12.4: Email Template Placeholder Validation
**Objective:** Ensure templates use valid placeholders

**Valid Placeholders:**
- {{contact_name}}
- {{contact_email}}
- {{website_title}}
- {{website_url}}
- {{sender_name}}
- {{sender_email}}

**Invalid Placeholders:**
- {{random_field}} → Should not break rendering
- {{contact.name}} → Wrong syntax
- {contact_name} → Missing double braces

### Test 12.5: Blacklist Priority
**Objective:** Verify blacklist checked before sending

**Order of Operations:**
1. Check contact email against blacklist
2. Check contact domain against blacklist
3. If blocked, prevent review queue creation
4. Log blocked attempt

**Test:**
```php
$contact = Contact::create(['email' => 'test@blocked.com']);
Blacklist::create(['type' => 'domain', 'value' => 'blocked.com']);

$service = app(App\Services\BlacklistService::class);
assertTrue($service->isBlocked($contact->email));
```

---

## Phase 13: Error Handling & Edge Cases (15 minutes)

### Test 13.1: Invalid URL Handling
**Objective:** Test URL validation

**Test Cases:**
- Missing protocol: "ploi.cloud" → Should auto-add https://
- Invalid URL: "not a url" → Validation error
- Unreachable URL: "https://fake-site-xyz-123.com" → Crawl fails gracefully

### Test 13.2: Missing Required Fields
**Objective:** Verify form validation

**Test All Resources:**
- Domain: Missing domain field
- Website: Missing URL or domain_id
- Contact: Missing email or website_id
- Template: Missing name, subject, or body
- SMTP: Missing host or port
- Review Queue: Missing contact_id

**Expected:**
- Red validation error messages
- Form submission blocked
- No database write

### Test 13.3: Database Constraints
**Objective:** Test unique constraints and foreign keys

**Test Cases:**
1. Duplicate domain name → Allow (soft delete consideration)
2. Duplicate contact email per website → Block
3. Orphaned contact (deleted website) → Cascade or prevent
4. Delete SMTP with sent logs → Should be soft delete only

### Test 13.4: Queue Job Failures
**Objective:** Verify graceful failure handling

**Test Scenarios:**
1. Crawl job timeout → Mark as failed with error
2. SMTP connection failure → Log error, retry later
3. Invalid email template → Skip and log
4. Database deadlock → Retry mechanism

**Monitor Queue:**
```bash
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry {id}
```

### Test 13.5: Large Data Sets
**Objective:** Performance testing

**Test Cases:**
1. Website with 100+ contacts → Pagination works
2. 1000+ emails in review queue → Table loads quickly
3. Bulk operations on 50+ items → No timeout
4. Large email body (10KB+) → Saves correctly

---

## Phase 14: Integration Testing (20 minutes)

### Test 14.1: Complete Lead Generation Flow
**Objective:** End-to-end pipeline test

**Full Workflow:**
1. Add domain: ploi.cloud
2. Add website: https://ploi.cloud
3. Crawl website (queue job)
4. Extract contacts (queue job)
5. Validate contacts (command)
6. Create email template (Dutch)
7. Configure SMTP (MailHog)
8. Generate review queue entries
9. Approve emails
10. Send emails (queue job)
11. Verify in MailHog
12. Check EmailSentLog

**Expected Results:**
- Complete pipeline without errors
- All data relationships correct
- Timestamps recorded at each step
- Contacts marked as contacted
- SMTP rotation works
- Rate limits respected

### Test 14.2: Multi-Website Campaign
**Objective:** Test scalability

**Steps:**
1. Add 5 domains
2. Add 2-3 websites per domain
3. Crawl all websites (bulk action)
4. Extract contacts from all
5. Create single template
6. Generate review queue for all contacts
7. Bulk approve
8. Bulk send

**Expected Results:**
- All websites processed independently
- No cross-contamination of data
- SMTP rotation distributes load
- Rate limiting prevents overflow

### Test 14.3: Error Recovery
**Objective:** Test resilience

**Simulate Failures:**
1. Kill queue worker mid-job
2. Restart and verify job retries
3. Database connection loss during crawl
4. SMTP server down during send

**Expected Results:**
- Jobs retry up to max attempts
- Failed jobs move to failed_jobs table
- Manual retry possible
- Error messages clear and actionable

---

## Phase 15: UI/UX Testing (10 minutes)

### Test 15.1: Responsive Design
**Objective:** Test mobile/tablet layouts

**Steps:**
1. Resize browser to mobile width (375px)
2. Test navigation menu (hamburger)
3. Verify tables stack or scroll horizontally
4. Test forms on mobile

**Expected Results:**
- Filament default responsive design works
- Tables scroll horizontally on mobile
- Forms remain usable
- No layout breaks

### Test 15.2: Accessibility
**Objective:** Basic ARIA/keyboard navigation

**Steps:**
1. Navigate using Tab key
2. Test form submission with Enter
3. Check color contrast (badges, buttons)
4. Verify screen reader labels

**Expected Results:**
- Tab order logical
- Focus indicators visible
- Form elements properly labeled
- Color contrast meets WCAG AA

### Test 15.3: Notifications & Feedback
**Objective:** User feedback on actions

**Test Actions:**
- Create success → Green notification
- Update success → Green notification
- Delete success → Red notification
- Validation error → Red notification with details
- Queue job dispatch → Info notification

**Expected Results:**
- Notifications appear top-right
- Auto-dismiss after 5 seconds
- Manual dismiss option
- Error details included

### Test 15.4: Loading States
**Objective:** Verify spinners and progress indicators

**Test Scenarios:**
- Table loading data
- Form submission
- Queue job running (Livewire polling)
- Bulk action processing

**Expected Results:**
- Spinner shown during load
- Button disabled during submit
- Progress indicator for long tasks

---

## Phase 16: Data Integrity Testing (10 minutes)

### Test 16.1: Soft Deletes
**Objective:** Verify soft delete across all resources

**Test Each Resource:**
1. Delete record
2. Verify `deleted_at` populated
3. Record not in default list
4. Toggle "Trashed" filter
5. Restore record
6. Verify `deleted_at` null

**Test Cascade:**
- Delete domain with websites → What happens to websites?
- Delete website with contacts → What happens to contacts?

### Test 16.2: Timestamps
**Objective:** Verify created_at, updated_at accurate

**Test:**
1. Create record → Check created_at
2. Wait 5 seconds
3. Update record → Check updated_at changed
4. Verify timezone correct (UTC storage)

### Test 16.3: JSON Fields
**Objective:** Test JSON storage and retrieval

**Test Fields:**
- Website: `requirement_match_details` (array)
- Website Requirement: `criteria` (JSON)

**Operations:**
1. Store complex JSON
2. Retrieve and parse
3. Update nested values
4. Search within JSON (if applicable)

### Test 16.4: Foreign Key Relationships
**Objective:** Verify associations load correctly

**Test Relationships:**
- Domain → hasMany(Websites)
- Website → belongsTo(Domain)
- Website → hasMany(Contacts)
- Contact → belongsTo(Website)
- EmailSentLog → belongsTo(Contact, Website, Template, SMTP)

**Verification:**
```php
$domain = Domain::with('websites')->find($id);
assertCount(2, $domain->websites);
```

---

## Phase 17: Performance Testing (Optional)

### Test 17.1: Query Optimization
**Objective:** Check N+1 query problems

**Enable Query Log:**
```php
DB::enableQueryLog();
// Perform action
dd(DB::getQueryLog());
```

**Test Scenarios:**
- List 50 websites with domain relationship
- List 100 contacts with website relationship
- Review queue with multiple relationships

**Expected:**
- Eager loading used (with/load)
- Minimal queries per page load
- No N+1 issues

### Test 17.2: Bulk Operations Performance
**Objective:** Test large batch processing

**Test:**
1. Create 100 websites
2. Bulk crawl all
3. Measure time to queue
4. Monitor queue processing time

**Expected:**
- Queue dispatch < 2 seconds
- Jobs process without memory issues
- No timeouts

---

## Phase 18: Security Testing (Optional)

### Test 18.1: Authentication Required
**Objective:** Verify no unauthorized access

**Steps:**
1. Log out
2. Try to access /admin/domains directly
3. Verify redirect to login

**Expected:**
- All admin routes protected
- Redirect to login with intended URL

### Test 18.2: Authorization (If Implemented)
**Objective:** Test role-based permissions

**If roles exist:**
- Admin can CRUD all resources
- Editor can view/edit but not delete
- Viewer can only view

### Test 18.3: XSS Prevention
**Objective:** Test input sanitization

**Test:**
1. Try to save `<script>alert('XSS')</script>` in text fields
2. Verify escaped in display
3. Rich editor (email body) allows safe HTML only

### Test 18.4: SQL Injection Prevention
**Objective:** Verify parameterized queries

**Test:**
1. Search for: `'; DROP TABLE domains; --`
2. Verify no SQL error
3. Eloquent ORM prevents injection

---

## Phase 19: Monitoring & Logging

### Test 19.1: Laravel Logs
**Objective:** Verify logging works

**Steps:**
1. Trigger error (invalid SMTP)
2. Check logs:
   ```bash
   docker compose exec app tail -f storage/logs/laravel.log
   ```
3. Verify error logged with stack trace

### Test 19.2: Queue Monitoring
**Objective:** Monitor job processing

**Commands:**
```bash
docker compose exec app php artisan queue:work --verbose
docker compose exec app php artisan queue:failed
docker compose exec app php artisan queue:retry all
```

### Test 19.3: Database Queries
**Objective:** Debug slow queries

**Enable Query Log:**
```php
DB::listen(function ($query) {
    Log::info($query->sql, $query->bindings);
});
```

---

## Phase 20: Playwright Automation Script

### Test 20.1: Automated Test Suite
**Objective:** Create reusable Playwright test

**Setup:**
```bash
npm init playwright@latest
npm install @playwright/test
```

**Test Script (tests/lead-mailer.spec.ts):**
```typescript
import { test, expect } from '@playwright/test';

test.describe('Lead Mailer E2E Tests', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('http://127.0.0.1:8000/admin/login');
    await page.fill('input[type="email"]', 'admin@example.com');
    await page.fill('input[type="password"]', 'password123');
    await page.click('button[type="submit"]');
    await expect(page).toHaveURL(/.*admin$/);
  });

  test('Create Domain - ploi.cloud', async ({ page }) => {
    await page.click('a[href*="/admin/domains"]');
    await page.click('a[href*="/admin/domains/create"]');
    
    await page.fill('input[name="domain"]', 'ploi.cloud');
    await page.selectOption('select[name="status"]', '1');
    await page.fill('textarea[name="notes"]', 'Test domain for automation');
    
    await page.click('button[type="submit"]');
    
    await expect(page.locator('text=ploi.cloud')).toBeVisible();
  });

  test('Create Website for ploi.cloud', async ({ page }) => {
    await page.click('a[href*="/admin/websites"]');
    await page.click('a[href*="/admin/websites/create"]');
    
    await page.click('div[wire:id*="mountedActionsData.domain_id"]');
    await page.fill('input[placeholder="Search..."]', 'ploi.cloud');
    await page.click('li:has-text("ploi.cloud")');
    
    await page.fill('input[name="url"]', 'https://ploi.cloud');
    
    await page.click('button[type="submit"]');
    
    await expect(page.locator('text=https://ploi.cloud')).toBeVisible();
  });

  test('Create Dutch Email Template', async ({ page }) => {
    await page.click('a[href*="/admin/email-templates"]');
    await page.click('a[href*="/admin/email-templates/create"]');
    
    await page.fill('input[name="name"]', 'Dutch Cloud Hosting Outreach');
    await page.fill('input[name="language"]', 'nl');
    await page.fill('input[name="subject"]', 'Hoi {{contact_name}}, interessante kans');
    
    await page.locator('.tiptap').fill('Hoi {{contact_name}}, ik vond jullie oplossing interessant.');
    
    await page.check('input[name="is_active"]');
    
    await page.click('button[type="submit"]');
    
    await expect(page.locator('text=Dutch Cloud Hosting Outreach')).toBeVisible();
  });

  test('Configure SMTP - MailHog', async ({ page }) => {
    await page.click('a[href*="/admin/smtp-credentials"]');
    await page.click('a[href*="/admin/smtp-credentials/create"]');
    
    await page.fill('input[name="name"]', 'MailHog Local');
    await page.fill('input[name="smtp_host"]', 'mailhog');
    await page.fill('input[name="smtp_port"]', '1025');
    await page.fill('input[name="from_email"]', 'noreply@leadmailer.test');
    await page.fill('input[name="from_name"]', 'Lead Mailer Test');
    await page.fill('input[name="daily_limit"]', '100');
    
    await page.check('input[name="is_active"]');
    
    await page.click('button[type="submit"]');
    
    await expect(page.locator('text=MailHog Local')).toBeVisible();
  });

  test('Crawl Website', async ({ page }) => {
    await page.click('a[href*="/admin/websites"]');
    
    await page.locator('tr:has-text("ploi.cloud")').locator('button:has-text("Crawl")').click();
    
    await page.click('button:has-text("Confirm")');
    
    await expect(page.locator('text=Crawl job queued')).toBeVisible();
  });

  test('Review Queue - Approve Email', async ({ page }) => {
    await page.click('a[href*="/admin/email-review-queues"]');
    
    await page.locator('tr').first().locator('button:has-text("Approve")').click();
    
    await page.click('button:has-text("Approve")');
    
    await expect(page.locator('text=Email approved')).toBeVisible();
  });
});
```

**Run Tests:**
```bash
npx playwright test
npx playwright test --headed
npx playwright test --debug
```

---

## Success Criteria Summary

### Must Pass (Critical):
1. All 8 Filament resources accessible
2. Domain TLD extraction works
3. Website crawling completes successfully
4. Contact extraction finds emails
5. Email validation detects invalid/disposable
6. Email template placeholders replaced
7. SMTP connection to MailHog successful
8. Review queue approve/reject workflow
9. Email sent and logged correctly
10. Blacklist blocks emails

### Should Pass (Important):
11. Priority scoring calculated correctly
12. Rate limiting enforced
13. Duplicate prevention works
14. Bulk actions process correctly
15. Filters and sorting work
16. Soft deletes functional
17. Foreign key relationships valid
18. Notifications appear on actions

### Nice to Have (Optional):
19. Responsive design on mobile
20. Performance acceptable with 100+ records
21. No N+1 query issues
22. Error recovery graceful
23. Playwright automation suite runs

---

## Testing Checklist

- [ ] Phase 1: Authentication & Navigation
- [ ] Phase 2: Domain Resource (ploi.cloud)
- [ ] Phase 3: Website Resource (ploi.cloud)
- [ ] Phase 4: Website Requirements
- [ ] Phase 5: Contact Resource & Extraction
- [ ] Phase 6: Email Template (Dutch)
- [ ] Phase 7: SMTP Credentials (MailHog)
- [ ] Phase 8: Email Review Queue
- [ ] Phase 9: Email Sent Logs
- [ ] Phase 10: Blacklist Entries
- [ ] Phase 11: Advanced Features
- [ ] Phase 12: Business Logic Validation
- [ ] Phase 13: Error Handling
- [ ] Phase 14: Integration Testing
- [ ] Phase 15: UI/UX Testing
- [ ] Phase 16: Data Integrity
- [ ] Phase 17: Performance (Optional)
- [ ] Phase 18: Security (Optional)
- [ ] Phase 19: Monitoring & Logging
- [ ] Phase 20: Playwright Automation

---

## Appendix A: Useful Commands

### Docker Commands
```bash
docker compose up -d
docker compose ps
docker compose logs -f app
docker compose exec app bash
```

### Laravel Commands
```bash
php artisan queue:work
php artisan queue:work --once
php artisan queue:failed
php artisan queue:retry all
php artisan tinker
php artisan migrate:fresh --seed
php artisan route:list
```

### Database Commands
```bash
docker compose exec db mysql -uroot -ppassword leadmailer
```

```sql
SHOW TABLES;
SELECT * FROM domains;
SELECT * FROM websites;
SELECT * FROM contacts;
SELECT * FROM email_review_queues;
```

### MailHog
- UI: http://127.0.0.1:8025
- SMTP: localhost:1025

---

## Appendix B: Dutch Email Template Examples

### Short & Simple (Recommended)
**Subject:** Hoi {{contact_name}}, vraag over {{website_title}}

**Body:**
```
Hoi {{contact_name}},

Ik zag {{website_title}} en vond jullie aanpak interessant.

Zou je 10 minuten hebben voor een kort gesprek deze week?

Groeten,
{{sender_name}}
```

### Medium Length
**Subject:** Interessante kans voor {{website_title}}

**Body:**
```
Hoi {{contact_name}},

Ik werk aan een vergelijkbaar project als {{website_title}} en zou graag even kort willen sparren over jullie aanpak.

Ik ben vooral benieuwd naar jullie ervaringen met [specific topic].

Heb je 15 minuten deze of volgende week?

Met vriendelijke groet,
{{sender_name}}
{{sender_email}}
```

### Professional
**Subject:** Samenwerking tussen {{company_name}} en {{website_title}}

**Body:**
```
Beste {{contact_name}},

Via {{website_url}} kwam ik in contact met jullie organisatie.

Wij zijn gespecialiseerd in [your offering] en zien mogelijk een interessante match met {{website_title}}.

Zou u open staan voor een korte kennismaking?

Met vriendelijke groet,
{{sender_name}}
{{company_name}}
{{sender_email}}
```

---

## Appendix C: Test Data Sets

### Sample Domains
- ploi.cloud (primary test)
- laravel.com
- filamentphp.com
- spatie.be (Dutch)
- mollie.com (Dutch)

### Sample Emails (Valid)
- test@ploi.cloud
- hello@example.com
- info@test-company.nl

### Sample Emails (Invalid)
- invalid@email (syntax)
- test@fake-domain-xyz-123.com (no MX)
- test@tempmail.com (disposable)

### Sample Blacklist Entries
- spam-domain.com (domain)
- bounced@example.com (email)
- noreply@blocked.com (email)

---

## End of Testing Plan

**Estimated Total Time:** 3-4 hours for complete manual testing
**Playwright Automation:** 1-2 hours to set up, 15 minutes to run

**Next Steps:**
1. Execute tests in phases
2. Document any bugs found
3. Create GitHub issues for failures
4. Build Playwright automation suite
5. Set up CI/CD pipeline for automated testing
