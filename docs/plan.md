The goal is to make an application that can be used to automatically research website and reach out to those websites via email.

USPs:
1. Assume a 'Domains' table which contains all the domains, generally speaking millions.
2. You should create a Websites table that can be linked to Domains to keep track which domains have been checked.
3. Per website it should have a 'requirements' section which can set which websites are good leads. Example is amount of pages, software like wordpress, words and more recommendations. existance of a url
4. Per website you should also set a SMTP credentials to sent emails from.
5. It should crawl up to a configurable maximum per website within a given timeframe (e.g. sent every day 10 mails to a matchin website between 8AM and 5PM)
6. You should be able to set a email template and Mistral AI should be used to fill it with the website data (e.g. first 10 pages as content)

Additional MVP Features:
7. Email/Contact Extraction: System to automatically find and extract email addresses from crawled website pages. Should support common patterns (contact page, about page, footer emails) and validate extracted email addresses.
8. Duplicate Prevention: Track which emails have been sent to which email addresses to prevent sending multiple emails to the same contact/domain. Maintain a record of all sent emails.
9. Review Queue: Websites can be marked as 'per review' status, requiring manual review and approval of each email before it is sent. This allows for quality control on sensitive campaigns.
10. Email Sent Log: Full logging system that tracks all emails sent, including recipient email address, timestamp, template used, and delivery status. This provides complete visibility into which emails have been sent to which email addresses.
11. Blacklist Management: System to manage blacklisted domains and email addresses that should be automatically excluded from outreach. Should support manual additions and bulk import/export.
12. Basic Dashboard: Web interface to view processing status, email statistics (sent, failed), recent activity logs, queue status, and overall campaign performance.

Use Laravel, Filament and Tailwind CSS. 
